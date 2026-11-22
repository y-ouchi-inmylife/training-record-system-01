/**
 * 利用の予約状況（カレンダーとその日の予約）の、DOM に触れない計算（2026-11 追加。設計書 screen-design.md S-0305-M05「予約状況」）。
 *
 * 画面の部品（usage-schedule.js の UsageSchedule）はここの関数で表示する月・選んだ日を決め、マスを組み立てる。
 * 利用モーダル（S-0305-M05）のほか、カレンダー画面（S-1602。2026-11 追加）でも使う。
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

/**
 * 同時に預かる数の文（数字だけ。「2」。0 も「0」）。
 * 2026-11 変更：その前は「n/上限」（「2/3」）だったが、「1 日に上限まで」と読めてしまうため、数字だけにした
 */
export function countText(count) {
    return `${count}`;
}

/**
 * カレンダーのマスに出す同時に預かる数（数字だけ。2026-11 変更。設計書 S-0305-M05「予約状況」）。
 * 月の預かる数を読み込んだあとは、予約がない日も「0」を出す（月のすべての日）。
 * 読み込む前（読み込み中・失敗）は出さない（空）
 *
 * @param {{count: number, capacity: (number|null), loaded: boolean}} cell
 * @returns {string}
 */
export function cellCountText({ count, capacity, loaded }) {
    if (!loaded || capacity === null) {
        return '';
    }

    return countText(count);
}

/**
 * カレンダーのマスの読み上げの文に入れる、予約の件数と同時に預かる数（「予約 4件 同時に預かる数 3」）。
 * 数字だけだと何の数か分からないため、言葉を添える。0 件の日も「予約 0件」を入れる。読み込む前は空。
 * 2026-11 変更：件数を足した（その前は「同時に預かる数 n」。さらにその前は「n/上限」）
 *
 * @param {{count: number, usageCount?: number, capacity: (number|null), loaded: boolean}} cell
 * @returns {string}
 */
export function cellCountAriaText(cell) {
    const text = cellCountText(cell);

    return text === '' ? '' : `予約 ${cell.usageCount ?? 0}件 同時に預かる数 ${text}`;
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
 * カレンダーのマス（ボタン）・日付の数字・同時に預かる数の数字の文字に付けるクラス（2026-11 追加。設計書 S-0305-M05「予約状況」）。
 *
 * - 空きあり・満・超過（capacityState）は同時に預かる数の数字の見た目だけで示し、マスの背景の色は変えない。
 *   満・超過は同時に預かる数の数字を小さなバッジ（countBadge。countBadgeClasses）にし、空きありは通常の色の太字のまま
 *   （2026-11 変更：その前は満・超過を文字の色〔$green-600・$red-600〕だけで示していた。さらにその前はマスの背景を緑・赤にしていた）
 * - 今日はマスの背景を薄い青（bg-primary-subtle）。日付の数字は通常のまま
 *   （2026-11 変更：その前は日付の数字を青の太字〔text-primary・fw-bold〕にしていた）
 * - 選んでいる日（＝利用日）は太い青の枠（usage-schedule-selected。app.scss で内側に描くので、マスの大きさは変わらない）
 * - 今日を選んでいるときは、薄い青の背景と枠の両方。上限をまだ読んでいない間（state が null）は空きありと同じ
 * - 今日より前の日（past。isPastDay）は、マスの背景を灰色（bg-secondary-subtle）、日付の数字を少し薄い色（text-body-secondary）。
 *   同時に預かる数の数字の見た目（バッジ・太字）は変えない。過去の日を選んでいるときは、灰色の背景と枠の両方（2026-11 追加）
 *
 * @param {{state: ('available'|'full'|'over'|null), selected: boolean, today: boolean, past?: boolean}} cell
 * @returns {{button: string[], day: string[], count: string[], countBadge: string[], usageCount: string[]}}
 *   usageCount は下の行で同時に預かる数の左に並べる予約の件数の要素（2026-11 追加）
 *   count は同時に預かる数の数字の入れ物、countBadge はその中のバッジ（空きありは空。バッジを作らない）
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

    // 同時に預かる数の数字はどれも太字（usage-schedule-count。app.scss）。満・超過は中をバッジにする（countBadgeClasses）
    const count = ['d-block', 'small', 'usage-schedule-count'];

    // 予約の件数（2026-11 追加）：下の行で同時に預かる数の左に並べる。控えめな灰色の太字（2026-11 変更：その前は fw-normal で
    // 太字にしなかった）。大きさは同時に預かる数と同じ。あいだは me-2 で少し空ける。
    // 2026-11 変更：その前は右上に小さく重ねていた（app.scss の位置の指定は消した）
    const usageCount = ['usage-schedule-usage-count', 'text-body-secondary', 'fw-bold', 'me-2'];

    return { button, day, count, countBadge: countBadgeClasses(state), usageCount };
}

