/**
 * The one HTTP call the sync engine makes.
 *
 * Kept apart from the engine so the engine stays free of `fetch` and can be
 * tested with hand-answered requests. A plain POST, not a Livewire action:
 * a Livewire request carries the component's whole snapshot - which
 * includes the document - up and down on every call.
 *
 * @param {string} url       POST /documents/{uuid}/sync
 * @param {string} csrfToken the page's CSRF token
 * @param {(url: string, init: object) => Promise<{status: number, json: () => Promise<object>}>} [fetchImpl]
 * @returns {(payload: object) => Promise<{status: number, body: object|null}>}
 */
export function createSyncRequest(url, csrfToken, fetchImpl = (...args) => fetch(...args)) {
    return async (payload) => {
        const response = await fetchImpl(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        let body = null;
        try {
            body = await response.json();
        } catch (_) {
            // Not JSON. The application always answers this request with
            // JSON (it is sent with Accept: application/json), an expired
            // session included - so this came from something in front of
            // the application, such as a firewall's or a rate limiter's
            // page. The engine treats a response with no body as a failure
            // to retry, never as a reason to stop.
            body = null;
        }

        return { status: response.status, body };
    };
}
