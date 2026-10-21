/**
 * テスト用の小さな IndexedDB の代わり（2026-10-21 追加。pending-recordings.js が使う機能だけ）。
 * open（onupgradeneeded・onsuccess・onerror）、transaction → objectStore の add・getAll・delete、トランザクションの oncomplete だけを、
 * メモリの上で非同期に（setTimeout で）まねる。新しいパッケージ（fake-indexeddb など）は足さない
 */
export function createFakeIndexedDB() {
    const databases = new Map();

    const later = (fn) => setTimeout(fn, 0);

    function makeDb(data) {
        return {
            createObjectStore(name, { keyPath, autoIncrement }) {
                data.stores.set(name, { keyPath, autoIncrement, rows: new Map(), next: 1 });
            },
            transaction(name) {
                const store = data.stores.get(name);
                if (!store) {
                    throw new Error('no store ' + name);
                }
                const tx = { oncomplete: null, onerror: null, onabort: null, error: null };
                const objectStore = {
                    add(value) {
                        const request = { result: undefined, onsuccess: null };
                        later(() => {
                            const id = store.next++;
                            store.rows.set(id, { ...value, [store.keyPath]: id });
                            request.result = id;
                            request.onsuccess && request.onsuccess();
                            tx.oncomplete && tx.oncomplete();
                        });
                        return request;
                    },
                    getAll() {
                        const request = { result: undefined, onsuccess: null };
                        later(() => {
                            request.result = [...store.rows.values()].map((row) => ({ ...row }));
                            request.onsuccess && request.onsuccess();
                            tx.oncomplete && tx.oncomplete();
                        });
                        return request;
                    },
                    delete(id) {
                        const request = { result: undefined, onsuccess: null };
                        later(() => {
                            store.rows.delete(id);
                            request.onsuccess && request.onsuccess();
                            tx.oncomplete && tx.oncomplete();
                        });
                        return request;
                    },
                };
                tx.objectStore = () => objectStore;
                return tx;
            },
        };
    }

    return {
        /** テストから中身を見る */
        rows(dbName, storeName) {
            const data = databases.get(dbName);
            return data ? [...data.stores.get(storeName).rows.values()] : [];
        },
        open(name) {
            const request = { result: undefined, onupgradeneeded: null, onsuccess: null, onerror: null, onblocked: null };
            later(() => {
                let data = databases.get(name);
                const isNew = !data;
                if (isNew) {
                    data = { stores: new Map() };
                    databases.set(name, data);
                }
                request.result = makeDb(data);
                if (isNew && request.onupgradeneeded) {
                    request.onupgradeneeded();
                }
                request.onsuccess && request.onsuccess();
            });
            return request;
        },
    };
}
