import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { buildRadarConfig, legendItems, legendSwatchSvg } from '../../resources/js/personality-radar-config.js';

// 性格診断のレーダーチャートを、トレーナー側のトレーニー詳細（S-0309）と会員側のマイページ（S-1402）で共有していること
// （2026-10 追加。要件定義書 6-15-19）。会員側のために作り直さず、同じ入口（personality-radar.js）と同じ設定の関数
// （personality-radar-config.js）を使う。

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

describe('性格診断のレーダーチャートの共有（トレーナー側と会員側）', () => {
    it('描く入口（personality-radar.js）は設定の関数を personality-radar-config から読み込んで使う', () => {
        const entry = read('../../resources/js/personality-radar.js');

        expect(entry).toMatch(/import \{ buildRadarConfig, legendItems, legendSwatchSvg \} from '\.\/personality-radar-config';/);
        expect(entry).toContain('new Chart(canvas, buildRadarConfig(data))');
        // canvas の data-personality-radar と、凡例の枠 data-personality-radar-legend="{canvas の id}" で見つける（画面に依らない）
        expect(entry).toContain("document.querySelectorAll('canvas[data-personality-radar]')");
        expect(entry).toContain('[data-personality-radar-legend="${canvas.id}"]');
    });

    it('トレーナー側と会員側のビューは同じ入口を読み込む', () => {
        const trainer = read('../../resources/views/trainees/show.blade.php');
        const client = read('../../resources/views/client/dashboard.blade.php');

        expect(trainer).toContain("@vite('resources/js/personality-radar.js')");
        expect(client).toContain("@vite('resources/js/personality-radar.js')");
        // 会員側専用の別の入口は作らない
        expect(client).not.toMatch(/@vite\('resources\/js\/client[^']*radar[^']*'\)/);
    });

    it('会員側の値（今回・前回・初回）からも、トレーナー側と同じ設定と凡例が作られる', () => {
        // 会員側の canvas に入る値の形（Trainee::personality_radar_data。トレーナー側と同じ）
        const data = {
            labels: ['対人', '対犬', '所有欲', '分離不安', '恐怖心', '訓練性能', '興奮性'],
            series: [
                { key: 'current', date: '2026/06/01', values: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'] },
                { key: 'previous', date: '2026/03/10', values: ['2.2', '2.0', '1.0', '1.7', '0.7', '1.3', '1.6'] },
                { key: 'initial', date: '2025/12/01', values: ['0.1', '1.0', '0.0', '0.7', '0.8', '2.9', '1.1'] },
            ],
        };
        const config = buildRadarConfig(data);

        expect(config.type).toBe('radar');
        expect(config.options.scales.r.min).toBe(0);
        expect(config.options.scales.r.max).toBe(4);
        expect(config.data.datasets.map((d) => d.label)).toEqual(['今回（2026/06/01）', '前回（2026/03/10）', '初回（2025/12/01）']);
        expect(legendItems(data).map((i) => i.label)).toEqual(config.data.datasets.map((d) => d.label));
        legendItems(data).forEach((item) => expect(legendSwatchSvg(item)).not.toContain('<rect'));
    });
});
