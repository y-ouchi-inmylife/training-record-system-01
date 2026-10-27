import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import {
    createPinLatest,
    handleOutsidePointerDown,
    isOnlyLatestActive,
    latestPointIndex,
    pinLatestIfIdle,
    resetToLatest,
} from '../../resources/js/measurement-chart-latest.js';

// 体重推移グラフで、最新点のツールチップを常時表示する（会員側 S-1402 だけ。2026-10-27 追加）。
// Chart.js のインスタンスの代わりに、使うところだけを持った偽物で確かめる。

const pts = (...ys) => ys.map((y, i) => ({ x: `2026-10-${String(i + 1).padStart(2, '0')}T08:00:00`, y }));

/** Chart.js のグラフの偽物（setActiveElements・tooltip・getDatasetMeta・render・canvas だけ） */
function fakeChart(points, active = []) {
    const canvas = { contains: (target) => target === canvas };
    const chart = {
        canvas,
        data: { datasets: [{ data: points }] },
        tooltipActive: active,
        setActiveElements: vi.fn(),
        tooltip: {
            getActiveElements: () => chart.tooltipActive,
            setActiveElements: vi.fn((elements) => {
                chart.tooltipActive = elements;
            }),
        },
        getDatasetMeta: () => ({ data: points.map((_, i) => ({ x: i * 10, y: 50 })) }),
        render: vi.fn(),
    };

    return chart;
}

const latestOf = (chart) => [{ datasetIndex: 0, index: chart.data.datasets[0].data.length - 1 }];

describe('最新点の位置', () => {
    it('y が null でない最後の要素', () => {
        expect(latestPointIndex(pts(5, 5.1, 5.2))).toBe(2);
        expect(latestPointIndex(pts(5, 5.1, null))).toBe(1);
    });

    it('計測値が 1 件ならその点', () => {
        expect(latestPointIndex(pts(5))).toBe(0);
    });

    it('点がない・すべて null なら -1', () => {
        expect(latestPointIndex([])).toBe(-1);
        expect(latestPointIndex(pts(null, null))).toBe(-1);
        expect(latestPointIndex(undefined)).toBe(-1);
    });

    it('最新点だけが選ばれているか', () => {
        expect(isOnlyLatestActive([{ datasetIndex: 0, index: 2 }], 2)).toBe(true);
        expect(isOnlyLatestActive([{ datasetIndex: 0, index: 1 }], 2)).toBe(false);
        expect(isOnlyLatestActive([], 2)).toBe(false);
    });
});

describe('最新点に戻す', () => {
    it('点が選ばれていなければ最新点を選び、ツールチップを最新点の座標に出す', () => {
        const chart = fakeChart(pts(5, 5.1, 5.2));

        expect(pinLatestIfIdle(chart)).toBe(true);
        expect(chart.setActiveElements).toHaveBeenCalledWith([{ datasetIndex: 0, index: 2 }]);
        expect(chart.tooltip.setActiveElements).toHaveBeenCalledWith([{ datasetIndex: 0, index: 2 }], { x: 20, y: 50 });
    });

    it('点が選ばれていれば何もしない（すでに最新点のときも、ほかの点のときも）', () => {
        const latest = fakeChart(pts(5, 5.1));
        latest.tooltipActive = latestOf(latest);
        const other = fakeChart(pts(5, 5.1), [{ datasetIndex: 0, index: 0 }]);

        expect(pinLatestIfIdle(latest)).toBe(false);
        expect(pinLatestIfIdle(other)).toBe(false);
        expect(latest.tooltip.setActiveElements).not.toHaveBeenCalled();
        expect(other.tooltip.setActiveElements).not.toHaveBeenCalled();
    });

    it('点がなければ何もしない', () => {
        const chart = fakeChart([]);

        expect(pinLatestIfIdle(chart)).toBe(false);
        expect(resetToLatest(chart)).toBe(false);
    });

    it('ほかの点が選ばれていれば最新点に戻す。すでに最新点なら何もしない', () => {
        const chart = fakeChart(pts(5, 5.1, 5.2), [{ datasetIndex: 0, index: 0 }]);

        expect(resetToLatest(chart)).toBe(true);
        expect(chart.tooltipActive).toEqual([{ datasetIndex: 0, index: 2 }]);
        expect(resetToLatest(chart)).toBe(false);
    });
});

