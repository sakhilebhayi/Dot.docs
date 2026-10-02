/**
 * The editor page's half of keeping an open document in step.
 *
 * The engine (./engine.js) asks the server what is new. This is what the
 * page does about it, and about its own saves: whether a save is sent, has
 * to wait, is sent again or is refused; whether a newer document is
 * followed or raises the conflict notice; what Keep mine, Load theirs and
 * Put it back do; when a version is adopted; and what happens to the
 * offline draft. Every one of those decisions is here, so that
 * `tests/js/sync.host.test.js` can run them under `node --test` together
 * with the real engine. The Blade component
 * (resources/views/livewire/documents/editor.blade.php) keeps the reactive
 * state fields and the page glue, and its methods are one-line calls into
 * this module.
 *
 * It holds NO state of its own. Alpine re-creates the component's data
 * object whenever a render changes the x-data string, and handlers bound at
 * first load stay bound to the first object, so every call has to read and
 * write the object it was made on. `createSyncHost(view, env)` is therefore
 * built afresh from `this` for each call; it keeps nothing between calls,
 * and it assigns to `view`'s own properties (never to a copy written back
 * later), which is what keeps Alpine's reactivity working.
 *
 * No DOM, no `window`, no Livewire, no clock and no console of its own:
 * everything outside the state object arrives in `env`.
 */

/** How long a save is waited for before it is taken as never answering. */
export const SAVE_EXPIRY_MS = 15000;

/**
 * This tab's identity for presence and the sync poll. Per page load, not
 * per browser: two tabs of one account are two tabs.
 *
 * `crypto.randomUUID` exists only in a secure context, so over plain HTTP
 * and in older browsers the id is built from 16 bytes of
 * `crypto.getRandomValues` instead, which is just as hard to guess. Only a
 * browser with no crypto object at all falls back to `Math.random`.
 * Whichever it is, the result matches the server's rule for a tab id: 1 to
 * 64 characters of `[A-Za-z0-9-]`.
 *
 * @param {{
 *   crypto: {randomUUID?: () => string, getRandomValues?: (bytes: Uint8Array) => Uint8Array}|null|undefined,
 *   random: () => number,
 *   now: () => number,
 * }} sources the page passes `window.crypto`, `Math.random` and `Date.now`
 * @returns {string}
 */
