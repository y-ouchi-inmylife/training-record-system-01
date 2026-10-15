/**
 * 性格診断のレーダーチャートの設定を組み立てる（S-0309 トレーニー詳細の性格診断の欄。2026-10 追加）。
 *
 * Chart.js を読み込まない純粋な関数だけを置く（vitest で確かめるため。tests/js/personality-radar-config.test.js）。
 * 描くのは personality-radar.js。設計書 S-0309「性格診断の欄」の「レーダーチャート」。
 *
 * - グラフは欄に 1 つ。今回（いちばん新しい回）・前回（その 1 つ前）・初回（いちばん古い回）を重ねる。
 *   どれを渡すか（結果が 1 つなら今回だけ、2 つなら今回・前回、3 つ以上なら 3 つとも）は Blade で決める
 * - 7 カテゴリ、目盛りは 0〜4 で固定（1 刻み）
 * - 見分け方は色だけに頼らない：今回は紺の実線と薄い塗り、前回は灰色の破線、初回は前回より薄い灰色の点線（どちらも塗りなし）
 * - 凡例に回答日を添える（「今回（2026/06/01）」など）。凡例は Chart.js では描かず、グラフの下に HTML で出す
 *   （Chart.js の凡例はグラフの枠の中に描かれ、3 つの項目が 2 行に折り返すため。2026-10 変更）。
 *   凡例の項目と線の見本も、グラフと同じ SERIES_STYLES から作る（legendItems・legendSwatchSvg）
 * - 値は、下の表と同じ表示の値（小数第 1 位の文字列）を数にして使う（表とグラフで数字が食い違わないように）
 */

/** 回の種類ごとの名前と見た目。並びは凡例の順（今回 → 前回 → 初回） */
export const SERIES_STYLES = {
    current: {
        name: '今回',
        borderColor: '#0a4fa8', // トレーナー側の紺（app.scss の .bg-trainer-nav と同じ）
        backgroundColor: 'rgba(10, 79, 168, 0.15)',
        borderDash: [],
        fill: true,
    },
    previous: {
        name: '前回',
        borderColor: '#6c757d', // 灰色
        backgroundColor: 'transparent',
        borderDash: [6, 4], // 破線
        fill: false,
    },
    initial: {
        name: '初回',
        borderColor: '#adb5bd', // 前回より薄い灰色
        backgroundColor: 'transparent',
        borderDash: [2, 3], // 細かい点の点線
        fill: false,
    },
};

/**
 * @param {{labels: string[], series: {key: 'current'|'previous'|'initial', date: string, values: string[]}[]}} data
 *   canvas の data-personality-radar に入れた JSON（labels はカテゴリの表示名、series は重ねる回。date は回答日 Y/m/d、
 *   values は表示の値）
 * @returns {object} Chart.js の設定（type・data・options）
 */
export function buildRadarConfig(data) {
    const datasets = data.series.map((series) => {
        const style = SERIES_STYLES[series.key];

        return {
            label: seriesLabel(series),
            data: series.values.map(Number),
            borderColor: style.borderColor,
            backgroundColor: style.backgroundColor,
            pointBackgroundColor: style.borderColor,
            borderWidth: 2,
            borderDash: style.borderDash,
            fill: style.fill,
        };
    });

    return {
        type: 'radar',
        data: {
            labels: data.labels,
            datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: false,
            scales: {
                r: {
                    min: 0,
                    max: 4,
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                },
            },
            plugins: {
                // 凡例は HTML で出す（legendItems）。Chart.js の凡例は出さない
                legend: { display: false },
            },
        },
    };
}

/**
 * 凡例の文（「今回（2026/06/01）」など）
 *
 * @param {{key: string, date: string}} series
 */
export function seriesLabel(series) {
    return `${SERIES_STYLES[series.key].name}（${series.date}）`;
}

/**
 * HTML の凡例の項目（並びは渡された順。今回 → 前回 → 初回）
 *
 * @param {{series: {key: string, date: string}[]}} data
 * @returns {{label: string, borderColor: string, backgroundColor: string, borderDash: number[], fill: boolean}[]}
 */
export function legendItems(data) {
    return data.series.map((series) => {
        const style = SERIES_STYLES[series.key];

        return {
            label: seriesLabel(series),
            borderColor: style.borderColor,
            backgroundColor: style.backgroundColor,
            borderDash: style.borderDash,
            fill: style.fill,
        };
    });
}

/**
 * 凡例の線の見本（幅 32・高さ 14 の小さな SVG の文字列）。グラフの線と同じ色・線の形（実線／破線／点線）にし、
 * 塗りのある回（今回）は線の下を薄く塗る。見た目の印なので aria-hidden を付ける（凡例の文は別に読み上げる）。
 * 値はすべて SERIES_STYLES の決まった値で、お客様の入力を含まないので、文字列で組み立ててよい
 *
 * @param {{borderColor: string, backgroundColor: string, borderDash: number[], fill: boolean}} item
 */
export function legendSwatchSvg(item) {
    const fill = item.fill ? `<rect x="1" y="1" width="30" height="12" fill="${item.backgroundColor}" />` : '';
    const dash = item.borderDash.length > 0 ? ` stroke-dasharray="${item.borderDash.join(' ')}"` : '';

    return `<svg width="32" height="14" viewBox="0 0 32 14" aria-hidden="true" focusable="false">${fill}`
        + `<line x1="1" y1="7" x2="31" y2="7" stroke="${item.borderColor}" stroke-width="2"${dash} /></svg>`;
}