/**
 * 同時に預かる数の数字のバッジのクラス（2026-11 変更。カレンダーのマスと、その日の予約の見出しで同じものを使う。設計書 S-0305-M05「予約状況」）。
 * 空きあり＝灰色のバッジ（badge bg-secondary。2026-11 追加）、満＝緑のバッジ（badge bg-success）、超過＝赤のバッジ（badge bg-danger）。
 * どれも白い文字（§2-5 のバッジの配色）。上限をまだ読んでいない間（null）＝なし（バッジにしない）。
 * 予約が 0 件の日は、カレンダーでも見出しでもバッジを使わない（cellBottomRow・dayHeadingParts が決める）。
 * 2026-11 変更：その前は空きありをバッジにしなかった。
 * usage-schedule-count-badge は、マスの大きさが変わらないようにバッジの文字の大きさと余白を合わせるクラス（app.scss）。
 * 2026-11 変更：その前は文字の色のクラス（usage-schedule-count-full・usage-schedule-count-over）を返していた
 *
 * @param {('available'|'full'|'over'|null)} state
 * @returns {string[]}
 */
export function countBadgeClasses(state) {
    if (state === CAPACITY_OVER) {
        return ['badge', 'bg-danger', 'usage-schedule-count-badge'];
    }
    if (state === CAPACITY_FULL) {
        return ['badge', 'bg-success', 'usage-schedule-count-badge'];
    }
    if (state === CAPACITY_AVAILABLE) {
        return ['badge', 'bg-secondary', 'usage-schedule-count-badge'];
    }

    return [];
}

/**
 * カレンダーのマスの下の行に出すもの（2026-11 変更。設計書 S-0305-M05「予約状況」）。2 通り：
 * - 予約が 1 件以上の日（件数と同時に預かる数が同じでも違っても）：左に灰色の太字の件数（usageCount）、
 *   右に同時に預かる数のバッジ（badge が true）
 * - 予約が 0 件の日：「0」だけ。バッジにしない（usageCount は空、badge が false）
 * 月の預かる数を読み込む前（読み込み中・失敗）は、何も出さない（count も空）。
 * 2026-11 変更：両方がいつも同じ位置にあるほうが読み取りやすいため、同じ日も両方出すようにした。その前は、件数と同じ日は
 * バッジだけにしていた。さらにその前は、0 件の日も件数と同時に預かる数の 2 つを出し、空きありはバッジにしなかった
 *
 * @param {{count: number, usageCount: number, capacity: (number|null), loaded: boolean}} cell
 * @returns {{usageCount: string, count: string, badge: boolean}}
 */
