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
 * The key an earlier park is moved to when a newer one takes the plain
 * `stale-<uuid>` key: `stale-<uuid>@<ms>`, where `<ms>` is the row's own
 * `parkedAt`, else its `savedAt`, else `now`. If that key is taken too, `-2`,
 * `-3`, ... is appended until one is free.
 *
 * Pure, and exported so `npm test` can exercise the rule without IndexedDB.
 * The result starts with STALE_PREFIX, so the sweep dates it by the row's own
 * stamp and deletes it seven days after ITS park; it never equals the plain
 * key.
 *
 * @param {string} docUuid
 * @param {{parkedAt?: number, savedAt?: number}|null} row the park being kept
 * @param {Iterable<string>} existingKeys the keys already in the store
 * @param {number} now
 */
export function keepKeyFor(docUuid, row, existingKeys, now = Date.now()) {
    const stamp = [row?.parkedAt, row?.savedAt].map(Number).find((candidate) => Number.isFinite(candidate) && candidate > 0);
    const taken = new Set(existingKeys);
    const base = `${STALE_PREFIX}${docUuid}@${stamp ?? now}`;

    let key = base;
    for (let suffix = 2; taken.has(key); suffix += 1) {
        key = `${base}-${suffix}`;
    }

    return key;
}

/**
 * Park a draft that is not restorable under `stale-<uuid>`. A park is never
 * overwritten: in the same readwrite transaction the park that was there is
 * kept under a second key (keepKeyFor()), and the new one takes the plain
 * key, which therefore always holds the LATEST park. Older parks stay under
 * their suffixed keys until the 7-day sweep, each dated by its own parkedAt.
 *
 * Callers: a draft whose baseVersion is behind the document (somebody has
 * saved since, so restoring it would overwrite them), a draft the writer
 * declined to restore, and the set-aside paths (Load theirs, an accepted
 * suggestion). None of these is this page's to destroy - least of all the
 * previous visit's park, which nothing in this visit has offered back.
 *
 * `options.from` records the source of such a text. `options.offerable` marks a park made because the page SET THE TEXT ASIDE
 * and offered it back. Such a park is offered again after the writer has dealt
 * with the one on offer (nextParkToOffer(), resolveParked()); every other
 * park stays a copy for recovery by hand.
 */
export async function parkStaleDraft(docUuid, json, baseVersion = null, options = {}) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const plainKey = STALE_PREFIX + docUuid;
            const now = Date.now();
            const read = store.get(plainKey);
            read.onsuccess = () => {
                const earlier = read.result;
                const write = () => {
                    store.put({
                        docUuid: plainKey,
                        json,
                        html: json,
                        savedAt: now,
                        parkedAt: now,
                        baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
                        // A park made to be OFFERED (a text set aside) can be offered again later (nextParkToOffer());
                        // any other park is a copy for recovery by hand.
                        offerable: options.offerable === true,
                        // Where the text came from, so it is offered back with the label it had ('conflict': the page
                        // took the writer's typing away; 'draft': a draft of an earlier visit).
                        ...(typeof options.from === 'string' ? { from: options.from } : {}),
                    });
                };
                if (!earlier) {
                    write();

                    return;
                }
                // The kept key must be free: list the keys of this document's parks.
                const keys = [];
                const range = IDBKeyRange.bound(plainKey, plainKey + '\uffff');
                const cursor = store.openKeyCursor(range);
                cursor.onsuccess = () => {
                    const at = cursor.result;
                    if (at) {
                        keys.push(at.key);
                        at.continue();

                        return;
                    }
                    store.put({ ...earlier, docUuid: keepKeyFor(docUuid, earlier, keys, now) });
                    write();
                };
                cursor.onerror = () => reject(cursor.error);
            };
            read.onerror  = () => reject(read.error);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
    } catch (_) { /* silent — don't break the editor */ }
}

