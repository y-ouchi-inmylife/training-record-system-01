import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FALLBACK_MS, setUpCloseLinks } from '../../resources/js/close-tab.js';

const DETAIL_URL = 'https://trs01-dev.test/clients/1';

// Node で動かすため、リンクと window は最小の代わりを使う
const makeLink = () => Object.assign(new EventTarget(), { href: DETAIL_URL });

const makeWin = ({ closes }) => {
    const win = {
        closed: false,
        location: { href: 'https://trs01-dev.test/clients/1/email-registration-tokens/print' },
        close: vi.fn(() => {
            if (closes) {
                win.closed = true;
            }
        }),
    };
    return win;
};

const click = (link) => {
    const event = new Event('click', { cancelable: true });
    link.dispatchEvent(event);
    return event;
};

describe('案内の画面の［閉じる］', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('押すと window.close() を呼び、リンクの移動はすぐにはしない', () => {
        const link = makeLink();
        const win = makeWin({ closes: false });
        setUpCloseLinks([link], { win });

        const event = click(link);

        expect(win.close).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
        expect(win.location.href).not.toBe(DETAIL_URL);
    });

    it('閉じられずに残っていれば、待ち時間のあとに会員詳細を開く', () => {
        const link = makeLink();
        const win = makeWin({ closes: false });
        setUpCloseLinks([link], { win });

        click(link);
        vi.advanceTimersByTime(FALLBACK_MS - 1);
        expect(win.location.href).not.toBe(DETAIL_URL);

        vi.advanceTimersByTime(1);
        expect(win.location.href).toBe(DETAIL_URL);
    });

    it('閉じられたら、会員詳細は開かない', () => {
        const link = makeLink();
        const win = makeWin({ closes: true });
        setUpCloseLinks([link], { win });

        click(link);
        vi.advanceTimersByTime(FALLBACK_MS * 2);

        expect(win.close).toHaveBeenCalledTimes(1);
        expect(win.location.href).not.toBe(DETAIL_URL);
    });
});
