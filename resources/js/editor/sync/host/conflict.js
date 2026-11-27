/**
 * The conflict notice and its two choices: raising it (enterConflict()),
 * Keep mine (keepMine()) and Load theirs (loadTheirs()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { LOAD_THEIRS_REPLACES_QUESTION } from './constants.js';
import { late } from './late.js';
import { paintVersion } from './versionStrip.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createConflict(view, env, h) {
    const {
        metric,
        cannotWrite,
        oweOverwrite,
        persist,
        syncState,
        applyFromSync,
    } = late(h, [
        'metric',
        'cannotWrite',
        'oweOverwrite',
        'persist',
        'syncState',
        'applyFromSync',
    ]);

    /**
     * A newer version exists and this tab has unsaved typing. Stop saving
     * and ask; the draft keeps the text in the meantime. `version` is the
     * version that is in the way: Keep mine needs only that number, not
     * the document itself.
     *
     * @param {number} version
     * @param {'impossible'|'attempts'|'expiry'} [reason] why the notice is raised, for the counters; only a notice
     *        that was not already standing is counted
     */
    function enterConflict(version, reason) {
        // The notice stands: no merge is tried behind the writer's back, and
        // the count of merges after refusals starts over after it.
        const raised = !view.conflict;
        view.merging = null;
        view.mergeAttempts = 0;
        if (raised && reason) {
            metric('conflict', { reason });
        }

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
        if (cannotWrite(handle) || !view.conflict) {
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
        paintVersion(view.baseVersion);
        oweOverwrite();
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
     * @returns {Promise<boolean>} whether the newer document was shown (false:
     *          nothing to show yet, declined, busy or could not be opened)
     */
    async function loadTheirs() {
        const handle = env.handle();
        const engine = env.engine();
        if (cannotWrite(handle) || !engine) {
            return false;
        }

        // One choice at a time: a Keep mine save is already in the air.
        if (syncState() === 'busy') {
            return false;
        }

        // A text is already set aside and offered. Load theirs sets THIS tab's
        // text aside in its place, and the page offers one set-aside text at a
        // time (the earlier one stays parked, under a kept key, and is offered
        // again once this one is dealt with, where the browser keeps drafts;
        // elsewhere it is gone): ask first, before the engine's document is
        // taken (a no changes nothing).
        if (view.setAside && engine.pending && !env.confirm(LOAD_THEIRS_REPLACES_QUESTION)) {
            return false;
        }

        const waiting = engine.takeRemote();
        if (!waiting) {
            engine.refetch(view.baseVersion);

            return false;
        }

        let mine = JSON.stringify(handle.editor.getJSON());

        const drafts = env.drafts();
        if (drafts) {
            // The writer can type while the text is being parked. Whatever the
            // editor holds when the park is done and nothing more has changed
            // is what is set aside; parking never overwrites, so the earlier
            // text stays parked too.
            let parked = null;
            const superseded = [];
            while (parked !== mine) {
                if (parked !== null) {
                    superseded.push(parked);
                }
                parked = mine;
                await drafts.parkStaleDraft(view.docUuid, parked, view.baseVersion, { offerable: true, from: 'conflict' });
                mine = JSON.stringify(handle.editor.getJSON());
            }
            // The earlier states of the same typing are not texts to offer again.
            superseded.forEach((earlier) => drafts.resolveParked?.(view.docUuid, earlier));
            env.info(
                '[Dot.Doc] Your unsaved text was kept as stale-' +
                    view.docUuid +
                    ' and will be removed after 7 days.'
            );
        }

        if (!applyFromSync(waiting, { force: true })) {
            view.syncNotice = 'The newer version could not be opened here. Reload the page.';

            return false;
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

        return true;
    }

    return { enterConflict, keepMine, loadTheirs };
}
