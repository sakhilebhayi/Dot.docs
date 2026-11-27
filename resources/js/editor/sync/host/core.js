/**
 * The small per-call helpers every other part of the host asks: the counters
 * (metric()), the write gates (readOnly(), cannotWrite()), the stamp of the
 * offline draft and the owed overwrite (draftStamp(), oweOverwrite()), and the
 * one place a save in the air expires (saveInTheAir()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { SAVE_EXPIRY_MS } from './constants.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createCore(view, env, h) {
    /**
     * Tell the page's counters something happened. Counts and closed words
     * only (see ./metrics.js). Absent in every test but the metrics ones, and
     * when the page switched counting off; a throw is the counters' own
     * business and never reaches the editing code.
     *
     * @param {string} kind
     * @param {object} [detail]
     */
    const metric = (kind, detail = {}) => {
        if (typeof env.metric !== 'function') {
            return;
        }
        try {
            env.metric(kind, detail);
        } catch (_) {
            // Counting never breaks editing.
        }
    };

    /**
     * Whether this page may not change the document: the server rendered it
     * for somebody who may only read (`view.editable` false), or a save was
     * refused and the editor was made read only (`handle.readOnly`). The
     * second is the truth once it is set, because Alpine can re-create the
     * data object and the handle lives on the element.
     *
     * Not the same as fail-closed (`handle.autosaves === false`): there the
     * editor holds something that is NOT the document and nothing may be
     * written into it, not even what other people save. A read only page
     * shows the real document and keeps following it; it only never writes.
     *
     * @param {object|null|undefined} handle
     * @returns {boolean}
     */
    const readOnly = (handle) => view.editable === false || (!!handle && handle.readOnly === true);

    /**
     * Whether a path that writes (a save, a draft, a base row, a put-back, a
     * merge, an accepted suggestion) has to stand down: no editor, a
     * fail-closed one, or a read only page. Following (applyFromSync(),
     * syncState()) does not ask this; it asks about fail-closed alone.
     *
     * @param {object|null|undefined} handle
     * @returns {boolean}
     */
    const cannotWrite = (handle) => !handle || handle.autosaves === false || readOnly(handle);

    /**
     * The version the offline draft is stamped with. The base, unless an
     * overwrite is owed: then one below the base the overwrite FIRST became
     * owed on (`overwriteFloor`), or below the base itself if that is lower.
     *
     * One below the base alone is not enough. Keep mine moves the base up
     * without writing a base row, so when an overwrite was already owed
     * before it (a c2 merge, Put it back), one below the moved-up base can
     * be exactly the version of the base row that earlier step wrote. A page
     * opened after a crash would then merge the draft against that row as
     * if nothing were owed (section 7.1 merges a draft only when no
     * overwrite is owed), and the other writer's words that the overwrite
     * was to keep in the history would be kept nowhere. Below the floor, the
     * draft is older than every row this tab wrote while it was owed, so it
     * is set aside and Put it back saves it as an overwrite.
     *
     * @returns {number}
     */
    const draftStamp = () =>
        view.overwriteOwed ? Math.min(view.baseVersion, view.overwriteFloor ?? view.baseVersion) - 1 : view.baseVersion;

    /**
     * An overwrite is owed from now on (Keep mine, Put it back, a merge
     * whose save overwrites). The floor is set only when it was not owed
     * already: it keeps the earliest base the overwrite has been owed on,
     * and everything that writes the overwrite off clears both.
     */
    const oweOverwrite = () => {
        if (!view.overwriteOwed || view.overwriteFloor === null || view.overwriteFloor === undefined) {
            view.overwriteFloor = view.baseVersion;
        }
        view.overwriteOwed = true;
    };

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

    return { saveInTheAir, metric, readOnly, cannotWrite, draftStamp, oweOverwrite };
}
