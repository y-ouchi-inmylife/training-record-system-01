import { describe, expect, it, vi } from 'vitest';
import { currentCsrfToken, fetchWithCsrfRetry, LoginExpiredError, refreshCsrfToken } from '../../resources/js/csrf-token.js';
import { DB_NAME, openPendingRecordingStore, STORE_NAME } from '../../resources/js/pending-recordings.js';
import { sendRecording } from '../../resources/js/recording-upload.js';
import { createFakeIndexedDB } from './support/fake-indexeddb.js';

// 録音を失わないための一時保存とトークンの取り直し（2026-10-21 追加。設計書 screen-design.md S-0502・architecture.md 2-7）。
// fetch と document（<meta name="csrf-token"> だけ）と IndexedDB は差し替える。

/** <meta name="csrf-token"> だけを持つ document の代わり */
function fakeDocument(token) {
    const meta = {
        content: token,
        getAttribute(name) { return name === 'content' ? this.content : null; },
        setAttribute(name, value) { if (name === 'content') this.content = value; },
    };
    return { meta, querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? meta : null) };
}

/** 応答の代わり */
function response(status, body = {}) {
    return { status, ok: status >= 200 && status < 300, json: async () => body };
}

/**
 * 送り先とトークンの口の応答を、順に返す fetch の代わり。呼ばれた URL・送ったトークンを記録する
 *
 * @param {{upload: object[], token: object[]}} plan
 */
function fakeFetch(plan) {
    const calls = [];
    const fn = vi.fn(async (url, init) => {
        calls.push({ url, token: init && init.headers ? init.headers['X-CSRF-TOKEN'] : undefined });
        const queue = url === '/csrf-token' ? plan.token : plan.upload;
        const next = queue.shift();
        if (next instanceof Error) {
            throw next;
        }
        return next;
    });
    fn.calls = calls;
    return fn;
}

const UPLOAD = '/audio-records/recording';

function recordingArgs(overrides = {}) {
    return {
        uploadUrl: UPLOAD,
        blob: new Blob(['audio'], { type: 'audio/webm' }),
        fileName: '20261021_101500.webm',
        mimeType: 'audio/webm',
        recordedAt: '2026-10-21T01:15:00.000Z',
        clientId: 7,
        trainerId: 2,
        ...overrides,
    };
}

describe('トークンの取り直し（csrf-token.js）', () => {
    it('取り直すと <meta name="csrf-token"> を新しいトークンに替える', async () => {
        const doc = fakeDocument('old');
        const fetchImpl = fakeFetch({ token: [response(200, { token: 'new', user_id: 2 })], upload: [] });

        await expect(refreshCsrfToken({ fetchImpl, doc })).resolves.toEqual({ token: 'new', userId: 2 });
        expect(currentCsrfToken(doc)).toBe('new');
    });

    it('ログインが切れていれば（401）LoginExpiredError', async () => {
        const fetchImpl = fakeFetch({ token: [response(401, { message: 'Unauthenticated.' })], upload: [] });

        await expect(refreshCsrfToken({ fetchImpl, doc: fakeDocument('old') })).rejects.toBeInstanceOf(LoginExpiredError);
    });

    it('419 なら取り直して 1 回だけ送り直す', async () => {
        const doc = fakeDocument('old');
        const fetchImpl = fakeFetch({ token: [response(200, { token: 'new', user_id: 2 })], upload: [response(419), response(201)] });

        const result = await fetchWithCsrfRetry(UPLOAD, (token) => ({ method: 'POST', headers: { 'X-CSRF-TOKEN': token } }), { fetchImpl, doc });

        expect(result.status).toBe(201);
        expect(fetchImpl.calls.map((c) => [c.url, c.token])).toEqual([[UPLOAD, 'old'], ['/csrf-token', undefined], [UPLOAD, 'new']]);
    });
});

