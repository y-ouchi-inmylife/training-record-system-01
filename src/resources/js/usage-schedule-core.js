/**
 * 利用の予約状況（カレンダーとその日の予約）の、DOM に触れない計算（2026-11 追加。設計書 screen-design.md S-0305-M05「予約状況」）。
 *
 * 画面の部品（usage-schedule.js の UsageSchedule）はここの関数で表示する月・選んだ日を決め、マスを組み立てる。
 * 利用モーダル（S-0305-M05）のほか、後でカレンダー画面（S-1602。まだ作らない）でも使い回す。
 * ここは DOM に触れない純粋な関数だけを置く（vitest で確かめる。copy-message-core.js と同じ分け方）。
 *
 * 状態（state）は { month: 'YYYY-MM', selected: 'YYYY-MM-DD' }。
 * - month：カレンダーに出している月
 * - selected：選んでいる日（＝利用日）。月を移しても選んだ日は変えない（出している月の外なら、カレンダーには印が出ない）
 */

/** 曜日の見出し（日曜始まり） */
export const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

const pad2 = (n) => String(n).padStart(2, '0');

/**
 * 「YYYY-MM-DD」を読む。形が違う・存在しない日付なら null（入力の途中〔「2026-11-2」など〕では動かさないため）
 *
 * @param {string|null|undefined} value
 * @returns {{year: number, month: number, day: number}|null}
 */
export function parseYmd(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value ?? '').trim());
    if (!m) {
        return null;
    }
    const year = parseInt(m[1], 10);
    const month = parseInt(m[2], 10);
    const day = parseInt(m[3], 10);
    if (month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) {
        return null;
    }

    return { year, month, day };
}

