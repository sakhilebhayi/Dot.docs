/**
 * Keeps an open editor in step with what other people save.
 *
 * There is no websocket in production (shared hosting cannot run one), so
 * the editor asks. This is the loop that asks, and decides what to do with
 * the answer:
 *
 *   - It polls `POST /documents/{uuid}/sync` with the newest version it has
 *     SEEN. Quickly while somebody else has the document open, slowly when
 *     alone, not at all while the tab is hidden.
 *   - When the answer carries a newer document it hands it to the host -
 *     but only if the host is `clean`. A tab with unsaved typing is told
 *     there is a conflict instead; a tab with a save in flight is left
 *     alone until that save settles; a read-only tab is never written to.
 *   - After every poll that completed, answered or not, it gives the host a
 *     tick (`onPolled`), so that what the host does on a timer of its own
 *     (sending a save that is owed) does not depend on the poll being
 *     answered.
 *   - It stops for good when the APPLICATION says the session has ended,
 *     access was removed or the document is gone. The application answers
 *     those with a JSON body; the same status with no JSON body came from
 *     something in front of it (a firewall, a rate limiter) and is only a
 *     failure to retry.
 *
 * `seen` is not the version the editor's content is BASED on (the host owns
 * that, and sends it with every save). It is only "the newest version this
 * tab has already downloaded", so an unresolved conflict does not make it
 * fetch the same document again on every poll.
 *
 * No DOM, no fetch and no timers of its own: the request function, the
 * timer and the visibility check are all injected, which is what lets
 * `tests/js/sync.engine.test.js` drive every case by hand.
 */

/** While somebody else has the document open. */
export const FAST_MS = 1500;

/** While this tab is alone in the document. */
export const SLOW_MS = 10000;

/** The slowest it will ever retry after failures. */
export const MAX_BACKOFF_MS = 30000;

/**
 * The version of the sync request format this code speaks, sent with every
 * poll. The server does not act on it yet; it is there so a later phase can
 * tell a tab still running this JavaScript to reload.
 */
export const PROTOCOL = 1;

const STOP_REASONS = { 401: 'signed-out', 419: 'signed-out', 403: 'forbidden', 404: 'gone' };

/**
 * @param {{
 *   request: (payload: {version: number, tab: string, protocol: number}) => Promise<{status: number, body: object|null}>,
 *   host: {
 *     state: () => 'clean'|'busy'|'dirty'|'closed',
 *     applyRemote: (remote: {version: number, json: object, outline: object|null, css: string|null}) => boolean,
 *     onConflict: (remote: {version: number, json: object, outline: object|null, css: string|null}) => void,
 *     onRefused: (remote: {version: number, json: object, outline: object|null, css: string|null}) => void,
 *     onMembers: (members: object[], others: number) => void,
 *     onPolled?: () => void,
 *     onStopped: (reason: 'signed-out'|'forbidden'|'gone') => void,
 *   },
 *   version: number,
 *   tab: string,
 *   visible?: () => boolean,
 *   setTimer?: (fn: () => void, ms: number) => unknown,
 *   clearTimer?: (id: unknown) => void,
 * }} options
 */
