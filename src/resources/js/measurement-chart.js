/**
 * トレーニー体重推移グラフの描画（S-1402 ダッシュボード）。
 *
 * Blade から `<canvas data-measurement-chart="...">` を配置し、その属性値に
 * JSON.stringify したチャートデータ（datasets）を載せる。
 * このスクリプトは DOM 読み込み後、該当する canvas をすべて拾って Chart.js
 * で折れ線を描く。
 *
 * ## 横軸は時間軸（2026-09 変更、設計書 S-1402「横軸を時間軸に変更した経緯」参照）
 *
 * 従前はカテゴリ軸で計測日を等間隔に並べていたが、間隔が違う計測点が同じ幅で
 * 表示される問題があったため、時間軸（`type: 'time'`）に変更した。時間軸には
 * date アダプタが**別途必要**なため、`chartjs-adapter-date-fns` を import している
 * （import しないと `TypeError: Cannot read properties of undefined (reading 'time')`
 * のようなエラーで描画が失敗する。Chart.js 4.x の典型的なハマりどころ）。
 *
 * バンドルサイズ抑制のため、必要な Chart.js コンポーネントだけを register する
 * ツリーシェイク前提の import 形式を採る（Chart.js の推奨形。
 * 既存の `qrcode` と同じく npm 経由・ビルドに含める方針。docs/architecture.md §2-1 参照）。
 */
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    TimeScale,
    Tooltip,
    Filler,
} from 'chart.js';
import 'chartjs-adapter-date-fns';
import { format, addDays, parseISO } from 'date-fns';
import { applyYearLabels, measurementTickLabelReserved } from './measurement-chart-ticks';
import { createPinLatest } from './measurement-chart-latest';

Chart.register(
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    TimeScale,
    Tooltip,
    Filler,
);

/**
 * canvas 要素と CSS 変数から線の色を決める。
 * `--c-brand-bright`（`resources/sass/client.scss` の :root で定義）を優先し、
 * 取得できない場合はハードコード値にフォールバックする。
 */
function resolveLineColor(canvas) {
    const style = getComputedStyle(canvas);
    const cssVar = style.getPropertyValue('--c-brand-bright').trim();
    return cssVar || '#2A4A94'; // Fallback: brand-bright と同値（client.scss $brand-bright）
}

/**
 * 単一の canvas に対してチャートを描画する。
 *
 * @param {HTMLCanvasElement} canvas
 * @param {{datasets: {data: {x: string, y: number}[]}[]}} data
 */
