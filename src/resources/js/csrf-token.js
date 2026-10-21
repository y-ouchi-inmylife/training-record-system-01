/**
 * CSRF トークンの取り直しと、ページの有効期限切れ（419）のときの送り直し（2026-10-21 追加。設計書 architecture.md 2-7
 * 「トークンの取り直し」）。DOM には <meta name="csrf-token"> の読み書きだけで触れる（vitest で確かめるため、fetch と document は差し替えられる）。
 *
 * - refreshCsrfToken：GET /csrf-token（ログインの要る口）から新しいトークンを取り、<meta name="csrf-token"> を替える。
 *   ログインが切れていれば（401）LoginExpiredError を投げる
 * - fetchWithCsrfRetry：送って 419 なら、トークンを取り直して自動で 1 回だけ送り直す。refreshFirst なら送る前に取り直す
 *   （［もう一度送る］など、ほかのタブでログインし直したあとかもしれないとき）。expectedUserId を渡すと、取り直したトークンが
 *   別の人のログインのものなら OtherUserError を投げて送らない
 *
 * 今使っているのは録音の送信（recording-upload.js）だけ。ほかの画面の JS（AI の自動生成・メディアの更新など）でも使える共通の部品
 */

/** トークンを取り直す口（routes/web.php の csrf-token） */
export const CSRF_TOKEN_URL = '/csrf-token';

/** ログインが切れていた（トークンの取り直しが 401） */
export class LoginExpiredError extends Error {
    constructor() {
        super('login expired');
        this.name = 'LoginExpiredError';
    }
}

/** 取り直したトークンが、期待した人とは別の人のログインのもの */
export class OtherUserError extends Error {
    constructor() {
        super('logged in as another user');
        this.name = 'OtherUserError';
    }
}

function metaElement(doc) {
    return doc ? doc.querySelector('meta[name="csrf-token"]') : null;
}

/** 画面の今のトークン（<meta name="csrf-token">） */
export function currentCsrfToken(doc = globalThis.document) {
    const meta = metaElement(doc);

    return meta ? (meta.getAttribute('content') || '') : '';
}

/**
 * 新しいトークンを取り、<meta name="csrf-token"> を替える
 *
 * @returns {Promise<{token: string, userId: number|null}>}
 */
export async function refreshCsrfToken({ url = CSRF_TOKEN_URL, fetchImpl = globalThis.fetch, doc = globalThis.document } = {}) {
    const response = await fetchImpl(url, {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (response.status === 401) {
        throw new LoginExpiredError();
    }
    if (!response.ok) {
        throw new Error('csrf token refresh failed: HTTP ' + response.status);
    }
    const body = await response.json();
    if (!body || typeof body.token !== 'string' || body.token === '') {
        throw new Error('csrf token refresh failed: no token');
    }
    const meta = metaElement(doc);
    if (meta) {
        meta.setAttribute('content', body.token);
    }

    return { token: body.token, userId: body.user_id ?? null };
}

/**
 * 送って、419 ならトークンを取り直して 1 回だけ送り直す
 *
 * @param {string} url
 * @param {(token: string) => RequestInit} makeInit  トークンから fetch の設定を作る（送り直しでも同じ本文を使う）
 * @returns {Promise<Response>}  2 回目の応答（419 のままのこともある）。取り直しの失敗は投げる
 */
export async function fetchWithCsrfRetry(url, makeInit, {
    tokenUrl = CSRF_TOKEN_URL,
    fetchImpl = globalThis.fetch,
    doc = globalThis.document,
    expectedUserId = null,
    refreshFirst = false,
} = {}) {
    const refresh = async () => {
        const result = await refreshCsrfToken({ url: tokenUrl, fetchImpl, doc });
        if (expectedUserId !== null && result.userId !== null && Number(result.userId) !== Number(expectedUserId)) {
            throw new OtherUserError();
        }

        return result.token;
    };

    let token = refreshFirst ? await refresh() : currentCsrfToken(doc);
    const response = await fetchImpl(url, makeInit(token));
    if (response.status !== 419) {
        return response;
    }
    token = await refresh();

    return fetchImpl(url, makeInit(token));
}