export function createSyncEngine(options) {
    const { request, host, tab } = options;
    const visible = options.visible ?? (() => true);
    const setTimer = options.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
    const clearTimer = options.clearTimer ?? ((id) => clearTimeout(id));

    let seen = options.version;
    /** The newest server document not yet in the editor. */
    let remote = null;
    /** A version the editor could not open, so it is not offered again. */
    let refused = null;
    let others = 0;
    let failures = 0;
    let timer = null;
    let inFlight = false;
    let again = false;
    let stopped = false;

    function nextDelay() {
        if (failures > 0) {
            return Math.min(MAX_BACKOFF_MS, FAST_MS * 2 ** failures);
        }

        return others > 0 ? FAST_MS : SLOW_MS;
    }

    function disarm() {
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    }

    function arm() {
        disarm();
        if (!stopped && visible()) {
            timer = setTimer(poll, nextDelay());
        }
    }

    /** Give the waiting document to the host, if the host can take it. */
    function deliver() {
        if (remote === null) {
            return;
        }

        const state = host.state();

        if (state === 'busy' || state === 'closed') {
            return;
        }

        if (state === 'dirty') {
            host.onConflict(remote);

            return;
        }

        if (remote.version === refused) {
            return;
        }

        let applied = false;
        try {
            applied = host.applyRemote(remote) === true;
        } catch (_) {
            // The host threw while applying (a plugin or a node view failed
            // inside the dispatch). Same as a refusal: the editor does not
            // show this version.
            applied = false;
        }

        if (applied) {
            remote = null;
        } else {
            // The editor could not open this version. Remember it, so it is
            // not offered again on every poll, and tell the host once so the
            // page can say so. Polling goes on: a later version may open.
            refused = remote.version;
            host.onRefused(remote);
        }
    }

    async function poll() {
        if (stopped) {
            return;
        }

        if (inFlight) {
            again = true;

            return;
        }

        inFlight = true;
        disarm();

        let response = null;
        try {
            response = await request({ version: seen, tab, protocol: PROTOCOL });
        } catch (_) {
            response = null;
        }

        inFlight = false;

        if (stopped) {
            return;
        }

        // Only when the response has a JSON body: that is the application
        // answering. A firewall or rate limiter in front of it answers 403
        // with an HTML page, which must not be read as "access removed".
        const reason = response && response.body ? STOP_REASONS[response.status] : undefined;
        if (reason) {
            stopped = true;
            again = false;
            host.onStopped(reason);

            return;
        }

        if (!response || response.status !== 200 || !response.body) {
            failures += 1;
        } else {
            failures = 0;

            const body = response.body;
            others = Number(body.others) || 0;
            // A host callback that throws must not end the loop: the next
            // poll would never be armed, and the tab would stop following
            // and drop out of presence with nothing on screen to say so.
            try {
                host.onMembers(Array.isArray(body.members) ? body.members : [], others);
            } catch (_) {
                // Keep polling.
            }

            const incoming = Number(body.version);
            // Compared with `seen` as it is NOW: this tab's own save may have
            // landed while the request was in the air.
            if (body.changed === true && incoming > seen) {
                seen = incoming;
                remote = { version: incoming, json: body.json, outline: body.outline ?? null, css: body.css ?? null };
            }

            try {
                deliver();
            } catch (_) {
                // Keep polling.
            }
        }

        // Every completed poll gives the host a tick, answered or not. The
        // host sends a save that is owed from it (one that was given up on
        // after never answering, for one). Were the tick given only on a
        // 200, a tab whose polls are blocked by something in front of the
        // application would also stop saving. A throw must not end the loop.
        try {
            host.onPolled?.();
        } catch (_) {
            // Keep polling.
        }

        if (again) {
            again = false;
            poll();

            return;
        }

        arm();
    }

    return {
        /** Begin polling. The first poll also registers this tab's presence. */
        start: poll,

        /** Poll now instead of waiting for the timer. */
        poke: poll,

        stop() {
            stopped = true;
            again = false;
            disarm();
        },

        /** This tab's own save landed at `version`. */
        saved(version) {
            if (version > seen) {
                seen = version;
            }

            if (remote !== null && remote.version <= version) {
                remote = null;
            }
        },

        /**
         * Download the current document again, asking from `version`. For
         * when the host needs a document the engine has already seen and no
         * longer holds - an ordinary poll would be told "nothing changed".
         */
        refetch(version) {
            seen = version;
            refused = null;
            poll();
        },

        /** The host's state changed (a save settled): try to deliver again. */
        retry: deliver,

        /** Hand over the waiting document, for the "Load theirs" choice. */
        takeRemote() {
            const waiting = remote;
            remote = null;

            return waiting;
        },

        /** The newest server document not yet in the editor, or null. */
        get pending() {
            return remote;
        },

        visibilityChanged() {
            if (visible()) {
                poll();
            } else {
                disarm();
            }
        },
    };
}
