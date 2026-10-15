/**
 * 会員詳細（S-0305）の［マイページ登録の案内を発行］［受付アンケートの案内を発行］のフォームを、
 * 別タブで送ったあとに、元のタブの会員詳細を新しい状態に開き直す（2026-10 追加）。
 *
 * 使い方：発行のフォームに target="_blank" と data-issue-in-new-tab を付ける。
 * 発行のリクエストは新しいタブで送られ、サーバーがそのタブで案内の画面（S-0307・S-0311）に
 * リダイレクトする。新しいタブはトレーナーのクリックそのもので開くので、ポップアップブロックにかからない
 * （requirements.md 6-3-6 備考「発行のあとに案内の画面を開く理由」）。
 *
 * 元のタブの動き：
 * - 送ったら、そのフォームの発行のボタンを押せなくする（二重に押さないため。開き直すと元に戻る）
 * - トレーナーが元のタブに戻ってきたとき（visibilitychange で visible になったとき）に 1 回だけ開き直す。
 *   送った直後に開き直すと、新しいタブの発行がまだ終わっておらず古い状態のままになることがあるため、
 *   戻ってきたときにする
 * - 送ってから FALLBACK_MS たっても元のタブが見えたまま（新しいタブが開かなかったなど）なら、そこで開き直す。
 *   その時点で見えていなければ何もせず、戻ってきたときに開き直す
 *
 * テスト（tests/js/issue-in-new-tab.test.js）は Node で動かすため、document・再読み込み・タイマーを
 * 引数で受け取る作りにしている。
 */

/** 送ってから、元のタブが見えたままなら開き直すまでの時間（ミリ秒） */
export const FALLBACK_MS = 3000;

/**
 * @param {Iterable<HTMLFormElement>} forms 発行のフォーム（data-issue-in-new-tab を付けたもの）
 * @param {object} env
 * @param {Document} env.doc visibilityState と visibilitychange を見る document
 * @param {() => void} env.reload 開き直す処理（ブラウザでは location.reload()）
 * @param {(fn: () => void, ms: number) => unknown} [env.setTimer]
 * @param {(id: unknown) => void} [env.clearTimer]
 * @param {number} [env.fallbackMs]
 */
export function setUpIssueForms(forms, {
    doc,
    reload,
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
    fallbackMs = FALLBACK_MS,
}) {
    let submitted = false;
    let reloaded = false;
    let timer = null;

    // 開き直すのは 1 回だけ
    const reloadOnce = () => {
        if (reloaded) {
            return;
        }
        reloaded = true;
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
        reload();
    };

    doc.addEventListener('visibilitychange', () => {
        if (submitted && doc.visibilityState === 'visible') {
            reloadOnce();
        }
    });

    for (const form of forms) {
        form.addEventListener('submit', () => {
            // 送信は止めない（ボタンを押せなくするだけ）
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
            });
            if (submitted) {
                return;
            }
            submitted = true;
            timer = setTimer(() => {
                timer = null;
                if (doc.visibilityState === 'visible') {
                    reloadOnce();
                }
            }, fallbackMs);
        });
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        const forms = document.querySelectorAll('form[data-issue-in-new-tab]');
        if (forms.length === 0) {
            return;
        }
        setUpIssueForms(forms, { doc: document, reload: () => window.location.reload() });
    });
}
