/**
 * Counters for the co-editing layer: how often the page merged, what a merge
 * could not keep, how often a save failed, how many people were in the
 * document together. They exist so the owner can decide, after a few weeks
 * of real use, whether true same-sentence co-typing (Phase 3 of the roadmap)
 * is worth building. Read with `php artisan sync:report`.
 *
 * COUNTS AND KINDS ONLY. Nothing here ever receives a document, a title, a
 * name or an address: `record()` takes a kind and a small detail (a source, a
 * reason, a number of other people), maps it to one of the names in
 * METRIC_KINDS and adds one, and anything it does not recognise is ignored.
 * METRIC_KINDS is the closed list the server accepts
 * (App\Services\SyncMetrics::KINDS; a PHP test holds the two equal).
 *
 * It must NEVER affect editing, so:
 *  - it is a counter map and a clock, no timers: `record()` flushes when a
 *    minute has passed since the last flush, and the page calls
 *    `flush({beacon: true})` when it is left;
 *  - nothing it does throws, and nothing waits on it;
 *  - a flush that fails is dropped without a word, with one retry at the
 *    next flush (the failed counts ride along once more, then go);
 *  - the page that builds it can leave it out (switched off), and the host
 *    (sync/host.js) works unchanged when `env.metric` is not there.
 *
 * Like the rest of sync/ it takes nothing from outside it and has no DOM,
 * no `window`, no `fetch`: the page hands in `send` (a POST that may outlive
 * the page), `beacon`, the CSRF token and the clock.
 */

/** The names the server accepts, in the order the report lists them. */
export const METRIC_KINDS = [
    'merge_live_clean', 'merge_live_lost',
    'merge_draft_clean', 'merge_draft_lost',
    'merge_suggestion_clean', 'merge_suggestion_lost',
    'lost_both_live', 'lost_deleted_live',
    'lost_both_draft', 'lost_deleted_draft',
    'lost_both_suggestion', 'lost_deleted_suggestion',
    'lost_put_back', 'lost_dismissed',
    'conflict_impossible', 'conflict_attempts', 'conflict_expiry',
    'echo_resolved',
    'save_resent',
    'backoff_1', 'backoff_2', 'backoff_3', 'backoff_4', 'backoff_5', 'backoff_6',
    'readonly_provisional', 'readonly_confirmed',
    'peers_0_secs', 'peers_1_secs', 'peers_2_secs', 'peers_3plus_secs',
];

/** At most one flush a minute (the unload flush apart). */
export const FLUSH_EVERY_MS = 60000;

/** The most one flush may carry for one kind; the server refuses more. */
export const MAX_COUNT = 3600;

/**
 * The most time one poll may credit to the people-present counters. The
 * slowest poll is every 10 s; a tab that was hidden or offline for an hour
 * has not been "with company" for an hour.
 */
export const PEERS_GAP_MS = 20000;

const KINDS = new Set(METRIC_KINDS);
const SOURCES = { merge: 'live', draft: 'draft', suggestion: 'suggestion' };

/**
 * The name a host event counts under, or null when it is not one of ours.
 *
 * @param {string} kind the host's event: merge, lost, lost-action, conflict, echo, resend, backoff, readonly
 * @param {object} [detail]
 * @returns {string|null}
 */
export function metricKey(kind, detail = {}) {
    const d = detail && typeof detail === 'object' ? detail : {};
    let key = null;

    switch (kind) {
        case 'merge':
            key = `merge_${SOURCES[d.source] ?? ''}_${d.lost === true ? 'lost' : 'clean'}`;
            break;
        case 'lost':
            key = `lost_${d.reason}_${SOURCES[d.source] ?? ''}`;
            break;
        case 'lost-action':
            key = d.action === 'put_back' ? 'lost_put_back' : d.action === 'dismiss' ? 'lost_dismissed' : null;
            break;
        case 'conflict':
            key = `conflict_${d.reason}`;
            break;
        case 'echo':
            key = 'echo_resolved';
            break;
        case 'resend':
            key = 'save_resent';
            break;
        case 'backoff':
            key = Number.isInteger(d.level) && d.level >= 1 ? `backoff_${Math.min(d.level, 6)}` : null;
            break;
        case 'readonly':
            key = `readonly_${d.stage}`;
            break;
        default:
            key = null;
    }

    return key !== null && KINDS.has(key) ? key : null;
}

/** peers_<n>_secs for n other people (3 stands for 3 or more). */
const peersKey = (others) => (others >= 3 ? 'peers_3plus_secs' : `peers_${others}_secs`);