describe('録音の送信（recording-upload.js）', () => {
    it('419 → 取り直し → 送り直しで成功し、一時保存を消す', async () => {
        const idb = createFakeIndexedDB();
        const store = await openPendingRecordingStore({ idbFactory: idb });
        const doc = fakeDocument('old');
        const fetchImpl = fakeFetch({
            token: [response(200, { token: 'new', user_id: 2 })],
            upload: [response(419), response(201, { data: { id: 55 } })],
        });

        const result = await sendRecording({ ...recordingArgs(), store, fetchImpl, doc });

        expect(result).toEqual({ status: 'ok', data: { data: { id: 55 } }, pendingId: null });
        expect(fetchImpl.calls.map((c) => c.token).filter(Boolean)).toEqual(['old', 'new']);
        expect(currentCsrfToken(doc)).toBe('new');
        // 送る前に保存し、成功したら消した
        expect(idb.rows(DB_NAME, STORE_NAME)).toEqual([]);
    });

    it('送り直しも 419 なら失敗（モーダルを出す）。一時保存は残る', async () => {
        const idb = createFakeIndexedDB();
        const store = await openPendingRecordingStore({ idbFactory: idb });
        const fetchImpl = fakeFetch({ token: [response(200, { token: 'new', user_id: 2 })], upload: [response(419), response(419)] });

        const result = await sendRecording({ ...recordingArgs(), store, fetchImpl, doc: fakeDocument('old') });

        expect(result.status).toBe('failed');
        expect(result.pendingId).not.toBeNull();
        const rows = idb.rows(DB_NAME, STORE_NAME);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ mimeType: 'audio/webm', fileName: '20261021_101500.webm', recordedAt: '2026-10-21T01:15:00.000Z', clientId: 7, trainerId: 2 });
        expect(rows[0].blob).toBeInstanceOf(Blob);
        // 自動の送り直しは 1 回だけ（送り先へは 2 回）
        expect(fetchImpl.calls.filter((c) => c.url === UPLOAD)).toHaveLength(2);
    });

    it('取り直しが 401 なら一時保存したままログインの案内（login-expired）。もう一度送ると同じ ID のまま、取り直して送る', async () => {
        const idb = createFakeIndexedDB();
        const store = await openPendingRecordingStore({ idbFactory: idb });
        const first = await sendRecording({
            ...recordingArgs(),
            store,
            fetchImpl: fakeFetch({ token: [response(401)], upload: [response(419)] }),
            doc: fakeDocument('old'),
        });

        expect(first.status).toBe('login-expired');
        expect(idb.rows(DB_NAME, STORE_NAME)).toHaveLength(1);

        // 別のタブでログインしたあと［もう一度送る］（refreshFirst）：取り直したトークンで送り、成功したら消す
        const doc = fakeDocument('old');
        const fetchImpl = fakeFetch({ token: [response(200, { token: 'after-login', user_id: 2 })], upload: [response(201, { data: { id: 56 } })] });
        const second = await sendRecording({ ...recordingArgs(), store, pendingId: first.pendingId, refreshFirst: true, fetchImpl, doc });

        expect(second.status).toBe('ok');
        expect(fetchImpl.calls.map((c) => [c.url, c.token])).toEqual([['/csrf-token', undefined], [UPLOAD, 'after-login']]);
        expect(idb.rows(DB_NAME, STORE_NAME)).toEqual([]);
    });

    it('送信そのものが 401（ログインしてから 12 時間を過ぎた）なら、一時保存したままログインの案内（login-expired）', async () => {
        // 2026-10-21 追加：EnforceAbsoluteSessionLifetime は、ログインしてから 12 時間を過ぎた JSON の呼び出しに 401 を返す
        const idb = createFakeIndexedDB();
        const store = await openPendingRecordingStore({ idbFactory: idb });
        const fetchImpl = fakeFetch({ token: [], upload: [response(401, { message: 'Unauthenticated.' })] });

        const result = await sendRecording({ ...recordingArgs(), store, fetchImpl, doc: fakeDocument('old') });

        expect(result.status).toBe('login-expired');
        expect(result.pendingId).not.toBeNull();
        expect(idb.rows(DB_NAME, STORE_NAME)).toHaveLength(1);
        // 401 は 419 ではないので、トークンの取り直しはしない（そのまま案内に進む）
        expect(fetchImpl.calls.map((c) => c.url)).toEqual([UPLOAD]);
    });

    it('別のトレーナーでログインし直していたら送らない（other-user）', async () => {
        const store = await openPendingRecordingStore({ idbFactory: createFakeIndexedDB() });
        const fetchImpl = fakeFetch({ token: [response(200, { token: 'other', user_id: 9 })], upload: [] });

        const result = await sendRecording({ ...recordingArgs(), store, refreshFirst: true, fetchImpl, doc: fakeDocument('old') });

        expect(result.status).toBe('other-user');
        expect(fetchImpl.calls.filter((c) => c.url === UPLOAD)).toHaveLength(0);
    });

    it('通信の失敗は failed。一時保存は残る', async () => {
        const idb = createFakeIndexedDB();
        const store = await openPendingRecordingStore({ idbFactory: idb });
        const fetchImpl = fakeFetch({ token: [], upload: [new TypeError('Failed to fetch')] });

        const result = await sendRecording({ ...recordingArgs(), store, fetchImpl, doc: fakeDocument('old') });

        expect(result.status).toBe('failed');
        expect(idb.rows(DB_NAME, STORE_NAME)).toHaveLength(1);
    });
});

