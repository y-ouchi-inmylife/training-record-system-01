/**
 * 非同期の保存（fetch）の入力エラー・入力エラー以外の失敗の表示を担う小さなユーティリティ
 *
 * 設計書 §2-7「非同期の保存（fetch）」の規約に沿って、
 *   - 入力エラー（HTTP 422 の errors）→ 欄の下に invalid-feedback で出す
 *   - 入力エラー以外の失敗（409・500・通信の失敗など）→ まとまりの上部に alert-danger で出す
 * ことを 4 つの関数にまとめる。インラインの script から呼べるよう window.FormErrors に載せる。
 *
 * 使い方:
 *   // 入力エラーを欄の下に出す（既存の表示は置き換え）
 *   FormErrors.showFieldErrors(panel, { title: ['表示名を入力してください。'], transcription_text: ['...'] });
 *   // 欄の赤枠と欄下文言を消す
 *   FormErrors.clearFieldErrors(panel);
 *   // 入力エラー以外の失敗をまとまりの上部に出す（既存の表示は置き換え）
 *   FormErrors.showFormMessage(panel, '処理中の音声記録は編集できません。…');
 *   // 入力エラー以外の失敗の表示を消す
 *   FormErrors.clearFormMessage(panel);
 *
 * 文言は textContent で入れるため、サーバーの文言に HTML タグが混ざっても描画されない。
 */

// この関数が作った欄下の <div> を見分けるためのマーカー属性（clearFieldErrors で削除する対象）
const FIELD_ERROR_MARKER = 'data-form-errors-field';
// この関数が作ったフォーム上部の <div> を見分けるためのマーカー属性
const FORM_MESSAGE_MARKER = 'data-form-errors-message';

/**
 * `container` の中の `[name="…"]` の入力欄に、サーバーの入力エラー（422 の errors）を出す。
 *
 * @param {HTMLElement} container  入力欄とエラー表示の親となる要素
 * @param {Object} errors          Laravel 標準の `errors`：`{ キー: [文言, …] }`
 *                                 空のとき・空のオブジェクトのときは何もしない
 * @returns {string[]}             欄が見つからなかったキーの文言の配列（呼び出し側で
 *                                 `showFormMessage` に回して取りこぼさないため）
 */
function showFieldErrors(container, errors) {
    if (!container || !errors || typeof errors !== 'object') {
        return [];
    }

    // 複数回呼ばれても整合するよう、前の表示（is-invalid と この関数が作った <div>）は毎回消す
    clearFieldErrors(container);

    const orphanMessages = [];

    Object.keys(errors).forEach(function (key) {
        const messages = errors[key];
        if (!Array.isArray(messages) || messages.length === 0) {
            return;
        }
        const message = messages[0]; // §2-7「同じ欄に複数のエラーがあるときは、最初の 1 つだけを出す」

        // CSS セレクタの attribute value に使うため、簡易なエスケープ（記号は使われない前提だが念のため）
        const input = container.querySelector('[name="' + cssAttrEscape(key) + '"]');
        if (!input) {
            // 欄が見つからないキー（たとえば hidden でサーバーだけが持つキー）は、
            // 取りこぼさないよう呼び出し側に戻して showFormMessage に回させる
            orphanMessages.push(message);
            return;
        }

        input.classList.add('is-invalid');

        // 入力欄の直後に、この関数が作った <div class="invalid-feedback d-block"> を 1 つだけ置く。
        // 兄弟セレクタ（.is-invalid ~ .invalid-feedback）に頼らず d-block で確実に表示する。
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback d-block';
        errorDiv.setAttribute(FIELD_ERROR_MARKER, '1');
        errorDiv.textContent = message;
        // input の直後に挿入（nextSibling が null なら末尾に追加される）
        input.parentNode.insertBefore(errorDiv, input.nextSibling);
    });

    return orphanMessages;
}

/**
 * `container` の中の `is-invalid` と、この関数（showFieldErrors）が作った欄下の <div> を消す。
 * 画面側で手で付けた invalid-feedback（Blade の `@error` などで描画したもの）は消さない。
 */
function clearFieldErrors(container) {
    if (!container) {
        return;
    }
    container.querySelectorAll('.is-invalid').forEach(function (el) {
        el.classList.remove('is-invalid');
    });
    container.querySelectorAll('[' + FIELD_ERROR_MARKER + ']').forEach(function (el) {
        el.remove();
    });
}

/**
 * `container` の先頭に、入力エラー以外の失敗の文言を `alert alert-danger` で出す。
 * 既にこの関数が出した表示があれば置き換える。閉じるボタンは付けない（§2-7）。
 *
 * @param {HTMLElement} container  パネル・モーダル本文などの表示先の要素
 * @param {string} message         出す文言（HTML としては扱わない）
 */
function showFormMessage(container, message) {
    if (!container || !message) {
        return;
    }

    // 置き換えのため、前の表示は毎回消す
    clearFormMessage(container);

    const alertEl = document.createElement('div');
    alertEl.className = 'alert alert-danger';
    alertEl.setAttribute('role', 'alert');
    alertEl.setAttribute(FORM_MESSAGE_MARKER, '1');
    alertEl.textContent = message;

    // container の先頭に差し込む
    container.insertBefore(alertEl, container.firstChild);
}

/**
 * この関数（showFormMessage）が出した入力エラー以外の失敗の表示を消す。
 */
function clearFormMessage(container) {
    if (!container) {
        return;
    }
    container.querySelectorAll('[' + FORM_MESSAGE_MARKER + ']').forEach(function (el) {
        el.remove();
    });
}

/**
 * CSS セレクタの attribute value に入れるときの最低限のエスケープ。
 * name 属性にはふつう安全な識別子しか入らないが、将来のために `"` と `\` を落とす。
 */
function cssAttrEscape(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

// window に載せ、インラインの script から直接呼べるようにする
window.FormErrors = {
    showFieldErrors: showFieldErrors,
    clearFieldErrors: clearFieldErrors,
    showFormMessage: showFormMessage,
    clearFormMessage: clearFormMessage,
};
