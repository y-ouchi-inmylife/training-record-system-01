import { describe, expect, it } from 'vitest';
import { buildRadarConfig, legendItems, legendSwatchSvg, radarStartAngle, SERIES_STYLES, TOP_CATEGORY_KEY } from '../../resources/js/personality-radar-config.js';

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

// 訓練性能の軸を真上に置く（2026-10-18 追加）。項目の並び（時計回りの順）は変えず、全体を回す
describe('性格診断のレーダーチャートの向き', () => {
    /** その項目の軸の角度（度。0 が真上、時計回り。Chart.js は 1 つ目の項目を startAngle に置き、360 / 項目の数 ずつ並べる） */
    const axisAngle = (startAngle, index, count) => (((startAngle + (index * 360) / count) % 360) + 360) % 360;

    it('訓練性能の位置と項目の数から角度を計算し、訓練性能の軸が真上（0 度）になる', () => {
        const angle = radarStartAngle(KEYS);

        expect(TOP_CATEGORY_KEY).toBe('trainability');
        expect(angle).toBeCloseTo(-(5 * 360) / 7, 10);
        expect(axisAngle(angle, KEYS.indexOf('trainability'), KEYS.length)).toBeCloseTo(0, 10);
    });

    it('並びや項目の数が変わっても、訓練性能が真上に来る', () => {
        for (const keys of [
            ['trainability', 'people', 'dogs'],
            ['people', 'trainability', 'dogs', 'fear'],
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'trainability'],
        ]) {
            const angle = radarStartAngle(keys);
            const top = axisAngle(angle, keys.indexOf('trainability'), keys.length);
            expect(Math.min(top, 360 - top)).toBeCloseTo(0, 10);
        }
        // 1 つ目にあるときは回さない
        expect(radarStartAngle(['trainability', 'people'])).toBe(0);
    });

    it('訓練性能が見つからないとき（keys がない古いデータなど）は回さない', () => {
        expect(radarStartAngle(undefined)).toBe(0);
        expect(radarStartAngle(['people', 'dogs'])).toBe(0);
    });

    it('トレーナー側と会員側（compact）の両方の設定に入り、項目の並びは変えない', () => {
        for (const options of [{}, { compact: true }]) {
            const config = buildRadarConfig({ labels: LABELS, keys: KEYS, series: [CURRENT] }, options);

            expect(config.options.scales.r.startAngle).toBeCloseTo(-(5 * 360) / 7, 10);
            expect(config.data.labels).toEqual(LABELS);
            expect(config.data.datasets[0].data).toEqual([2, 1.9, 1, 1.4, 2.7, 0.9, 1]);
        }
    });

    it('keys を渡さないときは今までどおり回さない', () => {
        const config = buildRadarConfig({ labels: LABELS, series: [CURRENT] });

        expect(config.options.scales.r.startAngle).toBe(0);
    });
});