function renderChart(canvas, data) {
    const color = resolveLineColor(canvas);

    // 線は 1 本の連続した折れ線（線の分割は廃止）。凡例は不要。
    // spanGaps は使わない（null 埋めが無くなったため）。
    const datasets = data.datasets.map((seg) => ({
        data: seg.data,
        borderColor: color,
        backgroundColor: color,
        pointBackgroundColor: color,
        pointBorderColor: color,
        pointRadius: 3,
        pointHoverRadius: 5,
        borderWidth: 2,
        tension: 0, // 直線で結ぶ（スプライン補間しない）
    }));

    // 横軸の範囲をデータに合わせる（設計書 S-1402「横軸の範囲をデータに合わせる」参照）。
    // Chart.js の自動範囲だと、（1）計測値 1 件のとき目盛りが出ず点だけ浮く、
    // （2）2 件以上でも最初の目盛りが最初の計測日にならない、という問題が発生する
    // ため、min / max を明示する。
    //
    // 決め方（1 件のときも複数のときも**同じロジック**で扱える）：
    //   - min = 最初の計測日の 0:00
    //   - max = 最後の計測日の**翌日** 0:00
    //   - 1 件のときは最初と最後が同じ点なので「その日の 0:00 〜 翌日の 0:00」の
    //     1 日範囲になる（点は左端に来る）
    //
    // タイムゾーン：data.x と同じ naive な ISO 8601 文字列
    // （'YYYY-MM-DDTHH:mm:ss'、タイムゾーン指定なし）で min/max を作る。UTC の 'Z'
    // や '+HH:MM' オフセットを付けると date-fns アダプタの解釈がずれて、意図した
    // 位置と違う場所に軸境界が来てしまう。format() のフォーマット文字列も同形式で
    // 統一する（`HH:mm:ss` は 00:00:00 固定）。
    const points = data.datasets[0].data;
    const firstX = points[0].x;              // 'YYYY-MM-DDTHH:mm:ss'
    const lastX = points[points.length - 1].x;
    const xMinDate = parseISO(`${firstX.substring(0, 10)}T00:00:00`);
    const xMaxDate = addDays(parseISO(`${lastX.substring(0, 10)}T00:00:00`), 1);
    const xMin = format(xMinDate, "yyyy-MM-dd'T'HH:mm:ss");
    const xMax = format(xMaxDate, "yyyy-MM-dd'T'HH:mm:ss");

    // 最新点のツールチップの常時表示（2026-10-27 追加。設計書 S-1402「最新点のツールチップを常時表示する」）。
    // data-measurement-chart-pin-latest が付いた canvas（会員側 S-1402）だけ。付いていないトレーナー側 S-0309 は
    // plugins・animation を足さず、今までと同じ設定のまま（measurement-chart-latest.js）
    const pinLatest = 'measurementChartPinLatest' in canvas.dataset ? createPinLatest() : null;

    new Chart(canvas, {
        type: 'line',
        data: {
            datasets,
        },
        ...(pinLatest ? { plugins: [pinLatest.plugin] } : {}),
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // 初期アニメーションは残し、初回の onComplete で最新点を出す（途中の座標にツールチップが取り残されないように）
            ...(pinLatest ? { animation: { onComplete: pinLatest.onAnimationComplete } } : {}),
            plugins: {
                legend: { display: false },
                tooltip: {
                    // タイトルは Chart.js の time スケールが `x` を自動整形する
                    // （下の scales.x.time.tooltipFormat で書式を指定）。
                    // 本文は「体重 X.XX kg」を出す。
                    callbacks: {
                        label(item) {
                            const v = item.parsed?.y;
                            return v == null ? '' : `体重 ${Number(v).toFixed(2)} kg`;
                        },
                    },
                },
            },
            scales: {
                y: {
                    // 縦軸の範囲は Chart.js の既定（データに合わせた自動調整）に任せる。
                    // 目盛りは詰まりすぎないように maxTicksLimit で本数を抑え、
                    // 各目盛りに単位「kg」を付ける。縦軸ラベルには「体重」を表示し、
                    // セクションの eyebrow は置かない（2026-09 変更。詳細は
                    // screen-design.md S-1402「体重推移」節参照）。
                    // `rotation: 0` で横書きにする理由：Chart.js の `scales.y.title` の
                    // 既定は文字列を 90° 回転させる（欧文向け）。日本語は文字ごと
                    // 回転させない縦書きが正しい表現だが、Chart.js は文字全体を回転
                    // させる仕様のため、日本語では 1 文字ずつ横倒しになって読みにくく
                    // なる。回転を止めて素直な横書きで表示する
                    // （将来この設定を見て「回転させたほうが体裁が良い」と考えて
                    // 既定に戻さないよう、この経緯をここに残す）。
                    title: { display: true, text: '体重', rotation: 0 },
                    ticks: {
                        maxTicksLimit: 5,
                        // 小数点以下 1 桁で揃える。実測値は 2 桁まで持つが軸目盛りは
                        // 1 桁で十分（詳細な値はツールチップ側に 2 桁で出す）。
                        callback(value) {
                            return `${Number(value).toFixed(1)} kg`;
                        },
                    },
                },
                x: {
                    // 時間軸。data の各点は {x: 'YYYY-MM-DDTHH:MM:SS' (ローカル time), y: 体重}。
                    // date-fns アダプタが naive な ISO 8601 文字列を**ローカル時間として解釈**する
                    // ため、コントローラ側で UTC の 'Z' や '+HH:MM' オフセットは付けない。
                    type: 'time',
                    // 横軸の範囲をデータに合わせる（詳細な理由は renderChart 内の xMin/xMax の
                    // 計算箇所と、設計書 S-1402「横軸の範囲をデータに合わせる」参照）。
                    min: xMin,
                    max: xMax,
                    time: {
                        // ツールチップ表示用の書式（date-fns のトークン）。
                        // 「2026/9/14 08:00」の形。yyyy=4桁年、M=1〜2桁月、d=1〜2桁日、HH=2桁時、mm=2桁分。
                        // 秒は表示しない（分精度で十分）。
                        tooltipFormat: 'yyyy/M/d HH:mm',
                        // 目盛りの粒度を**日単位に固定**する（設計書 S-1402「横軸の目盛りを
                        // 日単位に固定した経緯」参照）。当初は unit 未指定で Chart.js の
                        // 自動選定に任せていたが、データ点が少ないと時単位が選ばれ
                        // 「12PM / 6PM / 12AM…」の目盛りが並び日付が読めなくなったため、
                        // 日単位に固定した。**time.unit は残しておく必要がある**：これは
                        // tick 生成の粒度を決める設定で、外すと時単位に戻り日付が読めない
                        // 問題が再発する（下の ticks.callback は「生成されたティックの
                        // 描画方法」を変えるだけで、生成の粒度には影響しない）。
                        // 点の位置は raw x の値（時刻を含む日時）で決まる Chart.js の仕様
                        // どおりのため、同じ日に朝・夕の 2 回計測した 2 点は目盛りが日単位
                        // でも時刻分だけ横に離れて表示される。
                        //
                        // **displayFormats.day は指定しない**：下の ticks.callback で
                        // 描画書式（M/d の文字列）を返しており、`callback` を指定すると `displayFormats`
                        // は Chart.js 内部で無視される仕様のため。残しておくと「使われている」
                        // と誤解を招くので削除した（設計書「横軸ラベルを 2 行にした経緯」参照）。
                        unit: 'day',
                    },
                    ticks: {
                        // 目盛りが密なときは自動で間引く。ラベルは回転させない。
                        autoSkip: true,
                        maxRotation: 0,
                        // 目盛りの文字はすべて日付（「9/15」）。左端と、年が変わってから最初の目盛りだけ 2 行目に年
                        // （measurement-chart-ticks.js。設計書 S-1402「横軸の年の出し方」）。
                        // ここ（間引く前の目盛り）では、いったんすべてに 2 行（日付と年）を付けて横軸の高さを 2 行分取り、
                        // 間引いたあとに下の afterFit で、画面に出ている目盛りの文字を付け直す。
                        // ツールチップには影響しない（`tooltipFormat: 'yyyy/M/d HH:mm'` は別系統で、「2026/9/15 08:00」のまま）。
                        // callback を指定すると time.displayFormats は使われない仕様のため、displayFormats は指定しない
                        callback(value) {
                            return measurementTickLabelReserved(value);
                        },
                    },
                    // 間引いた（autoSkip）あとの、画面に出ている目盛りで年を付けるかどうかを決める（2026-10 追加）
                    afterFit(axis) {
                        applyYearLabels(axis);
                    },
                },
            },
        },
    });
}

function renderAllMeasurementCharts() {
    const canvases = document.querySelectorAll('canvas[data-measurement-chart]');
    canvases.forEach((canvas) => {
        const raw = canvas.getAttribute('data-measurement-chart');
        if (!raw) return;
        try {
            const data = JSON.parse(raw);
            if (!data.datasets || data.datasets.length === 0) return;
            renderChart(canvas, data);
        } catch (err) {
            console.error('measurement chart render failed:', err);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderAllMeasurementCharts);
} else {
    renderAllMeasurementCharts();
}
