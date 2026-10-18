import { describe, expect, it } from 'vitest';
import { buildRadarConfig, legendItems, legendSwatchSvg, rotateToTop, SERIES_STYLES, TOP_CATEGORY_KEY } from '../../resources/js/personality-radar-config.js';

const LABELS = ['対人', '対犬', '所有欲', '分離不安', '恐怖心', '訓練性能', '興奮性'];
const KEYS = ['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'];
const CURRENT = { key: 'current', date: '2026/06/01', values: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'] };
const PREVIOUS = { key: 'previous', date: '2026/03/10', values: ['2.2', '2.0', '1.0', '1.7', '0.7', '1.3', '1.6'] };
const INITIAL = { key: 'initial', date: '2025/12/01', values: ['0.1', '1.0', '0.0', '0.7', '0.8', '2.9', '1.1'] };

describe('性格診断のレーダーチャートの設定', () => {
    it('7 カテゴリのラベルと、目盛り 0〜4 の固定', () => {
        const config = buildRadarConfig({ labels: LABELS, series: [CURRENT] });

        expect(config.type).toBe('radar');
        expect(config.data.labels).toEqual(LABELS);
        expect(config.options.scales.r.min).toBe(0);
        expect(config.options.scales.r.max).toBe(4);
        expect(config.options.scales.r.ticks.stepSize).toBe(1);
    });

    it('今回だけ：紺の実線と薄い塗り、凡例に回答日', () => {
        const config = buildRadarConfig({ labels: LABELS, series: [CURRENT] });

        expect(config.data.datasets).toHaveLength(1);
        const current = config.data.datasets[0];
        expect(current.label).toBe('今回（2026/06/01）');
        expect(current.data).toEqual([2, 1.9, 1, 1.4, 2.7, 0.9, 1]);
        expect(current.borderColor).toBe(SERIES_STYLES.current.borderColor);
        expect(current.borderDash).toEqual([]);
        expect(current.fill).toBe(true);
    });

    it('今回・前回：前回は灰色の破線（塗りなし）', () => {
        const config = buildRadarConfig({ labels: LABELS, series: [CURRENT, PREVIOUS] });

        expect(config.data.datasets.map((d) => d.label)).toEqual(['今回（2026/06/01）', '前回（2026/03/10）']);
        const previous = config.data.datasets[1];
        expect(previous.data).toEqual([2.2, 2, 1, 1.7, 0.7, 1.3, 1.6]);
        expect(previous.borderColor).toBe('#6c757d');
        expect(previous.borderDash).toEqual([6, 4]);
        expect(previous.fill).toBe(false);
    });

    it('今回・前回・初回：初回は前回より薄い灰色の細かい点線（塗りなし）', () => {
        const config = buildRadarConfig({ labels: LABELS, series: [CURRENT, PREVIOUS, INITIAL] });

        expect(config.data.datasets.map((d) => d.label)).toEqual(['今回（2026/06/01）', '前回（2026/03/10）', '初回（2025/12/01）']);
        const initial = config.data.datasets[2];
        expect(initial.data).toEqual([0.1, 1, 0, 0.7, 0.8, 2.9, 1.1]);
        expect(initial.borderColor).toBe('#adb5bd');
        expect(initial.borderDash).toEqual([2, 3]);
        expect(initial.fill).toBe(false);
        // 線の形は 3 本とも違う
        const dashes = config.data.datasets.map((d) => JSON.stringify(d.borderDash));
        expect(new Set(dashes).size).toBe(3);
    });
});

describe('性格診断のレーダーチャートの HTML の凡例', () => {
    it('Chart.js の凡例は出さない', () => {
        const config = buildRadarConfig({ labels: LABELS, series: [CURRENT] });

        expect(config.options.plugins.legend.display).toBe(false);
    });

    it('今回だけ：項目は 1 つで、見本は紺の実線だけ（塗りの四角は描かない）', () => {
        const items = legendItems({ series: [CURRENT] });

        expect(items.map((i) => i.label)).toEqual(['今回（2026/06/01）']);
        const svg = legendSwatchSvg(items[0]);
        expect(svg).toContain('aria-hidden="true"');
        expect(svg).toContain('stroke="#0a4fa8"');
        expect(svg).not.toContain('stroke-dasharray');
        // 2026-10 変更：当初は線の下に塗りの色の四角を描いていた
        expect(svg).not.toContain('<rect');
        expect(svg).not.toContain('rgba(10, 79, 168, 0.15)');
    });

    it('今回・前回：前回の見本は灰色の破線で塗りなし', () => {
        const items = legendItems({ series: [CURRENT, PREVIOUS] });

        expect(items.map((i) => i.label)).toEqual(['今回（2026/06/01）', '前回（2026/03/10）']);
        const svg = legendSwatchSvg(items[1]);
        expect(svg).toContain('stroke="#6c757d"');
        expect(svg).toContain('stroke-dasharray="6 4"');
        expect(svg).not.toContain('<rect');
    });

    it('今回・前回・初回：並びは今回 → 前回 → 初回で、初回の見本は薄い灰色の点線。見本はグラフの線と同じ値', () => {
        const data = { labels: LABELS, series: [CURRENT, PREVIOUS, INITIAL] };
        const items = legendItems(data);
        const datasets = buildRadarConfig(data).data.datasets;

        expect(items.map((i) => i.label)).toEqual(['今回（2026/06/01）', '前回（2026/03/10）', '初回（2025/12/01）']);
        expect(legendSwatchSvg(items[2])).toContain('stroke="#adb5bd"');
        expect(legendSwatchSvg(items[2])).toContain('stroke-dasharray="2 3"');
        // 凡例とグラフで文・色・線の形が一致する
        items.forEach((item, i) => {
            expect(item.label).toBe(datasets[i].label);
            expect(item.borderColor).toBe(datasets[i].borderColor);
            expect(item.borderDash).toEqual(datasets[i].borderDash);
        });
    });

    it('見本は今回・前回・初回のどれでも線 1 本だけで、大きさと線の位置・太さは今までどおり', () => {
        const items = legendItems({ series: [CURRENT, PREVIOUS, INITIAL] });
        const expected = [
            ['#0a4fa8', ''],
            ['#6c757d', ' stroke-dasharray="6 4"'],
            ['#adb5bd', ' stroke-dasharray="2 3"'],
        ];

        items.forEach((item, i) => {
            const [color, dash] = expected[i];
            expect(legendSwatchSvg(item)).toBe(
                '<svg width="32" height="14" viewBox="0 0 32 14" aria-hidden="true" focusable="false">'
                + `<line x1="1" y1="7" x2="31" y2="7" stroke="${color}" stroke-width="2"${dash} /></svg>`,
            );
            expect(legendSwatchSvg(item)).not.toMatch(/<rect|<path|<polygon|fill=/);
        });
    });

    it('グラフ本体の今回の塗りは今までどおり', () => {
        const dataset = buildRadarConfig({ labels: LABELS, series: [CURRENT] }).data.datasets[0];

        expect(dataset.fill).toBe(true);
        expect(dataset.backgroundColor).toBe('rgba(10, 79, 168, 0.15)');
    });
});

// 訓練性能の軸を真上に置く（2026-10-18 追加）。時計回りの順は変えず、グラフに渡す並びの始まりを訓練性能にずらす
// （最初は scales.r.startAngle で回していたが、目盛りの数字が対人の軸に沿って横倒しになったため、この形にした）
describe('性格診断のレーダーチャートの向き', () => {
    const ROTATED_LABELS = ['訓練性能', '興奮性', '対人', '対犬', '所有欲', '分離不安', '恐怖心'];

    it('ラベルの先頭が訓練性能で、時計回りの順は変わらない', () => {
        const config = buildRadarConfig({ labels: LABELS, keys: KEYS, series: [CURRENT] });

        expect(TOP_CATEGORY_KEY).toBe('trainability');
        expect(config.data.labels).toEqual(ROTATED_LABELS);
        // 時計回りの順（輪として見たとき）は元の並びと同じ：先頭からたどると元の並びの訓練性能から始めたものになる
        const start = LABELS.indexOf('訓練性能');
        expect(config.data.labels).toEqual([...LABELS.slice(start), ...LABELS.slice(0, start)]);
    });

    it('今回・前回・初回の値もラベルと同じだけずれる（ラベルと値の対応がずれない）', () => {
        const config = buildRadarConfig({ labels: LABELS, keys: KEYS, series: [CURRENT, PREVIOUS, INITIAL] });

        [CURRENT, PREVIOUS, INITIAL].forEach((series, i) => {
            const data = config.data.datasets[i].data;
            // どのラベルの位置にも、元の並びでそのラベルだった値がある
            config.data.labels.forEach((label, j) => {
                expect(data[j]).toBe(Number(series.values[LABELS.indexOf(label)]));
            });
        });
        // 例：先頭（訓練性能）の値は、元の並びの 6 番目（訓練性能）の値
        expect(config.data.datasets[0].data[0]).toBe(0.9);
        expect(config.data.datasets[2].data[0]).toBe(2.9);
    });

    it('keys に trainability がないとき・keys がないときは並べ直さない', () => {
        for (const data of [
            { labels: LABELS, series: [CURRENT] },
            { labels: LABELS, keys: ['a', 'b', 'c', 'd', 'e', 'f', 'g'], series: [CURRENT] },
        ]) {
            const config = buildRadarConfig(data);
            expect(config.data.labels).toEqual(LABELS);
            expect(config.data.datasets[0].data).toEqual([2, 1.9, 1, 1.4, 2.7, 0.9, 1]);
        }
    });

    it('並びや項目の数が変わっても、訓練性能が先頭に来る', () => {
        const data = { labels: ['A', 'B', 'T', 'D'], keys: ['a', 'b', 'trainability', 'd'], series: [{ key: 'current', date: '2026/06/01', values: ['1', '2', '3', '4'] }] };

        const rotated = rotateToTop(data);

        expect(rotated.labels).toEqual(['T', 'D', 'A', 'B']);
        expect(rotated.keys).toEqual(['trainability', 'd', 'a', 'b']);
        expect(rotated.series[0].values).toEqual(['3', '4', '1', '2']);
        // 渡されたデータは書き換えない
        expect(data.labels).toEqual(['A', 'B', 'T', 'D']);
        expect(data.series[0].values).toEqual(['1', '2', '3', '4']);
    });

    it('角度では回さない（scales.r.startAngle を指定しない）。トレーナー側と compact の両方で同じ並び', () => {
        for (const options of [{}, { compact: true }]) {
            const config = buildRadarConfig({ labels: LABELS, keys: KEYS, series: [CURRENT] }, options);

            expect(config.options.scales.r).not.toHaveProperty('startAngle');
            expect(config.data.labels).toEqual(ROTATED_LABELS);
            expect(config.data.datasets[0].data).toEqual([0.9, 1, 2, 1.9, 1, 1.4, 2.7]);
        }
    });
});

// 点数なし（そのカテゴリの答えがすべて「未経験」。2026-10-18 追加）
describe('性格診断のレーダーチャートの点数なし', () => {
    // 対犬（2 番目）と訓練性能（6 番目）が点数なし
    const WITH_NULL = { key: 'current', date: '2026/06/01', values: ['2.0', null, '1.0', '1.4', '2.7', null, '1.0'] };

    it('点数なしは 0 にせず null のまま渡し、となりの点どうしをつなぐ（spanGaps）', () => {
        for (const options of [{}, { compact: true }]) {
            const config = buildRadarConfig({ labels: LABELS, series: [WITH_NULL] }, options);
            const dataset = config.data.datasets[0];

            expect(dataset.data).toEqual([2, null, 1, 1.4, 2.7, null, 1]);
            expect(dataset.spanGaps).toBe(true);
        }
    });

    it('訓練性能を上に置く並べ直しでも、null は同じ項目の位置のまま動く', () => {
        const config = buildRadarConfig({ labels: LABELS, keys: KEYS, series: [WITH_NULL, PREVIOUS] });

        // 並べ直したあと：訓練性能・興奮性・対人・対犬・所有欲・分離不安・恐怖心
        expect(config.data.labels).toEqual(['訓練性能', '興奮性', '対人', '対犬', '所有欲', '分離不安', '恐怖心']);
        expect(config.data.datasets[0].data).toEqual([null, 1, 2, null, 1, 1.4, 2.7]);
        expect(config.data.labels[config.data.datasets[0].data.indexOf(null)]).toBe('訓練性能');
        expect(config.data.labels[config.data.datasets[0].data.lastIndexOf(null)]).toBe('対犬');
        // ほかの回（点数なしがない）はそのまま数になる
        expect(config.data.datasets[1].data).not.toContain(null);
        expect(config.data.datasets.every((d) => d.spanGaps === true)).toBe(true);
    });
});
