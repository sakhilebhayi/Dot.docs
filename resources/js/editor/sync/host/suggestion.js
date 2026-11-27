/**
 * An accepted AI suggestion going into the page (section 8): applied whole,
 * or merged over unsaved typing (applySuggestion(), mergeSuggestion()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { late } from './late.js';
import { paintVersion } from './versionStrip.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createSuggestion(view, env, h) {
    const {
        metric,
        readOnly,
        draftStamp,
        oweOverwrite,
        persist,
        refreshOutline,
        adoptVersion,
        markConfirmed,
        owesOverwrite,
        setAsideTyping,
        offerLost,
    } = late(h, [
        'metric',
        'readOnly',
        'draftStamp',
        'oweOverwrite',
        'persist',
        'refreshOutline',
        'adoptVersion',
        'markConfirmed',
        'owesOverwrite',
        'setAsideTyping',
        'offerLost',
    ]);

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
        if (readOnly(handle) && handle.autosaves !== false) {
            view.aiError = 'You can read this document but not change it, so the suggestion was not applied here.';

            return;
        }

        if (handle.autosaves === false) {
            view.aiError =
                'This document is open read-only, so the accepted suggestion was not applied here. Reload the page once the content problem is fixed.';

            return;
        }

        // Whatever save is in the air was written against a base this tab is
        // about to leave. It is given up on HERE, before the merge decides its
        // base and before markConfirmed(): its answer, whenever it comes, is a
        // late one and is ignored once the suggestion is confirmed (see
        // persist()). Left to expire on its own later, it would null
        // `confirmed` again and install its older document as the echo of
        // the NEXT merge, which would read a later change of somebody else
        // that happens to equal it (a restore of that version) as this tab's
        // own; and answering ok it would move the base back to its version.
        let retiredSave = false;
        if (view.saving > 0) {
            view.saving = 0;
            view.expiredSaveSeq = view.saveSeq;
            retiredSave = true;
        }

        // Phase 2 (section 8): over unsaved typing the suggestion is MERGED
        // like any other remote document when a base is known, so the typing
        // survives wherever the suggestion did not rewrite it, and what it
        // did rewrite is offered back. When no base is known (after Keep
        // mine), or the merge or its apply fails, the typing is set aside
        // first, as after Load theirs, and the suggestion goes in whole.
        // Phase 1 replaced it silently.
        const base =
            view.confirmed ?? (view.known !== null && view.known.version === view.baseVersion ? view.known.json : null);
        // The typing, as it stands, ONCE, before anything can replace it: the
        // merge below may change the editor and then fail (its apply can throw
        // after the document changed), and the fall-through must set aside the
        // typing, not whatever the page shows by then.
        const mine = view.unsaved ? JSON.stringify(handle.editor.getJSON()) : null;
        if (view.unsaved && base !== null && mergeSuggestion(handle, base, content, version)) {
            return;
        }
        if (mine !== null && view.setAside) {
            // One text is set aside at a time (the offer; its park is kept
            // either way). Setting this one aside would replace the offer.
            // The suggestion is already stored as version `version` (accepting
            // it saved it): it is not pending any more, so the writer is not
            // sent back to accept it. The next poll delivers it to this tab,
            // which cannot merge it either, and raises the notice.
            // The merge above may have changed the editor before its apply
            // threw: the typing is then in no place but the draft row. Put it
            // back, so that what the writer typed is what the page shows.
            if (JSON.stringify(handle.editor.getJSON()) !== mine) {
                try {
                    handle.applyRemote(JSON.parse(mine), { force: true });
                } catch (cause) {
                    env.log('[Dot.Doc] The typing could not be put back after a failed suggestion merge.', cause);
                }
            }
            view.aiError = 'Suggestion saved, but your unsaved text could not be added to it. Put back or discard the set-aside text first.';
            // The save that was in the air is given up on above; the typing it
            // carried is owed again, not left unsaved with nothing to send it.
            if (retiredSave) {
                view.resave = true;
            }

            return;
        }

        // A throw is a failure like a false answer (mergeSuggestion() treats it
        // so), with one difference: it can come AFTER the document changed. The
        // typing is then no longer in the editor, so it is set aside here, as it
        // would have been on success, and the writer is not told the document
        // is unchanged.
        const before = mine ?? JSON.stringify(handle.editor.getJSON());
        let applied = false;
        try {
            applied = handle.applyRemote(content, { force: true }) === true;
        } catch (cause) {
            env.log('[Dot.Doc] Applying the accepted suggestion threw.', cause);
            applied = false;
        }
        if (!applied) {
            const changed = JSON.stringify(handle.editor.getJSON()) !== before;
            if (changed && mine !== null) {
                setAsideTyping(mine, version);
            }
            view.aiError = changed
                ? 'The suggestion may not have been applied cleanly. Reload the page to check.'
                : 'That suggestion could not be applied — the document is unchanged.';
            if (retiredSave) {
                view.resave = true;
            }

            return;
        }

        // The typing was removed from the editor by the apply just made: it is
        // parked and offered back in this same call, before anything can fail
        // afterwards. Had the apply failed, it would still be in the editor
        // and nothing would be set aside.
        if (mine !== null) {
            setAsideTyping(mine, version);
        }

        // The editor now holds exactly the version the server stored. Text
        // this tab had marked unsaved is no longer in it (it is set aside
        // just above), and no save will answer for it: the word that still says
        // Editing goes back to Saved. Only on that change.
        if (view.unsaved) {
            env.report('good', 'Saved');
        }
        view.unsaved = false;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), version);
        view.overwriteOwed = false;
        view.overwriteFloor = null;
        adoptVersion(version, { contentLoaded: true });
        view.aiError = '';
        view.tick++;
        refreshOutline();
    }

    /**
     * The suggestion merge: section 5.5's steps 6 to 9 with the suggestion
     * as theirs, `source: 'suggestion'` and the suggestion's version as the
     * new base.
     *
     * @returns {boolean} whether the suggestion was merged and applied
     */
    function mergeSuggestion(handle, base, content, version) {
        let result = null;
        try {
            result = handle.merge(JSON.parse(base), content);
        } catch (_) {
            result = null;
        }
        if (!result) {
            return false;
        }

        let applied = false;
        try {
            applied = handle.applyRemote(result.merged, { force: true }) === true;
        } catch (_) {
            applied = false;
        }
        if (!applied) {
            return false;
        }
        metric('merge', { source: 'suggestion', lost: result.lost.length > 0 });

        const overwrites = owesOverwrite(result, view.baseVersion, version, content);
        view.merging = null;
        view.mergeAttempts = 0;
        view.baseVersion = version;
        paintVersion(view.baseVersion);
        view.conflict = null;
        markConfirmed(result.changed ? result.theirs : JSON.stringify(handle.editor.getJSON()), version);
        if (result.changed && overwrites) {
            oweOverwrite();
        } else if (!result.changed) {
            view.overwriteOwed = false;
            view.overwriteFloor = null;
        }
        view.unsaved = result.changed;
        if (result.lost.length > 0) {
            offerLost(result.lost, 'suggestion', version);
        }
        env.engine()?.saved(version);
        view.aiError = '';
        view.tick++;

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

        if (result.changed) {
            view.resave = true;
            persist(handle.editor.getJSON());
        } else {
            env.report('good', 'Saved');
        }
        refreshOutline();

        return true;
    }

    return { applySuggestion, mergeSuggestion };
}
