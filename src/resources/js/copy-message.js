/**
 * 「文面をコピー」ボタンを動かす（S-0307 マイページ登録案内・S-0311 受付アンケート案内で共用）。
 *
 * 使い方：ボタンに data-copy-message と data-copy-text="{文面}" を付ける。
 * ページの中に複数のボタンがあってもよい（それぞれが自分の data-copy-text をコピーする）。
 *
 * 文面はサーバー側（例：ClientEmailRegistrationTokenController::buildCopyText）で組み立て、
 * ボタンの data-copy-text 属性に載せる。JavaScript では画面上の文字を拾って組み立てず、
 * この属性値をそのままクリップボードに書き込む（画面の見た目と文面を独立させるため。
 * screen-design.md S-0307「案内文面のコピー」参照）。
 *
 * 押した結果が分かるように、成功時／失敗時ともボタンのラベルを一時的に
 * 変える。何も起きない状態にはしない（コピーできたと思って貼り付けに
 * 失敗するのを防ぐため）。
 *
 * 経緯：2026-10 に、マイページ登録案内専用の copy-registration-message.js
 * （data-copy-registration-message のボタンを 1 つだけ拾う作り）から、名前と属性を変えて共用にした。
 */
const setUpCopyButton = (button) => {
    const originalLabel = button.textContent;
    let resetTimer = null;

    // 押下後、指定ラベルへ一時的に切り替え、2 秒後に元に戻す。
    // 連打時は毎回タイマーをリセットする（表示がずれないように）。
    const flashLabel = (label) => {
        button.textContent = label;
        if (resetTimer) {
            window.clearTimeout(resetTimer);
        }
        resetTimer = window.setTimeout(() => {
            button.textContent = originalLabel;
            resetTimer = null;
        }, 2000);
    };

    button.addEventListener('click', async () => {
        const text = button.dataset.copyText ?? '';
        try {
            // navigator.clipboard は HTTPS もしくは localhost、かつユーザー操作起点でのみ動作する。
            // 使えない場合は失敗扱いにする（URL 文字列がページ上に残っているので、
            // トレーナーは手作業でコピーして貼り付けられる）。
            if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                throw new Error('clipboard API not available');
            }
            await navigator.clipboard.writeText(text);
            flashLabel('コピーしました');
        } catch (err) {
            console.error('文面のコピーに失敗しました:', err);
            flashLabel('コピーできませんでした');
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-copy-message]').forEach(setUpCopyButton);
});
