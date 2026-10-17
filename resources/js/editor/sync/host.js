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
 * everything outside the state object arrives in `env`. Its one import is
 * ./merge.js, inside sync/: the helpers that build the lost row's entries.
 *
 * Phase 2 (the paragraph-level merge, docs/superpowers/specs/
 * 2026-10-16-coediting-phase-2-paragraph-merge-design.md): a newer document
 * that arrives while this tab holds unsaved typing is MERGED block by block
 * (mergeOrConflict()), applied over the typing and saved on the new base;
 * a save refused as stale fetches the newer document to merge it
 * (beginMerge()); the conflict notice is for a merge that cannot be done.
 * What the merge could not keep is offered back in the notice bar's lost
 * row (offerLost(), putBackLost(), dismissLost()).
 */

import { appendLost, blockWord, lostEntry } from './merge.js';

/** How long a save is waited for before it is taken as never answering. */
export const SAVE_EXPIRY_MS = 15000;

/**
 * How many merges after refused saves are tried in a row before the
 * conflict notice (section 5.4 of the Phase 2 spec; the spec's starting
 * value was 3, raised after the random walk of the dry run showed three
 * writers reaching it with every merge possible). Reset by an accepted
 * save, by a merge that leaves nothing of mine to save, by enterConflict()
 * and by applyFromSync().
 */
export const MERGE_ATTEMPTS = 5;

/** How long the fetch for a merge is waited for before the conflict notice (section 5.7). */
export const MERGE_FETCH_EXPIRY_MS = SAVE_EXPIRY_MS;

/** How many item slots the notice bar's lost row has; the markup in editor.blade.php mirrors it. */
export const LOST_ROWS = 5;

/** The sentence of each lost-row item, by reason and source (section 6.2). The `parked` source prefixes a stored sentence instead (restoreDraft()). */
const LOST_SENTENCES = {
    'both:merge': (word) => `This ${word} was changed elsewhere at the same moment. The other version is on the page; this is yours.`,
    'deleted:merge': (word) => `This ${word} was removed elsewhere while you were typing in it.`,
    'both:draft': (word) =>
        `This ${word} was changed in the document after your draft was written. The document's version is on the page; this is the draft's.`,
    'deleted:draft': (word) => `This ${word} was removed from the document after your draft was written.`,
    'both:suggestion': (word) =>
        `This ${word} was rewritten by the accepted suggestion. The suggestion's version is on the page; this is yours.`,
    'deleted:suggestion': (word) => `The accepted suggestion removed this ${word} while you were typing in it.`,
};

/**
 * What Put it back asks before it replaces the page with text that was set
 * aside, whenever the writer cannot be taken to know what that replaces:
 * the text is a draft from an earlier visit, or the document has moved on
 * since the text was set aside, or the page holds text that is not saved.
 */
export const PUT_BACK_QUESTION =
    'Put this text back? It will replace the text on the page now, including everything saved since this text was written. The version it replaces is kept in the history.';

/** Added to the question when the page holds text no saved version has. */
export const PUT_BACK_UNSAVED_WARNING = ' Text on the page that has not been saved yet will be lost.';

/**
 * What Discard asks before it withdraws the offer of text this tab set aside
 * itself (Load theirs): that text is the writer's unsaved typing and is in no
 * version, so once the offer is gone only the parked copy holds it, and
 * nothing in the page offers that again.
 */
