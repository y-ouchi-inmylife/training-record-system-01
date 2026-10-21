/**
 * 送る前の録音の一時保存（ブラウザの IndexedDB。2026-10-21 追加。設計書 architecture.md 2-7「録音の一時保存」・
 * screen-design.md S-0502「録音を失わないための一時保存とトークンの取り直し」）。
 *
 * 録音はアップロードに成功するまでブラウザのメモリにしかなく、画面を閉じる・読み込み直すと消えるため、送る前にここへ保存し、
 * 送信に成功したとき・［破棄する］を押したときに消す。読むときは録音したトレーナーの ID で絞る（ほかの人の録音は出さない）。
 *
 * 保存するもの：blob（録音）・mimeType・fileName・recordedAt（ISO 8601）・clientId（送り先の会員）・trainerId（録音したトレーナー）。
 * 会員名は保存しない（録音実行の画面には会員名を出さないため）。
 *
 * IndexedDB が使えない（ない・開けない・OPEN_TIMEOUT_MS で開けない。プライベートブラウズなど）ときは、available が false の
 * 何もしない保管場所を返す（保存は null、一覧は空）。どの操作も例外を投げず、画面が固まらないようにする。
 * idbFactory は vitest で差し替える（ふだんは window.indexedDB）
 */

export const DB_NAME = 'trs01-pending-recordings';
export const STORE_NAME = 'recordings';
export const OPEN_TIMEOUT_MS = 3000;

/** 使えないときの保管場所（何もしない） */
function unavailableStore() {
    return {
        available: false,
        async save() { return null; },
        async listFor() { return []; },
        async remove() {},
    };
}

function openDatabase(idbFactory) {
    return new Promise((resolve, reject) => {
        let request;
        try {
            request = idbFactory.open(DB_NAME, 1);
        } catch (error) {
            reject(error);

            return;
        }
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error || new Error('indexedDB open failed'));
        request.onblocked = () => reject(new Error('indexedDB open blocked'));
    });
}

function withTimeout(promise, ms) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('indexedDB open timeout')), ms);
        promise.then(
            (value) => { clearTimeout(timer); resolve(value); },
            (error) => { clearTimeout(timer); reject(error); },
        );
    });
}

/**
 * 保管場所を開く
 *
 * @returns {Promise<{available: boolean, save: Function, listFor: Function, remove: Function}>}
 */
export async function openPendingRecordingStore({ idbFactory = globalThis.indexedDB, timeoutMs = OPEN_TIMEOUT_MS } = {}) {
    if (!idbFactory) {
        return unavailableStore();
    }
    let db;
    try {
        db = await withTimeout(openDatabase(idbFactory), timeoutMs);
    } catch (error) {
        return unavailableStore();
    }

    // 1 つの要求を 1 つのトランザクションで行い、トランザクションが終わったら結果を返す
    const run = (mode, makeRequest) => new Promise((resolve, reject) => {
        let result;
        let tx;
        try {
            tx = db.transaction(STORE_NAME, mode);
            const request = makeRequest(tx.objectStore(STORE_NAME));
            request.onsuccess = () => { result = request.result; };
        } catch (error) {
            reject(error);

            return;
        }
        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(tx.error || new Error('indexedDB transaction failed'));
        tx.onabort = () => reject(tx.error || new Error('indexedDB transaction aborted'));
    });

    return {
        available: true,
        /** 保存して ID を返す（失敗したら null） */
        async save(recording) {
            try {
                return await run('readwrite', (store) => store.add({ ...recording }));
            } catch (error) {
                return null;
            }
        },
        /** そのトレーナーの未送信の録音（録音した日時の古い順）。ほかのトレーナーの録音は入れない */
        async listFor(trainerId) {
            try {
                const all = (await run('readonly', (store) => store.getAll())) || [];

                return all
                    .filter((recording) => Number(recording.trainerId) === Number(trainerId))
                    .sort((a, b) => String(a.recordedAt).localeCompare(String(b.recordedAt)));
            } catch (error) {
                return [];
            }
        },
        /** 消す（ID がなければ何もしない） */
        async remove(id) {
            if (id === null || id === undefined) {
                return;
            }
            try {
                await run('readwrite', (store) => store.delete(id));
            } catch (error) {
                // 消せなくても画面は止めない（次に開いたときに未送信として出る）
            }
        },
    };
}
