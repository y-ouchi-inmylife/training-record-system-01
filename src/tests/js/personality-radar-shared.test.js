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
        // data-personality-radar-compact が付いた canvas（会員側）だけ、小さなグラフの設定にする（2026-10 追加）。
        // data-personality-radar-card が付いた canvas（記録の一覧のアドバイスのカード）は、さらに小さな文字にする（2026-10-20 追加）
        expect(entry).toContain("compact: 'personalityRadarCompact' in canvas.dataset,");
        expect(entry).toContain("card: 'personalityRadarCard' in canvas.dataset,");
        // data-personality-radar-lazy が付いた canvas は、画面に入ってから描く（2026-10-20 追加）
        expect(entry).toContain("'personalityRadarLazy' in canvas.dataset");
        expect(entry).toContain('new IntersectionObserver(');
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

    it('compact の設定（会員側）は、枠の高さいっぱいに描き文字を小さくする。トレーナー側（指定なし）は今までどおり', () => {
        const data = {
            labels: ['対人', '対犬', '所有欲', '分離不安', '恐怖心', '訓練性能', '興奮性'],
            series: [{ key: 'current', date: '2026/06/01', values: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'] }],
        };
        const trainer = buildRadarConfig(data);
        const client = buildRadarConfig(data, { compact: true });

        // トレーナー側：今までと同じ（正方形の枠・Chart.js の既定の文字の大きさ・余白）
        expect(trainer.options.maintainAspectRatio).toBe(true);
        expect(trainer.options.layout).toBeUndefined();
        expect(trainer.options.scales.r.pointLabels).toBeUndefined();
        expect(trainer.options.scales.r.ticks).toEqual({ stepSize: 1 });
        expect(buildRadarConfig(data, { compact: false })).toEqual(trainer);
        expect(buildRadarConfig(data, {})).toEqual(trainer);

        // 会員側：枠の高さいっぱい・項目名 11px・目盛り 9px・余白なし。データと色・線の形は同じ
        expect(client.options.maintainAspectRatio).toBe(false);
        expect(client.options.layout).toEqual({ padding: 0 });
        expect(client.options.scales.r.pointLabels.font.size).toBe(11);
        expect(client.options.scales.r.ticks.font.size).toBe(9);
        expect(client.options.scales.r.ticks.stepSize).toBe(1);
        expect(client.options.scales.r.min).toBe(0);
        expect(client.options.scales.r.max).toBe(4);
        expect(client.data).toEqual(trainer.data);
        expect(client.options.plugins).toEqual(trainer.options.plugins);
    });

    it('トレーナー側のビューの canvas には compact の印を付けない', () => {
        const trainerView = read('../../resources/views/trainees/show.blade.php');
        const clientView = read('../../resources/views/client/dashboard.blade.php');

        expect(trainerView).not.toContain('data-personality-radar-compact');
        expect(clientView).toContain('data-personality-radar-compact');
    });

    it('記録の一覧のアドバイスのカード（card）は、凡例なし・線 1 本で、compact の文字の大きさだけを小さくする（2026-10-20 追加）', () => {
        // カードの canvas に入る値の形（TraineePersonalityDiagnosisResult::personalityRadarDataOnly。今回の 1 つだけ）
        const data = {
            labels: ['対人', '対犬', '所有欲', '分離不安', '恐怖心', '訓練性能', '興奮性'],
            keys: ['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'],
            series: [{ key: 'current', date: '2026/06/01', values: ['2.0', '1.9', '1.0', '1.4', '2.7', '0.9', '1.0'] }],
        };
        const compact = buildRadarConfig(data, { compact: true });
        const card = buildRadarConfig(data, { compact: true, card: true });

        // 線は 1 本（今回）・凡例は出さない
        expect(card.data.datasets).toHaveLength(1);
        expect(card.data.datasets[0].borderColor).toBe('#0a4fa8');
        expect(card.options.plugins.legend.display).toBe(false);
        // 文字の大きさ（項目名 10px・目盛り 8px）と項目名の間のほかは S-1418（compact）と同じ
        expect(card.options.scales.r.pointLabels).toEqual({ font: { size: 10 }, padding: 2 });
        expect(card.options.scales.r.ticks).toEqual({ stepSize: 1, font: { size: 8 }, backdropPadding: 1 });
        const strip = (config) => ({ ...config, options: { ...config.options, scales: { r: { ...config.options.scales.r, pointLabels: null, ticks: null } } } });
        expect(strip(card)).toEqual(strip(compact));
        // 訓練性能が真上（先頭）
        expect(card.data.labels[0]).toBe('訓練性能');
        // card だけ（compact なし）は、トレーナー側の設定のまま
        expect(buildRadarConfig(data, { card: true })).toEqual(buildRadarConfig(data));
    });

    it('記録の一覧のアドバイスのカードの canvas には card と lazy の印を付ける。愛犬のカードと S-1418 には付けない', () => {
        const card = read('../../resources/views/client/_evaluation-session.blade.php');
        const dashboard = read('../../resources/views/client/dashboard.blade.php');
        const s1418 = read('../../resources/views/client/personality-diagnosis-results/show.blade.php');

        expect(card).toContain('data-personality-radar-compact data-personality-radar-card data-personality-radar-lazy');
        expect(card).not.toContain('data-personality-radar-legend');
        expect(dashboard).not.toContain('data-personality-radar-card');
        expect(dashboard).not.toContain('data-personality-radar-lazy');
        expect(s1418).not.toContain('data-personality-radar-card');
        expect(s1418).not.toContain('data-personality-radar-lazy');
    });
});
