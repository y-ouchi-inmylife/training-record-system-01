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
 * - 点数なし（そのカテゴリの答えがすべて「未経験」。値が null）は 0 にせず null のまま渡し、その回の点を描かない。
 *   線は点数なしを飛ばして、となりの点どうしをつなぐ（spanGaps）ので、形は閉じたまま見える（2026-10-18 追加）
 * - 訓練性能の軸を真上（12 時）に置く（2026-10-18 追加。rotateToTop）。訓練性能だけは点数が高いほど良く、ほかの 6 項目は
 *   高いほど気をつけたい傾向のため、ほかと分けて見やすくする。点数の向きは変えない（参考にしている本などの向きに合わせる）。
 *   時計回りの順は変えず、グラフに渡す並びの始まりを訓練性能にずらす。トレーナー側と会員側（compact）の両方に効く
 */

/** 真上に置くカテゴリ（値。PersonalityDiagnosisQuestions::categories() のキー） */
export const TOP_CATEGORY_KEY = 'trainability';

/**
 * グラフに渡す並びを、訓練性能が先頭になるようにずらす（2026-10-18 追加）。
 *
 * Chart.js は 1 つ目の項目を真上に置き、時計回りに並べる。項目名（labels）・カテゴリの値（keys）・各回の値（series[].values）を
 * 同じだけ回して（ずらして）並べ直すので、時計回りの順は変わらず、ラベルと値の対応もずれない。
 * 目盛りの数字は 1 つ目の項目の軸に沿って描かれるので、真上の訓練性能の軸にまっすぐ上向きで並ぶ。
 * （最初は scales.r.startAngle で全体を回していたが、目盛りの数字が 1 つ目の項目〔対人〕の軸に沿って横倒しになり、
 * 見にくかったため、この形にした。2026-10-18 変更）
 *
 * 訓練性能の位置は表示名ではなくカテゴリの値（keys の trainability）で探す。見つからないとき（keys がない古いデータなど）は
 * 並べ直さない。渡されたデータは書き換えず、新しいものを返す（保存の形・データの並びは変えない）
 *
 * @param {{labels: string[], keys?: string[], series: {values: string[]}[]}} data
 * @returns {{labels: string[], keys?: string[], series: object[]}}
 */
export function rotateToTop(data) {
    const index = Array.isArray(data.keys) ? data.keys.indexOf(TOP_CATEGORY_KEY) : -1;
    if (index <= 0) {
        return data;
    }

    const rotate = (list) => [...list.slice(index), ...list.slice(0, index)];

    return {
        ...data,
        labels: rotate(data.labels),
        keys: rotate(data.keys),
        series: data.series.map((series) => ({ ...series, values: rotate(series.values) })),
    };
}

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
 * @param {{labels: string[], keys?: string[], series: {key: 'current'|'previous'|'initial', date: string, values: (string|null)[]}[]}} data
 *   canvas の data-personality-radar に入れた JSON（labels はカテゴリの表示名、keys はカテゴリの値〔labels と同じ並び〕、
 *   series は重ねる回。date は回答日 Y/m/d、values は表示の値）
 * @param {{compact?: boolean, card?: boolean}} [options]
 *   compact：会員側のマイページ（S-1402）の小さなグラフ用（2026-10 追加）。枠の縦横比に合わせず（maintainAspectRatio: false）、
 *   枠の高さいっぱいに描き、項目名と目盛りの文字を小さくし、周りの余白をなくす。指定しなければ（トレーナー側 S-0309）
 *   今までと同じ設定を返す（トレーナー側の見た目を変えないため）
 *   card：マイページの記録の一覧のアドバイスのカードの、さらに小さなグラフ用（2026-10-20 追加。compact と一緒に指定する）。
 *   compact の設定のうち、項目名と目盛りの文字の大きさだけを小さくする（色・軸・目盛りの作りは S-1418 と同じ）
 * @returns {object} Chart.js の設定（type・data・options）
 */