describe('グラフごとの部品（createPinLatest）', () => {
    it('初回の初期アニメーションが終わるまでは出さず、終わったら最新点を出して描き直す（初回だけ）', () => {
        const chart = fakeChart(pts(5, 5.1));
        const { plugin, onAnimationComplete } = createPinLatest();
        const args = {};

        plugin.afterEvent(chart, args);
        expect(chart.tooltip.setActiveElements).not.toHaveBeenCalled();
        expect(args.changed).toBeUndefined();

        onAnimationComplete({ chart });
        expect(chart.tooltipActive).toEqual(latestOf(chart));
        expect(chart.render).toHaveBeenCalledTimes(1);

        // 2 回目以降（ツールチップのアニメーションの終わりなど）は何もしない
        chart.tooltipActive = [];
        onAnimationComplete({ chart });
        expect(chart.render).toHaveBeenCalledTimes(1);
    });

    it('初期アニメーションの間にホバーされていたら、その点のまま', () => {
        const chart = fakeChart(pts(5, 5.1, 5.2), [{ datasetIndex: 0, index: 0 }]);
        const { onAnimationComplete } = createPinLatest();

        onAnimationComplete({ chart });

        expect(chart.tooltipActive).toEqual([{ datasetIndex: 0, index: 0 }]);
        expect(chart.render).not.toHaveBeenCalled();
    });

    it('イベントのあと点が選ばれていなければ最新点に戻し、描き直させる。点が選ばれていれば何もしない', () => {
        const chart = fakeChart(pts(5, 5.1, 5.2));
        const { plugin, onAnimationComplete } = createPinLatest();
        onAnimationComplete({ chart });

        // ほかの点にホバー → そのまま（Chart.js 標準の切り替え）
        chart.tooltipActive = [{ datasetIndex: 0, index: 1 }];
        const hover = {};
        plugin.afterEvent(chart, hover);
        expect(hover.changed).toBeUndefined();
        expect(chart.tooltipActive).toEqual([{ datasetIndex: 0, index: 1 }]);

        // mouseout・点のない場所 → Chart.js が空にする → 最新点に戻す
        chart.tooltipActive = [];
        const out = {};
        plugin.afterEvent(chart, out);
        expect(out.changed).toBe(true);
        expect(chart.tooltipActive).toEqual(latestOf(chart));

        // すでに最新点なら何もしない（描き直しを繰り返さない）
        chart.tooltip.setActiveElements.mockClear();
        const again = {};
        plugin.afterEvent(chart, again);
        expect(again.changed).toBeUndefined();
        expect(chart.tooltip.setActiveElements).not.toHaveBeenCalled();
    });

    it('リサイズによる更新のあと、選ばれている点で位置を計算し直す（なければ最新点）。リサイズでない更新では何もしない', () => {
        const chart = fakeChart(pts(5, 5.1, 5.2));
        const { plugin, onAnimationComplete } = createPinLatest();
        onAnimationComplete({ chart });
        chart.tooltip.setActiveElements.mockClear();

        plugin.afterUpdate(chart);
        expect(chart.tooltip.setActiveElements).not.toHaveBeenCalled();

        chart.tooltipActive = [{ datasetIndex: 0, index: 1, element: {} }];
        plugin.resize(chart);
        plugin.afterUpdate(chart);
        expect(chart.tooltip.setActiveElements).toHaveBeenLastCalledWith([{ datasetIndex: 0, index: 1 }], { x: 10, y: 50 });

        chart.tooltipActive = [];
        plugin.resize(chart);
        plugin.afterUpdate(chart);
        expect(chart.tooltipActive).toEqual(latestOf(chart));
    });

    it('グラフごとに状態を持つ（1 つ目の初期アニメーションが終わっても、2 つ目は出さない）', () => {
        const first = fakeChart(pts(5, 5.1));
        const second = fakeChart(pts(3));
        const a = createPinLatest();
        const b = createPinLatest();

        a.onAnimationComplete({ chart: first });
        b.plugin.afterEvent(second, {});

        expect(first.tooltipActive).toEqual(latestOf(first));
        expect(second.tooltip.setActiveElements).not.toHaveBeenCalled();
    });
});

describe('canvas の外のタップ', () => {
    it('タップした場所がその canvas の外のグラフだけ最新点に戻して描き直す', () => {
        const tapped = fakeChart(pts(5, 5.1, 5.2), [{ datasetIndex: 0, index: 0 }]);
        const other = fakeChart(pts(4, 4.1), [{ datasetIndex: 0, index: 0 }]);

        // tapped の canvas の中をタップ → tapped はそのまま、other は外なので最新点に戻る
        handleOutsidePointerDown({ target: tapped.canvas }, new Set([tapped, other]));

        expect(tapped.tooltipActive).toEqual([{ datasetIndex: 0, index: 0 }]);
        expect(tapped.render).not.toHaveBeenCalled();
        expect(other.tooltipActive).toEqual(latestOf(other));
        expect(other.render).toHaveBeenCalledTimes(1);
    });

    it('すでに最新点なら描き直さない', () => {
        const chart = fakeChart(pts(5));
        chart.tooltipActive = latestOf(chart);

        handleOutsidePointerDown({ target: {} }, new Set([chart]));

        expect(chart.render).not.toHaveBeenCalled();
    });
});

describe('measurement-chart.js の組み込み', () => {
    const src = readFileSync(new URL('../../resources/js/measurement-chart.js', import.meta.url), 'utf8');

    it('data-measurement-chart-pin-latest が付いた canvas だけに足す（トレーナー側 S-0309 は今までどおり）', () => {
        expect(src).toContain("'measurementChartPinLatest' in canvas.dataset ? createPinLatest() : null");
        expect(src).toContain('...(pinLatest ? { plugins: [pinLatest.plugin] } : {}),');
        expect(src).toContain('...(pinLatest ? { animation: { onComplete: pinLatest.onAnimationComplete } } : {}),');
    });

    it('Chart.defaults を変えず、プラグインをグローバルに登録しない（レーダーチャートに影響させない）', () => {
        const latest = readFileSync(new URL('../../resources/js/measurement-chart-latest.js', import.meta.url), 'utf8');

        expect(src).not.toContain('Chart.defaults');
        expect(src).not.toMatch(/Chart\.register\([^)]*pinLatest/);
        expect(latest).not.toMatch(/from 'chart\.js'/);
        // interaction（ホバー・タップの判定）は変えない
        expect(src).not.toContain('interaction');
    });
});
