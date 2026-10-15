/**
 * 案内の画面（S-0307 マイページ登録案内・S-0311 受付アンケート案内）の［閉じる］を動かす（2026-10 追加）。
 *
 * 使い方：会員詳細へのリンク（<a href="…">）に data-close-tab を付ける。
 * - 押したら、リンクの移動を止めて window.close() でタブを閉じる。案内の画面は会員詳細から別タブで開く
 *   （target="_blank"。履歴が 1 つのタブ）ので、ブラウザはスクリプトで閉じることを許す
 * - 閉じられなかったとき（URL を直接開いた・ブックマークから開いた・ブラウザが許さないなど）に備え、
 *   FALLBACK_MS たってもタブが残っていれば、そのタブでリンク先（会員詳細）を開く。
 *   これで、どの開き方でも押して何も起きないことはない
 * - スクリプトが動かなければ、ふつうのリンクとして会員詳細を開く
 *
 * 設計書 screen-design.md S-0307 備考「操作エリアのまとめ方」。
 * テスト（tests/js/close-tab.test.js）は Node で動かすため、window・タイマーを引数で受け取る作りにしている。
 */

/** 閉じられずにタブが残っていたら、リンク先を開くまでの時間（ミリ秒） */
export const FALLBACK_MS = 500;

/**
 * @param {Iterable<HTMLAnchorElement>} links data-close-tab を付けたリンク
 * @param {object} env
 * @param {Window} env.win close()・closed・location を使う window
 * @param {(fn: () => void, ms: number) => unknown} [env.setTimer]
 * @param {number} [env.fallbackMs]
 */
export function setUpCloseLinks(links, {
    win,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    fallbackMs = FALLBACK_MS,
}) {
    for (const link of links) {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            win.close();
            // 閉じられたタブではここに来ない。残っていればリンク先を開く
            setTimer(() => {
                if (!win.closed) {
                    win.location.href = link.href;
                }
            }, fallbackMs);
        });
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        setUpCloseLinks(document.querySelectorAll('a[data-close-tab]'), { win: window });
    });
}
