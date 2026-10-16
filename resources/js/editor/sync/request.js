/**
 * The one HTTP call the sync engine makes.
 *
 * Kept apart from the engine so the engine stays free of `fetch` and can be
 * tested with hand-answered requests. A plain POST, not a Livewire action:
 * a Livewire request carries the component's whole snapshot - which
 * includes the document - up and down on every call.
 *
 * A poll that has not answered in REQUEST_TIMEOUT_MS is given up on: the
 * fetch is aborted and the call resolves with `{status: 0, body: null}`,
 * which the engine treats as a completed poll that failed (a tick for the
 * host, a retry with back-off). Without that, one request that never
 * settled (a stalled connection, a proxy holding it) left the engine with
 * `inFlight` set for good: no further poll, no tick, and the tab neither
 * followed nor re-sent an owed save. The timers are injectable so a test
 * can drive the limit by hand; the page passes nothing and gets the global
 * ones.
 *
 * @param {string} url       POST /documents/{uuid}/sync
 * @param {string} csrfToken the page's CSRF token
 * @param {(url: string, init: object) => Promise<{status: number, json: () => Promise<object>}>} [fetchImpl]
 * @param {{setTimeout?: (fn: () => void, ms: number) => unknown, clearTimeout?: (id: unknown) => void}} [timers]
 * @returns {(payload: object) => Promise<{status: number, body: object|null}>}
 */
export function createSyncRequest(url, csrfToken, fetchImpl = (...args) => fetch(...args), timers = {}) {
    const setTimer = timers.setTimeout ?? ((fn, ms) => setTimeout(fn, ms));
    const clearTimer = timers.clearTimeout ?? ((id) => clearTimeout(id));

    return (payload) =>
        new Promise((resolve, reject) => {
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            let settled = false;

            const timer = setTimer(() => {
                if (settled) {
                    return;
                }
                settled = true;
                controller?.abort();
                resolve({ status: 0, body: null });
            }, REQUEST_TIMEOUT_MS);

            const finish = (outcome, value) => {
                clearTimer(timer);
                if (settled) {
                    return;
                }
                settled = true;
                outcome(value);
            };

            let attempt;
            try {
                attempt = Promise.resolve(
                    fetchImpl(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(payload),
                        signal: controller ? controller.signal : undefined,
                    })
                );
            } catch (error) {
                attempt = Promise.reject(error);
            }

            attempt.then(
                async (response) => {
                    let body = null;
                    try {
                        body = await response.json();
                    } catch (_) {
                        // Not JSON. The application always answers this
                        // request with JSON (it is sent with Accept:
                        // application/json), an expired session included -
                        // so this came from something in front of the
                        // application, such as a firewall's or a rate
                        // limiter's page. The engine treats a response with
                        // no body as a failure to retry, never as a reason
                        // to stop.
                        body = null;
                    }

                    finish(resolve, { status: response.status, body });
                },
                (error) => finish(reject, error)
            );
        });
}

/** How long a poll is waited for before it is aborted and counted as failed. */
export const REQUEST_TIMEOUT_MS = 20000;
