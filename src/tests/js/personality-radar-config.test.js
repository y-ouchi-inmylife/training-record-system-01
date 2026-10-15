import { describe, expect, it } from 'vitest';
import { buildRadarConfig, CURRENT_COLOR, PREVIOUS_COLOR } from '../../resources/js/personality-radar-config.js';

const LABELS = ['対人', '対犬', '所有欲', '分離不安', '恐怖心', '訓練性能', '興奮性'];

describe('性格診断のレーダーチャートの設定', () => {
    it('7 カテゴリのラベルと、目盛り 0〜4 の固定', () => {
        const config = buildRadarConfig({ labels: LABELS, current: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'], previous: null });

        expect(config.type).toBe('radar');
        expect(config.data.labels).toEqual(LABELS);
        expect(config.options.scales.r.min).toBe(0);
        expect(config.options.scales.r.max).toBe(4);
        expect(config.options.scales.r.ticks.stepSize).toBe(1);
        expect(config.options.plugins.legend.display).toBe(true);
    });

    it('前回がない回はデータの組が「今回」の 1 つだけ', () => {
        const config = buildRadarConfig({ labels: LABELS, current: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'], previous: null });

        expect(config.data.datasets).toHaveLength(1);
        expect(config.data.datasets[0].label).toBe('今回');
        expect(config.data.datasets[0].data).toEqual([2, 1.9, 1, 1.4, 2.7, 0.9, 1]);
        expect(config.data.datasets[0].borderColor).toBe(CURRENT_COLOR);
        expect(config.data.datasets[0].fill).toBe(true);
    });

    it('前回がある回は「今回」と「前回」（灰色の点線・塗りなし）の 2 つ', () => {
        const config = buildRadarConfig({
            labels: LABELS,
            current: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'],
            previous: ['2.2', '2.0', '1.0', '1.7', '0.7', '1.3', '1.6'],
        });

        expect(config.data.datasets.map((d) => d.label)).toEqual(['今回', '前回']);
        const previous = config.data.datasets[1];
        expect(previous.data).toEqual([2.2, 2, 1, 1.7, 0.7, 1.3, 1.6]);
        expect(previous.borderColor).toBe(PREVIOUS_COLOR);
        expect(previous.borderDash).toEqual([6, 4]);
        expect(previous.fill).toBe(false);
    });
});