export function buildRadarConfig(data, { compact = false, card = false } = {}) {
    // 訓練性能が先頭（真上）になるように並べ直す（上の rotateToTop）
    const rotated = rotateToTop(data);
    const datasets = rotated.series.map((series) => {
        const style = SERIES_STYLES[series.key];

        return {
            label: seriesLabel(series),
            // 点数なしは null のまま（0 の位置に描かない）
            data: series.values.map((value) => (value === null ? null : Number(value))),
            // 点数なしの項目を飛ばして、となりの点どうしをつなぐ（2026-10-18 追加）
            spanGaps: true,
            borderColor: style.borderColor,
            backgroundColor: style.backgroundColor,
            pointBackgroundColor: style.borderColor,
            borderWidth: 2,
            borderDash: style.borderDash,
            fill: style.fill,
        };
    });

    const config = {
        type: 'radar',
        data: {
            labels: rotated.labels,
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

    if (compact) {
        // 正方形の枠だと、項目名が左右に張り出す分だけグラフが小さくなり、上下に空きが残る。
        // 枠（横長）の高さいっぱいに描き、文字を小さくして項目名が欠けないようにする
        config.options.maintainAspectRatio = false;
        config.options.layout = { padding: 0 };
        config.options.scales.r.pointLabels = { font: { size: COMPACT_POINT_LABEL_SIZE }, padding: 4 };
        config.options.scales.r.ticks = { stepSize: 1, font: { size: COMPACT_TICK_SIZE }, backdropPadding: 1 };
        if (card) {
            // 一辺 180px ほどの枠でも項目名が欠けないよう、文字の大きさと項目名の間だけを詰める（2026-10-20 追加）
            config.options.scales.r.pointLabels = { font: { size: CARD_POINT_LABEL_SIZE }, padding: 2 };
            config.options.scales.r.ticks = { stepSize: 1, font: { size: CARD_TICK_SIZE }, backdropPadding: 1 };
        }
    }

    return config;
}

/** 会員側の小さなグラフ（compact）の項目名と目盛りの文字の大きさ（px）。トレーナー側は Chart.js の既定（12px） */
export const COMPACT_POINT_LABEL_SIZE = 11;
export const COMPACT_TICK_SIZE = 9;

/** 記録の一覧のアドバイスのカードのグラフ（card）の項目名と目盛りの文字の大きさ（px。2026-10-20 追加） */
export const CARD_POINT_LABEL_SIZE = 10;
export const CARD_TICK_SIZE = 8;

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
 * 凡例の線の見本（幅 32・高さ 14 の小さな SVG の文字列）。グラフの線と同じ色・線の形（実線／破線／点線）の**線だけ**にする。
 * グラフの今回の薄い塗りは、見本では表さない（2026-10 変更：当初は塗りのある回〔今回〕の線の下に、塗りの色の四角を描いていたが、
 * 小さな四角だと青に見えず灰色の背景に見えたため。今回は太い紺の実線で見分けられる）。
 * 線は今までどおり縦の真ん中（y=7）に引くので、文字との縦のそろいは変わらない。
 * 見た目の印なので aria-hidden を付ける（凡例の文は別に読み上げる）。
 * 値はすべて SERIES_STYLES の決まった値で、お客様の入力を含まないので、文字列で組み立ててよい
 *
 * @param {{borderColor: string, backgroundColor: string, borderDash: number[], fill: boolean}} item
 */
export function legendSwatchSvg(item) {
    const dash = item.borderDash.length > 0 ? ` stroke-dasharray="${item.borderDash.join(' ')}"` : '';

    return '<svg width="32" height="14" viewBox="0 0 32 14" aria-hidden="true" focusable="false">'
        + `<line x1="1" y1="7" x2="31" y2="7" stroke="${item.borderColor}" stroke-width="2"${dash} /></svg>`;
}
