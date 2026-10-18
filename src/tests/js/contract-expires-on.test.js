import { describe, expect, it } from 'vitest';
import { endOfMonth, followExpiresOn, isFollowingContractedOn } from '../../resources/js/contract-expires-on.js';

// 契約の登録のモーダルの有効期限の初期値（契約日の月の最終日。2026-10-18 追加）
describe('endOfMonth', () => {
    it.each([
        ['2026-10-18', '2026-10-31'],
        ['2026-10-01', '2026-10-31'],
        ['2026-11-15', '2026-11-30'],
        ['2026-02-10', '2026-02-28'],
        ['2028-02-10', '2028-02-29'],
        ['2100-02-01', '2100-02-28'],
        ['2000-02-01', '2000-02-29'],
        ['2026-12-31', '2026-12-31'],
        ['2026-12-01', '2026-12-31'],
        ['2026-01-31', '2026-01-31'],
    ])('%s の月の最終日は %s', (ymd, expected) => {
        expect(endOfMonth(ymd)).toBe(expected);
    });

    it.each(['', null, undefined, '2026-10-1', '2026/10/18', '2026-13-01', '2026-00-10', '2026-02-30', '2026-10-32', 'abc'])(
        '形が違う・存在しない日付（%s）は null',
        (ymd) => {
            expect(endOfMonth(ymd)).toBeNull();
        },
    );
});

describe('followExpiresOn', () => {
    it('触っていなければ、変えた契約日の月の最終日に合わせる', () => {
        expect(followExpiresOn('2026-11-05', '2026-10-31', false)).toBe('2026-11-30');
        expect(followExpiresOn('2028-02-01', '2026-10-31', false)).toBe('2028-02-29');
    });

    it('触ったあとは変えない（直した値・空のまま）', () => {
        expect(followExpiresOn('2026-11-05', '2026-12-15', true)).toBe('2026-12-15');
        expect(followExpiresOn('2026-11-05', '', true)).toBe('');
    });

    it('契約日が入力の途中・正しくないときは今の値のまま', () => {
        expect(followExpiresOn('2026-11-0', '2026-10-31', false)).toBe('2026-10-31');
        expect(followExpiresOn('', '2026-10-31', false)).toBe('2026-10-31');
    });
});

describe('isFollowingContractedOn', () => {
    it('有効期限が契約日の月の最終日なら、触っていないとみなす', () => {
        expect(isFollowingContractedOn('2026-10-18', '2026-10-31')).toBe(true);
    });

    it('空・違う日・契約日が正しくないときは、触ったとみなす', () => {
        expect(isFollowingContractedOn('2026-10-18', '')).toBe(false);
        expect(isFollowingContractedOn('2026-10-18', '2026-12-15')).toBe(false);
        expect(isFollowingContractedOn('', '')).toBe(false);
    });
});