/** その月の日数（うるう年も扱う。UTC で数えて、時間帯の影響を受けないようにする） */
export function daysInMonth(year, month) {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** 「YYYY-MM-DD」の月（「YYYY-MM」） */
export function ymOf(ymd) {
    return String(ymd).slice(0, 7);
}

/**
 * 月を delta だけ移す（前月は -1、翌月は +1）
 *
 * @param {string} ym 「YYYY-MM」
 * @param {number} delta
 * @returns {string} 「YYYY-MM」
 */
export function shiftMonth(ym, delta) {
    const [year, month] = ym.split('-').map((v) => parseInt(v, 10));
    const index = year * 12 + (month - 1) + delta;

    return `${Math.floor(index / 12)}-${pad2((index % 12) + 1)}`;
}

/** 月の見出し（「2026年11月」） */
export function monthHeading(ym) {
    const [year, month] = ym.split('-').map((v) => parseInt(v, 10));

    return `${year}年${month}月`;
}

/**
 * 月のマス。週ごとに 7 つ（日曜始まり）。前月・翌月の日は null（空のマス）
 *
 * @param {string} ym 「YYYY-MM」
 * @returns {Array<Array<string|null>>} 日付は「YYYY-MM-DD」
 */
export function monthWeeks(ym) {
    const [year, month] = ym.split('-').map((v) => parseInt(v, 10));
    const firstWeekday = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
    const cells = Array(firstWeekday).fill(null);
    for (let day = 1; day <= daysInMonth(year, month); day++) {
        cells.push(`${ym}-${pad2(day)}`);
    }
    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    const weeks = [];
    for (let i = 0; i < cells.length; i += 7) {
        weeks.push(cells.slice(i, i + 7));
    }

    return weeks;
}

/** 日付の短い見出し（「11/26(木)」。サーバーの date_label と同じ形） */
export function dayLabel(ymd) {
    const d = parseYmd(ymd);
    if (!d) {
        return '';
    }
    const weekday = new Date(Date.UTC(d.year, d.month - 1, d.day)).getUTCDay();

    return `${d.month}/${d.day}(${WEEKDAYS[weekday]})`;
}

/** カレンダーのマスに出す預かる数（「2/3」）。預かる数が 0 なら空 */
export function countText(count, capacity) {
    return count > 0 ? `${count}/${capacity}` : '';
}

/** 預かる数の状態：空きあり（上限未満。0 も含む）・満（上限ちょうど）・超過（上限を超える）。2026-11 追加 */
export const CAPACITY_AVAILABLE = 'available';
export const CAPACITY_FULL = 'full';
export const CAPACITY_OVER = 'over';

/**
 * その日の預かる数と上限から、空きあり・満・超過を決める（2026-11 変更：その前は上限以上をひとまとめに「赤」としていた）。
 * 画面の部品はこの結果でマスの色を決める（空きあり＝色なし、満＝緑、超過＝赤。設計書 S-0305-M05「予約状況」）。
 * 預かる数が 0 の日は空きあり
 *
 * @param {number} count その日の預かる数
 * @param {number} capacity 同時に預かれる数（上限）
 * @returns {'available'|'full'|'over'}
 */
export function capacityState(count, capacity) {
    if (count > capacity) {
        return CAPACITY_OVER;
    }
    if (count > 0 && count === capacity) {
        return CAPACITY_FULL;
    }

    return CAPACITY_AVAILABLE;
}

/**
 * カレンダーのマス（ボタン）と、その中の日付の数字に付けるクラス（2026-11 追加。設計書 S-0305-M05「予約状況」）。
 *
 * - 背景と文字の色は、空きあり・満・超過の 3 段階（capacityState）。選んでいる日・今日でも変えない
 *   （空きあり＝btn-light、満＝緑、超過＝赤。上限をまだ読んでいない間〔state が null〕は空きありと同じ）
 * - 選んでいる日（＝利用日）は太い青の枠（usage-schedule-selected。app.scss で内側に描くので、マスの大きさは変わらない）。
 *   2026-11 変更：その前は青の塗りつぶし（btn-primary）で、満・超過の色より優先していた
 * - 今日は日付の数字を青の太字（text-primary・fw-bold）。枠は付けない。2026-11 変更：その前は青の枠（border-primary）
 * - 今日を選んでいるときは、枠と青の太字の両方
 *
 * @param {{state: ('available'|'full'|'over'|null), selected: boolean, today: boolean}} cell
 * @returns {{button: string[], day: string[]}}
 */
export function dayCellClasses({ state, selected, today }) {
    const button = ['btn', 'btn-sm', 'w-100', 'px-0', 'py-1', 'lh-sm', 'usage-schedule-day'];
    if (state === CAPACITY_OVER) {
        // 超過：赤（§2-5 の bg-danger〔異常〕の系統）
        button.push('bg-danger-subtle', 'text-danger', 'border-danger-subtle');
    } else if (state === CAPACITY_FULL) {
        // 満（上限ちょうど）：緑（§2-5 の bg-success〔正常・完了〕の系統）
        button.push('bg-success-subtle', 'text-success-emphasis', 'border-success-subtle');
    } else {
        button.push('btn-light');
    }
    if (selected) {
        button.push('usage-schedule-selected');
    }

    const day = ['d-block'];
    if (today) {
        button.push('usage-schedule-today');
        day.push('text-primary', 'fw-bold');
    }

    return { button, day };
}

/**
 * 開いたときの状態。日付が正しくなければ fallback（今日）にする
 *
 * @param {string} ymd 利用日の欄の値
 * @param {string} fallback 今日（「YYYY-MM-DD」）
 */
export function initialState(ymd, fallback) {
    const date = parseYmd(ymd) ? ymd : fallback;

    return { month: ymOf(date), selected: date };
}

/** 日付を選ぶ（その日の月に移る） */
export function selectDate(state, ymd) {
    if (!parseYmd(ymd)) {
        return state;
    }

    return { month: ymOf(ymd), selected: ymd };
}

/**
 * 利用日の欄に入力したとき。正しい日付ならその月に移ってその日を選び、正しくない間は今のまま
 */
export function applyInput(state, value) {
    return selectDate(state, String(value ?? '').trim());
}

/** 月を移す（選んだ日は変えない） */
export function moveMonth(state, delta) {
    return { ...state, month: shiftMonth(state.month, delta) };
}

/**
 * 予約の 1 行の文（「8:00〜19:00　アムロ（佐藤 一郎）」）。中身はサーバーの JSON の time_range・label
 */
export function usageLine(usage) {
    return `${usage.time_range}　${usage.label}`;
}

/** その日の予約の見出し（「11/26(木) の予約　2/3」。2026-11 変更：単位を付けない） */
export function dayHeading(ymd, count, capacity) {
    return `${dayLabel(ymd)} の予約　${count}/${capacity}`;
}
