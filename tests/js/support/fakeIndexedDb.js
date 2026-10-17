/**
 * A just-enough IndexedDB for resources/js/offline.js under `node --test`: one
 * object store of rows keyed by `docUuid`, readwrite/readonly transactions that
 * run their requests in order on later turns of the event loop and complete
 * once nothing is pending, `get`, `put`, `delete`, `openCursor`, `openKeyCursor`
 * over a key range, and `IDBKeyRange.bound`. Values are structured-cloned in
 * and out, as the real thing does.
 *
 * It is not a model of IndexedDB's isolation (a transaction here sees every
 * write made before its request runs, which is what a real readwrite
 * transaction on one store gives): enough to test what offline.js does with
 * rows, not how a browser schedules them.
 *
 * @returns {{rows: Map<string, object>, uninstall: () => void}}
 */
export function installFakeIndexedDb() {
    const rows = new Map();
    const previous = { indexedDB: globalThis.indexedDB, IDBKeyRange: globalThis.IDBKeyRange };
    const turn = (fn) => setImmediate(fn);

    function transaction() {
        const tx = {
            pending: 0,
            done: false,
            oncomplete: null,
            onerror: null,
            onabort: null,
            error: null,
            settle() {
                turn(() => {
                    if (tx.pending === 0 && !tx.done) {
                        tx.done = true;
                        tx.oncomplete?.();
                    }
                });
            },
            objectStore: () => store(tx),
        };

        return tx;
    }

    function request(tx, run) {
        const req = { result: undefined, error: null, onsuccess: null, onerror: null };
        tx.pending += 1;
        turn(() => {
            req.result = run();
            req.onsuccess?.({ target: req });
            tx.pending -= 1;
            tx.settle();
        });

        return req;
    }

    function cursorRequest(tx, range, withValue) {
        const req = { result: undefined, error: null, onsuccess: null, onerror: null };
        let keys = null;
        let at = 0;
        const step = () => {
            tx.pending += 1;
            turn(() => {
                keys ??= [...rows.keys()].filter((key) => !range || (key >= range.lower && key <= range.upper)).sort();
                if (at < keys.length) {
                    const key = keys[at];
                    at += 1;
                    req.result = {
                        key,
                        ...(withValue ? { value: structuredClone(rows.get(key)) } : {}),
                        continue: step,
                        delete: () => rows.delete(key),
                    };
                } else {
                    req.result = null;
                }
                req.onsuccess?.({ target: req });
                tx.pending -= 1;
                tx.settle();
            });
        };
        step();

        return req;
    }

    function store(tx) {
        return {
            get: (key) => request(tx, () => structuredClone(rows.get(key))),
            put: (value) => request(tx, () => void rows.set(value.docUuid, structuredClone(value))),
            delete: (key) => request(tx, () => void rows.delete(key)),
            openCursor: (range) => cursorRequest(tx, range, true),
            openKeyCursor: (range) => cursorRequest(tx, range, false),
        };
    }

    globalThis.IDBKeyRange = { bound: (lower, upper) => ({ lower, upper }) };
    globalThis.indexedDB = {
        open() {
            const req = { result: null, error: null, onupgradeneeded: null, onsuccess: null, onerror: null };
            turn(() => {
                const db = {
                    objectStoreNames: { contains: () => true },
                    createObjectStore: () => {},
                    transaction: () => transaction(),
                };
                req.result = db;
                req.onupgradeneeded?.({ target: req });
                req.onsuccess?.({ target: req });
            });

            return req;
        },
    };

    return {
        rows,
        uninstall() {
            globalThis.indexedDB = previous.indexedDB;
            globalThis.IDBKeyRange = previous.IDBKeyRange;
        },
    };
}
