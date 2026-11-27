/**
 * Following the server's document: what the engine may do now (syncState()),
 * the page's first confirmed state (opened()), applying a newer document
 * (applyFromSync()), adopting a version this page produced (adoptVersion()),
 * and recording what is confirmed and known (markConfirmed(), writeBase()).
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
export function createFollow(view, env, h) {
    const {
        saveInTheAir,
        readOnly,
        cannotWrite,
        resendIfOwed,
        settleIfBackAtConfirmed,
    } = late(h, [
        'saveInTheAir',
        'readOnly',
        'cannotWrite',
        'resendIfOwed',
        'settleIfBackAtConfirmed',
    ]);

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
        if (handle.autosaves === false) {
            env.report('danger', 'Read only');
        } else if (readOnly(handle)) {
            // Somebody who may read the document and not change it: calm,
            // not an alarm, and never Saved.
            env.report('idle', 'Read only');
        } else {
            env.report('good', 'Saved');
        }
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
        paintVersion(view.baseVersion);
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
        paintVersion(view.baseVersion);
        view.unsaved = false;
        // A merge under way is over: the tab was clean after all (it undid
        // everything while the fetch was out), so it follows instead.
        view.merging = null;
        view.mergeAttempts = 0;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), remote.version);
        // Whatever this tab had put back is no longer in the editor.
        view.overwriteOwed = false;
        view.overwriteFloor = null;
        // The server now holds newer content than any draft. A read only
        // page leaves the drafts alone: one in this browser may be text that
        // was typed when this person could still change the document.
        const drafts = env.drafts();
        if (drafts && !readOnly(handle)) {
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
        if (cannotWrite(handle)) {
            return;
        }

        env.drafts()?.saveBase(view.docUuid, json, version);
    }

    return { opened, syncState, adoptVersion, applyFromSync, markConfirmed, writeBase };
}
