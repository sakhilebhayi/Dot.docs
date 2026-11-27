/**
 * The offline draft: writing it on every edit (edited()), and what the page
 * does with a draft it finds when it opens (restoreDraft(),
 * restoreDraftIfRestorable(), and the draft merge of section 7.1, mergeDraft()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { DRAFT_MERGE_QUESTION } from './constants.js';
import { late } from './late.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createDrafts(view, env, h) {
    const {
        saveInTheAir,
        metric,
        readOnly,
        cannotWrite,
        draftStamp,
        oweOverwrite,
        writeBase,
        owesOverwrite,
        offerNextPark,
        offerLost,
        reofferLost,
    } = late(h, [
        'saveInTheAir',
        'metric',
        'readOnly',
        'cannotWrite',
        'draftStamp',
        'oweOverwrite',
        'writeBase',
        'owesOverwrite',
        'offerNextPark',
        'offerLost',
        'reofferLost',
    ]);

    /**
     * The writer changed the document. Runs off every keystroke; the save
     * itself is debounced inside the bundle.
     */
    function edited() {
        // A read only page has no writer: whatever changed the editor is not
        // typing, and nothing here may say Editing or be kept as unsaved.
        if (readOnly(env.handle())) {
            return;
        }

        view.unsaved = true;
        env.report('idle', 'Editing');

        const handle = env.handle();
        const drafts = env.drafts();
        // Never touch a draft in fail-closed mode: what the editor is
        // holding then is not the document.
        if (!drafts || cannotWrite(handle)) {
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
        // Stamped below it (below the base the overwrite first became owed
        // on, see draftStamp()), it is older than the document for every
        // later page: restoreDraft() sets it aside, and Put it back saves
        // it as an overwrite.
        drafts.saveDraft(view.docUuid, json, draftStamp());
    }

    /**
     * The draft merge (section 7.1): a draft the document has moved past,
     * merged against the server document it was typed on (the base row of
     * its version), with the page's document as theirs and the draft as
     * mine. `deletionsWin: false`: a block the draft deleted that somebody
     * has changed since stays, and nothing is overwritten.
     *
     * @returns {Promise<boolean>} true when the draft was dealt with here
     *          (merged, or cleared because it is all in the document);
     *          false to fall through to today's park and set-aside
     */
    async function mergeDraft(drafts, handle, draft, parsed) {
        let base = null;
        try {
            base = await drafts.loadBase(view.docUuid);
        } catch (_) {
            base = null;
        }
        if (!base || typeof base.json !== 'string' || base.baseVersion !== draft.baseVersion) {
            return false;
        }

        let result = null;
        try {
            result = handle.merge(JSON.parse(base.json), handle.editor.getJSON(), { mine: parsed, deletionsWin: false });
        } catch (_) {
            result = null;
        }
        if (!result) {
            return false;
        }

        // The beacon stored it, or it is all in the document: nothing to ask.
        if (!result.changed && result.lost.length === 0) {
            drafts.clearDraft(view.docUuid);

            return true;
        }

        if (!env.confirm(DRAFT_MERGE_QUESTION)) {
            return false;
        }

        // The pre-merge copy stays recoverable, as today.
        await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion);

        // Nothing is applied under an IME composition (as mergeOrConflict()
        // holds): the draft falls through to today's park and set-aside, and
        // the composition and the typing stay on the page.
        if (handle.composing) {
            return false;
        }
        drafts.clearDraft(view.docUuid);

        // The page is editable while the question is open and the park is
        // written, so the writer may have typed meanwhile. The merge above was
        // made before that: applying it would wipe the typing. Merge again from
        // the editor as it is NOW (this runs to the apply without another
        // await, so nothing can slip in), the typing counting as part of
        // theirs, the page's document.
        let current = null;
        const theirsNow = handle.editor.getJSON();
        try {
            current = handle.merge(JSON.parse(base.json), theirsNow, { mine: parsed, deletionsWin: false });
        } catch (_) {
            current = null;
        }
        if (!current) {
            return false;
        }
        result = current;
        // A draft deletion across a gap of versions can look like a plain one (see owesOverwrite()).
        const overwrites = owesOverwrite(result, draft.baseVersion, view.documentVersion, theirsNow);

        let applied = false;
        try {
            applied = handle.applyRemote(result.merged, { force: true }) === true;
        } catch (_) {
            applied = false;
        }
        if (!applied) {
            return false;
        }
        metric('merge', { source: 'draft', lost: result.lost.length > 0 });

        // `confirmed` stays: opened() set it to the page document, which is
        // theirs. What survived of the draft is unsaved on the current base
        // and goes out on the first tick once startSync() has run. Typing the
        // writer did meanwhile is unsaved too, and the apply just emptied the
        // editor's own autosave: `unsaved` is never lowered here, and it is
        // owed to the tick.
        view.unsaved = view.unsaved || result.changed;
        if (result.changed && overwrites) {
            oweOverwrite();
        }
        if (view.unsaved) {
            // The page said Saved when it opened; the merged text is not.
            env.report('idle', 'Editing');
        }
        if (result.lost.length > 0) {
            offerLost(result.lost, 'draft', view.baseVersion);
        }
        if (view.unsaved) {
            // Stamped like any draft written while an overwrite is owed (draftStamp()): the page may die before
            // the save leaves, and the reopened page must set this draft aside rather than merge it.
            drafts.saveDraft(view.docUuid, JSON.stringify(handle.editor.getJSON()), draftStamp());
            view.resave = true;
        }
        view.tick++;
        env.info(
            '[Dot.Doc] An unsaved draft based on v' +
                draft.baseVersion +
                ' was merged into the document at v' +
                view.documentVersion +
                '; the copy before the merge was kept as stale-' +
                view.docUuid +
                '.'
        );

        return true;
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
        if (cannotWrite(handle)) {
            return;
        }

        // BEFORE the draft, the lost row: a list parked by a merge in an
        // earlier visit of this browser is offered again, and the draft
        // merge below appends its own losses to it. offerLost() parks the
        // WHOLE list, so the earlier park must be read first, or it would be
        // overwritten and the fresh entries read back as last visit's.
        await reofferLost(drafts);
        await restoreDraftIfRestorable(drafts, handle);
        // Nothing set aside by the draft logic: an earlier visit's set-aside text that was never dealt with is offered.
        await offerNextPark();
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
                // Phase 2: with the base row of the draft's own version, the
                // draft is merged into the document as it is now (one
                // question first), or cleared when it is all in the document
                // already. Without the row, with a merge that cannot be done,
                // or when the writer declines: today's park and set-aside.
                if (await mergeDraft(drafts, handle, draft, parsed)) {
                    return;
                }

                // Not restorable, but not this page's to destroy either.
                // Park it under a stale- key so it can be recovered by
                // hand; parkStaleDraft() stamps parkedAt, and the sweep on
                // the next app boot collects it after 7 days.
                // Offer it in the page as well, through the notice bar and
                // Put it back. Not when the document already says exactly
                // this (the unload beacon stored it and nobody has saved
                // since): there is nothing to put back. When it differs,
                // the page cannot tell whether this text was never stored
                // or was stored and then built on by later saves, so the
                // source is recorded as a draft and putBack() asks before
                // it replaces anything. A park of a text that is offered is
                // marked so, and is offered again later if the writer does
                // not deal with it (see offerNextPark()).
                const offered = env.documentsDiffer(parsed, editor.getJSON());
                await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion, { offerable: offered, from: 'draft' });
                drafts.clearDraft(view.docUuid);
                if (offered) {
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

    return { edited, mergeDraft, restoreDraft, restoreDraftIfRestorable };
}
