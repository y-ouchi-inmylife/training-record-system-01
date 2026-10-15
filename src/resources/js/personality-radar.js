/**
 * 性格診断のレーダーチャートを描く（S-0309 トレーニー詳細の性格診断の欄。2026-10 追加）。
 *
 * Blade の `<canvas data-personality-radar="…">` の属性値（JSON。labels・current・previous）から、
 * personality-radar-config.js で設定を組み立てて Chart.js で描く。計測値のグラフ（measurement-chart.js）と同じく、
 * 必要な部品だけを register する（新しいパッケージは足さない）。
 *
 * 閉じている回の canvas は大きさが 0 なので、そのまま描くと崩れる。そのため：
 * - 画面を開いたときに開いている回（いちばん新しい回）は、すぐに描く
 * - 閉じている回は、その回の collapse が開いたとき（shown.bs.collapse）に初めて描く
 * - 一度描いた回は、閉じて開き直しても描き直さない
 * 回の中の「50問の回答」の collapse の shown も外側へ伝わるため、event.target が回の collapse のときだけ描く。
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

const drawn = new WeakSet();

function draw(canvas) {
    if (drawn.has(canvas)) {
        return;
    }
    drawn.add(canvas);
    new Chart(canvas, buildRadarConfig(JSON.parse(canvas.dataset.personalityRadar)));
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-personality-diagnosis-result]').forEach((collapse) => {
        const canvas = collapse.querySelector('canvas[data-personality-radar]');
        if (!canvas) {
            return;
        }
        if (collapse.classList.contains('show')) {
            draw(canvas);
        }
        collapse.addEventListener('shown.bs.collapse', (event) => {
            if (event.target === collapse) {
                draw(canvas);
            }
        });
    });
});
