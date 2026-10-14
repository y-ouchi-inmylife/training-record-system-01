import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    INPUT_HINTS,
    STATE,
    ageLabel,
    birthdayInputDisplay,
    birthdayInputState,
    daysInMonth,
    normalize,
    todayInTokyo,
} from '../../resources/js/trainee-age.js';

// PHP のテストと同じ固定データを読む（tests/fixtures/）
const fixturesDir = resolve(dirname(fileURLToPath(import.meta.url)), '../fixtures');
const readCases = (file) => JSON.parse(readFileSync(resolve(fixturesDir, file), 'utf8')).cases;
const parseRef = (ref) => ref.split('-').map((part) => parseInt(part, 10));

describe('年齢の表示（tests/fixtures/trainee-age-cases.json。PHP の TraineeAge と同じ結果）', () => {
    for (const c of readCases('trainee-age-cases.json')) {
        it(c.label, () => {
            const [refYear, refMonth, refDay] = parseRef(c.ref);
            expect(ageLabel(c.year, c.month, c.day, refYear, refMonth, refDay)).toBe(c.age);
        });
    }
});

describe('入力の判定（tests/fixtures/trainee-birthday-input-cases.json。PHP の TraineeBirthdayInput と同じ状態）', () => {
    for (const c of readCases('trainee-birthday-input-cases.json')) {
        it(c.label, () => {
            const [refYear, refMonth, refDay] = parseRef(c.ref);
            expect(birthdayInputState(c.year, c.month, c.day, refYear, refMonth, refDay)).toBe(c.state);
        });
    }
});

describe('状態の記号 → 入力中の案内（要件定義書 6-16「入力中の表示」の表）', () => {
    it('対応表', () => {
        expect(INPUT_HINTS).toEqual({
            not_numeric: '数字で入力してください',
            month_out_of_range: '日付を確認してください',
            month_without_year: '年も入力してください',
            day_without_year: '年も入力してください',
            day_without_month: '月も入力してください',
            invalid_date: '日付を確認してください',
            future: '未来の日付になっています',
            too_old: '年を確認してください',
        });
    });

    it('ok と empty 以外のすべての状態に案内がある', () => {
        const states = Object.values(STATE).filter((s) => s !== STATE.OK && s !== STATE.EMPTY);
        expect(Object.keys(INPUT_HINTS).sort()).toEqual([...states].sort());
    });

    it('入力中の表示：ok は年齢、empty は何も出さず、それ以外は案内', () => {
        expect(birthdayInputDisplay('2020', '3', '15', 2026, 10, 20)).toEqual({ state: 'ok', text: '6歳7ヶ月' });
        expect(birthdayInputDisplay('２０２０', '０３', '', 2026, 10, 20)).toEqual({ state: 'ok', text: '6歳7ヶ月' });
        expect(birthdayInputDisplay('2020', null, null, 2026, 10, 20)).toEqual({ state: 'ok', text: '約6歳' });
        expect(birthdayInputDisplay('', '', '', 2026, 10, 20)).toEqual({ state: 'empty', text: '' });
        expect(birthdayInputDisplay('', '3', '', 2026, 10, 20)).toEqual({ state: 'month_without_year', text: '年も入力してください' });
        expect(birthdayInputDisplay('2020', '', '15', 2026, 10, 20)).toEqual({ state: 'day_without_month', text: '月も入力してください' });
        expect(birthdayInputDisplay('2027', '', '', 2026, 10, 20)).toEqual({ state: 'future', text: '未来の日付になっています' });
    });
});

describe('欄の値を直す（PHP の TraineeBirthdayInput::normalize と同じ）', () => {
    it('全角の数字を半角に、前後の空白（全角を含む）を取る', () => {
        expect(normalize('２０２０')).toBe('2020');
        expect(normalize('　０３ ')).toBe('03');
        expect(normalize(' 2020\t')).toBe('2020');
        expect(normalize('20２0')).toBe('2020');
    });

    it('空・空白だけは null', () => {
        expect(normalize('')).toBeNull();
        expect(normalize('　 ')).toBeNull();
        expect(normalize(null)).toBeNull();
        expect(normalize(undefined)).toBeNull();
    });

    it('数字以外の文字や間の空白はそのまま残す（判定で「数字でない」になる）', () => {
        expect(normalize('２０２０年')).toBe('2020年');
        expect(normalize('20 20')).toBe('20 20');
    });
});

describe('その月の日数', () => {
    it('うるう年の 2 月は 29 日', () => {
        expect(daysInMonth(2024, 2)).toBe(29);
        expect(daysInMonth(2026, 2)).toBe(28);
        expect(daysInMonth(2100, 2)).toBe(28);
        expect(daysInMonth(2000, 2)).toBe(29);
        expect(daysInMonth(2026, 4)).toBe(30);
        expect(daysInMonth(2026, 12)).toBe(31);
    });
});

describe('基準日（日本時間の今日）', () => {
    it('UTC の 15 時以降は、日本時間では翌日', () => {
        expect(todayInTokyo(new Date('2026-10-19T15:00:00Z'))).toEqual({ year: 2026, month: 10, day: 20 });
        expect(todayInTokyo(new Date('2026-10-19T14:59:59Z'))).toEqual({ year: 2026, month: 10, day: 19 });
    });
});
