/**
 * 録音の送信の流れ（録音実行 S-0502。2026-10-21 追加。設計書 screen-design.md S-0502「録音を失わないための一時保存とトークンの取り直し」）。
 *
 * 1. 送る前に、録音を一時保存する（pending-recordings.js。まだ保存していないときだけ。保存できない端末では飛ばす）
 * 2. 送る（csrf-token.js の fetchWithCsrfRetry。419 ならトークンを取り直して自動で 1 回だけ送り直す）
 * 3. 成功したら一時保存を消す
 *
 * 結果の status：
 * - 'ok'：送れた（data はサーバーの応答の JSON）
 * - 'login-expired'：トークンの取り直しが 401、または送信そのものが 401（ログインが切れていた。トレーナーがログインしてから 12 時間を
 *   過ぎたとき〔EnforceAbsoluteSessionLifetime。2026-10-21 追加〕は、送信が 401 になる）。別のタブでログインしてから送り直す
 * - 'other-user'：録音したトレーナーと別の人でログインしていた。送っていない
 * - 'failed'：そのほか（通信の失敗・送り直しも 419・サーバーのエラー）
 * どの場合も pendingId（一時保存の ID。保存できなかったら null）を返す。送り直すときは同じ pendingId を渡す（二重に保存しない）
 */
import { fetchWithCsrfRetry, LoginExpiredError, OtherUserError } from './csrf-token';

/**
 * @param {object} options
 * @param {string} options.uploadUrl   送り先（audio-records.recording.store）
 * @param {Blob} options.blob          録音
 * @param {string} options.fileName
 * @param {string} options.mimeType
 * @param {string} options.recordedAt  録音した日時（ISO 8601）
 * @param {number} options.clientId    送り先の会員
 * @param {number} options.trainerId   録音したトレーナー（ログイン中のトレーナー）
 * @param {{available: boolean, save: Function, remove: Function}} options.store  一時保存の保管場所
 * @param {number|null} [options.pendingId]   すでに一時保存した ID（送り直しのとき）
 * @param {boolean} [options.refreshFirst]    送る前にトークンを取り直す（［もう一度送る］など）
 * @returns {Promise<{status: string, data?: object, pendingId: number|null}>}
 */
export async function sendRecording({
    uploadUrl,
    blob,
    fileName,
    mimeType,
    recordedAt,
    clientId,
    trainerId,
    store,
    pendingId = null,
    refreshFirst = false,
    tokenUrl,
    fetchImpl = globalThis.fetch,
    doc = globalThis.document,
}) {
    let id = pendingId;
    if (id === null && store && store.available) {
        id = await store.save({ blob, mimeType, fileName, recordedAt, clientId, trainerId });
    }

    const formData = new FormData();
    formData.append('file', blob, fileName);
    formData.append('client_id', String(clientId));

    let response;
    try {
        response = await fetchWithCsrfRetry(uploadUrl, (token) => ({
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: formData,
        }), { tokenUrl, fetchImpl, doc, expectedUserId: trainerId, refreshFirst });
    } catch (error) {
        if (error instanceof LoginExpiredError) {
            return { status: 'login-expired', pendingId: id };
        }
        if (error instanceof OtherUserError) {
            return { status: 'other-user', pendingId: id };
        }

        return { status: 'failed', pendingId: id };
    }

    if (response.status === 401) {
        return { status: 'login-expired', pendingId: id };
    }
    if (!response.ok) {
        return { status: 'failed', pendingId: id };
    }
    let data = null;
    try {
        data = await response.json();
    } catch (error) {
        data = null;
    }
    if (store) {
        await store.remove(id);
    }

    return { status: 'ok', data, pendingId: null };
}