export function createTabId({ crypto, random, now }) {
    if (crypto && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    if (crypto && typeof crypto.getRandomValues === 'function') {
        const bytes = crypto.getRandomValues(new Uint8Array(16));

        return `tab-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
    }

    return `tab-${random().toString(36).slice(2)}${now().toString(36)}`;
}

/**
 * @typedef {object} SyncView The Blade component's state fields.
 * @property {number} saving how many of this tab's own saves are in the air
 * @property {number} savingSince when the latest of them left
 * @property {boolean} unsaved true from the first local edit until a save of
 *           exactly what the editor holds has been confirmed by the server
 * @property {boolean} resave a save is owed; resendIfOwed() sends it
 * @property {string|null} confirmed the document, as a JSON string, as the
 *           server last confirmed it; null before the editor is mounted, and
 *           again from the moment one of this tab's saves goes unanswered
 *           until the server has said what it holds
 * @property {boolean} overwriteOwed the next save puts this tab's own text
 *           back over a version it loaded, so it goes as an overwrite
 * @property {{version: number, ready: boolean}|null} conflict
 * @property {string|null} setAside the writer's own text, as a JSON string,
 *           kept so the page can offer to put it back
 * @property {string} syncNotice
 * @property {string} memberKey
 * @property {string} docUuid
 * @property {number} documentVersion the version this page was rendered from
 * @property {number} baseVersion the version this tab's copy is based on
 * @property {string} aiError
 * @property {number} tick
 */

/**
 * @typedef {object} SyncEnv Everything the page hands the module.
 * @property {() => object|null|undefined} handle the editor handle the bundle
 *           parked on the element (`editor`, `autosaves`, `pending`,
 *           `applyRemote`), or nothing when no editor is mounted
 * @property {() => object|null|undefined} engine the sync engine parked on
 *           the element, or nothing before it has started
 * @property {(json: object, baseVersion: number, overwrite: boolean) => Promise<{ok: boolean, conflict: boolean, version: number}>} save
 *           Editor::saveContent()
 * @property {() => Promise<void>} refreshOutline pull the server's outline
 * @property {() => void} refreshPresence re-render the presence strip
 * @property {(tone: string, word: string) => void} report the status word
 * @property {() => object|null|undefined} drafts `window.offlineDraft`
 * @property {(a: object, b: object) => boolean} documentsDiffer
 * @property {(outline: object) => void} showOutline hand an outline that
 *           came with a document to the bundle
 * @property {(css: string) => void} showCss put a document style in the page
 * @property {(question: string) => boolean} confirm
 * @property {() => number} now
 * @property {(...args: unknown[]) => void} info `console.info`
 * @property {(...args: unknown[]) => void} log `console.error`
 */

/**
 * @param {SyncView} view the Alpine data object the call was made on
 * @param {SyncEnv} env
 */
export function createSyncHost(view, env) {
    /**
     * Whether one of this tab's own saves is still being waited for.
     *
     * A save that has not answered in 15 seconds is not coming back (a
     * dropped connection leaves the $wire promise pending for ever). The
     * tab stops waiting for it, and the save is owed again: resendIfOwed()
     * sends it. This is the ONLY place a save expires, and everything that
     * needs to know whether a save is in the air asks here: persist(),
     * syncState() and resendIfOwed(). Whichever of them notices first
     * leaves the same state behind. persist() is the one that matters when
     * nothing else runs: resendIfOwed() is reached from a poll, and a tab
     * whose polls are blocked must still be able to save.
     *
     * A save that is given up on may still have been STORED: only its
     * answer is known to be lost. From then on what the server holds is not
     * known, so nothing counts as confirmed (`confirmed` is null) until a
     * save is accepted or a server document is applied. Without that, an
     * edit sent, stored, never answered and then undone would find the
     * editor back at the last confirmed document, be written off as Saved,
     * and the next poll would bring the undone text back.
     *
     * @returns {boolean}
     */
    function saveInTheAir() {
        if (view.saving > 0 && env.now() - view.savingSince >= SAVE_EXPIRY_MS) {
            view.saving = 0;
            view.resave = true;
            view.confirmed = null;
        }

        return view.saving > 0;
    }

    /**
     * The editor has just been mounted by this page. What it holds now is
     * what the server last confirmed.
     */
    function opened() {
        const handle = env.handle();

        view.confirmed = JSON.stringify(handle.editor.getJSON());
        env.report(
            handle.autosaves === false ? 'danger' : 'good',
            handle.autosaves === false ? 'Read only' : 'Saved'
        );
    }

    /**
     * The writer changed the document. Runs off every keystroke; the save
     * itself is debounced inside the bundle.
     */
    function edited() {
        view.unsaved = true;
        env.report('idle', 'Editing');

        const handle = env.handle();
        const drafts = env.drafts();
        // Never write a draft in fail-closed mode: what the editor is
        // holding then is not the document.
        if (drafts && handle && handle.autosaves !== false) {
            drafts.saveDraft(view.docUuid, JSON.stringify(handle.editor.getJSON()), view.baseVersion);
        }
    }

    /**
     * Save the document.
     *
     * $wire actions resolve with the PHP method's return value, so a
     * refused save is visible here. saveContent() answers
     * {ok, conflict, version}:
     *   ok       - stored; `version` becomes the base of the next save.
     *   conflict - somebody saved first. Nothing was written. Saving is
     *              suspended and the writer is asked what to do.
     *   neither  - the content was rejected (DocumentSchema), or the page
     *              is out of date; the error renders in the status area.
     * On anything but `ok` the offline draft is KEPT - it is the only
     * remaining copy of what the writer typed.
     *
     * One save in the air at a time. Livewire sends a second call after
     * the first, with the arguments it was CALLED with, so a second
     * autosave fired before the first had answered would state the old
     * base and be refused: the tab would conflict with itself. A save
     * asked for in the meantime is remembered in `resave` and sent by
     * resendIfOwed() once the first has answered.
     *
     * `force` is the Keep mine choice: the base version has just been
     * moved up to the newer document's, so this save knowingly replaces
     * it. It goes to the server as the overwrite flag, which keeps the
     * replaced version in the history first. The save that follows Put
     * it back carries the same flag (`overwriteOwed`): it too replaces
     * a version on purpose, the one this tab loaded.
     *
     * @param {object} json
     * @param {{force?: boolean}} options
     * @returns {Promise<void>}
     */
    function persist(json, { force = false } = {}) {
        const handle = env.handle();
        // Fail-closed (the content check refused the document): the editor
        // is read-only and must not write anything back.
        if (handle && handle.autosaves === false) {
            return Promise.resolve();
        }

        // A save is already in the air: this one is owed, and resendIfOwed()
        // sends it once that one has answered. Checked BEFORE the conflict,
        // because the save in the air may be the Keep mine save that ends
        // the conflict: text handed over meanwhile must not be dropped.
        // Asked through saveInTheAir(), never by reading `saving`: a save
        // that has not answered in 15 seconds is given up on HERE too, and
        // this autosave then goes out by itself. Otherwise one lost answer
        // would turn every later autosave into an owed one for as long as
        // no poll came to notice.
        if (saveInTheAir() && !force) {
            view.resave = true;

            return Promise.resolve();
        }

        // An unresolved conflict: every save would be refused. The draft
        // keeps the text; the notice asks the question.
        if (view.conflict && !force) {
            env.report('danger', 'Not saved');

            return Promise.resolve();
        }

        env.report('idle', 'Saving');

        // What is being SENT, captured now: the draft is cleared only if
        // the editor still holds exactly this when the answer arrives.
        const snapshot = JSON.stringify(json);

        view.saving++;
        view.savingSince = env.now();

        // Two handlers on ONE then(), not then().catch(): a catch() at the
        // end would also catch whatever goes wrong AFTER the answer, report
        // a save the server stored as Not saved, and count that save as
        // answered a second time.
        return env.save(json, view.baseVersion, force || view.overwriteOwed).then(
            (result) => {
                view.saving = Math.max(0, view.saving - 1);

                try {
                    saveAnswered(result, snapshot);
                } catch (error) {
                    // Not the save's failure: the save has been answered.
                    env.log('[Dot.Doc] Something failed after a save was answered.', error);
                }

                return refreshOutline();
            },
            () => {
                // The save itself failed: the request was never answered.
                // Whether the server stored it is not known, so nothing
                // counts as confirmed any more (see saveInTheAir()).
                view.saving = Math.max(0, view.saving - 1);
                view.confirmed = null;
                env.report('danger', 'Not saved');
                env.engine()?.retry();
            }
        );
    }

    /**
     * Pull the fresh numbers after a save. A failure here is the outline's
     * own, never the save's: it goes to the log, and the promise resolves.
     *
     * @returns {Promise<void>}
     */
    function refreshOutline() {
        const failed = (error) => env.log('[Dot.Doc] The outline could not be refreshed.', error);

        try {
            return Promise.resolve(env.refreshOutline()).catch(failed);
        } catch (error) {
            failed(error);

            return Promise.resolve();
        }
    }

    /**
     * The server answered a save of `snapshot`.
     *
     * @param {{ok?: boolean, conflict?: boolean, version?: number}|null|undefined} result
     * @param {string} snapshot the document that was sent, as a JSON string
     */
    function saveAnswered(result, snapshot) {
        if (result && result.ok) {
            view.baseVersion = result.version;
            view.conflict = null;
            view.confirmed = snapshot;
            view.overwriteOwed = false;
            env.engine()?.saved(result.version);
            if (clearDraftIfSettled(snapshot)) {
                view.unsaved = false;
                // Said only here: Saved means the stored document is what
                // the editor holds.
                env.report('good', 'Saved');
            } else {
                // What was stored is not what the editor holds now, so a
                // save is owed and the word does not go to Saved: it stays
                // on Editing, or goes to Saving when resendIfOwed() sends.
                // Normally the bundle's debounce is still armed and sends
                // it. But the debounce may find nothing to send (the editor
                // is back at what the bundle last handed over, which
                // persist() only marked as owed), and this answer may have
                // come late, after the text typed since was handed to
                // persist() and turned away by a conflict this very answer
                // has just ended. In both cases nothing else would send
                // that text until the next keystroke.
                view.resave = true;
            }
        } else if (result && result.conflict && result.version <= view.baseVersion) {
            // Refused against a version this tab is already based on: one
            // of its own saves got there first. That is not a conflict -
            // send again on the new base.
            view.resave = true;
        } else if (result && result.conflict) {
            enterConflict(result.version);
        } else {
            env.report('danger', 'Not saved');
        }

        // A newer document may have been waiting for this save to settle
        // before the engine decided what to do with it.
        env.engine()?.retry();
        resendIfOwed();
    }

    /**
     * Send the save that is owed, if one is and nothing stands in its way.
     * Called when a save answers, after every poll that completed (answered
     * or not: the engine's `onPolled` tick) and when the browser comes back
     * online. It only ever sends text that is still
     * unsaved HERE (`unsaved`); it never sends a copy the writer has not
     * touched, and that includes a copy the writer changed and changed
     * back: on every call it first checks whether the editor is back at
     * what the server last confirmed.
     */
    function resendIfOwed() {
        const handle = env.handle();
        // Fail-closed: nothing is written back.
        if (!handle || handle.autosaves === false) {
            return;
        }

        if (saveInTheAir()) {
            return;
        }

        // The writer may have undone what was unsaved. Here, after the
        // save-in-the-air check and before anything is sent, so that every
        // tick runs it and a document the writer did not change is never
        // sent (see settleIfBackAtConfirmed()).
        settleIfBackAtConfirmed(handle);

        if (!view.resave) {
            return;
        }

        // handle.pending means the bundle's own debounce is still armed.
        // It normally calls persist() itself, but not always: when the
        // editor is back at the document the bundle last handed over (a
        // typo and a backspace), the debounce finds nothing to send, and
        // that document may be one persist() only marked as owed. So the
        // save STAYS owed while the debounce is armed, and a later call
        // sends it if the debounce did not. Returning after `resave` was
        // cleared dropped it for good.
        if (handle.pending) {
            return;
        }

        view.resave = false;
        if (view.unsaved && !view.conflict) {
            persist(handle.editor.getJSON());
        }
    }

    /**
     * Drop the offline draft only when the document the server just stored
     * is still exactly what the editor holds AND nothing further is queued.
     * Anything else means the draft is still the only copy of something.
     *
     * @param {string} snapshot the document that was stored, as a JSON string
     * @returns {boolean} whether the save settled, that is, whether the
     *          editor still holds exactly what was stored. persist() clears
     *          `unsaved` on that answer, so it is given even when there is
     *          no draft store.
     */
    function clearDraftIfSettled(snapshot) {
        const handle = env.handle();
        if (!handle || handle.autosaves === false) {
            return false;
        }

        if (handle.pending) {
            return false;
        }

        if (JSON.stringify(handle.editor.getJSON()) !== snapshot) {
            return false;
        }

        const drafts = env.drafts();
        if (drafts) {
            drafts.clearDraft(view.docUuid);
        }

        return true;
    }

    /**
     * Nothing is unsaved after all: the editor is back at exactly what the
     * server last confirmed.
     *
     * An edit undone again inside the bundle's debounce never reaches
     * persist(): the bundle finds nothing to send, so no save would ever
     * answer to clear `unsaved`. The tab would say Editing for good, stop
     * following, raise a conflict over text it does not hold, and send a
     * document its writer did not change the next time the browser came
     * back online. So this runs on every tick: from resendIfOwed(), which
     * the engine reaches after every completed poll and backOnline() calls,
     * and from syncState(). It says Saved only when it takes the tab from
     * unsaved to nothing unsaved, so an idle reader says nothing.
     *
     * Both callers run it AFTER saveInTheAir() has answered no, never
     * before. While a save is in the air the server may be about to hold
     * something other than `confirmed`: type x, the save of it leaves,
     * delete x, and the editor is back at `confirmed` while the server is
     * about to store the x. Settling there would drop the owed save of the
     * deletion and say Saved over a document the server does not hold. The
     * `saving` test below is the same guard for a caller that forgets.
     * And once a save has gone unanswered, `confirmed` is null and nothing
     * is settled until the server has said what it holds.
     *
     * @param {object} handle the editor handle, mounted and not fail-closed
     */
    function settleIfBackAtConfirmed(handle) {
        if (
            !view.unsaved ||
            view.conflict ||
            view.saving > 0 ||
            handle.pending ||
            view.confirmed === null ||
            JSON.stringify(handle.editor.getJSON()) !== view.confirmed
        ) {
            return;
        }

        view.unsaved = false;
        view.overwriteOwed = false;
        env.report('good', 'Saved');
    }

    /**
     * What the sync engine may do with a newer document right now.
     *
     * @returns {'clean'|'busy'|'dirty'|'closed'}
     */
    function syncState() {
        const handle = env.handle();
        if (!handle || handle.autosaves === false) {
            return 'closed';
        }

        if (saveInTheAir()) {
            return 'busy';
        }

        // After the save-in-the-air check, never before it.
        settleIfBackAtConfirmed(handle);

        // `unsaved`, not only handle.pending: after a save that never
        // answered or was rejected, the bundle reports nothing pending
        // while this tab still holds text that exists nowhere else.
        if (view.conflict || view.unsaved || handle.pending) {
            return 'dirty';
        }

        return 'clean';
    }

    /**
     * This page just saved the document through some other action (a style
     * change, an accepted suggestion). Move the base up to the version that
     * produced, so the next autosave is not refused.
     *
     * A style change re-saves what the SERVER holds, not what this editor
     * holds. If the version it produced is not exactly one past this tab's
     * base, somebody else saved first and the editor does not have their
     * text: leave the base alone and let the engine bring the newer
     * document (or raise the conflict). Adopting the version blindly would
     * make this tab's next save erase their work. `contentLoaded` is for
     * the caller that has just put exactly that version's content into the
     * editor.
     *
     * @param {number} version
     * @param {{contentLoaded?: boolean}} options
     */
    function adoptVersion(version, { contentLoaded = false } = {}) {
        if (!Number.isFinite(version)) {
            return;
        }

        if (!contentLoaded && version !== view.baseVersion + 1) {
            env.engine()?.poke();

            return;
        }

        view.baseVersion = version;
        env.engine()?.saved(version);
        // An autosave that travelled with that action may have been
        // refused against the version the action itself produced.
        if (view.conflict && view.conflict.version <= version) {
            view.conflict = null;
            view.resave = true;
            resendIfOwed();
        }
    }

    /**
     * Put a newer server document into the editor. `force` is the Load
     * theirs choice, the only case allowed to replace unsaved typing.
     *
     * @param {{version: number, json: object, outline: object|null, css: string|null}} remote
     * @param {{force?: boolean}} options
     * @returns {boolean} whether the editor now shows that document
     */
    function applyFromSync(remote, { force = false } = {}) {
        const handle = env.handle();
        // Fail-closed: what the editor shows is not the document, so
        // nothing may be written into it.
        if (!handle || handle.autosaves === false) {
            return false;
        }

        if (!handle.applyRemote(remote.json, { force })) {
            return false;
        }

        // Text this tab had marked unsaved is no longer in the editor, and
        // no save will ever answer for it: the word that still says Editing
        // goes back to Saved. Only on that change, so a reader who follows
        // a document says nothing.
        if (view.unsaved) {
            env.report('good', 'Saved');
        }

        view.baseVersion = remote.version;
        view.unsaved = false;
        view.confirmed = JSON.stringify(handle.editor.getJSON());
        // Whatever this tab had put back is no longer in the editor.
        view.overwriteOwed = false;
        // The server now holds newer content than any draft.
        const drafts = env.drafts();
        if (drafts) {
            drafts.clearDraft(view.docUuid);
        }

        if (remote.outline) {
            env.showOutline(remote.outline);
        }

        // Somebody else may have changed the document style: the outline
        // carries its numbering and page setup, this carries its fonts
        // and colours.
        if (remote.css) {
            env.showCss(remote.css);
        }

        view.tick++;

        return true;
    }

    /**
     * A newer version exists and this tab has unsaved typing. Stop saving
     * and ask; the draft keeps the text in the meantime. `version` is the
     * version that is in the way: Keep mine needs only that number, not
     * the document itself.
     *
     * @param {number} version
     */
    function enterConflict(version) {
        const engine = env.engine();
        const waiting = engine ? engine.pending : null;

        view.conflict = {
            version: Math.max(
                Number.isFinite(version) ? version : 0,
                view.conflict ? view.conflict.version : 0,
                waiting ? waiting.version : 0
            ),
            ready: !!waiting,
        };
        env.report('danger', 'Not saved');
        // Download the newer document if the engine does not hold it.
        // refetch, not poke: the engine may already have SEEN that
        // version, and an ordinary poll would be told nothing changed.
        if (!view.conflict.ready) {
            engine?.refetch(view.baseVersion);
        }
    }

    /**
     * Keep mine: save this tab's text over the newer version, on purpose.
     * The base moves up to that version so the save is accepted. The
     * waiting document is left with the engine: when the save lands,
     * persist() tells the engine, which drops it; if the save is lost,
     * both buttons still work.
     */
    function keepMine() {
        const handle = env.handle();
        if (!handle || handle.autosaves === false || !view.conflict) {
            return;
        }

        // One choice at a time: a Keep mine save is already in the air.
        if (syncState() === 'busy') {
            return;
        }

        const waiting = env.engine()?.pending;
        const target = Math.max(view.conflict.version || 0, waiting ? waiting.version : 0);
        if (!target) {
            return;
        }

        view.baseVersion = Math.max(view.baseVersion, target);
        persist(handle.editor.getJSON(), { force: true });
    }

    /**
     * Load theirs: show the newer document. This tab's text is parked as a
     * stale- draft and also held in `setAside`, so the page can offer to
     * put it back.
     *
     * @returns {Promise<void>}
     */
    async function loadTheirs() {
        const handle = env.handle();
        const engine = env.engine();
        if (!handle || handle.autosaves === false || !engine) {
            return;
        }

        // One choice at a time: a Keep mine save is already in the air.
        if (syncState() === 'busy') {
            return;
        }

        const waiting = engine.takeRemote();
        if (!waiting) {
            engine.refetch(view.baseVersion);

            return;
        }

        const mine = JSON.stringify(handle.editor.getJSON());

        const drafts = env.drafts();
        if (drafts) {
            await drafts.parkStaleDraft(view.docUuid, mine, view.baseVersion);
            env.info(
                '[Dot.Doc] Your unsaved text was kept as stale-' +
                    view.docUuid +
                    ' and will be removed after 7 days.'
            );
        }

        if (!applyFromSync(waiting, { force: true })) {
            view.syncNotice = 'The newer version could not be opened here. Reload the page.';

            return;
        }

        view.setAside = mine;
        view.conflict = null;
        env.report('good', 'Saved');
    }

    /**
     * Put it back: the writer chose Load theirs and wants their own text
     * after all (or opened the page with a draft the document had moved
     * past). setContent() emits `update`, so the ordinary autosave sends
     * the text on the current base.
     */
    function putBack() {
        const handle = env.handle();
        // Fail-closed: nothing may be written into the editor.
        if (!handle || handle.autosaves === false || !view.setAside) {
            return;
        }

        try {
            handle.editor.commands.setContent(JSON.parse(view.setAside), { errorOnInvalidContent: true });
            view.setAside = null;
            // This replaces the version that was loaded, exactly as Keep
            // mine would have: the save that carries it says so, and the
            // server keeps the replaced version in the history first.
            view.overwriteOwed = true;
        } catch (_) {
            view.syncNotice = 'Your text could not be put back.';
        }
    }

    /**
     * The presence strip is rendered by Livewire; only ask it to re-render
     * when the set of people actually changed.
     *
     * @param {{id: number|string}[]} members
     */
    function membersChanged(members) {
        const key = members.map((member) => member.id).join(',');
        if (key === view.memberKey) {
            return;
        }

        const first = view.memberKey === '';
        view.memberKey = key;
        // The first report matches what the page was rendered with unless
        // somebody else is already here.
        if (first && members.length <= 1) {
            return;
        }

        env.refreshPresence();
    }

    /**
     * Look for an offline draft of this document when the page opens.
     *
     * loadDraft returns {json, savedAt, baseVersion}. A draft is offered
     * only when it was written against THIS version of the document - if
     * the version has moved on, somebody else has saved since and restoring
     * the draft would overwrite them.
     *
     * @returns {Promise<void>}
     */
    async function restoreDraft() {
        const drafts = env.drafts();
        if (!drafts) {
            return;
        }

        const handle = env.handle();
        // Fail-closed: the editor is read-only and holds something that is
        // not the document, so neither restore a draft nor delete one.
        if (!handle || handle.autosaves === false) {
            return;
        }

        const editor = handle.editor;

        try {
            const draft = await drafts.loadDraft(view.docUuid);
            if (!draft) {
                return;
            }

            const parsed = JSON.parse(draft.json);
            if (!parsed || parsed.type !== 'doc' || draft.baseVersion === null) {
                drafts.clearDraft(view.docUuid);

                return;
            }

            if (draft.baseVersion < view.documentVersion) {
                // Not restorable, but not this page's to destroy either.
                // Park it under a stale- key so it can be recovered by
                // hand; parkStaleDraft() stamps parkedAt, and the sweep on
                // the next app boot collects it after 7 days.
                await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion);
                drafts.clearDraft(view.docUuid);
                // Offer it in the page as well: the notice bar shows
                // Your text was set aside, with Put it back. Not when the
                // document already says exactly this (the unload beacon
                // stored it): there is nothing to put back.
                if (env.documentsDiffer(parsed, editor.getJSON())) {
                    view.setAside = draft.json;
                }
                env.info(
                    '[Dot.Doc] An offline draft based on v' +
                        draft.baseVersion +
                        ' was kept as stale-' +
                        view.docUuid +
                        ': the document is now at v' +
                        view.documentVersion +
                        ', so restoring it would overwrite a newer save.'
                );

                return;
            }

            if (draft.baseVersion > view.documentVersion) {
                drafts.clearDraft(view.docUuid);

                return;
            }

            // Same version. Outline::apply() stamps toc.entries and
            // crossRef.label into the stored document, so those come off
            // both sides or every load would look like a difference.
            if (!env.documentsDiffer(parsed, editor.getJSON())) {
                drafts.clearDraft(view.docUuid);

                return;
            }

            if (!env.confirm('An unsaved offline draft of this document was found. Restore it?')) {
                // Declining is not the same as discarding, and this is a
                // single confirm() with no undo behind it. Park the draft
                // rather than delete it, so a mis-click stays recoverable
                // for the 7 days the sweep leaves it alone.
                await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion);
                drafts.clearDraft(view.docUuid);
                env.info(
                    '[Dot.Doc] The offline draft was not restored. It was kept as stale-' +
                        view.docUuid +
                        ' and will be removed after 7 days.'
                );

                return;
            }

            try {
                editor.commands.setContent(parsed, { errorOnInvalidContent: true });
            } catch (_) {
                // Unopenable: keep the draft rather than lose it.
                env.info('[Dot.Doc] The offline draft could not be applied and has been kept.');

                return;
            }
            // The draft is NOT cleared here. The restore emits `update`,
            // which rewrites the draft and sets `unsaved`; the save that
            // follows can now be refused as a conflict, and until it has
            // settled the draft is the only stored copy of this text.
            // persist() clears it once the save has gone through.
        } catch (_) {
            // A draft that cannot be read is left where it is.
        }
    }

    /**
     * An accepted suggestion is a document the server has already stored,
     * so it arrives as JSON and goes in the same way a collaborator's
     * update does: validated, outside the undo stack, and refused rather
     * than blanking the page.
     *
     * @param {object} content
     * @param {number} version the version the server stored it as
     */
    function applySuggestion(content, version) {
        const handle = env.handle();
        if (!handle) {
            return;
        }

        // The same fail-closed gate applyFromSync() has. In that mode the
        // content check refused the document: the editor is read-only and
        // what it is showing is not the document, so merging an accepted
        // suggestion into the view would show the writer a document that
        // exists nowhere.
        if (handle.autosaves === false) {
            view.aiError =
                'This document is open read-only, so the accepted suggestion was not applied here. Reload the page once the content problem is fixed.';

            return;
        }

        if (!handle.applyRemote(content, { force: true })) {
            view.aiError = 'That suggestion could not be applied — the document is unchanged.';

            return;
        }

        // The editor now holds exactly the version the server stored. Text
        // this tab had marked unsaved is no longer in it, and no save will
        // answer for it: the word that still says Editing goes back to
        // Saved. Only on that change.
        if (view.unsaved) {
            env.report('good', 'Saved');
        }
        view.unsaved = false;
        view.confirmed = JSON.stringify(handle.editor.getJSON());
        view.overwriteOwed = false;
        adoptVersion(version, { contentLoaded: true });
        view.aiError = '';
        view.tick++;
        refreshOutline();
    }

    /**
     * Back online: send what was typed while offline, and ONLY that.
     * resendIfOwed() saves when this tab holds unsaved text and does
     * nothing otherwise; text typed and deleted again is not unsaved text,
     * and resendIfOwed() checks for that before it sends anything (see
     * settleIfBackAtConfirmed()). Saving unconditionally, as the page used to, sent
     * an idle reader's stale copy: refused as a conflict that reader never
     * caused, or stored as a new version that threw everybody who was
     * typing into one. The draft is NOT cleared here: persist() clears it
     * itself, and only once the save has stored what the editor is holding.
     */
    function backOnline() {
        view.resave = true;
        resendIfOwed();
        env.engine()?.poke();
    }

    /**
     * The callbacks the sync engine is created with (its `host` option).
     * The engine keeps them for as long as it lives, so they stay bound to
     * the data object that started it, as the page's own handlers do.
     */
    function engineHost() {
        return {
            state: () => syncState(),
            applyRemote: (remote) => applyFromSync(remote),
            onConflict: (remote) => enterConflict(remote.version),
            onRefused: () => {
                view.syncNotice = 'A newer version could not be opened here. Reload the page.';
            },
            // Every answered poll is also the moment to send a save that
            // never answered.
            onMembers: (members) => {
                membersChanged(members);
                resendIfOwed();
            },
            // And so is every poll that completed without an answer from
            // the application (a firewall's 403, a 5xx, no network): saving
            // must not depend on the follow loop being healthy. On an
            // answered poll this is the second call; by then the save is in
            // the air or nothing is owed, so it does nothing.
            onPolled: () => resendIfOwed(),
            onStopped: (reason) => {
                view.syncNotice =
                    {
                        'signed-out': 'You have been signed out. Reload the page to keep editing.',
                        forbidden: 'You no longer have access to this document.',
                        gone: 'This document no longer exists.',
                    }[reason] || 'This page has stopped updating. Reload it.';
                env.report('danger', 'Not saved');
            },
        };
    }

    return {
        opened,
        edited,
        persist,
        resendIfOwed,
        clearDraftIfSettled,
        syncState,
        adoptVersion,
        applyFromSync,
        enterConflict,
        keepMine,
        loadTheirs,
        putBack,
        membersChanged,
        restoreDraft,
        applySuggestion,
        backOnline,
        engineHost,
    };
}
