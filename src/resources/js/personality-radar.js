/**
 * 性格診断のレーダーチャートを描く（S-0309 トレーニー詳細の性格診断の欄。2026-10 追加）。
 *
 * Blade の `<canvas data-personality-radar="…">` の属性値（JSON。labels・series）から、
 * personality-radar-config.js で設定を組み立てて Chart.js で描く。グラフは欄に 1 つで、画面を開いたときに描く。
 * 計測値のグラフ（measurement-chart.js）と同じく、必要な部品だけを register する（新しいパッケージは足さない）。
 *
 * 2026-10 変更：当初は回ごとにグラフを置き、閉じている回は開いたとき（shown.bs.collapse）に描いていた。
 * 欄をグラフ 1 つと結果の表に作り直したため、その仕組みをなくした。
 */
import {
    Chart,
    RadarController,
    RadialLinearScale,
    PointElement,
    LineElement,
    Filler,
    Legend,
    Tooltip,
} from 'chart.js';
import { buildRadarConfig } from './personality-radar-config';

Chart.register(RadarController, RadialLinearScale, PointElement, LineElement, Filler, Legend, Tooltip);

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('canvas[data-personality-radar]').forEach((canvas) => {
        new Chart(canvas, buildRadarConfig(JSON.parse(canvas.dataset.personalityRadar)));
    });
});