export const DISCARD_QUESTION =
    'Discard this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';

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
 * @typedef {object} SyncView The Blade component's state fields.
 * @property {number} saving how many of this tab's own saves are in the air
 * @property {number} savingSince when the latest of them left
 * @property {number} saveSeq the number persist() gave the last save it sent;
 *           every save this tab sends gets the next one
 * @property {number} expiredSaveSeq the number of the last save this tab gave
 *           up on (saveInTheAir()): the answer to a save numbered at or below
 *           it comes too late to be trusted on its own
 * @property {boolean} unsaved true from the first local edit until a save of
 *           exactly what the editor holds has been confirmed by the server
 * @property {boolean} resave a save is owed; resendIfOwed() sends it
 * @property {string|null} confirmed the document, as a JSON string, as the
 *           server last confirmed it, which is the document of the version
 *           `baseVersion` names. Null before the editor is mounted, and
 *           again whenever that is no longer known: from the moment one of
 *           this tab's saves goes unanswered, and from the moment Keep mine
 *           moves the base up to a version the editor has never held. It is
 *           set again when a save is accepted or a server document applied
 * @property {boolean} overwriteOwed this tab's text is to replace, on
 *           purpose, the version its base now names (Keep mine moved the
 *           base up to it; Put it back replaces the version that was
 *           loaded). Every save says so (the Keep mine save, a retry, an
 *           ordinary autosave, the unload beacon) until one that said so is
 *           accepted, and the server keeps the replaced version first
 * @property {{version: number, ready: boolean}|null} conflict
 * @property {string|null} setAside the writer's own text, as a JSON string,
 *           kept so the page can offer to put it back
 * @property {'conflict'|'draft'|null} setAsideFrom where `setAside` came
 *           from: `conflict` is this tab's text, set aside a moment ago by
 *           Load theirs; `draft` is a draft found at page load that the
 *           document has moved past. Null while nothing is set aside
 * @property {number|null} setAsideBase the document version the page showed
 *           when the text was set aside. Null while nothing is set aside
 * @property {string} syncNotice
 * @property {string} memberKey
 * @property {string} docUuid
 * @property {number} documentVersion the version this page was rendered from
 * @property {number} baseVersion the version this tab's copy is based on
 * @property {string} aiError
 * @property {number} tick
 * @property {{version: number, since: number}|null} merging set while a
 *           newer document is being fetched for a merge (beginMerge());
 *           persist() sends nothing while it is, syncState() reports dirty,
 *           and resendIfOwed() gives it up after MERGE_FETCH_EXPIRY_MS
 * @property {number} mergeAttempts merges after refused saves, in a row.
 *           Reset by an accepted save, by enterConflict() and by applyFromSync()
 * @property {{version: number, json: string}|null} known the last server
 *           document this tab held, as the editor serialises it, with its
 *           version. NEVER nulled: `confirmed` is nulled on purpose (a lost
 *           answer, a failed request, Keep mine) while the last agreed
 *           document is still the right merge base, as long as its version
 *           is the one the typing is based on
 * @property {string|null} sentSnapshot the JSON string of the save now in the air
 * @property {string|null} unanswered the JSON string of the last save given
 *           up on or whose request failed; the merge's echo (section 4.2).
 *           Null once anything is confirmed
 * @property {object[]} lost the lost row's entries (section 6.1), oldest first
 */

