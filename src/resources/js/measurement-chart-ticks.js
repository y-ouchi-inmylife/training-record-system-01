/**
 * 体重推移グラフ（measurement-chart.js）の横軸の目盛りの文字（2026-10 追加）。
 * Chart.js を読み込まない小さなモジュールに分け、vitest で確かめる。
 *
 * 目盛りは日単位（time.unit: 'day'）で、**最初の目盛りも含めてすべて日付だけ**（`M/d`。「9/15」）。
 * 2026-10 変更：当初は目盛りが 1 日の始まり（0:00）を指すことを示すため、最初の目盛り（index === 0）だけ
 * 2 行目に時刻（「0:00」）を出していた（['9/15', '0:00'] の配列を返していた）。ユーザーレビューで、すべての目盛りを
 * 日付だけにそろえた。すべて 1 行になったので、配列ではなく文字列を返す
 */
import { format } from 'date-fns';

/**
 * @param {number} value 目盛りの位置（タイムスタンプ。ミリ秒。Chart.js の ticks.callback の第 1 引数）
 * @returns {string} 「9/15」の形
 */
export function measurementTickLabel(value) {
    return format(new Date(value), 'M/d');
}
