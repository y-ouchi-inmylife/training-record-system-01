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
    cellCountText,
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
    it('預かる数と上限の文は「n/上限」（0 も「0/上限」）', () => {
        expect(countText(2, 3)).toBe('2/3');
        // 2026-11 変更：0 も「0/3」（その前は空）
        expect(countText(0, 3)).toBe('0/3');
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
        expect(dayHeading('2026-11-26', 2, 3)).toBe('11/26(木) の予約　2/3');
        expect(dayHeading('2026-11-26', 4, 3)).toBe('11/26(木) の予約　4/3');
        expect(dayHeading('2026-11-26', 4, 3)).not.toContain('頭');
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

    it('空きありの「n/上限」は通常の色の太字で、バッジにしない', () => {
        expect(cell(CAPACITY_AVAILABLE, false, false).count).toEqual(['d-block', 'small', 'usage-schedule-count']);
        expect(cell(CAPACITY_AVAILABLE, false, false).countBadge).toEqual([]);
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

    it('バッジのクラスは満（badge bg-success）・超過（badge bg-danger）だけ（カレンダーと見出しで同じ判定）', () => {
        expect(countBadgeClasses(CAPACITY_FULL)).toEqual(['badge', 'bg-success', 'usage-schedule-count-badge']);
        expect(countBadgeClasses(CAPACITY_OVER)).toEqual(['badge', 'bg-danger', 'usage-schedule-count-badge']);
        expect(countBadgeClasses(CAPACITY_AVAILABLE)).toEqual([]);
        expect(countBadgeClasses(null)).toEqual([]);
    });

    it.each([
        [3, 3, ['badge', 'bg-success', 'usage-schedule-count-badge']],
        [4, 3, ['badge', 'bg-danger', 'usage-schedule-count-badge']],
        [1, 3, []],
        [0, 3, []],
    ])('見出し：預かる数 %i・上限 %i の「n/上限」のバッジのクラスは %j', (count, capacity, classes) => {
        const parts = dayHeadingParts('2026-11-20', count, capacity);
        expect(parts.label).toBe('11/20(金) の予約　');
        expect(parts.count).toBe(`${count}/${capacity}`);
        expect(parts.countClasses).toEqual(classes);
        // 見出し全体の文はそのまま
        expect(dayHeading('2026-11-20', count, capacity)).toBe(`11/20(金) の予約　${count}/${capacity}`);
    });

    it('見出しのバッジのクラスは、カレンダーの同じ日の「n/上限」のバッジのクラスと同じ', () => {
        for (const [count, capacity] of [[0, 3], [1, 3], [3, 3], [4, 3]]) {
            const cell = dayCellClasses({ state: capacityState(count, capacity), selected: false, today: false });
            const heading = dayHeadingParts('2026-11-20', count, capacity);
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

    it('予約がない日にも「0/上限」が出て、空きあり（色なし・太字）になる', () => {
        expect(cellCountText({ count: 0, capacity: 3, loaded: true })).toBe('0/3');
        expect(capacityState(0, 3)).toBe(CAPACITY_AVAILABLE);
        expect(cell({ state: capacityState(0, 3) }).count).toEqual(['d-block', 'small', 'usage-schedule-count']);
        expect(cellCountText({ count: 2, capacity: 3, loaded: true })).toBe('2/3');
    });

    it('月の預かる数を読み込む前は「n/上限」を出さない（上限を知っていても）', () => {
        expect(cellCountText({ count: 0, capacity: 3, loaded: false })).toBe('');
        expect(cellCountText({ count: 2, capacity: 3, loaded: false })).toBe('');
        expect(cellCountText({ count: 0, capacity: null, loaded: true })).toBe('');
    });
});

