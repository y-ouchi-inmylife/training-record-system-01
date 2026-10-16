/**
 * 体重推移グラフ（measurement-chart.js）の横軸の目盛りの文字（2026-10 追加）。
 * Chart.js を読み込まない小さなモジュールに分け、vitest で確かめる。
 *
 * 目盛りは日単位（time.unit: 'day'）で、すべて日付（`M/d`。「9/15」）。
 * **左端の目盛りと、年が変わってから最初の目盛りだけ、2 行目に年**（`yyyy`）を添える（['9/21', '2026'] の配列。
 * Chart.js は配列を複数行で描く）。年をまたがない期間なら、年は左端の 1 か所だけ。
 *
 * 年を付けるかどうかは、**目盛りを自動で間引いた（autoSkip）あとの、画面に出ている目盛り**で決める。
 * ticks.callback は間引く前の目盛りに対して呼ばれるので、そこで決めると、年が変わった日（1/1 など）の目盛りが
 * 間引かれたときに年が出なくなったり、見えない目盛りに年が付いたりする。そのため：
 * - ticks.callback では、いったんすべての目盛りに 2 行（measurementTickLabelReserved）を付け、横軸の高さを 2 行分取る
 * - 間引いたあとに呼ばれる軸の afterFit（applyYearLabels）で、画面に出ている目盛りの文字を付け直す
 *
 * 2026-10 の経緯：当初は最初の目盛りだけ 2 行目に時刻（「0:00」）を出していた → すべて日付だけにした →
 * 年をまたぐ期間で年が分からないため、左端と年が変わった目盛りに年を出すようにした
 */
import { format } from 'date-fns';

/** 日付だけ（1 行）。「9/15」 */
export function measurementTickLabel(value) {
    return format(new Date(value), 'M/d');
}

/** 日付と年（2 行）。['9/15', '2026'] */
export function measurementTickLabelWithYear(value) {
    const d = new Date(value);
    return [format(d, 'M/d'), format(d, 'yyyy')];
}

/**
 * ticks.callback 用：間引く前の目盛りすべてに 2 行を付けておく（横軸の高さを 2 行分取るため。
 * 文字は afterFit で付け直す。年の行は日付の行より短いので、間引きの多さは日付だけのときと変わらない）
 */
export function measurementTickLabelReserved(value) {
    return measurementTickLabelWithYear(value);
}

/**
 * 画面に出ている目盛り（左から順の値。ミリ秒）の文字を決める。
 * いちばん左と、ひとつ左の目盛りと年が違う目盛りは ['M/d', 'yyyy']、ほかは 'M/d'
 *
 * @param {number[]} values
 * @returns {(string|string[])[]}
 */
export function measurementTickLabels(values) {
    return values.map((value, i) => {
        const year = new Date(value).getFullYear();
        const showYear = i === 0 || new Date(values[i - 1]).getFullYear() !== year;

        return showYear ? measurementTickLabelWithYear(value) : measurementTickLabel(value);
    });
}

/**
 * 横軸の afterFit 用：間引いたあとの目盛り（axis.ticks）の文字を付け直す。
 * Chart.js は update の中で、間引き（autoSkip）→ 大きさの計算（fit）→ afterFit の順に呼ぶので、
 * ここで見える axis.ticks は画面に出る目盛りだけ。文字は描くとき（draw）に axis.ticks の label から作られる
 *
 * @param {{ticks: {value: number, label: string|string[]}[]}} axis
 */
export function applyYearLabels(axis) {
    const labels = measurementTickLabels(axis.ticks.map((tick) => tick.value));
    axis.ticks.forEach((tick, i) => {
        tick.label = labels[i];
    });
}
