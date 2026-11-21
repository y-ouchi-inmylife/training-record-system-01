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

/** 預かる数と上限の文（「2/3」。0 も「0/3」。2026-11 変更：その前は 0 なら空にしていた） */
export function countText(count, capacity) {
    return `${count}/${capacity}`;
}

/**
 * カレンダーのマスに出す「n/上限」（2026-11 変更。設計書 S-0305-M05「予約状況」）。
 * 月の預かる数を読み込んだあとは、予約がない日も「0/上限」を出す（月のすべての日）。
 * 読み込む前（読み込み中・失敗）は出さない（空）
 *
 * @param {{count: number, capacity: (number|null), loaded: boolean}} cell
 * @returns {string}
 */
export function cellCountText({ count, capacity, loaded }) {
    if (!loaded || capacity === null) {
        return '';
    }

    return countText(count, capacity);
}

/**
 * 今日より前の日（過去の日）か（2026-11 追加）。今日はサーバーの今日（月の JSON の today）。
 * 今日をまだ知らない間（null）は過去の日として扱わない
 *
 * @param {string} ymd 「YYYY-MM-DD」
 * @param {(string|null)} today 「YYYY-MM-DD」
 */
export function isPastDay(ymd, today) {
    return today !== null && today !== undefined && ymd < today;
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
 * カレンダーのマス（ボタン）・日付の数字・「n/上限」の文字に付けるクラス（2026-11 追加。設計書 S-0305-M05「予約状況」）。
 *
 * - 空きあり・満・超過（capacityState）は「n/上限」の文字の色だけで示し、マスの背景の色は変えない
 *   （満＝緑 usage-schedule-count-full、超過＝赤の太字 usage-schedule-count-over。色は app.scss で Bootstrap の $green-700・$red-600。
 *   小さな文字でも背景の上で読め〔コントラスト比 4.5:1 以上〕、緑・赤と見分けられる中間の濃さ。
 *   2026-11 変更：その前は満・超過のマスの背景も緑・赤〔bg-success-subtle・bg-danger-subtle〕にしていた）
 * - 今日はマスの背景を薄い青（bg-primary-subtle）。日付の数字は通常のまま
 *   （2026-11 変更：その前は日付の数字を青の太字〔text-primary・fw-bold〕にしていた）
 * - 選んでいる日（＝利用日）は太い青の枠（usage-schedule-selected。app.scss で内側に描くので、マスの大きさは変わらない）
 * - 今日を選んでいるときは、薄い青の背景と枠の両方。上限をまだ読んでいない間（state が null）は空きありと同じ
 * - 今日より前の日（past。isPastDay）は、マスの背景を灰色（bg-secondary-subtle）、日付の数字を少し薄い色（text-body-secondary）。
 *   「n/上限」の色と太字は変えない。過去の日を選んでいるときは、灰色の背景と枠の両方（2026-11 追加）
 *
 * @param {{state: ('available'|'full'|'over'|null), selected: boolean, today: boolean, past?: boolean}} cell
 * @returns {{button: string[], day: string[], count: string[]}}
 */
export function dayCellClasses({ state, selected, today, past = false }) {
    const button = ['btn', 'btn-sm', 'w-100', 'px-0', 'py-1', 'lh-sm', 'usage-schedule-day'];
    const day = ['d-block'];
    if (today) {
        button.push('usage-schedule-today', 'bg-primary-subtle');
    } else if (past) {
        button.push('usage-schedule-past', 'bg-secondary-subtle');
        day.push('text-body-secondary');
    } else {
        button.push('btn-light');
    }
    if (selected) {
        button.push('usage-schedule-selected');
    }

    // 「n/上限」はどれも太字（usage-schedule-count。app.scss）。色は満・超過だけ（countColorClasses）
    const count = ['d-block', 'small', 'usage-schedule-count', ...countColorClasses(state)];

    return { button, day, count };
}

/**
 * 「n/上限」の色のクラス（2026-11 追加。カレンダーのマスと、その日の予約の見出しで同じものを使う。設計書 S-0305-M05「予約状況」）。
 * 満＝usage-schedule-count-full（緑）、超過＝usage-schedule-count-over（赤）、空きあり・上限をまだ読んでいない間（null）＝なし。
 * 色は app.scss に 1 か所だけ書く
 *
 * @param {('available'|'full'|'over'|null)} state
 * @returns {string[]}
 */
export function countColorClasses(state) {
    if (state === CAPACITY_OVER) {
        return ['usage-schedule-count-over'];
    }
    if (state === CAPACITY_FULL) {
        return ['usage-schedule-count-full'];
    }

    return [];
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
    const parts = dayHeadingParts(ymd, count, capacity);

    return parts.label + parts.count;
}

/**
 * その日の予約の見出しを、「M/D(曜) の予約　」と「n/上限」に分けたもの（2026-11 追加）。
 * 「n/上限」の部分だけに、カレンダーと同じ色のクラス（countColorClasses。判定は capacityState）を付ける。見出しはもともと太字
 *
 * @returns {{label: string, count: string, countClasses: string[]}}
 */
export function dayHeadingParts(ymd, count, capacity) {
    return {
        label: `${dayLabel(ymd)} の予約　`,
        count: `${count}/${capacity}`,
        countClasses: countColorClasses(capacityState(count, capacity)),
    };
}

/**
 * 利用の保存の前の確認（GET /api/usage-schedule/check）に送るクエリ（2026-11 追加。設計書 S-0305-M05「保存時の上限の確認」）。
 * 編集のときは、編集中の利用自身を数えないよう exclude_usage_id を付ける
 *
 * @param {{usedOn: string, startTime: string, endTime: string, excludeUsageId?: (string|number|null)}} input
 * @returns {string}
 */
export function capacityCheckQuery({ usedOn, startTime, endTime, excludeUsageId = null }) {
    const params = new URLSearchParams({ used_on: usedOn, start_time: startTime, end_time: endTime });
    if (excludeUsageId !== null && excludeUsageId !== '') {
        params.set('exclude_usage_id', String(excludeUsageId));
    }

    return params.toString();
}

/**
 * 上限を超えるときの確認ダイアログ（confirm()）の文（2026-11 追加。設計書 S-0305-M05「保存時の上限の確認」）。
 * 中身は問い合わせの答え（date_label・peak_start・peak_end・count・capacity。判定はサーバー）。画面では預かる数を計算しない。
 * 文の頭の「この利用を入れると」は、カレンダーの「n/上限」（入れる前の数）と count が食い違って見えないように付ける
 *
 * @param {{date_label: string, peak_start: string, peak_end: string, count: number, capacity: number}} check
 * @param {'登録'|'更新'} button 押したボタンの名前
 * @returns {string}
 */
export function capacityConfirmMessage(check, button) {
    return `この利用を入れると、${check.date_label} ${check.peak_start}〜${check.peak_end} に同時に預かる数が ${check.count} になり、`
        + `上限（${check.capacity}）を超えます。\n\n${button}しますか？`;
}

