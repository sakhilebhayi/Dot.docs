/**
 * The body of the unload beacon: the save the bundle sends through
 * `navigator.sendBeacon()` when the page goes away with text still inside
 * the autosave debounce (`POST /documents/{uuid}/autosave`).
 *
 * It states the same two things every save through Livewire states, and
 * both are read HERE, at the moment of leaving, never when the editor was
 * mounted:
 *   - `base_version`: the version this page's copy is based on. The server
 *     refuses the save when somebody has saved since.
 *   - `overwrite`: whether this tab owes an overwrite (`overwriteOwed`: the
 *     writer chose Keep mine or Put it back, and no save that said so has
 *     been accepted yet). The base then names the very version this text
 *     replaces on purpose, so the save is accepted; with the flag the
 *     server first keeps that version in the history. Without it, a page
 *     closed inside the debounce after Put it back, or after a Keep mine
 *     save that failed, replaced the other person's text and kept it
 *     nowhere.
 *
 * `overwrite` is always a real boolean: the server validates it as one and
 * stores nothing when it is anything else. sendBeacon() cannot set headers,
 * so the CSRF token rides in the body.
 *
 * Here, not inside the bundle's mount(), so that `node --test` can run it.
 *
 * @param {object} json the document
 * @param {{csrfToken?: string, getBaseVersion?: () => number, getOverwrite?: () => boolean}} options
 *        the editor's mount options
 * @returns {{_token: string, content: object, base_version: number|null, overwrite: boolean}}
 */
export function unloadSaveBody(json, options = {}) {
    return {
        _token: options.csrfToken || '',
        content: json,
        base_version: typeof options.getBaseVersion === 'function' ? options.getBaseVersion() : null,
        overwrite: typeof options.getOverwrite === 'function' ? !!options.getOverwrite() : false,
    };
}

/**
 * Whether the unload flush sends a beacon for the document the editor holds
 * (the decision of the bundle's flushSave({beacon: true}); here, so that
 * `node --test` can run it).
 *
 *   - `dirty` and `changed`: typed since the last hand-over, and different
 *     from it: the debounce had not fired yet. Sent.
 *   - `unsent`: the host says the text was handed over to Livewire and its
 *     request failed or is held by the back-off (`unsentAtUnload()`). The
 *     bundle counts a document it has handed over as sent, so without this
 *     the beacon skipped exactly the text the writer would otherwise lose
 *     in a browser that keeps no draft. Sent.
 *   - `sentAlready`: this very content went by beacon a moment ago
 *     (pagehide, then destroy()): never twice for one content.
 *
 * @param {{dirty: boolean, changed: boolean, unsent: boolean, sentAlready: boolean}} state
 * @returns {boolean}
 */
export function beaconWanted({ dirty, changed, unsent, sentAlready }) {
    if (sentAlready) {
        return false;
    }

    return (dirty && changed) || unsent === true;
}
