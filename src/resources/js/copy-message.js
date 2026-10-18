/**
 * 文面のコピーのボタンを動かす（S-0307 マイページ登録・S-0311 受付アンケート・S-0312 愛犬性格診断のご案内で共用。
 * 2026-10-18 変更：ボタンの名前は［文面をコピー］から［コピー］にした。読み上げは aria-label の「文面をコピー」）。
 *
 * 使い方：ボタンに data-copy-message と data-copy-text="{文面}" を付け、ボタンと知らせの場所（data-copy-status。
 * aria-live="polite"）を同じ data-copy-area の中に置く（invitations/_panels.blade.php）。
 *
 * 文面はサーバー側（App\Support\InvitationMessage）で組み立て、ボタンの data-copy-text 属性に載せる。
 * JavaScript では画面上の文字を拾って組み立てず、この属性値をそのままクリップボードに書き込む。
 *
 * 押したときの動き（知らせをボタンの左に出し、数秒で消す。ボタンの文字は変えない）は copy-message-core.js。
 * 2026-10-18 変更：その前はボタンの文字を「コピーしました」に変え、幅が変わらないようボタンに最低の幅を付けていた。
 *
 * 経緯：2026-10 に、マイページ登録案内専用の copy-registration-message.js
 * （data-copy-registration-message のボタンを 1 つだけ拾う作り）から、名前と属性を変えて共用にした。
 */
import { setUpCopyButton } from './copy-message-core.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-copy-message]').forEach((button) => {
        const status = button.closest('[data-copy-area]')?.querySelector('[data-copy-status]');
        if (!status) {
            console.error('コピーの知らせの場所（data-copy-status）が見つかりません');
            return;
        }
        setUpCopyButton(button, status, {
            clipboard: navigator.clipboard,
            setTimer: (fn, ms) => window.setTimeout(fn, ms),
            clearTimer: (id) => window.clearTimeout(id),
            logError: (...args) => console.error(...args),
        });
    });
});
