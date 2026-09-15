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

/**
 * A draft that cannot be restored is parked under this prefix rather than
 * deleted — the writer declined the restore, or the document has moved on to a
 * newer version — because a mis-click must not destroy the only copy of
 * somebody's offline work. Nothing in the UI reads a parked draft back yet, so
 * without a sweep they accumulate in IndexedDB for ever.
 */
export const STALE_PREFIX = 'stale-';

/** How long a parked draft is kept. Seven days, swept on app boot. */
export const STALE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Whether a stored row is a parked draft that has outlived the TTL.
 *
 * Pure, and exported so `npm test` can exercise it without IndexedDB. Rows
 * that are not parked drafts are never expired here: the live draft for a
 * document is the writer's only unsaved copy and age says nothing about it.
 *
 * @param {{docUuid?: string, parkedAt?: number, savedAt?: number}} row
 */
export function staleDraftIsExpired(row, now = Date.now(), ttl = STALE_TTL_MS) {
    if (!row || typeof row.docUuid !== 'string' || !row.docUuid.startsWith(STALE_PREFIX)) {
        return false;
    }

    // `parkedAt` is when it was parked; `savedAt` is what an older build wrote
    // at the same moment. A parked row with neither is from a build older than
    // both and cannot be dated at all, so it goes.
    const parked = Number(row.parkedAt ?? row.savedAt);
    if (!Number.isFinite(parked) || parked <= 0) {
        return true;
    }

    return now - parked > ttl;
}

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

/**
 * Park a draft that is not restorable under `stale-<uuid>`, replacing whatever
 * was parked for that document before.
 *
 * Two callers: a draft whose baseVersion is behind the document (somebody has
 * saved since, so restoring it would overwrite them), and a draft the writer
 * declined to restore. Neither is this page's to destroy — the sweep on the
 * next boot is what eventually collects it.
 */
export async function parkStaleDraft(docUuid, json, baseVersion = null) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put({
                docUuid: STALE_PREFIX + docUuid,
                json,
                html: json,
                savedAt: Date.now(),
                parkedAt: Date.now(),
                baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
            });
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) { /* silent — don't break the editor */ }
}

/**
 * Delete parked drafts older than the TTL. Called once on app boot.
 *
 * @returns {Promise<number>} how many rows were removed
 */
export async function purgeStaleDrafts(now = Date.now(), ttl = STALE_TTL_MS) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            let removed = 0;
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const req = tx.objectStore(STORE_NAME).openCursor();
            req.onsuccess = (e) => {
                const cursor = e.target.result;
                if (!cursor) return;
                if (staleDraftIsExpired(cursor.value, now, ttl)) {
                    cursor.delete();
                    removed += 1;
                }
                cursor.continue();
            };
            req.onerror   = () => reject(req.error);
            tx.oncomplete = () => resolve(removed);
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) {
        return 0;
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

    // Parked drafts are kept so a mis-click is recoverable, not for ever.
    purgeStaleDrafts();

    window.addEventListener('online',  () => onOnline && onOnline());
    window.addEventListener('offline', () => onOffline && onOffline());
}