/**
 * The park to offer next: among a document's parks (the plain `stale-<uuid>`
 * and the kept `stale-<uuid>@...` rows) the newest that was made to be offered
 * and has not been dealt with. Pure, and exported for `npm test`.
 *
 * @param {object[]} rows the document's stale- rows, each with its `docUuid` key
 * @returns {{json: string, baseVersion: number|null, parkedAt: number, from: string|null}|null}
 */
export function chooseNextPark(rows) {
    const candidates = rows.filter((row) => row && row.offerable === true && row.resolved !== true && typeof row.json === 'string');
    if (candidates.length === 0) {
        return null;
    }

    const newest = [...candidates].sort(
        // Newest first; the plain key holds the LATEST park, so on a tie it comes before a kept one.
        (a, b) =>
            (Number(b.parkedAt) || 0) - (Number(a.parkedAt) || 0) ||
            Number(String(a.docUuid).includes('@')) - Number(String(b.docUuid).includes('@')) ||
            String(b.docUuid).localeCompare(String(a.docUuid))
    )[0];
    const baseVersion = Number(newest.baseVersion);

    return {
        json: newest.json,
        baseVersion: Number.isFinite(baseVersion) && newest.baseVersion !== null ? baseVersion : null,
        parkedAt: Number(newest.parkedAt) || 0,
        from: typeof newest.from === 'string' ? newest.from : null,
    };
}

/** Every stale- row of a document, plain and kept, read inside the caller's transaction. */
function readStaleRows(store, docUuid, done) {
    const rows = [];
    const plain = STALE_PREFIX + docUuid;
    const cursor = store.openCursor(IDBKeyRange.bound(plain, plain + '\uffff'));
    cursor.onsuccess = () => {
        const at = cursor.result;
        if (at) {
            if (at.key === plain || at.key.startsWith(plain + '@')) {
                rows.push(at.value);
            }
            at.continue();

            return;
        }
        done(rows);
    };
}

/**
 * The next text to offer the writer: the newest offerable park of this document
 * that nobody has dealt with (chooseNextPark()). Read only; null when there is
 * none or the store cannot be read.
 *
 * @returns {Promise<{json: string, baseVersion: number|null, parkedAt: number}|null>}
 */
export async function nextParkToOffer(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            let result = null;
            readStaleRows(tx.objectStore(STORE_NAME), docUuid, (rows) => {
                result = chooseNextPark(rows);
            });
            tx.oncomplete = () => resolve(result);
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
    } catch (_) {
        return null;
    }
}

/**
 * The writer has dealt with this text (Put it back, or Discard): every park of
 * the document that holds exactly it is marked resolved, so it is not offered
 * again. The rows stay, for their 7 days.
 *
 * @returns {Promise<boolean>} whether the write completed
 */
