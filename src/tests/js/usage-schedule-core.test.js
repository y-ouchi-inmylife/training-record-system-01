import { describe, expect, it } from 'vitest';
import {
    CAPACITY_AVAILABLE,
    CAPACITY_FULL,
    CAPACITY_OVER,
    applyInput,
    capacityState,
    countText,
    dayCellClasses,
    dayHeading,
    dayLabel,
    initialState,
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
    it('預かる数が 1 以上なら「n/上限」、0 なら空', () => {
        expect(countText(2, 3)).toBe('2/3');
        expect(countText(0, 3)).toBe('');
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

    it.each([
        [CAPACITY_FULL, 'usage-schedule-count-full'],
        [CAPACITY_OVER, 'usage-schedule-count-over'],
    ])('%s のマスに背景の色のクラスは付かず、「n/上限」に文字の色のクラスが付く', (state, textColor) => {
        const { button, day, count } = cell(state, false, false);
        BG_COLORS.forEach((c) => expect(button).not.toContain(c));
        expect(button).toContain('btn-light');
        expect(count).toEqual(['d-block', 'small', 'usage-schedule-count', textColor]);
        expect(day).toEqual(['d-block']);
    });

    it('空きありの「n/上限」は通常の文字の色', () => {
        expect(cell(CAPACITY_AVAILABLE, false, false).count).toEqual(['d-block', 'small', 'usage-schedule-count']);
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
        [CAPACITY_FULL, 'usage-schedule-count-full'],
        [CAPACITY_OVER, 'usage-schedule-count-over'],
    ])('今日が %s のとき、薄い青の背景と緑・赤の文字が両方付く', (state, textColor) => {
        const { button, count } = cell(state, false, true);
        expect(button).toContain('bg-primary-subtle');
        expect(count).toContain(textColor);
        BG_COLORS.forEach((c) => expect(button).not.toContain(c));
    });

    it('今日を選んでいるとき、薄い青の背景と枠が両方付く', () => {
        const { button } = cell(CAPACITY_OVER, true, true);
        expect(button).toEqual(expect.arrayContaining(['bg-primary-subtle', 'usage-schedule-selected']));
        expect(button).not.toContain('btn-primary');
    });

    it('選んでいる日は枠だけ（背景はその日のまま。塗りつぶしにならない）', () => {
        const { button, count } = cell(CAPACITY_FULL, true, false);
        expect(button).toEqual(expect.arrayContaining(['btn-light', 'usage-schedule-selected']));
        expect(button).not.toContain('btn-primary');
        expect(count).toContain('usage-schedule-count-full');
    });

    it('上限をまだ読んでいない間は、空きありと同じ', () => {
        expect(cell(null, false, false)).toEqual({
            button: ['btn', 'btn-sm', 'w-100', 'px-0', 'py-1', 'lh-sm', 'usage-schedule-day', 'btn-light'],
            day: ['d-block'],
            count: ['d-block', 'small', 'usage-schedule-count'],
        });
    });
});