/**
 * @param {{
 *   send: (body: string) => Promise<unknown>,
 *   beacon?: (body: string) => boolean,
 *   token: () => string,
 *   now: () => number,
 *   enabled?: boolean,
 *   flushEveryMs?: number,
 * }} options
 *   send    POST the body to the metrics endpoint and resolve with whether it was accepted (false or a throw is a failure)
 *   beacon  navigator.sendBeacon with the same body; used on unload only
 *   token   the CSRF token, read when a body is built (a beacon cannot set headers: it rides in the body)
 */
export function createMetrics({ send, beacon, token, now, enabled = true, flushEveryMs = FLUSH_EVERY_MS }) {
    /** @type {Record<string, number>} */
    let pending = {};
    /** Counts whose flush failed once: they ride along with the next flush, and go if that fails too. */
    let carry = {};
    /** Milliseconds credited to each peers_* kind that have not made a whole second yet. */
    const partial = {};
    let lastPeersAt = null;
    let lastFlushAt = now();
    let inFlight = false;

    const add = (key, n = 1) => {
        pending[key] = Math.min(MAX_COUNT, (pending[key] ?? 0) + n);
    };

    /** Whole seconds out of the millisecond remainders, into `pending`. */
    const settlePeers = () => {
        for (const key of Object.keys(partial)) {
            const whole = Math.floor(partial[key] / 1000);
            if (whole > 0) {
                partial[key] -= whole * 1000;
                add(key, whole);
            }
        }
    };

    /** pending + carry as one body, or null when there is nothing to say. */
    const body = (fresh, held) => {
        const counts = {};
        for (const source of [held, fresh]) {
            for (const [key, value] of Object.entries(source)) {
                if (KINDS.has(key) && Number.isInteger(value) && value > 0) {
                    counts[key] = Math.min(MAX_COUNT, (counts[key] ?? 0) + value);
                }
            }
        }

        return Object.keys(counts).length === 0 ? null : JSON.stringify({ _token: token(), counts });
    };

    /**
     * Count one host event.
     *
     * @param {string} kind
     * @param {object} [detail]
     */
    function record(kind, detail = {}) {
        if (!enabled) {
            return;
        }

        try {
            if (kind === 'peers') {
                const others = Number.isFinite(detail?.others) ? Math.max(0, Math.floor(detail.others)) : null;
                if (others !== null) {
                    const at = now();
                    const key = peersKey(others);
                    if (lastPeersAt !== null) {
                        partial[key] = (partial[key] ?? 0) + Math.min(Math.max(0, at - lastPeersAt), PEERS_GAP_MS);
                    }
                    lastPeersAt = at;
                    settlePeers();
                }
            } else {
                const key = metricKey(kind, detail);
                if (key !== null) {
                    add(key, Number.isInteger(detail?.n) && detail.n > 0 ? detail.n : 1);
                }
            }

            if (now() - lastFlushAt >= flushEveryMs) {
                flush();
            }
        } catch (_) {
            // A counter never breaks the page.
        }
    }

    /**
     * Send what has been counted. Never throws, never rejects, never waits
     * on anything the page needs.
     *
     * Normal flush: one request at a time, and a failure keeps the counts of
     * THIS flush for the next one (only those: what had already been carried
     * once is dropped). Unload flush (`beacon`): once, by beacon, else by
     * `send`; the page is going and nothing is kept.
     *
     * @param {{beacon?: boolean}} options
     * @returns {Promise<boolean>} whether a request was accepted
     */
    function flush({ beacon: leaving = false } = {}) {
        if (!enabled) {
            return Promise.resolve(false);
        }

        try {
            if (inFlight && !leaving) {
                return Promise.resolve(false);
            }
            settlePeers();
            const fresh = pending;
            const held = carry;
            const payload = body(fresh, held);
            lastFlushAt = now();
            if (payload === null) {
                return Promise.resolve(false);
            }
            pending = {};
            carry = {};

            if (leaving) {
                let handed = false;
                try {
                    handed = typeof beacon === 'function' && beacon(payload) === true;
                } catch (_) {
                    handed = false;
                }
                if (!handed) {
                    try {
                        Promise.resolve(send(payload)).catch(() => {});
                    } catch (_) {
                        // Nothing more to try.
                    }
                }

                return Promise.resolve(handed);
            }

            inFlight = true;
            let sent;
            try {
                sent = Promise.resolve(send(payload));
            } catch (error) {
                sent = Promise.reject(error);
            }

            return sent.then(
                (answer) => answer !== false && !(answer && answer.ok === false),
                () => false
            ).then((accepted) => {
                inFlight = false;
                if (!accepted) {
                    // One more try, at the next flush: the counts of this one only.
                    carry = fresh;
                }

                return accepted;
            });
        } catch (_) {
            inFlight = false;

            return Promise.resolve(false);
        }
    }

    return {
        record,
        flush,
        /** What is waiting, for the tests: counts not yet sent and counts held for a retry. */
        waiting: () => ({ pending: { ...pending }, carry: { ...carry } }),
    };
}
