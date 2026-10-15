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
import { buildRadarConfig, legendItems, legendSwatchSvg } from './personality-radar-config';

Chart.register(RadarController, RadialLinearScale, PointElement, LineElement, Filler, Legend, Tooltip);

/**
 * グラフの下の HTML の凡例を作る（2026-10 変更：Chart.js の凡例はグラフの枠の中に描かれ 2 行に折り返すため、HTML にした）。
 * 見本の SVG は決まった値だけから作り、凡例の文は textContent で入れる
 */
function renderLegend(container, data) {
    container.replaceChildren();
    legendItems(data).forEach((item) => {
        const li = document.createElement('li');
        li.className = 'd-inline-flex align-items-center gap-1';
        li.innerHTML = legendSwatchSvg(item);
        const text = document.createElement('span');
        text.textContent = item.label;
        li.appendChild(text);
        container.appendChild(li);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('canvas[data-personality-radar]').forEach((canvas) => {
        const data = JSON.parse(canvas.dataset.personalityRadar);
        new Chart(canvas, buildRadarConfig(data));
        const legend = document.querySelector(`[data-personality-radar-legend="${canvas.id}"]`);
        if (legend) {
            renderLegend(legend, data);
        }
    });
});
