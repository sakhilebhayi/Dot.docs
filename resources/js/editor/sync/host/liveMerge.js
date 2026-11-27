/**
 * The live paragraph merge (Phase 2, section 5): fetching the newer document
 * after a refused save (beginMerge(), expireMerging()), merging it over the
 * typing or raising the notice (mergeOrConflict()), and whether a merge owes
 * the overwrite flag (owesOverwrite()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { MERGE_FETCH_EXPIRY_MS } from './constants.js';
import { late } from './late.js';
import { paintVersion } from './versionStrip.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createLiveMerge(view, env, h) {
    const {
        saveInTheAir,
        metric,
        cannotWrite,
        draftStamp,
        oweOverwrite,
        persist,
        markConfirmed,
        enterConflict,
        offerLost,
    } = late(h, [
        'saveInTheAir',
        'metric',
        'cannotWrite',
        'draftStamp',
        'oweOverwrite',
        'persist',
        'markConfirmed',
        'enterConflict',
        'offerLost',
    ]);

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
        if (cannotWrite(handle)) {
            return;
        }

        const engine = env.engine();
        if (!engine) {
            enterConflict(version, 'impossible');

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
            enterConflict(version, 'expiry');
        }
    }

    /**
     * Whether a merge owes the overwrite flag. The merge says so itself for
     * case c2 (my deletion beats a block they edited). It cannot see a block
     * that was changed and changed back in a GAP of versions: theirs equals the
     * base for it, my deletion reads as a plain c1 and carries no flag, though
     * the version in the gap had other words in it. So when the base is more
     * than one version older than the document merged into, a merged result
     * that no longer holds a top-level block theirs holds owes the overwrite
     * too, and the server keeps the replaced version in the history. Cost: an
     * extra history row in the gap case.
     *
     * @param {{merged: object, overwrites: boolean}} result
     * @param {number} baseVersion the version the base document is of
     * @param {number} theirsVersion the version of the document merged into
     * @param {object} theirsJson that document
     * @returns {boolean}
     */
    function owesOverwrite(result, baseVersion, theirsVersion, theirsJson) {
        if (result.overwrites) {
            return true;
        }
        if (!(theirsVersion - baseVersion > 1)) {
            return false;
        }

        const kept = new Set((result.merged?.content ?? []).map((block) => block?.attrs?.id));

        return (theirsJson?.content ?? []).some((block) => block?.attrs?.id != null && !kept.has(block.attrs.id));
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
        if (cannotWrite(handle)) {
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
            enterConflict(remote.version, 'impossible');

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
            enterConflict(remote.version, 'impossible');

            return;
        }

        // Only for the counters, and only when the tab holds an echo: the same merge without it (a pure function;
        // nothing is applied). Whatever it offers more than the real one is what the echo rule kept.
        let echoKept = false;
        if (typeof env.metric === 'function' && view.unanswered) {
            try {
                const bare = handle.merge(JSON.parse(base), remote.json, { echo: null });
                echoKept = !!bare && bare.lost.length > result.lost.length;
            } catch (_) {
                echoKept = false;
            }
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
            enterConflict(remote.version, 'impossible');

            return;
        }

        // The counters (before the bookkeeping, which forgets the echo): a live merge was performed, and the echo
        // rule decided it when, without the echo, the same merge would have offered more back.
        metric('merge', { source: 'merge', lost: result.lost.length > 0 });
        if (echoKept) {
            metric('echo');
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
        const overwrites = owesOverwrite(result, view.baseVersion, remote.version, remote.json);
        view.merging = null;
        view.baseVersion = remote.version;
        paintVersion(view.baseVersion);
        view.conflict = null;
        markConfirmed(result.changed ? result.theirs : JSON.stringify(handle.editor.getJSON()), remote.version);
        if (result.changed && overwrites) {
            oweOverwrite();
        } else if (!result.changed) {
            view.overwriteOwed = false;
            view.overwriteFloor = null;
        }
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
        //    unsaved (stamped below the base while an overwrite is owed, as
        //    edited() stamps it: draftStamp()), cleared when the server holds
        //    everything.
        const drafts = env.drafts();
        if (drafts) {
            if (result.changed) {
                drafts.saveDraft(
                    view.docUuid,
                    JSON.stringify(handle.editor.getJSON()),
                    draftStamp()
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

    return { beginMerge, expireMerging, owesOverwrite, mergeOrConflict };
}