export function cellBottomRow({ count, usageCount, capacity, loaded }) {
    if (!loaded || capacity === null) {
        return { usageCount: '', count: '', badge: false };
    }
    if (usageCount === 0) {
        return { usageCount: '', count: countText(count), badge: false };
    }

    return { usageCount: countText(usageCount), count: countText(count), badge: true };
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

/**
 * 並べて出す月（左から。2026-11 追加。設計書 S-1602「2 か月の並び」）。monthCount が 1 なら month だけ（利用モーダル）、
 * 2 なら month とその翌月（12 月なら翌年 1 月）
 *
 * @param {string} month 左の月（「YYYY-MM」）
 * @param {number} monthCount 並べる月の数
 * @returns {string[]}
 */
export function visibleMonths(month, monthCount = 1) {
    return Array.from({ length: monthCount }, (_, i) => shiftMonth(month, i));
}

/**
 * 日付を選ぶ。出している月の日なら、選んだ日だけを変えて月は動かさない（2 か月並べているときに右の月の日を押しても、並びを動かさない。
 * 2026-11 追加）。出していない月の日なら、その日の月に移る（1 か月のときは、今までどおりその日の月に移るのと同じ）
 */
export function selectDate(state, ymd, monthCount = 1) {
    if (!parseYmd(ymd)) {
        return state;
    }
    const month = visibleMonths(state.month, monthCount).includes(ymOf(ymd)) ? state.month : ymOf(ymd);

    return { month, selected: ymd };
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

/**
 * 会員詳細の URL の型（<x-usage-schedule> の client-url）の中で、会員の id のところに置く印（2026-11 追加。設計書 S-1602）
 */
export const CLIENT_ID_PLACEHOLDER = '__CLIENT_ID__';

/**
 * 会員詳細の URL（2026-11 追加。設計書 S-1602「会員名のリンク」）。型の CLIENT_ID_PLACEHOLDER を会員の id に置き換える。
 * 型がない・id がないときは null（リンクにしない）
 *
 * @param {(string|null|undefined)} template
 * @param {(number|string|null|undefined)} clientId
 * @returns {(string|null)}
 */
export function clientDetailUrl(template, clientId) {
    if (!template || clientId === null || clientId === undefined || clientId === '') {
        return null;
    }

    return template.replace(CLIENT_ID_PLACEHOLDER, encodeURIComponent(String(clientId)));
}

/**
 * 予約の 1 行を、文字とリンクの部分に分けたもの（2026-11 追加。設計書 S-1602「会員名のリンク」）。
 * - 会員詳細の URL の型（clientUrlTemplate）があるとき（カレンダー画面の linkClients: true）：
 *   「開始〜終了　トレーニー（」・会員名（href 付き）・「）」の 3 つ。会員名の部分だけがリンク。トレーニーがない利用は「開始〜終了　（」から始まる
 * - 型がないとき（利用モーダル）・JSON に会員の id がないとき：usageLine と同じ文 1 つ（リンクにしない）
 *
 * @param {{time_range: string, label: string, client_id?: number, client_name?: string, trainee_name?: string}} usage
 * @param {(string|null)} clientUrlTemplate
 * @returns {Array<{text: string, href?: string}>}
 */
export function usageLineParts(usage, clientUrlTemplate = null) {
    const href = clientDetailUrl(clientUrlTemplate, usage.client_id);
    if (href === null) {
        return [{ text: usageLine(usage) }];
    }

    // トレーニーがない利用は、会員名だけ（かっこを付けない。2026-11 変更）
    if (!usage.trainee_name) {
        return [{ text: `${usage.time_range}　` }, { text: usage.client_name ?? '', href }];
    }

    return [
        { text: `${usage.time_range}　${usage.trainee_name}（` },
        { text: usage.client_name ?? '', href },
        { text: '）' },
    ];
}

/**
 * 選んでいる日を変えたときの URL（2026-11 追加。設計書 S-1602「選んでいる日を URL で持つ」）。
 * 今の URL（href）のクエリの date を ymd にしたもの（ほかのクエリ・# はそのまま。同じオリジンの中の、パスから後ろ）。
 * URL を書き換えない設定（syncUrl が false。利用モーダル）のとき・選んでいる日が変わらないときは null（書き換えない）
 *
 * @param {{href: string, syncUrl: boolean, previous: (string|null), selected: string}} args
 * @returns {(string|null)}
 */
export function scheduleDateUrl({ href, syncUrl, previous, selected }) {
    if (!syncUrl || previous === selected || !parseYmd(selected)) {
        return null;
    }
    const url = new URL(href);
    url.searchParams.set('date', selected);

    return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * その日の予約の見出しの文（「11/20(金)　4件　同時に預かる数 3」。予約が 0 件の日は「11/20(金)　0件」。
 * 2026-11 変更：その前は「11/20(金) の予約 4件…」。過去の日も選べるので「予約」は合わないため「の予約」を外した）
 *
 * @param {string} ymd
 * @param {number} usageCount その日の予約（利用）の件数
 * @param {number} count その日の同時に預かる数
 * @param {number} capacity 同時に預かれる数（上限）
 */
export function dayHeading(ymd, usageCount, count, capacity) {
    const parts = dayHeadingParts(ymd, usageCount, count, capacity);

    return parts.dateLabel + parts.gap + parts.usageCountLabel + (parts.showCount ? parts.countLabel + parts.count : '');
}

/**
 * その日の予約の見出しを、日付「M/D(曜)」・件数「n件」と「同時に預かる数 m」に分けたもの（2026-11 変更。設計書 S-0305-M05「その日の予約」）。
 * - 「n件」はその日の予約（利用）の件数（一覧の行の数）。「件」は予約〔記録〕を数えるもので、犬を数えるものではない
 * - 「同時に預かる数 m」は、予約が 1 件以上の日だけ出す（showCount）。数字の部分（count）だけに、カレンダーと同じ
 *   バッジのクラス（countBadgeClasses。判定は capacityState）を付ける。見出しはもともと太字
 * - 件数と同時に預かる数を分けるのは、時間帯が重ならなければ同じ日に上限より多く預かれるため
 * 2026-11 変更：その前は「M/D(曜) の予約 n件」（label）。さらにその前は「M/D(曜) の予約　n/上限」
 *
 * @returns {{dateLabel: string, gap: string, usageCountLabel: string, showCount: boolean, countLabel: string, count: string, countClasses: string[]}}
 */
export function dayHeadingParts(ymd, usageCount, count, capacity) {
    return {
        // 日付と件数（2026-11 変更：「M/D(曜) の予約 n件」から「の予約」を外した）。あいだは全角の空白 1 つぶん（「同時に預かる数」の前と同じ）。
        // 画面では、読み上げで続けて読まれないよう、あいだに見えない区切り（読点）も入れる（usage-schedule.js）
        dateLabel: dayLabel(ymd),
        gap: '　',
        usageCountLabel: `${usageCount}件`,
        showCount: usageCount > 0,
        countLabel: '　同時に預かる数 ',
        count: countText(count),
        countClasses: countBadgeClasses(capacityState(count, capacity)),
    };
}

/**
 * 利用の保存の前の確認（GET /api/usage-schedule/check）に送るクエリ（2026-11 追加。設計書 S-0305-M05「保存時の確認」）。
 * 編集のときは、編集中の利用自身を数えないよう exclude_usage_id を付ける。
 * トレーニーが選ばれているときは trainee_id を付ける（同じトレーニーの重なりの確認。2026-11 追加。選ばれていなければ付けない）
 *
 * @param {{usedOn: string, startTime: string, endTime: string, traineeId?: (string|number|null), excludeUsageId?: (string|number|null)}} input
 * @returns {string}
 */
export function usageCheckQuery({ usedOn, startTime, endTime, traineeId = null, excludeUsageId = null }) {
    const params = new URLSearchParams({ used_on: usedOn, start_time: startTime, end_time: endTime });
    if (traineeId !== null && traineeId !== '') {
        params.set('trainee_id', String(traineeId));
    }
    if (excludeUsageId !== null && excludeUsageId !== '') {
        params.set('exclude_usage_id', String(excludeUsageId));
    }

    return params.toString();
}

/**
 * 保存の前の確認ダイアログ（confirm()）の文（2026-11 追加。設計書 S-0305-M05「保存時の確認」）。
 * 中身は問い合わせの答え（判定はサーバー。画面では預かる数も重なりも計算しない）。
 * 要る確認の文を、重なり → 上限の順に 1 行ずつ並べ、空行のあとに 1 回だけ「登録しますか？」（編集は「更新しますか？」）を付ける。
 * - 重なり：「アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。」（trainee_label・overlap_start・overlap_end）
 * - 上限：「この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 4 になり、上限（3）を超えます。」
 *   文の頭の「この利用を入れると」は、カレンダーの同時に預かる数の数字（入れる前の数）と count が食い違って見えないように付ける
 * サーバー側の黄色の注意は、同じ文を UsageCapacityService::confirmationMessage で組み立てる
 *
 * @param {{date_label: string, needs_overlap_confirmation?: boolean, trainee_label?: string, overlap_start?: string, overlap_end?: string,
 *          needs_capacity_confirmation?: boolean, peak_start?: string, peak_end?: string, count?: number, capacity?: number}} check
 * @param {'登録'|'更新'} button 押したボタンの名前
 * @returns {string}
 */
export function usageConfirmMessage(check, button) {
    const lines = [];
    if (check.needs_overlap_confirmation) {
        lines.push(`${check.trainee_label} は、${check.date_label} ${check.overlap_start}〜${check.overlap_end} にすでに予約があります。`);
    }
    if (check.needs_capacity_confirmation) {
        lines.push(`この利用を入れると、${check.date_label} ${check.peak_start}〜${check.peak_end} に同時に預かる数が ${check.count} になり、`
            + `上限（${check.capacity}）を超えます。`);
    }

    return `${lines.join('\n')}\n\n${button}しますか？`;
}

// -----------------------------------------------------------------------------
// カレンダー画面（S-1602）のその日の予約のタイムテーブル（2026-11 追加。設計書 S-1602「その日の予約のタイムテーブル」）。
// 広い画面だけで出す（狭い画面と利用モーダルはリストのまま）。ここは DOM に触れない計算だけ。時刻は 0 時からの分で扱う
// -----------------------------------------------------------------------------

/** 時の数字を 1 時間ごとに出すのに要る、1 時間あたりの幅（px）。これより狭いときは 2 時間ごと */
export const TIMETABLE_MIN_HOUR_LABEL_PX = 28;

/**
 * 「HH:MM」（「H:MM」も可）を 0 時からの分にする。正しくなければ null
 *
 * @param {string} value
 * @returns {(number|null)}
 */
export function toMinutes(value) {
    const m = /^(\d{1,2}):(\d{2})$/.exec(String(value ?? '').trim());
    if (!m) {
        return null;
    }
    const hour = parseInt(m[1], 10);
    const minute = parseInt(m[2], 10);
    if (hour > 24 || minute > 59 || (hour === 24 && minute !== 0)) {
        return null;
    }

    return hour * 60 + minute;
}

/**
 * タイムテーブルの時間の幅（分）。基本は営業時間で、その日の利用が営業時間より前に始まる・後に終わるときは、その時刻まで広げる。
 * 左端は 1 時間単位に切り下げ、右端は 1 時間単位に切り上げる（例：8:00〜19:00 に 19:00〜20:00 の利用 → 8:00〜20:00）。
 * 営業時間が読めないときは、利用の時刻だけで決める。日をまたぐ利用はない（終了は開始より後。S-0305-M05）
 *
 * @param {{openTime: string, closeTime: string, usages: Array<{start_time: string, end_time: string}>}} args
 * @returns {{start: number, end: number}}
 */
export function timetableRange({ openTime, closeTime, usages }) {
    const points = [toMinutes(openTime), toMinutes(closeTime)];
    for (const usage of usages) {
        points.push(toMinutes(usage.start_time), toMinutes(usage.end_time));
    }
    const valid = points.filter((v) => v !== null);
    if (valid.length === 0) {
        return { start: 0, end: 24 * 60 };
    }
    const start = Math.floor(Math.min(...valid) / 60) * 60;
    let end = Math.ceil(Math.max(...valid) / 60) * 60;
    if (end <= start) {
        end = start + 60;
    }

    return { start, end };
}

/**
 * 時間の幅の中で、開始〜終了の位置（左端と幅。時間の幅に対する割合〔%〕）。幅の外にはみ出す分は切る
 *
 * @param {{start: number, end: number}} range
 * @param {number} startMinutes
 * @param {number} endMinutes
 * @returns {{left: number, width: number}}
 */
export function timetablePosition(range, startMinutes, endMinutes) {
    const span = range.end - range.start;
    const from = Math.min(Math.max(startMinutes, range.start), range.end);
    const to = Math.min(Math.max(endMinutes, range.start), range.end);

    return {
        left: ((from - range.start) / span) * 100,
        width: (Math.max(to - from, 0) / span) * 100,
    };
}

/**
 * 営業時間の外の部分（灰色にする。左端と幅の割合）。営業時間が読めないときはなし
 *
 * @param {{start: number, end: number}} range
 * @param {string} openTime
 * @param {string} closeTime
 * @returns {Array<{left: number, width: number}>}
 */
export function timetableOutside(range, openTime, closeTime) {
    const open = toMinutes(openTime);
    const close = toMinutes(closeTime);
    if (open === null || close === null || close <= open) {
        return [];
    }
    const segments = [];
    if (open > range.start) {
        segments.push(timetablePosition(range, range.start, open));
    }
    if (close < range.end) {
        segments.push(timetablePosition(range, close, range.end));
    }

    return segments;
}

/**
 * 時の数字を何時間ごとに出すか（1 か 2）。1 時間あたりの幅が TIMETABLE_MIN_HOUR_LABEL_PX 未満なら 2。幅が分からない（0）ときは 1
 *
 * @param {number} trackWidthPx 目盛りの幅（px）
 * @param {number} hourCount 時間の幅の時間数
 */
export function timetableLabelStep(trackWidthPx, hourCount) {
    if (!trackWidthPx || hourCount <= 0) {
        return 1;
    }

    return trackWidthPx / hourCount < TIMETABLE_MIN_HOUR_LABEL_PX ? 2 : 1;
}

/**
 * 目盛りの時（左端から右端まで 1 時間ごと）。labeled は数字を出すか（左端から step 時間ごと）
 *
 * @param {{start: number, end: number}} range
 * @param {number} step 1 か 2
 * @returns {Array<{hour: number, left: number, labeled: boolean}>}
 */
export function timetableHours(range, step = 1) {
    const first = range.start / 60;
    const last = range.end / 60;
    const hours = [];
    for (let hour = first; hour <= last; hour++) {
        hours.push({ hour, left: ((hour - first) / (last - first)) * 100, labeled: (hour - first) % step === 0 });
    }

    return hours;
}

/**
 * 帯の時刻をどこに書くか：帯の中（inside）、帯の外の右（right）、帯の外の左（left）。
 * 帯の中に収まらなければ右、右に余白がなければ左。どちらにも収まらなければ右（はみ出した分は隠れる）。帯の中で文字を切らない
 *
 * @param {{bandLeftPx: number, bandWidthPx: number, labelWidthPx: number, trackWidthPx: number, paddingPx?: number, gapPx?: number}} args
 * @returns {('inside'|'right'|'left')}
 */
export function timetableLabelPlacement({ bandLeftPx, bandWidthPx, labelWidthPx, trackWidthPx, paddingPx = 4, gapPx = 4 }) {
    if (labelWidthPx + paddingPx * 2 <= bandWidthPx) {
        return 'inside';
    }
    if (bandLeftPx + bandWidthPx + gapPx + labelWidthPx <= trackWidthPx) {
        return 'right';
    }
    if (bandLeftPx - gapPx - labelWidthPx >= 0) {
        return 'left';
    }

    return 'right';
}

/**
 * 左の列の「トレーニー（会員名）」を、列の上限を超えたときに「…」で省いてよい部分（shrink）と、必ず全体を出す部分（keep）に分けたもの
 * （設計書 S-1602「列の幅」。2026-11 変更：その前は分けずに末尾を省いていて、会員名のリンクが隠れることがあった）。
 * - トレーニーがある利用：省いてよいのはトレーニーの名前だけ。「（会員名）」はかっこごと必ず全体を出す（会員名のリンクをいつも押せるように）
 * - トレーニーがない利用：会員名だけ（かっこを付けない）。上限を超えるときだけ会員名を省く
 * - 会員詳細の URL の型がない・JSON に会員の id がないとき：label の 1 つの文（リンクにしない）
 * shrink・keep の順につなげると、行の全体の文になる
 *
 * @returns {{shrink: Array<{text: string, href?: string}>, keep: Array<{text: string, href?: string}>}}
 */
export function usageNameGroups(usage, clientUrlTemplate = null) {
    const href = clientDetailUrl(clientUrlTemplate, usage.client_id);
    if (href === null) {
        return { shrink: [{ text: usage.label }], keep: [] };
    }
    if (!usage.trainee_name) {
        return { shrink: [{ text: usage.client_name ?? '', href }], keep: [] };
    }

    return {
        shrink: [{ text: usage.trainee_name }],
        keep: [{ text: '（' }, { text: usage.client_name ?? '', href }, { text: '）' }],
    };
}

/** 左の列の行の全体の文（「…」で省いたときの title に入れる。2026-11 追加） */
export function usageNameText({ shrink, keep }) {
    return [...shrink, ...keep].map((part) => part.text).join('');
}

/**
 * タイムテーブル 1 つ分の表示データ。予約がない日は null（タイムテーブルを出さず、「予約はありません」の知らせだけ）
 *
 * @param {{usages: Array, openTime: string, closeTime: string, clientUrlTemplate?: (string|null)}} args
 * @returns {(null|{range: {start: number, end: number}, hourCount: number, outside: Array<{left: number, width: number}>,
 *            rows: Array<{timeRange: string, name: {shrink: Array<{text: string, href?: string}>, keep: Array<{text: string, href?: string}>}, left: number, width: number}>})}
 */
export function timetableModel({ usages, openTime, closeTime, clientUrlTemplate = null }) {
    if (!usages || usages.length === 0) {
        return null;
    }
    const range = timetableRange({ openTime, closeTime, usages });

    return {
        range,
        hourCount: (range.end - range.start) / 60,
        outside: timetableOutside(range, openTime, closeTime),
        rows: usages.map((usage) => ({
            timeRange: usage.time_range,
            name: usageNameGroups(usage, clientUrlTemplate),
            ...timetablePosition(range, toMinutes(usage.start_time) ?? range.start, toMinutes(usage.end_time) ?? range.start),
        })),
    };
}

/**
 * カレンダー画面（S-1602）のその日の予定の枠の、最低の行数（2026-11 追加。設計書 S-1602「日を切り替えてもカレンダーが動かないようにする」）。
 * 同時に預かれる数（上限）の行数分にする。上限をまだ読んでいない・正しくないときは 1
 *
 * @param {(number|null|undefined)} capacity
 * @returns {number}
 */
export function dayMinRows(capacity) {
    const n = Number(capacity);

    return Number.isInteger(n) && n >= 1 ? n : 1;
}