export async function resolveParked(docUuid, json) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            readStaleRows(store, docUuid, (rows) => {
                rows
                    .filter((row) => row.json === json && row.resolved !== true)
                    .forEach((row) => store.put({ ...row, resolved: true }));
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
 * The key of a tab's own lost row: `lost-<uuid>@<tabId>`. Two tabs of one
 * document in one browser share the database, so each writes only its own row
 * and neither can erase the other's entries. With no tab id (an old caller)
 * the key is the plain `lost-<uuid>` of the build that wrote one row per
 * document, which loadLost() and adoptLost() still read.
 */
function lostKey(docUuid, tabId = '') {
    return LOST_PREFIX + docUuid + (tabId ? `@${tabId}` : '');
}

/**
 * Every lost row of a document, plain and per tab: the rows whose key starts
 * with `lost-<uuid>`. Read inside the caller's transaction.
 */
function readLostRows(store, docUuid, done) {
    const rows = [];
    const prefix = LOST_PREFIX + docUuid;
    const cursor = store.openCursor(IDBKeyRange.bound(prefix, prefix + '\uffff'));
    cursor.onsuccess = () => {
        const at = cursor.result;
        if (at) {
            // `lost-<uuid>` itself, or `lost-<uuid>@<tab>`: never another document's row.
            if (at.key === prefix || at.key.startsWith(prefix + '@')) {
                rows.push(at.value);
            }
            at.continue();

            return;
        }
        done(rows);
    };
}

/**
 * The lost rows of a document as one list: rows oldest first (by their own
 * parkedAt, then key), each row's entries in order, an exact duplicate (the
 * same id with identical json) kept once. Pure, and exported for `npm test`.
 *
 * @param {object[]} rows
 * @returns {{entries: object[], dismissed: object[], baseVersion: number|null, parkedAt: number, dismissedAt: number|null}|null}
 */
export function unionLostRows(rows) {
    const usable = rows.filter((row) => row && Array.isArray(row.entries));
    if (usable.length === 0) {
        return null;
    }

    const ordered = [...usable].sort((a, b) => (Number(a.parkedAt) || 0) - (Number(b.parkedAt) || 0) || String(a.docUuid).localeCompare(String(b.docUuid)));
    const seen = new Set();
    const merge = (lists) => {
        const out = [];
        lists.forEach((list) =>
            list.forEach((entry) => {
                const identity = `${entry.id}|${JSON.stringify(entry.json)}`;
                if (!seen.has(identity)) {
                    seen.add(identity);
                    out.push(entry);
                }
            })
        );

        return out;
    };
    const entries = merge(ordered.map((row) => row.entries));
    seen.clear();
    const dismissed = merge(ordered.map((row) => (Array.isArray(row.dismissed) ? row.dismissed : [])));
    const newest = ordered[ordered.length - 1];
    const baseVersion = Number(newest.baseVersion);

    return {
        entries,
        dismissed,
        baseVersion: Number.isFinite(baseVersion) && newest.baseVersion !== null ? baseVersion : null,
        parkedAt: Number(newest.parkedAt) || 0,
        // Something on offer means the list is not dismissed, whichever row was.
        dismissedAt: entries.length > 0 ? null : Math.max(0, ...ordered.map((row) => Number(row.dismissedAt) || 0)) || null,
    };
}

/**
 * Write this tab's lost row: the tab's WHOLE lost list, rewritten on every
 * merge that lost something and on every put-back; the row's dismissal stamp
 * does not survive (a new loss is offered again), but the entries Dismiss all
 * moved aside under `dismissed` do: they stay for the row's 7 days, whatever
 * is offered after them. A plain copy is stored: the page hands in Alpine's
 * reactive proxy, which IndexedDB cannot clone. See LOST_PREFIX and lostKey().
 *
 * @returns {Promise<boolean>} true once the write has completed; false means
 *   nothing was stored and the caller still holds the only copy. Nothing is
 *   thrown.
 */
export async function parkLost(docUuid, entries, baseVersion = null, tabId = '') {
    try {
        const db = await openDb();
        const plain = JSON.parse(JSON.stringify(entries));
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            // Read the row in the same transaction: `dismissed` is carried over.
            const key = lostKey(docUuid, tabId);
            const req = store.get(key);
            req.onsuccess = () => {
                store.put({
                    docUuid: key,
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
 * Every tab's lost entries of a document as one list (unionLostRows()), read
 * only. A plain `lost-<uuid>` row is read too.
 *
 * @returns {Promise<{entries: object[], dismissed: object[], baseVersion: number|null, parkedAt: number, dismissedAt: number|null}|null>}
 */
export async function loadLost(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            let result = null;
            readLostRows(tx.objectStore(STORE_NAME), docUuid, (rows) => {
                result = unionLostRows(rows);
            });
            tx.oncomplete = () => resolve(result);
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
    } catch (_) {
        return null;
    }
}

/**
 * What a page does on open with the lost rows of its document: take ALL of
 * them (every earlier tab's, and a plain one) into THIS tab's own row and
 * delete the others, in one transaction, so each entry has exactly one owner
 * from then on. Put it back and Dismiss all rewrite or mark the owner's row
 * and nobody else's. Returns the list taken (unionLostRows()), or null when
 * there was nothing, or when the write failed (nothing changed then).
 *
 * @returns {Promise<{entries: object[], dismissed: object[], baseVersion: number|null, parkedAt: number, dismissedAt: number|null}|null>}
 */
export async function adoptLost(docUuid, tabId = '') {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const own = lostKey(docUuid, tabId);
            let result = null;
            readLostRows(store, docUuid, (rows) => {
                result = unionLostRows(rows);
                if (result === null) {
                    return;
                }
                rows.forEach((row) => {
                    if (row.docUuid !== own) {
                        store.delete(row.docUuid);
                    }
                });
                store.put({
                    docUuid: own,
                    entries: result.entries,
                    dismissed: result.dismissed,
                    parkedAt: result.parkedAt || Date.now(),
                    baseVersion: result.baseVersion,
                    ...(result.dismissedAt ? { dismissedAt: result.dismissedAt } : {}),
                });
            });
            tx.oncomplete = () => resolve(result);
            tx.onerror    = () => reject(tx.error);
            tx.onabort    = () => reject(tx.error);
        });
    } catch (_) {
        return null;
    }
}

/**
 * What Dismiss all does to the rows of a document, as the rows to write. The
 * dismissing tab's OWN row is emptied into `dismissed` (and stamped); in every
 * other row of the document the entries that are the ones being dismissed (the
 * tab's own entries and `listed`, the list its page showed, by identity: the
 * same id with identical json) move to `dismissed` as well. Without that a tab
 * whose row was adopted by another page, or whose entries another tab's row
 * still holds, would dismiss for now and see the text again after a reload.
 * Pure, and exported for `npm test`.
 *
 * @param {object[]} rows the document's lost rows, each with its `docUuid` key
 * @param {string} ownKey the dismissing tab's row key
 * @param {object[]} listed the entries the dismissing page showed
 * @param {number} now
 * @returns {object[]} the rows to put
 */
export function dismissInRows(rows, ownKey, listed = [], now = Date.now()) {
    const identity = (entry) => `${entry.id}|${JSON.stringify(entry.json)}`;
    const own = rows.find((row) => row.docUuid === ownKey);
    const dismissing = new Set([...(Array.isArray(listed) ? listed : []), ...(Array.isArray(own?.entries) ? own.entries : [])].map(identity));
    const out = [];

    rows.forEach((row) => {
        const entries = Array.isArray(row.entries) ? row.entries : [];
        const isOwn = row.docUuid === ownKey;
        const hit = entries.filter((entry) => isOwn || dismissing.has(identity(entry)));
        if (hit.length === 0 && !isOwn) {
            return;
        }
        const left = entries.filter((entry) => !hit.includes(entry));
        out.push({
            ...row,
            entries: left,
            dismissed: [...(Array.isArray(row.dismissed) ? row.dismissed : []), ...hit],
            ...(isOwn || left.length === 0 ? { dismissedAt: now } : {}),
        });
    });

    return out;
}

/**
 * Dismiss all: the park stays for its 7 days (the button says so), but it is
 * not offered again on the next visit. The entries move aside under
 * `dismissed`, which parkLost() carries over, so a later loss that rewrites
 * `entries` does not take the dismissed text out of the browser. Marks this
 * tab's OWN row and, in the same transaction, the same entries in every other
 * row of the document (dismissInRows()); another tab's other entries are still
 * on offer. `listed` is what the page showed: with its own row adopted away
 * the page still dismisses what it listed.
 *
 * @returns {Promise<boolean>} true once the write has completed; false means
 *   nothing was stored and the caller still holds the only copy. Nothing is
 *   thrown.
 */
export async function markLostDismissed(docUuid, tabId = '', listed = []) {
    try {
        const db = await openDb();
        const plain = JSON.parse(JSON.stringify(Array.isArray(listed) ? listed : []));
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            readLostRows(store, docUuid, (rows) => {
                dismissInRows(rows, lostKey(docUuid, tabId), plain).forEach((row) => store.put(row));
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
