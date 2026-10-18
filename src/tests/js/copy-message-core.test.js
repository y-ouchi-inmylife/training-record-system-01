import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { COPIED_LABEL, FAILED_LABEL, NOTICE_DURATION_MS, setUpCopyButton } from '../../resources/js/copy-message-core.js';

// ご案内の画面（S-0307・S-0311・S-0312）の［コピー］の動き（2026-10-18 追加）。
// 押すとボタンの左の知らせの場所に「コピーしました」を出し、数秒で消す。ボタンの文字は変えない。
// DOM のない環境で動かすため、ボタンと知らせの場所は必要なところだけを持つ作り物にする。

const fakeClassList = () => {
    const set = new Set();
    return {
        toggle: (name, on) => (on ? set.add(name) : set.delete(name)),
        remove: (...names) => names.forEach((name) => set.delete(name)),
        contains: (name) => set.has(name),
        get size() {
            return set.size;
        },
    };
};

const fakeButton = (text) => {
    const handlers = [];
    return {
        textContent: 'コピー',
        dataset: { copyText: text },
        style: {},
        addEventListener: (type, fn) => handlers.push([type, fn]),
        handlers,
    };
};

const fakeStatus = () => ({ textContent: '', classList: fakeClassList() });

const setUp = (clipboard) => {
    const button = fakeButton('山田 太郎 様\n\nご案内です。');
    const status = fakeStatus();
    const logError = vi.fn();
    const { copy } = setUpCopyButton(button, status, {
        clipboard,
        setTimer: (fn, ms) => setTimeout(fn, ms),
        clearTimer: (id) => clearTimeout(id),
        logError,
    });
    return { button, status, copy, logError };
};

describe('［コピー］を押したとき', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('押すとボタンのクリックに動きを付ける', () => {
        const { button } = setUp({ writeText: vi.fn() });
        expect(button.handlers.map(([type]) => type)).toEqual(['click']);
    });

    it('コピーできたら知らせに「コピーしました」（緑）を出し、ボタンの文字・幅は変えない', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        const { button, status, copy } = setUp({ writeText });

        await copy();

        expect(writeText).toHaveBeenCalledWith('山田 太郎 様\n\nご案内です。');
        expect(status.textContent).toBe(COPIED_LABEL);
        expect(status.classList.contains('text-success')).toBe(true);
        expect(status.classList.contains('text-danger')).toBe(false);
        expect(button.textContent).toBe('コピー');
        expect(button.style).toEqual({});
    });

    it('数秒（NOTICE_DURATION_MS）で知らせを消す', async () => {
        const { status, copy } = setUp({ writeText: vi.fn().mockResolvedValue(undefined) });

        await copy();
        vi.advanceTimersByTime(NOTICE_DURATION_MS - 1);
        expect(status.textContent).toBe(COPIED_LABEL);
        vi.advanceTimersByTime(1);
        expect(status.textContent).toBe('');
        expect(status.classList.size).toBe(0);
        expect(NOTICE_DURATION_MS).toBe(3000);
    });

    it('続けて押したら、あとから押したときから数えて消す', async () => {
        const { status, copy } = setUp({ writeText: vi.fn().mockResolvedValue(undefined) });

        await copy();
        vi.advanceTimersByTime(2000);
        await copy();
        vi.advanceTimersByTime(2000);
        expect(status.textContent).toBe(COPIED_LABEL);
        vi.advanceTimersByTime(1000);
        expect(status.textContent).toBe('');
    });

    it('コピーできなかったら同じ場所に「コピーできませんでした」（赤）を出し、ボタンの文字は変えない', async () => {
        const { button, status, copy, logError } = setUp({ writeText: vi.fn().mockRejectedValue(new Error('denied')) });

        await copy();

        expect(status.textContent).toBe(FAILED_LABEL);
        expect(status.classList.contains('text-danger')).toBe(true);
        expect(status.classList.contains('text-success')).toBe(false);
        expect(button.textContent).toBe('コピー');
        expect(logError).toHaveBeenCalled();
        vi.advanceTimersByTime(NOTICE_DURATION_MS);
        expect(status.textContent).toBe('');
    });

    it('クリップボードが使えない（http など）ときも「コピーできませんでした」を出す', async () => {
        const { status, copy } = setUp(undefined);

        await copy();

        expect(status.textContent).toBe(FAILED_LABEL);
    });

    it('失敗のあとに成功したら、緑の「コピーしました」に変わる', async () => {
        const writeText = vi.fn().mockRejectedValueOnce(new Error('denied')).mockResolvedValueOnce(undefined);
        const { status, copy } = setUp({ writeText });

        await copy();
        await copy();

        expect(status.textContent).toBe(COPIED_LABEL);
        expect(status.classList.contains('text-success')).toBe(true);
        expect(status.classList.contains('text-danger')).toBe(false);
    });
});