describe('一時保存（pending-recordings.js）', () => {
    it('ほかのトレーナーの未送信の録音は出さない（録音した日時の古い順）', async () => {
        const store = await openPendingRecordingStore({ idbFactory: createFakeIndexedDB() });
        await store.save({ blob: new Blob(['b']), recordedAt: '2026-10-21T02:00:00.000Z', clientId: 1, trainerId: 2 });
        await store.save({ blob: new Blob(['a']), recordedAt: '2026-10-21T01:00:00.000Z', clientId: 1, trainerId: 2 });
        await store.save({ blob: new Blob(['x']), recordedAt: '2026-10-21T01:30:00.000Z', clientId: 1, trainerId: 3 });

        const mine = await store.listFor(2);

        expect(mine.map((r) => r.recordedAt)).toEqual(['2026-10-21T01:00:00.000Z', '2026-10-21T02:00:00.000Z']);
        expect(await store.listFor(3)).toHaveLength(1);
        expect(await store.listFor(4)).toEqual([]);
    });

    it('破棄すると消える', async () => {
        const store = await openPendingRecordingStore({ idbFactory: createFakeIndexedDB() });
        const id = await store.save({ blob: new Blob(['a']), recordedAt: '2026-10-21T01:00:00.000Z', clientId: 1, trainerId: 2 });

        await store.remove(id);

        expect(await store.listFor(2)).toEqual([]);
    });

    it('IndexedDB が使えないとき（ない・開くと例外・開けないまま）も固まらず、保存しないで送る', async () => {
        const throwing = { open() { throw new Error('SecurityError'); } };
        const hanging = { open() { return {}; } }; // いつまでも応答しない
        for (const idbFactory of [undefined, null, throwing, hanging]) {
            const store = await openPendingRecordingStore({ idbFactory, timeoutMs: 20 });
            expect(store.available).toBe(false);
            expect(await store.save({ trainerId: 2 })).toBeNull();
            expect(await store.listFor(2)).toEqual([]);
            await expect(store.remove(1)).resolves.toBeUndefined();

            const result = await sendRecording({
                ...recordingArgs(),
                store,
                fetchImpl: fakeFetch({ token: [], upload: [response(201, { data: { id: 57 } })] }),
                doc: fakeDocument('old'),
            });
            expect(result).toEqual({ status: 'ok', data: { data: { id: 57 } }, pendingId: null });
        }
    });
});

describe('録音実行の画面（recording-v2/session.blade.php）', () => {
    it('送信は共通の部品を使い、失敗のモーダルに［ログイン画面を開く］（別のタブ）と文の出し分けがある', async () => {
        const { readFileSync } = await import('node:fs');
        const view = readFileSync(new URL('../../resources/views/recording-v2/session.blade.php', import.meta.url), 'utf8');
        const entry = readFileSync(new URL('../../resources/js/recording-session.js', import.meta.url), 'utf8');

        expect(entry).toContain('window.RecordingUpload = { currentCsrfToken, refreshCsrfToken, openPendingRecordingStore, sendRecording };');
        expect(view).toContain('window.RecordingUpload.sendRecording({');
        expect(view).toContain("await uploadRecording({ refreshFirst: true });");
        expect(view).toMatch(/<a href="\{\{ route\('login'\) \}\}" target="_blank" rel="noopener" class="btn btn-outline-primary d-none" id="btn-open-login">ログイン画面を開く<\/a>/);
        expect(view).toContain('ログインの有効期限が切れたため、送信できませんでした。録音はこの端末に一時保存しています。［ログイン画面を開く］から別のタブでログインしてから、［もう一度送る］を押してください。');
        // 画面を開いたときのトークンを持ち続けない
        expect(view).not.toMatch(/const csrfToken\s*=/);
        // 未送信の録音の帯
        expect(view).toContain('<div id="pending-recordings"');
        expect(view).toContain("'未送信の録音があります（' + formatRecordedAt(recording.recordedAt) + ' の録音）'");
    });
});
