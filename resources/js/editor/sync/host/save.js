/**
 * The save queue: sending a save (persist()), its answer (saveAnswered()),
 * the owed resend and its back-off (resendIfOwed(), backOnline()), settling
 * when the editor is back at the confirmed document, and whether text is
 * still unsent when the page goes away (unsentAtUnload(), for the beacon).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { MERGE_ATTEMPTS, SAVE_RETRY_DELAYS_MS } from './constants.js';
import { late } from './late.js';
import { paintVersion } from './versionStrip.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createSave(view, env, h) {
    const {
        saveInTheAir,
        metric,
        cannotWrite,
        markConfirmed,
        beginMerge,
        enterConflict,
        accessBeingChecked,
    } = late(h, [
        'saveInTheAir',
        'metric',
        'cannotWrite',
        'markConfirmed',
        'beginMerge',
        'enterConflict',
        'accessBeingChecked',
    ]);

    /**
     * Whether the text this page holds is unsent and unanswered, for the
     * unload beacon: it is unsaved and no answer has settled the request that
     * carried it. That is the case while a save is still in the air
     * (`saving`, whatever its age), when it FAILED (`saveFailures`, reset by
     * any answer), when its next send is HELD by the back-off (`saveRetryAt`
     * in the future), and when it was given up on, or failed, and nothing
     * has been confirmed since (`unanswered`).
     *
     * Livewire never rejects the promise of a call whose fetch failed, so in
     * a real browser the page cannot tell a dead request from a slow one: it
     * only gives the save up after SAVE_EXPIRY_MS, and `saveFailures` stays 0.
     * A request in the air is aborted by the closing page, so its text is
     * beaconed too. That is safe whatever became of the request: both state
     * the same base (and overwrite flag), so the server stores the text once,
     * refuses the other as stale and cuts no second history version.
     *
     * The bundle counts a document it handed to Livewire as sent, so it asks.
     * Never for a page that cannot write (read only, fail-closed): a beacon
     * is a write. No side effects: it is called while the page is going away.
     *
     * @returns {boolean}
     */
    function unsentAtUnload() {
        if (cannotWrite(env.handle())) {
            return false;
        }

        return (
            view.unsaved === true &&
            (view.unanswered !== null ||
                view.saveFailures > 0 ||
                view.saveRetryAt > env.now() ||
                // A save still in the air, whatever its age (past SAVE_EXPIRY_MS it is given up on, though nothing
                // has noticed yet: saveInTheAir() does, on the next poll tick).
                view.saving > 0)
        );
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
        if (handle && cannotWrite(handle)) {
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

        // A save came back 403 and the server has not yet said whether this
        // person may still change the document (saveRefused()): nothing goes
        // out until it has. Owed, so nothing typed meanwhile is dropped.
        if (accessBeingChecked() && !force) {
            view.resave = true;
            env.report('danger', 'Not saved');

            return Promise.resolve();
        }

        // The last send's request failed and its hold has not passed: the text is owed, not sent. Keep mine
        // (`force`) is a choice the writer has just made and is not held. The word stays Not saved and the draft
        // keeps the text; the poll tick after the hold sends the newest text.
        if (!force && view.saveRetryAt > env.now()) {
            view.resave = true;
            env.report('danger', 'Not saved');

            return Promise.resolve();
        }

        env.report('idle', 'Saving');

        // A send after a request that FAILED is a resend (the count of failures in a row is still standing).
        if (view.saveFailures > 0) {
            metric('resend');
        }

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
        // The server document the tab held when this left. A late answer of a
        // save given up on is believed only while the page still stands where
        // it did: a base number alone is fooled by Keep mine then Load theirs,
        // which brings the base back to the number it had at send time while
        // the page shows another document.
        const knownAtSend = view.known;

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
                // Any answer, accepted or refused, shows that saves reach the application: the hold is over.
                view.saveFailures = 0;
                view.saveRetryAt = 0;
                if (givenUpOn()) {
                    const knownNow = view.known;
                    const knownMoved =
                        (knownNow?.version ?? null) !== (knownAtSend?.version ?? null) ||
                        (knownNow?.json ?? null) !== (knownAtSend?.json ?? null);
                    if (view.confirmed !== null || view.baseVersion !== baseAtSend || knownMoved) {
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
                // And it is owed again: the next completed poll sends it
                // (resendIfOwed(), at the engine's back-off). Left unowed,
                // the text waited for the next keystroke or a change from
                // elsewhere, and the unload beacon does not carry it (the
                // bundle counts the document as sent), so a tab closed at
                // Not saved in a browser that keeps no drafts lost it. Only
                // a request that FAILED: a refusal (ok false) is an answer.
                view.resave = true;
                // Held for longer after each failure in a row (SAVE_RETRY_DELAYS_MS), counted on the page's own
                // fields because this module holds no state.
                view.saveFailures += 1;
                view.saveRetryAt = env.now() + SAVE_RETRY_DELAYS_MS[Math.min(view.saveFailures, SAVE_RETRY_DELAYS_MS.length) - 1];
                metric('backoff', { level: view.saveFailures });
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
            paintVersion(view.baseVersion);
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
                view.overwriteFloor = null;
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
                enterConflict(result.version, 'attempts');
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
        const handle = env.handle();
        // Fail-closed: nothing is written back.
        if (cannotWrite(handle)) {
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
        if (cannotWrite(handle)) {
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
        view.overwriteFloor = null;
        // The draft now says what the server holds: it is not kept (see
        // edited()).
        const drafts = env.drafts();
        if (drafts) {
            drafts.clearDraft(view.docUuid);
        }
        env.report('good', 'Saved');
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

    return {
        unsentAtUnload,
        persist,
        refreshOutline,
        saveAnswered,
        resendIfOwed,
        clearDraftIfSettled,
        settleIfBackAtConfirmed,
        backOnline,
    };
}
