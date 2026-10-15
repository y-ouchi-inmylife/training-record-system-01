import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FALLBACK_MS, setUpIssueForms } from '../../resources/js/issue-in-new-tab.js';

// Node で動かすため、document とフォームは EventTarget で作った最小の代わりを使う
const makeDoc = () => Object.assign(new EventTarget(), { visibilityState: 'visible' });

const makeForm = () => {
    const button = { disabled: false };
    const form = Object.assign(new EventTarget(), {
        querySelectorAll: (selector) => (selector === 'button[type="submit"]' ? [button] : []),
    });
    return { form, button };
};

const submit = (form) => form.dispatchEvent(new Event('submit'));

const setVisibility = (doc, state) => {
    doc.visibilityState = state;
    doc.dispatchEvent(new Event('visibilitychange'));
};

describe('発行のフォームを別タブで送ったあと、元のタブを開き直す', () => {
    let doc;
    let reload;

    beforeEach(() => {
        vi.useFakeTimers();
        doc = makeDoc();
        reload = vi.fn();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('送ったあと、そのフォームのボタンを押せなくする', () => {
        const { form, button } = makeForm();
        const other = makeForm();
        setUpIssueForms([form, other.form], { doc, reload });

        submit(form);

        expect(button.disabled).toBe(true);
        expect(other.button.disabled).toBe(false);
    });

    it('送ったあと、見えるようになったら 1 回だけ開き直す（hidden では開き直さない）', () => {
        const { form } = makeForm();
        setUpIssueForms([form], { doc, reload });

        submit(form);
        setVisibility(doc, 'hidden');
        expect(reload).not.toHaveBeenCalled();

        setVisibility(doc, 'visible');
        expect(reload).toHaveBeenCalledTimes(1);

        // 2 回目に見えるようになっても開き直さない
        setVisibility(doc, 'hidden');
        setVisibility(doc, 'visible');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('送っていなければ、見えるようになっても開き直さない', () => {
        const { form } = makeForm();
        setUpIssueForms([form], { doc, reload });

        setVisibility(doc, 'hidden');
        setVisibility(doc, 'visible');
        vi.advanceTimersByTime(FALLBACK_MS * 2);

        expect(reload).not.toHaveBeenCalled();
    });

    it('送ってから一定時間たっても見えたままなら開き直す', () => {
        const { form } = makeForm();
        setUpIssueForms([form], { doc, reload });

        submit(form);
        vi.advanceTimersByTime(FALLBACK_MS - 1);
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('一定時間たったときに見えていなければ開き直さず、戻ってきたときに 1 回だけ開き直す', () => {
        const { form } = makeForm();
        setUpIssueForms([form], { doc, reload });

        submit(form);
        setVisibility(doc, 'hidden');
        vi.advanceTimersByTime(FALLBACK_MS);
        expect(reload).not.toHaveBeenCalled();

        setVisibility(doc, 'visible');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('一定時間より前に戻ってきて開き直したら、時間がたっても 2 回目は開き直さない', () => {
        const { form } = makeForm();
        setUpIssueForms([form], { doc, reload });

        submit(form);
        setVisibility(doc, 'hidden');
        setVisibility(doc, 'visible');
        vi.advanceTimersByTime(FALLBACK_MS * 2);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('2 つのフォームを続けて送っても、開き直すのは 1 回だけ', () => {
        const first = makeForm();
        const second = makeForm();
        setUpIssueForms([first.form, second.form], { doc, reload });

        submit(first.form);
        submit(second.form);
        setVisibility(doc, 'hidden');
        setVisibility(doc, 'visible');
        vi.advanceTimersByTime(FALLBACK_MS * 2);

        expect(first.button.disabled).toBe(true);
        expect(second.button.disabled).toBe(true);
        expect(reload).toHaveBeenCalledTimes(1);
    });
});
