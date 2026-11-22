import { describe, expect, it } from 'vitest';
import {
    CAPACITY_AVAILABLE,
    CAPACITY_FULL,
    CAPACITY_OVER,
    applyInput,
    usageCheckQuery,
    usageConfirmMessage,
    capacityState,
    countBadgeClasses,
    cellCountAriaText,
    cellCountText,
    cellBottomRow,
    countText,
    dayCellClasses,
    dayHeading,
    dayHeadingParts,
    dayLabel,
    initialState,
    isPastDay,
    monthHeading,
    monthWeeks,
    moveMonth,
    parseYmd,
    selectDate,
    shiftMonth,
    usageLine,
    usageLineParts,
    clientDetailUrl,
    scheduleDateUrl,
    CLIENT_ID_PLACEHOLDER,
    toMinutes,
    timetableRange,
    timetablePosition,
    timetableOutside,
    timetableLabelStep,
    timetableHours,
    timetableLabelPlacement,
    usageNameGroups,
    googleCalendarParts,
    GOOGLE_CALENDAR_LINK_LABEL,
    usageNameText,
    timetableModel,
    dayMinRows,
    visibleMonths,
} from '../../resources/js/usage-schedule-core.js';

// 利用の予約状況（カレンダーとその日の予約）の計算（2026-11 追加。設計書 S-0305-M05「予約状況」）。
// 画面の部品（usage-schedule.js）は DOM を使うため、ここでは DOM に触れない計算と状態の移り方を確かめる。

describe('parseYmd', () => {
    it('正しい日付を読む（うるう年も）', () => {
        expect(parseYmd('2026-11-26')).toEqual({ year: 2026, month: 11, day: 26 });
        expect(parseYmd(' 2028-02-29 ')).toEqual({ year: 2028, month: 2, day: 29 });
    });

    it.each(['', null, undefined, '2026-11-2', '2026/11/26', '2026-13-01', '2026-02-29', '2026-11-31', 'abc'])(
        '形が違う・存在しない日付（%s）は null',
        (value) => {
            expect(parseYmd(value)).toBeNull();
        },
    );
});

describe('月の移動と見出し', () => {
    it.each([
        ['2026-11', 1, '2026-12'],
        ['2026-12', 1, '2027-01'],
        ['2026-01', -1, '2025-12'],
        ['2026-11', -1, '2026-10'],
        ['2026-11', 14, '2028-01'],
    ])('%s を %i か月移すと %s', (ym, delta, expected) => {
        expect(shiftMonth(ym, delta)).toBe(expected);
    });

    it('見出しは「YYYY年M月」', () => {
        expect(monthHeading('2026-11')).toBe('2026年11月');
        expect(monthHeading('2027-01')).toBe('2027年1月');
    });
});

