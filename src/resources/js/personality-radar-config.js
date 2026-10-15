/**
 * 性格診断のレーダーチャートの設定を組み立てる（S-0309 トレーニー詳細の性格診断の欄。2026-10 追加）。
 *
 * Chart.js を読み込まない純粋な関数だけを置く（vitest で確かめるため。tests/js/personality-radar-config.test.js）。
 * 描くのは personality-radar.js。設計書 S-0309「性格診断の欄」の「レーダーチャート」。
 *
 * - 7 カテゴリ、目盛りは 0〜4 で固定（1 刻み）
 * - 「今回」は濃い線と薄い塗り、「前回」は灰色の点線（塗りなし）。前回がない回は「今回」だけ
 * - 凡例に「今回」「前回」を出す
 * - 値は、隣の点数の表と同じ表示の値（小数第 1 位の文字列）を数にして使う（表とグラフで数字が食い違わないように）
 */

/** 今回の線の色（トレーナー側の紺。app.scss の .bg-trainer-nav と同じ） */
export const CURRENT_COLOR = '#0a4fa8';

/** 今回の塗り（同じ紺を薄く） */
export const CURRENT_FILL = 'rgba(10, 79, 168, 0.15)';

/** 前回の線の色（灰色） */
export const PREVIOUS_COLOR = '#6c757d';

/**
 * @param {{labels: string[], current: string[], previous: (string[]|null)}} data
 *   canvas の data-personality-radar に入れた JSON（labels はカテゴリの表示名、current・previous は表示の値）
 * @returns {object} Chart.js の設定（type・data・options）
 */
export function buildRadarConfig(data) {
    const datasets = [
        {
            label: '今回',
            data: data.current.map(Number),
            borderColor: CURRENT_COLOR,
            backgroundColor: CURRENT_FILL,
            pointBackgroundColor: CURRENT_COLOR,
            borderWidth: 2,
            fill: true,
        },
    ];

    if (Array.isArray(data.previous)) {
        datasets.push({
            label: '前回',
            data: data.previous.map(Number),
            borderColor: PREVIOUS_COLOR,
            backgroundColor: 'transparent',
            pointBackgroundColor: PREVIOUS_COLOR,
            borderWidth: 2,
            borderDash: [6, 4],
            fill: false,
        });
    }

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
                legend: { display: true, position: 'bottom' },
            },
        },
    };
}
