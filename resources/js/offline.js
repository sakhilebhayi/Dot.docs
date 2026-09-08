/**
 * Offline document draft manager.
 * Persists editor content to IndexedDB so work is not lost when offline.
 * Call saveDraft(docUuid, json, baseVersion) on every keystroke, where `json`
 * is the serialised Dot.Doc document and `baseVersion` is the document
 * `version` the last successful save returned (seeded from the server at
 * mount).
 * Call loadDraft(docUuid) on editor init to decide whether to restore; it
 * returns `{json, savedAt, baseVersion}`.
 *
 * Recency is decided by VERSION, never by time. `savedAt` comes off the
 * browser's own clock and `updated_at` off the server's, so a client whose
 * clock runs slow would discard real offline work — a draft is restorable only
 * while `baseVersion === document.version`, i.e. nobody has saved since it was
 * written. `savedAt` is kept for diagnostics only.
 */

const DB_NAME    = 'dotdocs-offline';
const STORE_NAME = 'drafts';

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('saves')) {
                db.createObjectStore('saves', { keyPath: 'id', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'docUuid' });
            }
        };
        req.onsuccess = (e) => resolve(e.target.result);
        req.onerror   = () => reject(req.error);
    });
}

export async function saveDraft(docUuid, json, baseVersion = null) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            // `html` is written alongside `json` only so a draft saved by an
            // older build of this page still reads back.
            tx.objectStore(STORE_NAME).put({
                docUuid,
                json,
                html: json,
                savedAt: Date.now(),
                baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
            });
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) { /* silent — don't break the editor */ }
}

/**
 * @returns {Promise<{json: string, savedAt: number, baseVersion: number|null}|null>}
 */
export async function loadDraft(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(docUuid);
            req.onsuccess = () => {
                const row = req.result;
                const json = row?.json ?? row?.html ?? null;
                const baseVersion = Number(row?.baseVersion);
                resolve(
                    json === null
                        ? null
                        : {
                              json,
                              savedAt: Number(row.savedAt) || 0,
                              // A draft written before versions were recorded
                              // has no base and is never auto-restorable.
                              baseVersion: Number.isFinite(baseVersion) && row.baseVersion !== null ? baseVersion : null,
                          }
                );
            };
            req.onerror   = () => reject(req.error);
        });
    } catch (_) {
        return null;
    }
}

export async function clearDraft(docUuid) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).delete(docUuid);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) { /* silent */ }
}

/**
 * Register the service worker and set up online/offline event listeners.
 * @param {function} onOnline  Called when the browser goes back online.
 * @param {function} onOffline Called when the browser goes offline.
 */
export function initOfflineSupport(onOnline, onOffline) {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => { /* non-fatal */ });
    }

    window.addEventListener('online',  () => onOnline && onOnline());
    window.addEventListener('offline', () => onOffline && onOffline());
}
