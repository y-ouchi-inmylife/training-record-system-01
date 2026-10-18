/**
 * 文面のコピーのボタンの動き（DOM を探す部分を除いたもの。copy-message.js から使う。2026-10-18 追加）。
 * ボタン・知らせの場所・クリップボード・タイマーを外から受け取るので、DOM のない JS のテスト（tests/js）でも確かめられる。
 *
 * 押した結果は、ボタンの左の知らせの場所（aria-live="polite"）に小さく出し、数秒で消す。ボタンの文字（［コピー］）は変えない
 * （2026-10-18 変更：2026-10-18 のユーザーレビューで、「コピーしました」に変わってもずれないよう付けていたボタンの最低の幅が
 * ［コピー］の文字に比べて大きすぎたため。その前はボタンの文字を約 2 秒「コピーしました」「コピーできませんでした」に変えていた）。
 * 成功のときも失敗のときも知らせを出し、何も起きない状態にはしない（コピーできたと思って貼り付けに失敗するのを防ぐため）。
 */
export const COPIED_LABEL = 'コピーしました';
export const FAILED_LABEL = 'コピーできませんでした';
// 知らせを出しておく長さ（ミリ秒）
export const NOTICE_DURATION_MS = 3000;

const NOTICE_CLASSES = { success: 'text-success', failure: 'text-danger' };

/**
 * ボタンを押したときに、data-copy-text の文面をコピーして知らせを出すようにする。
 * @param {object} button ［コピー］のボタン（data-copy-text を持つ）
 * @param {object} status 知らせの場所（文字と色だけを変える。場所の大きさは CSS で先に取ってある）
 * @param {object} deps clipboard（navigator.clipboard）、setTimer・clearTimer（window.setTimeout・clearTimeout）、logError
 */
export const setUpCopyButton = (button, status, deps) => {
    const { clipboard, setTimer, clearTimer, logError = () => {} } = deps;
    let clearTimerId = null;

    // 知らせを出し、NOTICE_DURATION_MS のあとに消す。続けて押したときはタイマーを付け直す（前の知らせが途中で消えないように）
    const showNotice = (kind) => {
        status.textContent = kind === 'success' ? COPIED_LABEL : FAILED_LABEL;
        status.classList.toggle(NOTICE_CLASSES.success, kind === 'success');
        status.classList.toggle(NOTICE_CLASSES.failure, kind !== 'success');
        if (clearTimerId !== null) {
            clearTimer(clearTimerId);
        }
        clearTimerId = setTimer(() => {
            status.textContent = '';
            status.classList.remove(NOTICE_CLASSES.success, NOTICE_CLASSES.failure);
            clearTimerId = null;
        }, NOTICE_DURATION_MS);
    };

    const copy = async () => {
        const text = button.dataset.copyText ?? '';
        try {
            // navigator.clipboard は HTTPS もしくは localhost、かつユーザー操作起点でのみ動作する。
            // 使えない場合は失敗扱いにする（文面は画面に出ているので、トレーナーは手作業でコピーして貼り付けられる）。
            if (!clipboard || typeof clipboard.writeText !== 'function') {
                throw new Error('clipboard API not available');
            }
            await clipboard.writeText(text);
            showNotice('success');
        } catch (err) {
            logError('文面のコピーに失敗しました:', err);
            showNotice('failure');
        }
    };

    button.addEventListener('click', copy);

    return { copy };
};
