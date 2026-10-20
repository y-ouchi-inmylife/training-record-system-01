/**
 * 性格診断のレーダーチャートを描く（S-0309 トレーニー詳細の性格診断の欄。2026-10 追加）。
 *
 * Blade の `<canvas data-personality-radar="…">` の属性値（JSON。labels・series）から、
 * personality-radar-config.js で設定を組み立てて Chart.js で描く。グラフは欄に 1 つで、画面を開いたときに描く。
 * 計測値のグラフ（measurement-chart.js）と同じく、必要な部品だけを register する（新しいパッケージは足さない）。
 *
 * 2026-10 変更：当初は回ごとにグラフを置き、閉じている回は開いたとき（shown.bs.collapse）に描いていた。
 * 欄をグラフ 1 つと結果の表に作り直したため、その仕組みをなくした。
 *
 * 2026-10-20 追加：マイページ（S-1402）の記録の一覧のアドバイスのカードにも、その回のグラフを出す（data-personality-radar-card）。
 * カードは記録の数だけ並ぶので、data-personality-radar-lazy が付いた canvas は、画面に入ってから（手前 200px から）描く
 * （IntersectionObserver。ないブラウザでは開いたときに描く）。付いていない canvas（愛犬のカード・S-1418・トレーナー側）は今までどおり開いたときに描く
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

/** 1 つの canvas にグラフを描く（凡例の枠があれば凡例も作る） */
function draw(canvas) {
    const data = JSON.parse(canvas.dataset.personalityRadar);
    // data-personality-radar-compact：会員側のマイページの小さなグラフ（2026-10 追加）。トレーナー側には付けない。
    // data-personality-radar-card：記録の一覧のアドバイスのカードの、さらに小さなグラフ（2026-10-20 追加）
    new Chart(canvas, buildRadarConfig(data, {
        compact: 'personalityRadarCompact' in canvas.dataset,
        card: 'personalityRadarCard' in canvas.dataset,
    }));
    const legend = document.querySelector(`[data-personality-radar-legend="${canvas.id}"]`);
    if (legend) {
        renderLegend(legend, data);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const canvases = [...document.querySelectorAll('canvas[data-personality-radar]')];
    const lazy = canvases.filter((canvas) => 'personalityRadarLazy' in canvas.dataset);
    canvases.filter((canvas) => !lazy.includes(canvas)).forEach(draw);

    if (lazy.length === 0) {
        return;
    }
    if (!('IntersectionObserver' in window)) {
        lazy.forEach(draw);

        return;
    }
    // 画面に入ったら 1 回だけ描き、見張るのをやめる
    const observer = new IntersectionObserver((entries) => {
        entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
            observer.unobserve(entry.target);
            draw(entry.target);
        });
    }, { rootMargin: '200px 0px' });
    lazy.forEach((canvas) => observer.observe(canvas));
});