/**
 * @typedef {object} SyncEnv Everything the page hands the module.
 * @property {() => object|null|undefined} handle the editor handle the bundle
 *           parked on the element (`editor`, `autosaves`, `pending`,
 *           `applyRemote`), or nothing when no editor is mounted
 * @property {() => object|null|undefined} engine the sync engine parked on
 *           the element, or nothing before it has started
 * @property {(json: object, baseVersion: number, overwrite: boolean) => Promise<{ok: boolean, conflict: boolean, version: number|null}>} save
 *           Editor::saveContent(). `version` is null in one answer only: the
 *           refusal of a save that stated no base
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
     * needs to know whether a save is in the air asks here (persist(),
     * syncState(), resendIfOwed(), edited() and putBack()). Whichever of
     * them notices first leaves the same state behind. persist() is the
     * one that matters when nothing else runs: resendIfOwed() is reached
     * from a poll, and a tab whose polls are blocked must still be able to
     * save.
     *
     * A save that is given up on may still have been STORED: only its
     * answer is known to be lost. From then on what the server holds is not
     * known, so nothing counts as confirmed (`confirmed` is null) until a
     * save is accepted or a server document is applied. Without that, an
     * edit sent, stored, never answered and then undone would find the
     * editor back at the last confirmed document, be written off as Saved,
     * and the next poll would bring the undone text back.
     *
     * The number of the save given up on is remembered (`expiredSaveSeq`),
     * because its answer may still arrive: persist() then knows the answer
     * is late and decides whether it can still be used (see there).
     *
     * @returns {boolean}
     */
    function saveInTheAir() {
        if (view.saving > 0 && env.now() - view.savingSince >= SAVE_EXPIRY_MS) {
            view.saving = 0;
            view.resave = true;
            view.confirmed = null;
            // The save may have been stored. If the next merge finds its
            // document on the server, that change is this tab's own.
            view.unanswered = view.sentSnapshot;
            view.expiredSaveSeq = view.saveSeq;
        }

        return view.saving > 0;
    }

    /**
     * The editor has just been mounted by this page. What it holds now is
     * what the server last confirmed.
     */
    function opened() {
        const handle = env.handle();

        // Confirmed and known; the base row is written at the END of
        // restoreDraft(): a draft of an earlier version is merged against
        // the row of ITS version, which this page must read before it
        // writes its own.
        markConfirmed(JSON.stringify(handle.editor.getJSON()), view.baseVersion, { writeRow: false });
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
        // Never touch a draft in fail-closed mode: what the editor is
        // holding then is not the document.
        if (!drafts || !handle || handle.autosaves === false) {
            return;
        }

        const json = JSON.stringify(handle.editor.getJSON());
        // A draft that says exactly what the server last confirmed is never
        // kept. It protects nothing, and it would outlive the page: once
        // anybody has saved, the next visit would find a draft that
        // differs from the document and offer to put back a copy that
        // holds none of this writer's text. So an edit undone again clears
        // the draft instead of rewriting it. Not while a save is in the
        // air, though: the server may be about to hold something else (the
        // very text that was just undone), and then this draft is the only
        // record that the writer took it out again. settleIfBackAtConfirmed()
        // clears it once that save has answered.
        if (!saveInTheAir() && json === view.confirmed) {
            drafts.clearDraft(view.docUuid);

            return;
        }

        // While an overwrite is owed, this text is not a draft OF the
        // version the base names: it is there to replace that version on
        // purpose (Keep mine moved the base up to it; Put it back replaces
        // the version that was loaded). Were the draft stamped with that
        // base, a page opened after a crash or a reload would find a draft
        // of the current version, offer a plain restore, and save it with
        // no overwrite flag: the replaced version would be kept nowhere.
        // Stamped one below, it is older than the document for every later
        // page: restoreDraft() sets it aside, and Put it back saves it as
        // an overwrite.
        drafts.saveDraft(view.docUuid, json, view.overwriteOwed ? view.baseVersion - 1 : view.baseVersion);
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
     * replaced version in the history first. Every save sent while
     * `overwriteOwed` is set carries the same flag: keepMine() and
     * putBack() set it, and it stays set until a save that CARRIED it is
     * accepted. So a retry of a Keep mine save that failed, and the
     * autosave after Put it back, say so too. Whether a save carried it is
     * decided when it is sent and handed to saveAnswered(): the answer to
     * an ordinary save that left before the overwrite was owed must not
     * write the overwrite off.
     *
     * Sending clears `resave`: the save that was owed is the one going out.
     * Left set, the flag outlived the send, and when the server rejected
     * that save resendIfOwed() found it and sent the same document again.
     *
     * Every save gets a number (`saveSeq`), so that an answer can be told
     * apart from the save in the air now. A save that saveInTheAir() has
     * given up on (its number is at or below `expiredSaveSeq`) may still
     * answer. Such an answer is used only while the tab has learnt nothing
     * newer since it left: nothing is confirmed (no later save accepted, no
     * server document applied) and the base is where it was. Otherwise it
     * is logged and ignored: adopting it would move the base onto a
     * version the editor does not show (Load theirs after a Keep mine
     * save that was given up on), and the next save would replace that
     * version without the overwrite flag. The poll brings the real outcome
     * to a tab that follows, and resendIfOwed() has the text that is still
     * unsaved. Whether or not a late answer is used, it does not count
     * against `saving`: that number was zeroed when the save was given up
     * on, and now counts the save that replaced it. A late FAILURE says
     * nothing at all about the save in the air, so it is only logged.
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

        // A newer document is being fetched to merge: the base this save
        // would state is known to be stale, so nothing goes out until the
        // merge has run (it sends the merged document itself). Owed, so
        // nothing typed meanwhile is dropped.
        if (view.merging && !force) {
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

        // The owed save is this one.
        view.resave = false;
        // What is in the air, for the echo rule should its answer be lost.
        view.sentSnapshot = snapshot;
        view.saving++;
        view.savingSince = env.now();
        const seq = ++view.saveSeq;
        const baseAtSend = view.baseVersion;

        // Whether THIS save says it overwrites, captured now.
        const overwrite = force || view.overwriteOwed;

        /** Whether saveInTheAir() gave this save up before it answered. */
        const givenUpOn = () => seq <= view.expiredSaveSeq;

        // Two handlers on ONE then(), not then().catch(): a catch() at the
        // end would also catch whatever goes wrong AFTER the answer, report
        // a save the server stored as Not saved, and count that save as
        // answered a second time.
        return env.save(json, view.baseVersion, overwrite).then(
            (result) => {
                if (givenUpOn()) {
                    if (view.confirmed !== null || view.baseVersion !== baseAtSend) {
                        env.log(
                            '[Dot.Doc] A save that was given up on answered after the page had moved on; the answer is ignored.',
                            result
                        );

                        return undefined;
                    }
                } else {
                    view.saving = Math.max(0, view.saving - 1);
                }

                try {
                    // A Put it back pressed after this save left replaced the
                    // text it carried: its answer must not write off the
                    // overwrite that text still owes.
                    saveAnswered(result, snapshot, overwrite && view.putBackSeq < seq);
                } catch (error) {
                    // Not the save's failure: the save has been answered.
                    env.log('[Dot.Doc] Something failed after a save was answered.', error);
                }

                return refreshOutline();
            },
            (error) => {
                if (givenUpOn()) {
                    env.log('[Dot.Doc] A save that was given up on failed after all; nothing is read into it.', error);

                    return;
                }

                // The save itself failed: the request was never answered.
                // Whether the server stored it is not known, so nothing
                // counts as confirmed any more (see saveInTheAir()).
                view.saving = Math.max(0, view.saving - 1);
                view.confirmed = null;
                // The server may have stored it all the same (section 5.6).
                view.unanswered = view.sentSnapshot;
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
     * @param {{ok?: boolean, conflict?: boolean, version?: number|null}|null|undefined} result
     * @param {string} snapshot the document that was sent, as a JSON string
     * @param {boolean} overwrite whether that save said it overwrites
     */
    function saveAnswered(result, snapshot, overwrite) {
        if (result && result.ok) {
            view.baseVersion = result.version;
            view.conflict = null;
            view.mergeAttempts = 0;
            markConfirmed(snapshot, result.version);
            // A merge under way for this version or an older one is over: the
            // fetch it started would bring the very version this answer
            // names, which engine.saved() below marks as seen, so it would
            // never be delivered and `merging` would hold every save until
            // the 15 s expiry raised a notice nobody caused (a late answer of
            // a save given up on, arriving while the fetch is out).
            if (view.merging && view.merging.version <= result.version) {
                view.merging = null;
                view.resave = true;
            }
            // The overwrite is settled only by a save that said so. An
            // ordinary save that left before Put it back was pressed did
            // not, and the put-back save still has to.
            if (overwrite) {
                view.overwriteOwed = false;
            }
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
            // Refused as stale (section 5.4): the text is still owed. The
            // writer choosing in the notice is never merged behind their
            // back; the refusal after MERGE_ATTEMPTS merges in a row ends in
            // the notice too; else the newer document is fetched and merged.
            view.resave = true;
            if (view.conflict || view.mergeAttempts >= MERGE_ATTEMPTS) {
                enterConflict(result.version);
            } else {
                view.mergeAttempts += 1;
                beginMerge(result.version);
            }
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
        // A fetch for a merge that has not answered in fifteen seconds ends
        // in the notice; the owed save goes out after the writer's choice.
        expireMerging();

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
     * unsaved to nothing unsaved, so an idle reader says nothing. The
     * offline draft goes with it: it says what the server holds.
     *
     * All three callers (syncState(), resendIfOwed() and putBack()) run it
     * AFTER saveInTheAir() has answered no, never before. While a save is in the air the server may be about to hold
     * something other than `confirmed`: type x, the save of it leaves,
     * delete x, and the editor is back at `confirmed` while the server is
     * about to store the x. Settling there would drop the owed save of the
     * deletion and say Saved over a document the server does not hold. The
     * `saving` test below is the same guard for a caller that forgets.
     * And once a save has gone unanswered, or Keep mine has moved the base
     * up, `confirmed` is null and nothing is settled until the server has
     * said what it holds.
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
        // The draft now says what the server holds: it is not kept (see
        // edited()).
        const drafts = env.drafts();
        if (drafts) {
            drafts.clearDraft(view.docUuid);
        }
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

        // Never apply a document under an IME composition: it would land in
        // the middle of a character being composed. The next poll or
        // retry() delivers.
        if (handle.composing) {
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

        // A restyle changes only stamps, which the merge's levelling
        // absorbs: the last known document is still the right merge base,
        // and it is now of this version. Only when `known` was current:
        // after Keep mine the base has moved past it, and it must not be
        // given the version the editor never held. applySuggestion() loads
        // the content itself and confirms it itself.
        const knownIsCurrent = !contentLoaded && view.known !== null && view.known.version === view.baseVersion;

        view.baseVersion = version;
        env.engine()?.saved(version);

        if (knownIsCurrent) {
            view.known = { version, json: view.known.json };
            writeBase(view.known.json, version);
        }

        // An autosave that travelled with that action may have been
        // refused against the version the action itself produced: the
        // merge it started, or the notice it raised, is over, and the owed
        // text goes out on the adopted base.
        if (view.merging && view.merging.version <= version) {
            view.merging = null;
            view.mergeAttempts = 0;
            view.resave = true;
            resendIfOwed();
        }
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
        // A merge under way is over: the tab was clean after all (it undid
        // everything while the fetch was out), so it follows instead.
        view.merging = null;
        view.mergeAttempts = 0;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), remote.version);
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
     * The server document this tab now holds is confirmed: `confirmed` and
     * `known` are set to it, the echo is forgotten, and the base row is
     * written so a draft of this version can be merged on a later visit.
     *
     * Called from opened() (the page document), saveAnswered() on ok (the
     * snapshot that was stored), applyFromSync() and applySuggestion() (the
     * editor's own serialisation of what was applied) and mergeOrConflict()
     * (theirs, or the editor's serialisation when nothing of mine survived).
     * `confirmed = null` stays a direct assignment where it is (a lost
     * answer, a failed request, Keep mine); `known` is never nulled.
     *
     * @param {string} json the document as a JSON string
     * @param {number} version the version it is
     * @param {{writeRow?: boolean}} options `writeRow: false` is opened()'s:
     *        the row is written once the draft check has read the old one
     */
    function markConfirmed(json, version, { writeRow = true } = {}) {
        view.confirmed = json;
        view.known = { version, json };
        view.unanswered = null;
        if (writeRow) {
            writeBase(json, version);
        }
    }

    /** The base row, never in fail-closed mode: what a read-only view holds is not the document. */
    function writeBase(json, version) {
        const handle = env.handle();
        if (!handle || handle.autosaves === false) {
            return;
        }

        env.drafts()?.saveBase(view.docUuid, json, version);
    }

    /**
     * A save was refused as stale: fetch the newer document and merge the
     * typing into it (section 5.4). When the engine already holds a document
     * at least that new, merge it at once.
     *
     * refetch(), not poke(): the engine may have SEEN that version already,
     * and an ordinary poll would be told nothing changed.
     *
     * @param {number} version the version the server is at
     */
    function beginMerge(version) {
        const handle = env.handle();
        // Fail-closed: nothing is fetched to be written into a read-only
        // view (and expireMerging() would raise "Not saved" on it 15 s on).
        if (!handle || handle.autosaves === false) {
            return;
        }

        const engine = env.engine();
        if (!engine) {
            enterConflict(version);

            return;
        }

        const waiting = engine.pending;
        if (waiting && waiting.version >= version) {
            mergeOrConflict(waiting);

            return;
        }

        // A document OLDER than the refused version (polled while the save
        // was in the air) is dropped: the fetch below replaces it. Left in
        // place, the retry() that follows every answered save would hand it
        // to mergeOrConflict(), which would merge it, end `merging` and send
        // the merged document on a base known to be stale: a refusal for
        // nothing, and one of the attempts gone.
        if (waiting) {
            engine.takeRemote();
        }

        view.merging = { version, since: env.now() };
        engine.refetch(view.baseVersion);
    }

    /**
     * A fetch for a merge that has not answered in MERGE_FETCH_EXPIRY_MS is
     * given up on: the notice stands, the engine's own back-off keeps
     * polling, and the owed save goes out after the writer's choice.
     */
    function expireMerging() {
        if (view.merging && env.now() - view.merging.since >= MERGE_FETCH_EXPIRY_MS) {
            const version = view.merging.version;
            view.merging = null;
            enterConflict(version);
        }
    }

    /**
     * A newer server document has arrived while this tab holds unsaved
     * typing: merge it, or raise the notice when that cannot be done
     * (section 5.5). The engine calls this through onConflict (a poll
     * brought the document to a dirty tab) and beginMerge() calls it (the
     * fetch after a refused save answered, or the engine already held it).
     *
     * @param {{version: number, json: object, outline: object|null, css: string|null}} remote
     */
    function mergeOrConflict(remote) {
        const handle = env.handle();
        // 1. Fail-closed: nothing.
        if (!handle || handle.autosaves === false) {
            return;
        }

        // 2. The notice stands: the writer is choosing, and nothing is
        //    merged behind their back. The notice learns the newer version.
        if (view.conflict) {
            enterConflict(remote.version);

            return;
        }

        // 3. A save in the air: the engine retries after its answer.
        if (saveInTheAir()) {
            return;
        }

        // 3a. A document older than the one under merge (a poll that was
        //     already in the air when the refusal started the fetch): the
        //     queued poll brings the newer one, and merging this one would
        //     send a save on a base known to be stale and spend an attempt.
        if (view.merging && remote.version < view.merging.version) {
            env.engine()?.takeRemote();

            return;
        }

        // 3b. An IME composition: what is being composed is in the DOM and
        //     not yet in the editor state, so applying now could garble it
        //     and it would not be in `lost`. beginMerge() reaches this
        //     without syncState()'s composing guard when the engine already
        //     holds the document. Hold the merge: the document stays pending
        //     in the engine, persist() stays held by `merging`, and the next
        //     poll's deliver() (the tab is dirty, not busy, once the
        //     composition ends) merges it.
        if (handle.composing) {
            view.merging ??= { version: remote.version, since: env.now() };

            return;
        }

        // 4. The base: the confirmed document, or the last known one when
        //    nothing is confirmed (a lost answer, a failed request) and it
        //    is of the version the typing is based on. After Keep mine the
        //    base names a version the editor never held: no base, so the
        //    notice, until a save is accepted.
        const base =
            view.confirmed ?? (view.known && view.known.version === view.baseVersion ? view.known.json : null);
        if (base === null) {
            view.merging = null;
            enterConflict(remote.version);

            return;
        }

        // 5. The merge. The echo is the document of a save whose answer was
        //    lost: wherever theirs agrees with it, that change is this tab's
        //    own and typing on top of it is kept, not offered back.
        let result = null;
        try {
            result = handle.merge(JSON.parse(base), remote.json, {
                echo: view.unanswered ? JSON.parse(view.unanswered) : null,
            });
        } catch (_) {
            result = null;
        }
        if (!result) {
            view.merging = null;
            enterConflict(remote.version);

            return;
        }

        // 6. The merged document goes in, over the typing it holds.
        let applied = false;
        try {
            applied = handle.applyRemote(result.merged, { force: true }) === true;
        } catch (_) {
            applied = false;
        }
        if (!applied) {
            view.merging = null;
            enterConflict(remote.version);

            return;
        }

        // 7. The bookkeeping. `confirmed` is theirs as this editor serialises
        //    it (plus the carried trailing paragraph) when something of mine
        //    survived, which is what the merged save is based on; when
        //    nothing did, the editor's own serialisation, so the settle check
        //    can find the editor back at it. The overwrite is ORed, never
        //    assigned, while something of mine is left to save: one owed from
        //    Put it back or from an earlier c2 merge survives a merge that owes
        //    none, until a save that carried it is accepted. A merge that
        //    leaves nothing of mine to save (changed false) sends nothing, so
        //    no save is left to carry the flag: it is cleared.
        const wasUnsaved = view.unsaved;
        view.merging = null;
        view.baseVersion = remote.version;
        view.conflict = null;
        markConfirmed(result.changed ? result.theirs : JSON.stringify(handle.editor.getJSON()), remote.version);
        view.overwriteOwed = result.changed ? view.overwriteOwed || result.overwrites : false;
        view.unsaved = result.changed;
        if (!result.changed) {
            view.mergeAttempts = 0; // nothing owed: the row of refusals is over
        }
        if (result.lost.length > 0) {
            offerLost(result.lost, 'merge', remote.version);
        }
        env.engine()?.saved(remote.version);
        if (remote.outline) {
            env.showOutline(remote.outline);
        }
        if (remote.css) {
            env.showCss(remote.css);
        }
        view.tick++;

        // 8. The draft: of the merged document while something of mine is
        //    unsaved (stamped one below while an overwrite is owed, as
        //    edited() stamps it), cleared when the server holds everything.
        const drafts = env.drafts();
        if (drafts) {
            if (result.changed) {
                drafts.saveDraft(
                    view.docUuid,
                    JSON.stringify(handle.editor.getJSON()),
                    view.overwriteOwed ? view.baseVersion - 1 : view.baseVersion
                );
            } else {
                drafts.clearDraft(view.docUuid);
            }
        }

        // 9. Something of mine survived: it is saved on the new base, now.
        //    Nothing did: this was a follow in disguise, and the word goes
        //    back to Saved once.
        if (result.changed) {
            view.resave = true;
            persist(handle.editor.getJSON());
        } else if (wasUnsaved) {
            env.report('good', 'Saved');
        }
    }

    /**
     * What a merge could not keep goes into the lost row (section 6.1).
     * Append, never replace: a paragraph lost twice in a row keeps both
     * offered versions. The whole list is parked under lost-<uuid>, its own
     * key, so a reload re-offers it and Load theirs' stale- park is not
     * overwritten.
     *
     * @param {object[]} lost the merge result's losses
     * @param {'merge'|'draft'|'suggestion'} source
     * @param {number} version the version the merge was against
     */
    function offerLost(lost, source, version) {
        const entries = lost.map((loss, n) =>
            lostEntry(loss, { source, version, n, sentence: sentenceFor(loss.reason, source, blockWord(loss.type)) })
        );
        view.lost = appendLost(view.lost, entries);
        env.drafts()?.parkLost(view.docUuid, view.lost, view.baseVersion);
        env.info(`[Dot.Doc] ${entries.length} block(s) could not be kept by the ${source} and are offered back in the notice bar.`);
    }

    /**
     * The sentence of a lost-row item (section 6.2).
     *
     * @param {'both'|'deleted'} reason
     * @param {'merge'|'draft'|'suggestion'} source
     * @param {string} word blockWord() of the block's type
     * @returns {string}
     */
    function sentenceFor(reason, source, word) {
        return LOST_SENTENCES[`${reason}:${source}`](word);
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
        // The notice stands: no merge is tried behind the writer's back, and
        // the count of merges after refusals starts over after it.
        view.merging = null;
        view.mergeAttempts = 0;

        const engine = env.engine();
        let waiting = engine ? engine.pending : null;
        const named = Math.max(Number.isFinite(version) ? version : 0, view.conflict ? view.conflict.version : 0);

        // A document the engine holds that is OLDER than the version the
        // notice names is not "theirs": Load theirs would show a version the
        // head has moved past (a Keep mine save refused by a still newer
        // version leaves exactly that). It is dropped, and the refetch below
        // brings the newest.
        if (waiting && waiting.version < named) {
            engine.takeRemote();
            waiting = null;
        }

        view.conflict = {
            version: Math.max(named, waiting ? waiting.version : 0),
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
     *
     * From the moment the base has moved up, EVERY save from this tab would
     * be accepted over the other person's version, not only the one sent
     * here: a retry after this one failed, the unload beacon, a later
     * autosave. So the overwrite is marked as owed at that same moment, and
     * stays owed until a save that says so is accepted.
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
        view.overwriteOwed = true;
        // `confirmed` is the document of the version this tab was based on
        // BEFORE the base moved. The base now names a version the editor
        // has never held, so the editor being back at `confirmed` would
        // prove nothing: written off as Saved there (should the notice be
        // cleared without a save, by a style change of this page's own),
        // the tab would show the old version on the new base and owe no
        // overwrite, and its next keystroke would replace the other
        // person's text with no version kept. Nothing counts as confirmed
        // until a save is accepted or a server document is applied.
        view.confirmed = null;
        persist(handle.editor.getJSON(), { force: true });
    }

    /**
     * Load theirs: show the newer document. This tab's text is parked as a
     * stale- draft and also held in `setAside`, so the page can offer to
     * put it back. `setAsideFrom` and `setAsideBase` record that it came
     * from this choice and which version the page showed at that moment.
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
        view.setAsideFrom = 'conflict';
        // The version the page shows now: applyFromSync() has just moved
        // the base to it. Put it back straight away replaces exactly this
        // version, which the writer has just chosen to look at.
        view.setAsideBase = view.baseVersion;
        view.conflict = null;
        // The word went back to Saved inside applyFromSync(), which took
        // the tab from unsaved to nothing unsaved; it is not said again.
    }

    /**
     * Put it back: the writer chose Load theirs and wants their own text
     * after all (or opened the page with a draft the document had moved
     * past). setContent() emits `update`, so the ordinary autosave sends
     * the text on the current base, as an overwrite.
     *
     * It replaces everything on the page, and through the save that
     * follows the stored document, so the writer has to know what that is.
     * Straight after Load theirs they do: it is the version they have just
     * chosen to look at. In three cases they may not, and the page asks
     * first (PUT_BACK_QUESTION, through `env.confirm`); a no changes
     * nothing, and the offer stays:
     *   - the text is a draft found at page load (`setAsideFrom` is not
     *     `conflict`). The page cannot tell a draft whose text was never
     *     stored from one whose text went into the document after the page
     *     died (the unload beacon, or a save that was in the air), so it
     *     may be offering text the document already has, and putting it
     *     back would undo everything saved after it;
     *   - the document has moved on since the text was set aside
     *     (`baseVersion` is no longer `setAsideBase`): this tab followed
     *     other people's saves, or saved the writer's own later work;
     *   - the page holds text that is not saved yet. That text is in no
     *     version, so the question also says it will be lost.
     */
    function putBack() {
        const handle = env.handle();
        // Fail-closed: nothing may be written into the editor.
        if (!handle || handle.autosaves === false || !view.setAside) {
            return;
        }

        // An edit undone again is not unsaved text: do not warn about it.
        if (!saveInTheAir()) {
            settleIfBackAtConfirmed(handle);
        }

        const knowingly =
            view.setAsideFrom === 'conflict' && view.baseVersion === view.setAsideBase && !view.unsaved;
        if (!knowingly && !env.confirm(PUT_BACK_QUESTION + (view.unsaved ? PUT_BACK_UNSAVED_WARNING : ''))) {
            return;
        }

        // This replaces the version that was loaded, exactly as Keep mine
        // would have: every save that carries it says so, and the server
        // keeps the replaced version in the history first. Owed BEFORE the
        // text goes in: setContent() emits `update`, and the draft that
        // edited() writes off it has to be stamped as an overwrite (see
        // there), as does a beacon sent before the debounce fires.
        const owedBefore = view.overwriteOwed;
        view.overwriteOwed = true;

        try {
            handle.editor.commands.setContent(JSON.parse(view.setAside), { errorOnInvalidContent: true });
            forgetSetAside();
        } catch (_) {
            // Nothing was put back, so nothing more is owed than before.
            view.overwriteOwed = owedBefore;
            view.syncNotice = 'Your text could not be put back.';
        }
    }

    /**
     * Discard: the writer does not want the text that was set aside. Only
     * the offer goes. Nothing is sent and the page is not touched. The copy
     * that was parked under stale-<uuid> when the text was set aside is
     * left alone and stays for its 7 days, so in a browser that keeps
     * drafts declining loses nothing. (A browser that cannot keep drafts
     * parked nothing: there the text is gone with the offer.)
     *
     * When the text came from Load theirs (`setAsideFrom` is `conflict`)
     * it is this tab's own unsaved typing, in no saved version: once the
     * offer is gone, nothing in the page offers it again. So that case asks
     * first (DISCARD_QUESTION, through `env.confirm`); a no changes
     * nothing. A draft from an earlier visit is withdrawn without a
     * question: the page took nothing away, and its text may already be
     * part of the document.
     */
    function discardSetAside() {
        if (view.setAsideFrom === 'conflict' && !env.confirm(DISCARD_QUESTION)) {
            return;
        }

        forgetSetAside();
    }

    /**
     * Nothing is set aside any more. The three fields go together: where
     * the text came from and which version it was set aside against mean
     * nothing without the text.
     */
    function forgetSetAside() {
        view.setAside = null;
        view.setAsideFrom = null;
        view.setAsideBase = null;
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
     * The base row (the document this tab's typing is based on, for the
     * draft merge of a later visit) is written here, AFTER the draft logic:
     * a draft of an earlier version is merged against the row of its own
     * version, which must be read before this page writes its own.
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

        await restoreDraftIfRestorable(drafts, handle);
        // The awaits above can see `confirmed` nulled (a save that failed or
        // expired): then the known document of this very version is the
        // row, and when there is none the row already stored is left alone
        // rather than overwritten with null.
        const json =
            view.confirmed ?? (view.known && view.known.version === view.baseVersion ? view.known.json : null);
        if (json !== null) {
            writeBase(json, view.baseVersion);
        }
    }

    /**
     * The draft logic of restoreDraft(): what is done with the live draft
     * found for this document, if any.
     *
     * @param {object} drafts `window.offlineDraft`
     * @param {object} handle the editor handle, mounted and not fail-closed
     * @returns {Promise<void>}
     */
    async function restoreDraftIfRestorable(drafts, handle) {
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
                // Offer it in the page as well, through the notice bar and
                // Put it back. Not when the document already says exactly
                // this (the unload beacon stored it and nobody has saved
                // since): there is nothing to put back. When it differs,
                // the page cannot tell whether this text was never stored
                // or was stored and then built on by later saves, so the
                // source is recorded as a draft and putBack() asks before
                // it replaces anything.
                if (env.documentsDiffer(parsed, editor.getJSON())) {
                    view.setAside = draft.json;
                    view.setAsideFrom = 'draft';
                    view.setAsideBase = view.baseVersion;
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
        markConfirmed(JSON.stringify(handle.editor.getJSON()), version);
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
            // A newer document while this tab holds unsaved typing: merged,
            // or the notice when that cannot be done.
            onConflict: (remote) => mergeOrConflict(remote),
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
        beginMerge,
        mergeOrConflict,
        expireMerging,
        markConfirmed,
        offerLost,
        sentenceFor,
        keepMine,
        loadTheirs,
        putBack,
        discardSetAside,
        membersChanged,
        restoreDraft,
        applySuggestion,
        backOnline,
        engineHost,
    };
}
