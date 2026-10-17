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
 * somebody's offline work. The editor page offers a parked draft back (Put it
 * back in the notice bar); without a sweep they would still accumulate in
 * IndexedDB for ever.
 */
export const STALE_PREFIX = 'stale-';

/**
 * The last server document a tab held, as it serialises it, with its version
 * (saveBase()/loadBase()). Written by markConfirmed() in sync/host.js on
 * open, on an accepted save, on an applied or merged document and on an
 * adopted restyle - never per keystroke. It is what lets a draft the
 * document has moved past be MERGED on the next visit instead of only set
 * aside: the merge needs the document the draft was based on.
 */
export const BASE_PREFIX = 'base-';

/**
 * The blocks a merge could not keep, offered back in the notice bar's lost
 * row (parkLost()/loadLost()/markLostDismissed()). Its own key, never the
 * stale- slot Load theirs uses, so neither overwrites the other.
 */
export const LOST_PREFIX = 'lost-';

/** The rows the sweep may remove. A live draft (the bare uuid) is never one of them. */
export const PARKED_PREFIXES = [STALE_PREFIX, LOST_PREFIX, BASE_PREFIX];

/** How long a parked row is kept. Seven days, swept on app boot. */
export const STALE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Whether a stored row is a parked row (a stale draft, a lost park or a base
 * row) that has outlived the TTL.
 *
 * Pure, and exported so `npm test` can exercise it without IndexedDB. Rows
 * that are not parked are never expired here: the live draft for a document
 * is the writer's only unsaved copy and age says nothing about it. A swept
 * base row only means that a stale draft falls back to being set aside; a
 * swept lost park means the copy the Dismiss button promised for 7 days has
 * had its 7 days.
 *
 * @param {{docUuid?: string, parkedAt?: number, savedAt?: number}} row
 */
export function parkedRowIsExpired(row, now = Date.now(), ttl = STALE_TTL_MS) {
    if (!row || typeof row.docUuid !== 'string' || !PARKED_PREFIXES.some((prefix) => row.docUuid.startsWith(prefix))) {
        return false;
    }

    // `parkedAt` is when it was parked; `savedAt` is what an older build wrote
    // at the same moment (and what a base row carries). A parked row with
    // neither is from a build older than both and cannot be dated at all, so
    // it goes.
    const parked = Number(row.parkedAt ?? row.savedAt);
    if (!Number.isFinite(parked) || parked <= 0) {
        return true;
    }

    return now - parked > ttl;
}

/** The rule's older name; the same rule. */
export function staleDraftIsExpired(row, now = Date.now(), ttl = STALE_TTL_MS) {
    return parkedRowIsExpired(row, now, ttl);
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
 * Delete parked rows (stale drafts, lost parks, base rows) older than the
 * TTL. Called once on app boot.
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
                if (parkedRowIsExpired(cursor.value, now, ttl)) {
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
 * Write the base row: the server document this tab holds as confirmed, and
 * the version it is. See BASE_PREFIX.
 *
 * @returns {Promise<boolean>} true once the write has completed; false means
 *   nothing was stored and the caller still holds the only copy. Nothing is
 *   thrown.
 */
export async function saveBase(docUuid, json, baseVersion = null) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put({
                docUuid: BASE_PREFIX + docUuid,
                json,
                savedAt: Date.now(),
                baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
            });
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
        return true;
    } catch (_) { /* silent — don't break the editor */
        return false;
    }
}

/**
 * @returns {Promise<{json: string, baseVersion: number|null}|null>}
 */
export async function loadBase(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(BASE_PREFIX + docUuid);
            req.onsuccess = () => {
                const row = req.result;
                const baseVersion = Number(row?.baseVersion);
                resolve(
                    typeof row?.json !== 'string'
                        ? null
                        : {
                              json: row.json,
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
 * Write the lost park: the WHOLE lost list of a document, rewritten on every
 * merge that lost something and on every put-back; the row's dismissal
 * stamp does not survive (a new loss is offered again), but the entries
 * Dismiss all moved aside under `dismissed` do: they stay for the row's 7
 * days, whatever is offered after them. A plain copy is stored: the page
 * hands in Alpine's reactive proxy, which IndexedDB cannot clone. See
 * LOST_PREFIX.
 *
 * @returns {Promise<boolean>} true once the write has completed; false means
 *   nothing was stored and the caller still holds the only copy. Nothing is
 *   thrown.
 */
export async function parkLost(docUuid, entries, baseVersion = null) {
    try {
        const db = await openDb();
        const plain = JSON.parse(JSON.stringify(entries));
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            // Read the row in the same transaction: `dismissed` is carried over.
            const req = store.get(LOST_PREFIX + docUuid);
            req.onsuccess = () => {
                store.put({
                    docUuid: LOST_PREFIX + docUuid,
                    entries: Array.isArray(plain) ? plain : [],
                    dismissed: Array.isArray(req.result?.dismissed) ? req.result.dismissed : [],
                    parkedAt: Date.now(),
                    baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
                });
            };
            req.onerror   = () => reject(req.error);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
        return true;
    } catch (_) { /* silent — don't break the editor */
        return false;
    }
}

/**
 * @returns {Promise<{entries: object[], dismissed: object[], baseVersion: number|null, parkedAt: number, dismissedAt: number|null}|null>}
 */
export async function loadLost(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(LOST_PREFIX + docUuid);
            req.onsuccess = () => {
                const row = req.result;
                const baseVersion = Number(row?.baseVersion);
                resolve(
                    !row || !Array.isArray(row.entries)
                        ? null
                        : {
                              entries: row.entries,
                              dismissed: Array.isArray(row.dismissed) ? row.dismissed : [],
                              baseVersion: Number.isFinite(baseVersion) && row.baseVersion !== null ? baseVersion : null,
                              parkedAt: Number(row.parkedAt) || 0,
                              dismissedAt: Number(row.dismissedAt) || null,
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
 * Dismiss all: the park stays for its 7 days (the button says so), but it is
 * not offered again on the next visit. The entries move aside under
 * `dismissed`, which parkLost() carries over, so a later loss that rewrites
 * `entries` does not take the dismissed text out of the browser.
 *
 * @returns {Promise<boolean>} true once the write has completed; false means
 *   nothing was stored and the caller still holds the only copy. Nothing is
 *   thrown.
 */
export async function markLostDismissed(docUuid) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const req = store.get(LOST_PREFIX + docUuid);
            req.onsuccess = () => {
                if (req.result) {
                    const row = req.result;
                    store.put({
                        ...row,
                        entries: [],
                        dismissed: [...(Array.isArray(row.dismissed) ? row.dismissed : []), ...(Array.isArray(row.entries) ? row.entries : [])],
                        dismissedAt: Date.now(),
                    });
                }
            };
            req.onerror   = () => reject(req.error);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
        return true;
    } catch (_) { /* silent — don't break the editor */
        return false;
    }
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
