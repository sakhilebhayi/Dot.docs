/**
 * Whether this person may still change the document: a refused (403) save and
 * the question it asks (saveRefused(), accessAnswered()), the answer that
 * rides on every poll (accessFromPoll()), and the read only page
 * (lockReadOnly(), readOnlyNoticed(), parkUnsent()). Also isSaveRequest(),
 * which tells the page which refused request is a save.
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { SAVE_EXPIRY_MS, SAVE_RETRY_DELAYS_MS } from './constants.js';
import { late } from './late.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createAccess(view, env, h) {
    const { metric, resendIfOwed } = late(h, ['metric', 'resendIfOwed']);

    /**
     * Whether a Livewire request is a save: its payload (the JSON string
     * Livewire sends, `{components: [{calls: [{method}]}]}`) calls
     * saveContent(). The page asks this of every Livewire request so that
     * only a refused SAVE is looked into. The cheap test on the string comes
     * first: every other request answers at once, without being parsed.
     *
     * @param {unknown} payload
     * @returns {boolean}
     */
    function isSaveRequest(payload) {
        if (typeof payload !== 'string' || !payload.includes('saveContent')) {
            return false;
        }

        try {
            const components = JSON.parse(payload)?.components;

            return (
                Array.isArray(components) &&
                components.some(
                    (component) =>
                        Array.isArray(component?.calls) && component.calls.some((call) => call?.method === 'saveContent')
                )
            );
        } catch (_) {
            return false;
        }
    }

    /**
     * Whether the question "may I still change this?" is out and unanswered
     * (and not older than a save is waited for).
     */
    const accessBeingChecked = () => view.accessCheckSince > 0 && env.now() - view.accessCheckSince < SAVE_EXPIRY_MS;

    /**
     * Park the words typed since the last stored save, when there are any.
     * A copy for recovery by hand (7 days, not offered back: nothing here
     * could write it in); the draft itself is left as it is.
     *
     * @returns {boolean} whether there were such words
     */
    function parkUnsent(handle) {
        if (!handle || handle.autosaves === false) {
            return false;
        }

        const mine = JSON.stringify(handle.editor.getJSON());
        const kept = view.unsaved || handle.pending || (view.confirmed !== null && mine !== view.confirmed);
        if (kept) {
            env.drafts()?.parkStaleDraft(view.docUuid, mine, view.baseVersion, { offerable: false, from: 'refused' });
        }

        return kept;
    }

    /**
     * This person may not change the document, and the server said so (or
     * the page was drawn that way after a render): the page becomes the read
     * only page. Nothing is owed any more, and the two rows that offer text
     * back (the lost row, the set-aside row) go, because their buttons would
     * write into an editor that is no longer editable; the text itself stays
     * parked in this browser.
     *
     * @param {boolean} kept whether words typed here were left unsent
     */
    function lockReadOnly(kept) {
        metric('readonly', { stage: 'confirmed' });
        const handle = env.handle();
        const offered = view.lost.length > 0 || view.setAside !== null;

        view.editable = false;
        view.unsentKept = kept || offered;
        handle?.makeReadOnly?.();
        view.saving = 0;
        view.resave = false;
        view.unsaved = false;
        view.conflict = null;
        view.merging = null;
        view.mergeAttempts = 0;
        view.overwriteOwed = false;
        view.overwriteFloor = null;
        view.accessCheckSince = 0;
        view.lost = [];
        view.setAside = null;
        view.setAsideFrom = null;
        view.setAsideBase = null;
        view.parkOfferPending = false;
        env.report('idle', 'Read only');
        env.refreshPresence();
    }

    /**
     * The editor was found read only on a re-render (the server drew
     * `editable: false` after this person's role was lowered): the page says
     * so in the same way a refused save does.
     */
    function readOnlyNoticed() {
        if (view.editable === false) {
            return;
        }

        lockReadOnly(parkUnsent(env.handle()) || view.unsaved);
    }

    /**
     * The sync poll said whether this person may still change the document
     * (`can_edit`, asked of the same gate as Editor::canEdit()). A poll
     * answer is the server speaking, so it is as good as the answer to
     * `env.canEdit()`:
     *
     *   - false: the same read only page a confirmed canEdit() = false makes
     *     (lockReadOnly()), words parked (readOnlyNoticed()). When a refused
     *     save has already parked words and its question is still out, the
     *     words typed during the hold are parked now, beside the first copy.
     *   - true: never re-enables a page that is read only (a reload does
     *     that: the server draws the page again). It answers only the
     *     question a refused save left out, the one case where this page
     *     is held and was never told no: the hold is lifted and the text
     *     goes again, exactly as accessAnswered() does on a yes.
     *   - anything but a boolean: nothing (an older server sends no field).
     *
     * @param {unknown} canEdit
     */
    function accessFromPoll(canEdit) {
        if (typeof canEdit !== 'boolean' || view.editable === false) {
            return;
        }

        if (canEdit === false) {
            // The same read only page and the same parking as a re-render that draws editable false. When a refused
            // save's question is still out, words typed during the hold are not in the park made when it was refused,
            // so they are parked now (the earlier copy stays beside it).
            readOnlyNoticed();

            return;
        }

        if (view.accessCheckSince > 0) {
            accessAnswered(view.accessCheckSince, true, false);
        }
    }

    /**
     * The server answered a save with `status`. A 403 does not say WHY: this
     * person's access may have been lowered, but it is also what a request
     * answers when ANOTHER action sent with it was refused (a Move beside an
     * autosave: the save may even have been stored), or a firewall in front
     * of the application. So it is provisional. The page holds its saves,
     * keeps the words safe (parked, and the draft stays), and asks the
     * server (`env.canEdit()`): only a no makes the page read only; a yes
     * undoes the hold and sends the text again, and a save that was stored
     * after all is dealt with by the ordinary stale-refusal and echo rules;
     * an answer that does not come (or fails) is a failed request, and the
     * text goes again after the usual back-off.
     *
     * Livewire's promise for a refused request never settles, so the save
     * the page was waiting for is let go here and is owed again.
     *
     * @param {number|undefined} status the HTTP status of the refused request
     * @returns {boolean} whether this was a 403 on a save: the caller then
     *          keeps Livewire from showing its own error page over the document
     */
    function saveRefused(status) {
        if (status !== 403) {
            return false;
        }

        // Settled already: a read only page, or the question is out.
        if (view.editable === false || accessBeingChecked()) {
            return true;
        }

        metric('readonly', { stage: 'provisional' });
        const handle = env.handle();
        const kept = parkUnsent(handle);

        const token = Math.max(1, env.now(), view.accessCheckSince + 1);
        view.accessCheckSince = token;
        view.saving = Math.max(0, view.saving - 1);
        // The save may have been stored: only its answer is known to be lost.
        view.confirmed = null;
        view.unanswered = view.sentSnapshot;
        view.resave = true;

        let asked;
        try {
            asked = Promise.resolve(env.canEdit());
        } catch (error) {
            asked = Promise.reject(error);
        }
        asked.then(
            (answer) => accessAnswered(token, answer, kept),
            () => accessAnswered(token, null, kept)
        );

        return true;
    }

    /**
     * The server answered "may I still change this?".
     *
     * @param {number} token the check this answers (a newer check replaces it)
     * @param {boolean|null} answer null when the question could not be answered
     * @param {boolean} kept whether words were parked when the check began
     */
    function accessAnswered(token, answer, kept) {
        if (view.accessCheckSince !== token || view.editable === false) {
            return;
        }
        view.accessCheckSince = 0;

        if (answer === false) {
            lockReadOnly(kept);

            return;
        }

        if (answer !== true) {
            view.saveFailures += 1;
            view.saveRetryAt = env.now() + SAVE_RETRY_DELAYS_MS[Math.min(view.saveFailures, SAVE_RETRY_DELAYS_MS.length) - 1];
            env.report('danger', 'Not saved');
        } else {
            view.saveFailures = 0;
            view.saveRetryAt = 0;
        }

        env.engine()?.retry();
        resendIfOwed();
    }

    return {
        isSaveRequest,
        parkUnsent,
        lockReadOnly,
        readOnlyNoticed,
        accessFromPoll,
        saveRefused,
        accessAnswered,
        accessBeingChecked,
    };
}