describe('monthWeeks', () => {
    it('日曜始まりで、前月・翌月の日は空のマス', () => {
        // 2026-11-01 は日曜、30 日は月曜
        const weeks = monthWeeks('2026-11');
        expect(weeks).toHaveLength(5);
        expect(weeks[0]).toEqual(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07']);
        expect(weeks[3][4]).toBe('2026-11-26'); // 11/26 は木曜
        expect(weeks[4]).toEqual(['2026-11-29', '2026-11-30', null, null, null, null, null]);
    });

    it('月の途中から始まる月は、前に空のマスを置く', () => {
        // 2026-12-01 は火曜
        const weeks = monthWeeks('2026-12');
        expect(weeks[0]).toEqual([null, null, '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05']);
        expect(weeks.flat().filter(Boolean)).toHaveLength(31);
        weeks.forEach((week) => expect(week).toHaveLength(7));
    });

    it('うるう年の 2 月は 29 日まで', () => {
        expect(monthWeeks('2028-02').flat().filter(Boolean).at(-1)).toBe('2028-02-29');
        expect(monthWeeks('2026-02').flat().filter(Boolean).at(-1)).toBe('2026-02-28');
    });
});

describe('預かる数の表示', () => {
    // 2026-11 変更：同時に預かる数は数字だけ（その前は「n/上限」。「1 日に上限まで」と読めてしまうため）
    it('同時に預かる数の文は数字だけ（0 も「0」）', () => {
        expect(countText(2)).toBe('2');
        expect(countText(0)).toBe('0');
        expect(countText(4)).toBe('4');
    });

    // 2026-11 変更：満（上限ちょうど）は緑、超過（上限を超える）は赤に分けた。その前は上限以上をひとまとめに赤にしていた
    it.each([
        [0, 3, CAPACITY_AVAILABLE],
        [1, 3, CAPACITY_AVAILABLE],
        [2, 3, CAPACITY_AVAILABLE],
        [3, 3, CAPACITY_FULL],
        [4, 3, CAPACITY_OVER],
        [10, 3, CAPACITY_OVER],
        [1, 1, CAPACITY_FULL],
        [2, 1, CAPACITY_OVER],
        [0, 1, CAPACITY_AVAILABLE],
    ])('預かる数 %i・上限 %i は %s', (count, capacity, expected) => {
        expect(capacityState(count, capacity)).toBe(expected);
    });

    it('状態の値は空きあり・満・超過の 3 つ', () => {
        expect([CAPACITY_AVAILABLE, CAPACITY_FULL, CAPACITY_OVER]).toEqual(['available', 'full', 'over']);
    });

    it('その日の予約の見出しと 1 行の文', () => {
        expect(dayLabel('2026-11-26')).toBe('11/26(木)');
        expect(dayLabel('2026-11-01')).toBe('11/1(日)');
        // 見出しは「M/D(曜)　n件　同時に預かる数 m」（2026-11 変更：「の予約」を外した。あいだは全角の空白）
        expect(dayHeading('2026-11-26', 2, 2, 3)).toBe('11/26(木)　2件　同時に預かる数 2');
        expect(dayHeading('2026-11-26', 4, 3, 3)).toBe('11/26(木)　4件　同時に預かる数 3');
        expect(dayHeading('2026-11-26', 4, 3, 3)).not.toMatch(/[頭匹]|\/3|の予約/);
        expect(usageLine({ time_range: '8:00〜19:00', label: 'アムロ（佐藤 一郎）' })).toBe('8:00〜19:00　アムロ（佐藤 一郎）');
    });
});

describe('状態の移り方', () => {
    it('開いたときは利用日の月・日。正しくない日付なら今日', () => {
        expect(initialState('2026-12-03', '2026-11-21')).toEqual({ month: '2026-12', selected: '2026-12-03' });
        expect(initialState('2026-12-3', '2026-11-21')).toEqual({ month: '2026-11', selected: '2026-11-21' });
        expect(initialState('', '2026-11-21')).toEqual({ month: '2026-11', selected: '2026-11-21' });
    });

    it('日付を押すと、その日を選ぶ', () => {
        const state = { month: '2026-11', selected: '2026-11-21' };
        expect(selectDate(state, '2026-11-26')).toEqual({ month: '2026-11', selected: '2026-11-26' });
    });

    it('利用日の欄に正しい日付を直接入れると、その月に移ってその日を選ぶ', () => {
        const state = { month: '2026-11', selected: '2026-11-21' };
        expect(applyInput(state, '2027-01-15')).toEqual({ month: '2027-01', selected: '2027-01-15' });
        expect(applyInput(state, ' 2026-11-30 ')).toEqual({ month: '2026-11', selected: '2026-11-30' });
    });

    it('入力の途中・正しくない日付の間は、今のまま', () => {
        const state = { month: '2026-11', selected: '2026-11-21' };
        for (const value of ['2027-0', '2027-01-1', '2026-11-31', '']) {
            expect(applyInput(state, value)).toBe(state);
        }
    });

    it('月を移しても、選んだ日は変えない', () => {
        const state = { month: '2026-11', selected: '2026-11-21' };
        expect(moveMonth(state, 1)).toEqual({ month: '2026-12', selected: '2026-11-21' });
        expect(moveMonth(moveMonth(state, 1), -2)).toEqual({ month: '2026-10', selected: '2026-11-21' });
    });

    it('前の月を出しているときに日付を押すと、その日の月に合わせる', () => {
        const state = { month: '2026-10', selected: '2026-11-21' };
        expect(selectDate(state, '2026-10-05')).toEqual({ month: '2026-10', selected: '2026-10-05' });
    });
});

// 満・超過は「n/上限」の文字の色だけ、今日は薄い青の背景、選んでいる日は太い青の枠（2026-11 変更。設計書 S-0305-M05「予約状況」）
describe('dayCellClasses', () => {
    const cell = (state, selected, today) => dayCellClasses({ state, selected, today });
    const BG_COLORS = ['bg-success-subtle', 'bg-danger-subtle', 'border-success-subtle', 'border-danger-subtle'];

    // 2026-11 変更：満・超過は「n/上限」を小さなバッジにした（その前は文字の色）
    it.each([
        [CAPACITY_FULL, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        [CAPACITY_OVER, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
    ])('%s のマスに背景の色のクラスは付かず、「n/上限」がバッジになる', (state, badge) => {
        const { button, day, count, countBadge } = cell(state, false, false);
        BG_COLORS.forEach((c) => expect(button).not.toContain(c));
        expect(button).toContain('btn-light');
        expect(count).toEqual(['d-block', 'small', 'usage-schedule-count']);
        expect(countBadge).toEqual(badge);
        expect(day).toEqual(['d-block']);
    });

    // 2026-11 変更：空きありも灰色のバッジ（その前はバッジにしなかった）
    it('空きありの同時に預かる数は灰色のバッジ', () => {
        expect(cell(CAPACITY_AVAILABLE, false, false).count).toEqual(['d-block', 'small', 'usage-schedule-count']);
        expect(cell(CAPACITY_AVAILABLE, false, false).countBadge).toEqual(['badge', 'bg-secondary', 'usage-schedule-count-badge']);
    });

    it('今日のマスは薄い青の背景で、日付の数字に青の太字は付かない', () => {
        const { button, day } = cell(CAPACITY_AVAILABLE, false, true);
        expect(button).toEqual(expect.arrayContaining(['usage-schedule-today', 'bg-primary-subtle']));
        expect(button).not.toContain('btn-light');
        expect(button).not.toContain('usage-schedule-selected');
        expect(day).toEqual(['d-block']);
        expect(day).not.toContain('text-primary');
        expect(day).not.toContain('fw-bold');
    });

    it.each([
        [CAPACITY_FULL, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        [CAPACITY_OVER, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
    ])('今日が %s のとき、薄い青の背景と緑・赤のバッジが両方付く', (state, badge) => {
        const { button, countBadge } = cell(state, false, true);
        expect(button).toContain('bg-primary-subtle');
        expect(countBadge).toEqual(badge);
        BG_COLORS.forEach((c) => expect(button).not.toContain(c));
    });

    it('今日を選んでいるとき、薄い青の背景と枠が両方付く', () => {
        const { button } = cell(CAPACITY_OVER, true, true);
        expect(button).toEqual(expect.arrayContaining(['bg-primary-subtle', 'usage-schedule-selected']));
        expect(button).not.toContain('btn-primary');
    });

    it('選んでいる日は枠だけ（背景はその日のまま。塗りつぶしにならない）', () => {
        const { button, countBadge } = cell(CAPACITY_FULL, true, false);
        expect(button).toEqual(expect.arrayContaining(['btn-light', 'usage-schedule-selected']));
        expect(button).not.toContain('btn-primary');
        expect(countBadge).toEqual(['badge', 'bg-success', 'usage-schedule-count-badge']);
    });

    it('上限をまだ読んでいない間は、空きありと同じ', () => {
        expect(cell(null, false, false)).toEqual({
            button: ['btn', 'btn-sm', 'w-100', 'px-0', 'py-1', 'lh-sm', 'usage-schedule-day', 'btn-light'],
            day: ['d-block'],
            count: ['d-block', 'small', 'usage-schedule-count'],
            countBadge: [],
            usageCount: ['usage-schedule-usage-count', 'text-body-secondary', 'fw-bold', 'me-2'],
        });
    });
});

// 保存の前の確認ダイアログ（2026-11 追加。設計書 S-0305-M05「保存時の確認」。重なりの確認は 2026-11 追加）
describe('保存の前の確認', () => {
    const capacity = {
        needs_confirmation: true, needs_capacity_confirmation: true, count: 4, capacity: 3, peak_start: '15:00', peak_end: '17:00',
        needs_overlap_confirmation: false, overlap_start: null, overlap_end: null, trainee_label: 'アムロ', date_label: '11/26(木)',
    };
    const overlap = {
        ...capacity, needs_capacity_confirmation: false, count: 2,
        needs_overlap_confirmation: true, overlap_start: '13:00', overlap_end: '19:00',
    };
    const both = { ...capacity, needs_overlap_confirmation: true, overlap_start: '13:00', overlap_end: '19:00' };

    it('上限だけ：登録', () => {
        expect(usageConfirmMessage(capacity, '登録')).toBe(
            'この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 4 になり、上限（3）を超えます。\n\n登録しますか？',
        );
    });

    it('上限だけ：編集は最後が「更新しますか？」', () => {
        expect(usageConfirmMessage({ ...capacity, count: 5, peak_start: '8:00', peak_end: '19:00' }, '更新')).toBe(
            'この利用を入れると、11/26(木) 8:00〜19:00 に同時に預かる数が 5 になり、上限（3）を超えます。\n\n更新しますか？',
        );
    });

    it('重なりだけ：登録・編集', () => {
        expect(usageConfirmMessage(overlap, '登録')).toBe('アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。\n\n登録しますか？');
        expect(usageConfirmMessage(overlap, '更新')).toBe('アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。\n\n更新しますか？');
    });

    it('重なりの文のトレーニーは表示名のまま（名前未登録も）', () => {
        expect(usageConfirmMessage({ ...overlap, trainee_label: '名前未登録' }, '登録'))
            .toBe('名前未登録 は、11/26(木) 13:00〜19:00 にすでに予約があります。\n\n登録しますか？');
    });

    it('両方：重なり → 上限の順に 1 行ずつ並べ、最後に 1 回だけ問う', () => {
        expect(usageConfirmMessage(both, '登録')).toBe(
            'アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。\n'
            + 'この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 4 になり、上限（3）を超えます。\n\n登録しますか？',
        );
        expect(usageConfirmMessage(both, '更新').endsWith('\n\n更新しますか？')).toBe(true);
        expect(usageConfirmMessage(both, '更新').match(/しますか？/g)).toHaveLength(1);
    });

    it('ダイアログの文に「頭」「匹」を使わない', () => {
        expect(usageConfirmMessage(both, '登録')).not.toMatch(/[頭匹]/);
    });

    it('問い合わせのクエリ：トレーニー・除く利用は、あるときだけ付ける', () => {
        expect(usageCheckQuery({ usedOn: '2026-11-26', startTime: '14:00', endTime: '18:00' }))
            .toBe('used_on=2026-11-26&start_time=14%3A00&end_time=18%3A00');
        for (const empty of [null, '']) {
            const query = usageCheckQuery({ usedOn: '2026-11-26', startTime: '14:00', endTime: '18:00', traineeId: empty, excludeUsageId: empty });
            expect(query).not.toContain('trainee_id');
            expect(query).not.toContain('exclude_usage_id');
        }
        expect(usageCheckQuery({ usedOn: '2026-11-26', startTime: '14:00', endTime: '18:00', traineeId: '10', excludeUsageId: 12 }))
            .toBe('used_on=2026-11-26&start_time=14%3A00&end_time=18%3A00&trainee_id=10&exclude_usage_id=12');
    });
});

// 「n/上限」はすべて太字、見出しの「n/上限」にもカレンダーと同じ色（2026-11 変更。設計書 S-0305-M05「予約状況」）
describe('「n/上限」の太字と色', () => {
    it.each([CAPACITY_AVAILABLE, CAPACITY_FULL, CAPACITY_OVER, null])(
        'カレンダーの「n/上限」は %s でも太字のクラス（usage-schedule-count）が付く',
        (state) => {
            const { count, day } = dayCellClasses({ state, selected: false, today: false });
            expect(count).toContain('usage-schedule-count');
            // 日付の数字は太字にしない
            expect(day).toEqual(['d-block']);
        },
    );

    it('バッジのクラスは空きあり（灰色）・満（緑）・超過（赤）。読み込む前はなし（カレンダーと見出しで同じ判定）', () => {
        expect(countBadgeClasses(CAPACITY_AVAILABLE)).toEqual(['badge', 'bg-secondary', 'usage-schedule-count-badge']);
        expect(countBadgeClasses(CAPACITY_FULL)).toEqual(['badge', 'bg-success', 'usage-schedule-count-badge']);
        expect(countBadgeClasses(CAPACITY_OVER)).toEqual(['badge', 'bg-danger', 'usage-schedule-count-badge']);
        expect(countBadgeClasses(null)).toEqual([]);
    });

    // 見出し：「M/D(曜)　n件　同時に預かる数 m」。数字の部分だけカレンダーと同じバッジ（2026-11 変更）
    it.each([
        ['満', 4, 3, 3, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        ['超過', 4, 4, 3, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
        ['空きあり', 1, 1, 3, ['badge', 'bg-secondary', 'usage-schedule-count-badge']],
        ['空きあり・件数と違う', 3, 2, 3, ['badge', 'bg-secondary', 'usage-schedule-count-badge']],
    ])('見出し（%s）：件数 %i・同時に預かる数 %i・上限 %i', (_, usageCount, count, capacity, classes) => {
        const parts = dayHeadingParts('2026-11-20', usageCount, count, capacity);
        expect(parts.dateLabel).toBe('11/20(金)');
        expect(parts.gap).toBe('　');
        expect(parts.usageCountLabel).toBe(`${usageCount}件`);
        expect(parts.showCount).toBe(true);
        expect(parts.countLabel).toBe('　同時に預かる数 ');
        expect(parts.count).toBe(`${count}`);
        expect(parts.countClasses).toEqual(classes);
        expect(dayHeading('2026-11-20', usageCount, count, capacity)).toBe(`11/20(金)　${usageCount}件　同時に預かる数 ${count}`);
    });

    it('見出し：予約が 0 件の日は「同時に預かる数」を出さない', () => {
        const parts = dayHeadingParts('2026-11-22', 0, 0, 3);
        expect(parts.dateLabel).toBe('11/22(日)');
        expect(parts.usageCountLabel).toBe('0件');
        expect(parts.showCount).toBe(false);
        expect(dayHeading('2026-11-22', 0, 0, 3)).toBe('11/22(日)　0件');
    });

    it('見出しのバッジのクラスは、カレンダーの同じ日の数字のバッジのクラスと同じ', () => {
        for (const [count, capacity] of [[0, 3], [1, 3], [3, 3], [4, 3]]) {
            const cell = dayCellClasses({ state: capacityState(count, capacity), selected: false, today: false });
            const heading = dayHeadingParts('2026-11-20', Math.max(count, 1), count, capacity);
            expect(cell.countBadge).toEqual(heading.countClasses);
        }
    });
});

// 過去の日は灰色の背景、予約がない日も「0/上限」（2026-11 追加。設計書 S-0305-M05「予約状況」）
describe('過去の日と「0/上限」', () => {
    const cell = (opts) => dayCellClasses({ state: CAPACITY_AVAILABLE, selected: false, today: false, past: false, ...opts });

    it('過去の日かどうかはサーバーの今日で決め、今日を知らない間は過去にしない', () => {
        expect(isPastDay('2026-11-20', '2026-11-21')).toBe(true);
        expect(isPastDay('2026-10-31', '2026-11-21')).toBe(true);
        expect(isPastDay('2026-11-21', '2026-11-21')).toBe(false);
        expect(isPastDay('2026-11-22', '2026-11-21')).toBe(false);
        expect(isPastDay('2026-11-20', null)).toBe(false);
    });

    it('過去の日のマスは灰色の背景、日付の数字は薄い色', () => {
        const { button, day } = cell({ past: true });
        expect(button).toEqual(expect.arrayContaining(['usage-schedule-past', 'bg-secondary-subtle']));
        expect(button).not.toContain('btn-light');
        expect(day).toEqual(['d-block', 'text-body-secondary']);
    });

    it('今日・今日より後の日には、灰色の背景も薄い色も付かない', () => {
        for (const opts of [{ today: true }, {}]) {
            const { button, day } = cell(opts);
            expect(button).not.toContain('bg-secondary-subtle');
            expect(button).not.toContain('usage-schedule-past');
            expect(day).not.toContain('text-body-secondary');
        }
    });

    it.each([
        [CAPACITY_FULL, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        [CAPACITY_OVER, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
    ])('過去の日が %s のとき、灰色の背景とバッジが両方付く（太字も）', (state, badge) => {
        const { button, count, countBadge } = cell({ state, past: true });
        expect(button).toContain('bg-secondary-subtle');
        expect(count).toEqual(['d-block', 'small', 'usage-schedule-count']);
        expect(countBadge).toEqual(badge);
    });

    it('過去の日を選んでいるとき、灰色の背景と枠が両方付く', () => {
        const { button } = cell({ past: true, selected: true });
        expect(button).toEqual(expect.arrayContaining(['bg-secondary-subtle', 'usage-schedule-selected']));
    });

    it('予約がない日は「0」だけで、件数もバッジも出さない（通常の色の太字）', () => {
        expect(cellBottomRow({ count: 0, usageCount: 0, capacity: 3, loaded: true })).toEqual({ usageCount: '', count: '0', badge: false });
        expect(cell({ state: capacityState(0, 3) }).count).toEqual(['d-block', 'small', 'usage-schedule-count']);
    });

    // マスの文字は数字だけで、満・超過のバッジのクラスは今のまま（2026-11 変更：その前は「n/上限」）
    it.each([
        [0, '0', ['badge', 'bg-secondary', 'usage-schedule-count-badge']],
        [1, '1', ['badge', 'bg-secondary', 'usage-schedule-count-badge']],
        [3, '3', ['badge', 'bg-success', 'usage-schedule-count-badge']],
        [4, '4', ['badge', 'bg-danger', 'usage-schedule-count-badge']],
    ])('マス：同時に預かる数 %i は「%s」', (count, text, badge) => {
        expect(cellCountText({ count, capacity: 3, loaded: true })).toBe(text);
        expect(cell({ state: capacityState(count, 3) }).countBadge).toEqual(badge);
    });

    // 読み上げの文は「予約 n件 同時に預かる数 m」（2026-11 変更：件数を足した）
    it('マスの読み上げの文は「予約 n件 同時に預かる数 m」（0 件も入れる。読み込む前は空）', () => {
        expect(cellCountAriaText({ count: 3, usageCount: 4, capacity: 3, loaded: true })).toBe('予約 4件 同時に預かる数 3');
        expect(cellCountAriaText({ count: 0, usageCount: 0, capacity: 3, loaded: true })).toBe('予約 0件 同時に預かる数 0');
        expect(cellCountAriaText({ count: 2, usageCount: 2, capacity: 3, loaded: false })).toBe('');
    });

    // 下の行：1 件以上の日は、件数と同じ日も違う日も、灰色の太字の件数＋バッジ（2026-11 変更：その前は同じ日はバッジだけ）
    it.each([
        ['違う・空きあり', 3, 2, { usageCount: '3', count: '2', badge: true }, ['badge', 'bg-secondary', 'usage-schedule-count-badge']],
        ['違う・満', 4, 3, { usageCount: '4', count: '3', badge: true }, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        ['違う・超過', 5, 4, { usageCount: '5', count: '4', badge: true }, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
        ['同じ・空きあり', 1, 1, { usageCount: '1', count: '1', badge: true }, ['badge', 'bg-secondary', 'usage-schedule-count-badge']],
        ['同じ・満', 3, 3, { usageCount: '3', count: '3', badge: true }, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        ['同じ・超過', 4, 4, { usageCount: '4', count: '4', badge: true }, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
    ])('下の行（%s）：件数 %i・同時に預かる数 %i', (_, usageCount, count, row, badge) => {
        expect(cellBottomRow({ count, usageCount, capacity: 3, loaded: true })).toEqual(row);
        expect(cell({ state: capacityState(count, 3) }).countBadge).toEqual(badge);
    });

    it('下の行：読み込む前は何も出さない（上限を知っていても）', () => {
        for (const [usageCount, count] of [[4, 3], [2, 2], [0, 0]]) {
            expect(cellBottomRow({ count, usageCount, capacity: 3, loaded: false })).toEqual({ usageCount: '', count: '', badge: false });
        }
        expect(cellBottomRow({ count: 2, usageCount: 2, capacity: null, loaded: true })).toEqual({ usageCount: '', count: '', badge: false });
    });

    it('件数と同じ日も、読み上げの文には件数と同時に預かる数の両方を入れる', () => {
        expect(cellCountAriaText({ count: 2, usageCount: 2, capacity: 3, loaded: true })).toBe('予約 2件 同時に預かる数 2');
    });

    it('件数の要素は控えめな灰色の太字で、同時に預かる数とのあいだを空ける（どの日でも同じ）', () => {
        for (const opts of [{}, { today: true }, { past: true }, { selected: true }, { state: CAPACITY_OVER }, { state: CAPACITY_FULL }]) {
            expect(cell(opts).usageCount).toEqual(['usage-schedule-usage-count', 'text-body-secondary', 'fw-bold', 'me-2']);
            expect(cell(opts).usageCount).not.toContain('fw-normal');
        }
    });

    it('月の預かる数を読み込む前は「n/上限」を出さない（上限を知っていても）', () => {
        expect(cellCountText({ count: 0, capacity: 3, loaded: false })).toBe('');
        expect(cellCountText({ count: 2, capacity: 3, loaded: false })).toBe('');
        expect(cellCountText({ count: 0, capacity: null, loaded: true })).toBe('');
    });
});

// カレンダー画面（S-1602。2026-11 追加）の設定：会員名のリンク（linkClients）と URL の date（syncUrl）
describe('カレンダー画面の設定', () => {
    const TEMPLATE = `https://trainer.example.test/clients/${CLIENT_ID_PLACEHOLDER}`;
    const withTrainee = { time_range: '8:00〜19:00', label: 'アムロ（佐藤 一郎）', client_id: 12, client_name: '佐藤 一郎', trainee_name: 'アムロ' };
    // トレーニーがない利用の label は会員名だけ（2026-11 変更：その前は「（中森）」）
    const withoutTrainee = { time_range: '19:01〜20:00', label: '中森', client_id: 7, client_name: '中森', trainee_name: '' };

    it('会員詳細の URL は、型の印を会員の id に置き換える', () => {
        expect(CLIENT_ID_PLACEHOLDER).toBe('__CLIENT_ID__');
        expect(clientDetailUrl(TEMPLATE, 12)).toBe('https://trainer.example.test/clients/12');
        expect(clientDetailUrl(TEMPLATE, '7')).toBe('https://trainer.example.test/clients/7');
    });

    it('型がない・id がないときは URL を作らない（リンクにしない）', () => {
        expect(clientDetailUrl(null, 12)).toBeNull();
        expect(clientDetailUrl('', 12)).toBeNull();
        expect(clientDetailUrl(undefined, 12)).toBeNull();
        expect(clientDetailUrl(TEMPLATE, null)).toBeNull();
        expect(clientDetailUrl(TEMPLATE, undefined)).toBeNull();
    });

    it('画面用（型あり）：会員名の部分だけがリンクで、時刻・トレーニー・かっこはリンクにしない', () => {
        expect(usageLineParts(withTrainee, TEMPLATE)).toEqual([
            { text: '8:00〜19:00　アムロ（' },
            { text: '佐藤 一郎', href: 'https://trainer.example.test/clients/12' },
            { text: '）' },
        ]);
    });

    it('画面用：トレーニーがない利用も、会員名をリンクにする（かっこを付けない。2026-11 変更）', () => {
        expect(usageLineParts(withoutTrainee, TEMPLATE)).toEqual([
            { text: '19:01〜20:00　' },
            { text: '中森', href: 'https://trainer.example.test/clients/7' },
        ]);
    });

    it('画面用：つなげた文字は、モーダルの 1 行の文と同じ', () => {
        for (const usage of [withTrainee, withoutTrainee]) {
            expect(usageLineParts(usage, TEMPLATE).map((part) => part.text).join('')).toBe(usageLine(usage));
        }
    });

    it('モーダル用（型なし）：リンクにせず、今までどおり 1 行の文だけ', () => {
        expect(usageLineParts(withTrainee)).toEqual([{ text: '8:00〜19:00　アムロ（佐藤 一郎）' }]);
        expect(usageLineParts(withTrainee, null)).toEqual([{ text: '8:00〜19:00　アムロ（佐藤 一郎）' }]);
        expect(usageLineParts(withoutTrainee, null)).toEqual([{ text: '19:01〜20:00　中森' }]);
        // JSON に会員の id がなければ、型があってもリンクにしない
        expect(usageLineParts({ time_range: '8:00〜19:00', label: 'アムロ（佐藤 一郎）' }, TEMPLATE)).toEqual([{ text: '8:00〜19:00　アムロ（佐藤 一郎）' }]);
    });

    it('URL の date：選んでいる日を書き換えた URL（パスから後ろ）を返す', () => {
        expect(scheduleDateUrl({ href: 'https://trainer.example.test/schedule', syncUrl: true, previous: '2026-11-26', selected: '2026-11-28' }))
            .toBe('/schedule?date=2026-11-28');
        expect(scheduleDateUrl({ href: 'https://trainer.example.test/schedule?date=2026-11-26', syncUrl: true, previous: '2026-11-26', selected: '2026-12-03' }))
            .toBe('/schedule?date=2026-12-03');
    });

    it('URL の date：ほかのクエリと # はそのまま残す', () => {
        expect(scheduleDateUrl({ href: 'https://trainer.example.test/schedule?x=1&date=2026-11-26#top', syncUrl: true, previous: '2026-11-26', selected: '2026-11-28' }))
            .toBe('/schedule?x=1&date=2026-11-28#top');
    });

    it('URL の date：モーダル用（syncUrl が false）・選んでいる日が変わらない（前月・翌月）・正しくない日付は書き換えない', () => {
        const href = 'https://trainer.example.test/schedule?date=2026-11-26';
        expect(scheduleDateUrl({ href, syncUrl: false, previous: '2026-11-26', selected: '2026-11-28' })).toBeNull();
        expect(scheduleDateUrl({ href, syncUrl: true, previous: '2026-11-26', selected: '2026-11-26' })).toBeNull();
        expect(scheduleDateUrl({ href, syncUrl: true, previous: '2026-11-26', selected: '2026-02-30' })).toBeNull();
    });

    it('前月・翌月に移っても選んでいる日は変わらないので、URL の date も変えない', () => {
        const state = initialState('2026-11-26', '2026-11-21');
        const next = moveMonth(state, 1);
        expect(next).toEqual({ month: '2026-12', selected: '2026-11-26' });
        expect(scheduleDateUrl({ href: 'https://trainer.example.test/schedule?date=2026-11-26', syncUrl: true, previous: state.selected, selected: next.selected })).toBeNull();
    });
});

// カレンダー画面（S-1602）のその日の予約のタイムテーブル（2026-11 追加）
describe('タイムテーブル', () => {
    const u = (start, end, extra = {}) => ({ time_range: `${start.replace(/^0/, '')}〜${end.replace(/^0/, '')}`, start_time: start, end_time: end, label: 'まま（柏原 美江）', ...extra });

    it('時刻を 0 時からの分にする（正しくなければ null）', () => {
        expect(toMinutes('08:00')).toBe(480);
        expect(toMinutes('8:30')).toBe(510);
        expect(toMinutes('19:05')).toBe(1145);
        expect(toMinutes('24:00')).toBe(1440);
        for (const v of ['', null, undefined, '8', '08:60', '25:00', 'ab:cd', '08:00:00']) {
            expect(toMinutes(v)).toBeNull();
        }
    });

    it('時間の幅：営業時間だけの日は営業時間', () => {
        expect(timetableRange({ openTime: '08:00', closeTime: '19:00', usages: [u('08:00', '19:00'), u('13:00', '15:00')] })).toEqual({ start: 480, end: 1140 });
    });

    it('時間の幅：営業時間より前に始まる利用があれば、前に広げる', () => {
        expect(timetableRange({ openTime: '08:00', closeTime: '19:00', usages: [u('07:00', '09:00')] })).toEqual({ start: 420, end: 1140 });
    });

    it('時間の幅：営業時間より後に終わる利用があれば、後ろに広げる', () => {
        expect(timetableRange({ openTime: '08:00', closeTime: '19:00', usages: [u('19:00', '20:00')] })).toEqual({ start: 480, end: 1200 });
    });

    it('時間の幅：分単位の時刻は、左端を切り下げ、右端を切り上げる', () => {
        expect(timetableRange({ openTime: '08:00', closeTime: '19:00', usages: [u('07:30', '19:01')] })).toEqual({ start: 420, end: 1200 });
        // 営業時間そのものが 1 時間単位でないときも同じ
        expect(timetableRange({ openTime: '08:30', closeTime: '18:15', usages: [u('09:00', '10:00')] })).toEqual({ start: 480, end: 1140 });
    });

    it('時間の幅：営業時間が読めないときは、利用の時刻だけで決める', () => {
        expect(timetableRange({ openTime: '', closeTime: undefined, usages: [u('09:15', '10:45')] })).toEqual({ start: 540, end: 660 });
    });

    it('帯の位置と幅：開始・終了から、左端と幅の割合を出す', () => {
        const range = { start: 480, end: 1200 };   // 8:00〜20:00（12 時間）
        expect(timetablePosition(range, 480, 1140)).toEqual({ left: 0, width: (660 / 720) * 100 });
        expect(timetablePosition(range, 1140, 1200)).toEqual({ left: (660 / 720) * 100, width: (60 / 720) * 100 });
        expect(timetablePosition(range, 780, 810)).toEqual({ left: 300 / 720 * 100, width: 30 / 720 * 100 });   // 13:00〜13:30
        // 幅の外にはみ出す分は切る
        expect(timetablePosition(range, 400, 500)).toEqual({ left: 0, width: (20 / 720) * 100 });
    });

    it('営業時間の外：広げた部分と、1 時間単位でない端を灰色にする', () => {
        expect(timetableOutside({ start: 480, end: 1140 }, '08:00', '19:00')).toEqual([]);
        expect(timetableOutside({ start: 480, end: 1200 }, '08:00', '19:00')).toEqual([{ left: (660 / 720) * 100, width: (60 / 720) * 100 }]);
        expect(timetableOutside({ start: 420, end: 1140 }, '08:30', '19:00')).toEqual([{ left: 0, width: (90 / 720) * 100 }]);
        expect(timetableOutside({ start: 480, end: 1140 }, '', '19:00')).toEqual([]);
    });

    it('時の数字：1 時間あたり 28px 以上なら 1 時間ごと、狭ければ 2 時間ごと（線は 1 時間ごと）', () => {
        expect(timetableLabelStep(12 * 28, 12)).toBe(1);
        expect(timetableLabelStep(12 * 28 - 1, 12)).toBe(2);
        expect(timetableLabelStep(0, 12)).toBe(1);
        const hours = timetableHours({ start: 480, end: 720 }, 2);   // 8〜12
        expect(hours.map((h) => h.hour)).toEqual([8, 9, 10, 11, 12]);
        expect(hours.map((h) => h.left)).toEqual([0, 25, 50, 75, 100]);
        expect(hours.map((h) => h.labeled)).toEqual([true, false, true, false, true]);
        expect(timetableHours({ start: 480, end: 600 }).map((h) => h.labeled)).toEqual([true, true, true]);
    });

    it('帯の時刻：収まれば帯の中、収まらなければ右、右に余白がなければ左', () => {
        const base = { labelWidthPx: 60, trackWidthPx: 300 };
        expect(timetableLabelPlacement({ ...base, bandLeftPx: 0, bandWidthPx: 68 })).toBe('inside');
        expect(timetableLabelPlacement({ ...base, bandLeftPx: 0, bandWidthPx: 67 })).toBe('right');
        expect(timetableLabelPlacement({ ...base, bandLeftPx: 270, bandWidthPx: 30 })).toBe('left');
        // どちらにも収まらなければ右（文字は帯の中で切らない）
        expect(timetableLabelPlacement({ labelWidthPx: 60, trackWidthPx: 80, bandLeftPx: 30, bandWidthPx: 20 })).toBe('right');
    });

    it('左の列の名前：型があれば会員名だけリンク、なければ label のまま（時刻は付けない）', () => {
        const usage = u('08:00', '19:00', { client_id: 7, client_name: '柏原 美江', trainee_name: 'まま' });
        const T = '/clients/__CLIENT_ID__';
        // 省いてよいのはトレーニーの名前だけ。「（会員名）」はかっこごと必ず出す部分（リンクを残す。2026-11 変更）
        expect(usageNameGroups(usage, T)).toEqual({
            shrink: [{ text: 'まま' }],
            keep: [{ text: '（' }, { text: '柏原 美江', href: '/clients/7' }, { text: '）' }],
        });
        expect(usageNameGroups(usage)).toEqual({ shrink: [{ text: 'まま（柏原 美江）' }], keep: [] });
        // トレーニーがない利用は会員名のリンクだけ（かっこを付けない）。上限を超えるときだけ会員名を省く。型がなければ label（会員名だけ）
        expect(usageNameGroups({ ...usage, trainee_name: '', label: '柏原 美江' }, T)).toEqual({ shrink: [{ text: '柏原 美江', href: '/clients/7' }], keep: [] });
        expect(usageNameGroups({ ...usage, trainee_name: '', label: '柏原 美江' })).toEqual({ shrink: [{ text: '柏原 美江' }], keep: [] });
    });

    it('長いトレーニー名でも、会員名のリンクは省く部分に入らない。title に入れる全体の文は、読み上げと同じ文字', () => {
        const long = 'とてもとても長い名前のトレーニーさん二号';
        const groups = usageNameGroups(u('08:00', '12:00', { client_id: 4, client_name: '大内 佳和', trainee_name: long }), '/clients/__CLIENT_ID__');
        expect(groups.shrink.some((part) => part.href !== undefined)).toBe(false);
        expect(groups.keep).toContainEqual({ text: '大内 佳和', href: '/clients/4' });
        expect(usageNameText(groups)).toBe(`${long}（大内 佳和）`);
        expect(usageNameText(usageNameGroups({ client_id: 4, client_name: '中森', trainee_name: '', label: '中森' }, '/clients/__CLIENT_ID__'))).toBe('中森');
    });

    it('予約がない日はタイムテーブルを作らない（null）', () => {
        expect(timetableModel({ usages: [], openTime: '08:00', closeTime: '19:00' })).toBeNull();
        expect(timetableModel({ usages: undefined, openTime: '08:00', closeTime: '19:00' })).toBeNull();
    });

    it('1 日分：行は渡した順のまま、時間の幅・時間数・灰色・帯を組み立てる', () => {
        const model = timetableModel({
            usages: [u('08:00', '19:00', { client_id: 1, client_name: 'A', trainee_name: 'a' }), u('19:00', '20:00', { client_id: 2, client_name: 'B', trainee_name: '' })],
            openTime: '08:00', closeTime: '19:00', clientUrlTemplate: '/clients/__CLIENT_ID__',
        });
        expect(model.range).toEqual({ start: 480, end: 1200 });
        expect(model.hourCount).toBe(12);
        expect(model.outside).toEqual([{ left: (660 / 720) * 100, width: (60 / 720) * 100 }]);
        expect(model.rows.map((r) => r.timeRange)).toEqual(['8:00〜19:00', '19:00〜20:00']);
        expect(model.rows[1]).toMatchObject({ left: (660 / 720) * 100, width: (60 / 720) * 100 });
        expect(model.rows[0].name.keep).toEqual([{ text: '（' }, { text: 'A', href: '/clients/1' }, { text: '）' }]);
        expect(model.rows[1].name).toEqual({ shrink: [{ text: 'B', href: '/clients/2' }], keep: [] });
    });
});

// カレンダー画面（S-1602）のその日の予定の枠の最低の行数（2026-11 追加。日を押してもカレンダーが動かないように）
describe('その日の予定の枠の最低の行数', () => {
    it('同時に預かれる数（上限）の行数分', () => {
        expect(dayMinRows(3)).toBe(3);
        expect(dayMinRows(1)).toBe(1);
        expect(dayMinRows(10)).toBe(10);
        expect(dayMinRows('4')).toBe(4);
    });

    it('上限をまだ読んでいない・正しくないときは 1', () => {
        for (const v of [null, undefined, 0, -2, 2.5, 'abc', '']) {
            expect(dayMinRows(v)).toBe(1);
        }
    });
});

// カレンダー画面（S-1602）の 2 か月の並び（2026-11 追加）
describe('2 か月の並び', () => {
    it('並べる月は、選んだ日の月とその翌月（12 月なら翌年 1 月）', () => {
        expect(visibleMonths(initialState('2026-11-20', '2026-11-01').month, 2)).toEqual(['2026-11', '2026-12']);
        expect(visibleMonths(initialState('2026-12-31', '2026-11-01').month, 2)).toEqual(['2026-12', '2027-01']);
    });

    it('利用モーダル（1 か月）は、その月だけ', () => {
        expect(visibleMonths('2026-11')).toEqual(['2026-11']);
        expect(visibleMonths('2026-12', 1)).toEqual(['2026-12']);
    });

    it('月の送りは 2 か月とも 1 か月ずつ（年をまたぐときも）。選んだ日は変えない', () => {
        const state = initialState('2026-11-20', '2026-11-01');
        const next = moveMonth(state, 1);
        expect(visibleMonths(next.month, 2)).toEqual(['2026-12', '2027-01']);
        expect(next.selected).toBe('2026-11-20');
        expect(visibleMonths(moveMonth(next, 1).month, 2)).toEqual(['2027-01', '2027-02']);
        expect(visibleMonths(moveMonth(moveMonth(next, 1), -1).month, 2)).toEqual(['2026-12', '2027-01']);
        expect(visibleMonths(moveMonth(state, -1).month, 2)).toEqual(['2026-10', '2026-11']);
    });

    it('右の月の日を押しても、選んだ日だけが変わり、出している月は変わらない（年をまたぐときも）', () => {
        expect(selectDate({ month: '2026-11', selected: '2026-11-20' }, '2026-12-03', 2)).toEqual({ month: '2026-11', selected: '2026-12-03' });
        expect(selectDate({ month: '2026-12', selected: '2026-12-31' }, '2027-01-04', 2)).toEqual({ month: '2026-12', selected: '2027-01-04' });
        // 左の月の日も同じ
        expect(selectDate({ month: '2026-11', selected: '2026-12-03' }, '2026-11-05', 2)).toEqual({ month: '2026-11', selected: '2026-11-05' });
    });

    it('出していない月の日なら、その日の月に移る。1 か月（利用モーダル）は今までどおり、その日の月に移る', () => {
        expect(selectDate({ month: '2026-11', selected: '2026-11-20' }, '2027-03-01', 2)).toEqual({ month: '2027-03', selected: '2027-03-01' });
        expect(selectDate({ month: '2026-11', selected: '2026-11-20' }, '2026-12-03')).toEqual({ month: '2026-12', selected: '2026-12-03' });
    });

    it('右の月の日を押したときも、URL の date はその日に書き換わる', () => {
        const state = { month: '2026-11', selected: '2026-11-20' };
        const next = selectDate(state, '2026-12-03', 2);
        expect(scheduleDateUrl({ href: 'https://trainer.example.test/schedule?date=2026-11-20', syncUrl: true, previous: state.selected, selected: next.selected }))
            .toBe('/schedule?date=2026-12-03');
    });
});

// カレンダー画面（S-1602）の「Googleカレンダーに追加」のリンク（2026-11 追加。設計書 S-1602「Googleカレンダーに追加」）
describe('Googleカレンダーに追加', () => {
    const T = '/clients/__CLIENT_ID__';
    const URL_ = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=a';
    const base = { time_range: '8:00〜19:00', start_time: '08:00', end_time: '19:00', label: 'アムロ（佐藤 一郎）', client_id: 12, client_name: '佐藤 一郎', trainee_name: 'アムロ' };
    const upcoming = { ...base, google_calendar_url: URL_ };
    const past = { ...base, google_calendar_url: null };
    const noTrainee = { ...upcoming, label: '佐藤 一郎', trainee_name: '' };
    const link = { text: '', href: URL_, calendar: true };
    const model = (usage, calendarLinks) => timetableModel({ usages: [usage], openTime: '08:00', closeTime: '19:00', clientUrlTemplate: T, calendarLinks });

    it('リンクの title・aria-label の文（別タブで開くことが分かる）', () => {
        expect(GOOGLE_CALENDAR_LINK_LABEL).toBe('Googleカレンダーに追加（別タブで開きます）');
    });

    it('設定が true で URL があるときだけリンクを作る（URL はサーバーの値のまま）', () => {
        expect(googleCalendarParts(upcoming, true)).toEqual([link]);
        expect(googleCalendarParts(past, true)).toEqual([]);
        expect(googleCalendarParts(base, true)).toEqual([]);
        expect(googleCalendarParts(upcoming)).toEqual([]);
    });

    it('リストの行：URL があれば行の最後にリンク。URL がなければ出ない', () => {
        expect(usageLineParts(upcoming, T, true)).toEqual([...usageLineParts(past, T, true), link]);
        expect(usageLineParts(noTrainee, T, true).at(-1)).toEqual(link);
        expect(usageLineParts(past, T, true).some((part) => part.calendar)).toBe(false);
        // 行の文は変わらない
        expect(usageLineParts(upcoming, T, true).map((part) => part.text).join('')).toBe(usageLine(upcoming));
    });

    it('タイムテーブルの行：リンクは必ず全体を出す部分（keep）の最後。省く部分（shrink）には入らない', () => {
        for (const usage of [upcoming, noTrainee, { ...upcoming, client_id: null }]) {
            const { shrink, keep } = model(usage, true).rows[0].name;
            expect(keep.at(-1)).toEqual(link);
            expect(shrink.some((part) => part.calendar)).toBe(false);
        }
        expect(model(noTrainee, true).rows[0].name).toEqual({ shrink: [{ text: '佐藤 一郎', href: '/clients/12' }], keep: [link] });
        // 省いたときの title の文には入らない
        expect(usageNameText(model(upcoming, true).rows[0].name)).toBe('アムロ（佐藤 一郎）');
    });

    it('タイムテーブルの行：URL がなければ出ない', () => {
        const { shrink, keep } = model(past, true).rows[0].name;
        expect([...shrink, ...keep].some((part) => part.calendar)).toBe(false);
    });

    it('利用モーダル（設定なし）：URL があってもリンクを出さない', () => {
        expect(usageLineParts(upcoming)).toEqual([{ text: '8:00〜19:00　アムロ（佐藤 一郎）' }]);
        expect(model(upcoming).rows[0].name.keep.some((part) => part.calendar)).toBe(false);
    });
});
