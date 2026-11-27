/**
 * The set-aside text and the queue of kept parks: Put it back (putBack()),
 * Discard (discardSetAside()), offering the next park (dealtWith(),
 * offerNextPark()), and setting text aside (forgetSetAside(), setAsideTyping()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import {
    DISCARD_QUESTION,
    PUT_BACK_FAILED_NOTICE,
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    lostNotices,
} from './constants.js';
import { late } from './late.js';

/**
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createSetAside(view, env, h) {
    const {
        saveInTheAir,
        cannotWrite,
        oweOverwrite,
        settleIfBackAtConfirmed,
        setLostNotice,
    } = late(h, [
        'saveInTheAir',
        'cannotWrite',
        'oweOverwrite',
        'settleIfBackAtConfirmed',
        'setLostNotice',
    ]);

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
        if (cannotWrite(handle) || !view.setAside) {
            return false;
        }

        // An edit undone again is not unsaved text: do not warn about it.
        if (!saveInTheAir()) {
            settleIfBackAtConfirmed(handle);
        }

        const knowingly =
            view.setAsideFrom === 'conflict' && view.baseVersion === view.setAsideBase && !view.unsaved;
        if (!knowingly && !env.confirm(PUT_BACK_QUESTION + (view.unsaved ? PUT_BACK_UNSAVED_WARNING : ''))) {
            return false;
        }

        // This replaces the version that was loaded, exactly as Keep mine
        // would have: every save that carries it says so, and the server
        // keeps the replaced version in the history first. Owed BEFORE the
        // text goes in: setContent() emits `update`, and the draft that
        // edited() writes off it has to be stamped as an overwrite (see
        // there), as does a beacon sent before the debounce fires.
        const owedBefore = view.overwriteOwed;
        const floorBefore = view.overwriteFloor ?? null;
        oweOverwrite();

        try {
            handle.editor.commands.setContent(JSON.parse(view.setAside), { errorOnInvalidContent: true });
            // The saves sent so far did not carry this text (see persist()).
            view.putBackSeq = view.saveSeq;
            const putBack = view.setAside;
            forgetSetAside();
            dealtWith(putBack);
        } catch (_) {
            // Nothing was put back, so nothing more is owed than before.
            view.overwriteOwed = owedBefore;
            view.overwriteFloor = floorBefore;
            setLostNotice(PUT_BACK_FAILED_NOTICE);

            return false;
        }

        return true;
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
     *
     * @returns {boolean} whether the offer was withdrawn (false: declined)
     */
    function discardSetAside() {
        // A text the page took away (Load theirs) is in no saved version, and so is any text that comes back
        // from the queue of kept parks (it has no base version: setAsideBase is null, offerNextPark()), which may
        // be another tab's: both ask.
        if ((view.setAsideFrom === 'conflict' || view.setAsideBase === null) && !env.confirm(DISCARD_QUESTION)) {
            return false;
        }

        const discarded = view.setAside;
        forgetSetAside();
        // Dealt with: it stays parked for its 7 days but is not offered again. The next kept park, if any, is offered now.
        if (discarded) {
            dealtWith(discarded);
        }

        return true;
    }

    /**
     * The writer has dealt with a set-aside text (Put it back, Discard): every
     * park holding it is marked resolved so it is not offered again, and the
     * next kept park, if any, is offered. When the store could not write the
     * mark (resolveParked() resolves false) nothing is offered next: the same
     * text would be offered straight back, and Discard would loop.
     *
     * @param {string} json
     * @returns {Promise<void>}
     */
    async function dealtWith(json) {
        const drafts = env.drafts();
        if (!drafts) {
            return;
        }
        let marked = true;
        try {
            marked = (await drafts.resolveParked?.(view.docUuid, json)) !== false;
        } catch (_) {
            marked = false;
        }
        if (marked) {
            await offerNextPark();
        }
    }

    /**
     * The next text to offer. Parks keep earlier set-aside texts (parkStaleDraft
     * never overwrites), but the page offers one at a time. When nothing is set
     * aside, the newest park made to be offered and not yet dealt with (Put it
     * back, Discard) is offered, with the label it had when it was parked and no
     * base version (so Put it back and Discard ask their questions); one that the page already shows is dealt with
     * without being offered. Never replaces a text on offer, never offers the same
     * text twice (dealing with it marks the rows), and the 7-day sweep removes the
     * rows it gets to.
     *
     * @returns {Promise<void>}
     */
    async function offerNextPark() {
        const drafts = env.drafts();
        const handle = env.handle();
        // Fail-closed: the editor holds something that is not the document.
        if (!drafts || !drafts.nextParkToOffer || cannotWrite(handle)) {
            return;
        }

        // Not while the writer is choosing, a merge is waiting for its document, or the page can no longer stay
        // in step: the offer is held and made on the poll tick after those have cleared (`parkOfferPending`).
        const stopReason = view.syncNotice !== '' && !lostNotices.includes(view.syncNotice);
        if (view.conflict || view.merging || stopReason) {
            view.parkOfferPending = true;

            return;
        }
        view.parkOfferPending = false;

        for (let attempt = 0; attempt < 50 && !view.setAside; attempt += 1) {
            let park = null;
            try {
                park = await drafts.nextParkToOffer(view.docUuid);
            } catch (_) {
                park = null;
            }
            // Something may have been set aside while the store was read.
            if (!park || view.setAside) {
                return;
            }

            let differs = false;
            try {
                differs = env.documentsDiffer(JSON.parse(park.json), handle.editor.getJSON());
            } catch (_) {
                differs = false; // not a document: nothing to put back
            }
            if (differs) {
                view.setAside = park.json;
                // The label it had when it was parked. No base version: it comes from the queue, so Put it back
                // asks its questions and Discard asks (see discardSetAside()).
                view.setAsideFrom = park.from ?? 'draft';
                view.setAsideBase = null;

                return;
            }
            let marked = true;
            try {
                marked = (await drafts.resolveParked?.(view.docUuid, park.json)) !== false;
            } catch (_) {
                marked = false;
            }
            if (!marked) {
                return; // it would be read again as the next park, for ever
            }
        }
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
     * The editor's unsaved text is parked and set aside once a document has
     * replaced it, as Load theirs does: the writer can put it back. Called
     * only after the apply that removed it has succeeded.
     *
     * @param {string} mine the typing as a JSON string, captured before the apply
     * @param {number} version the version the page will show
     */
    function setAsideTyping(mine, version) {
        env.drafts()?.parkStaleDraft(view.docUuid, mine, view.baseVersion, { offerable: true, from: 'conflict' });
        view.setAside = mine;
        view.setAsideFrom = 'conflict';
        view.setAsideBase = version;
    }

    return { putBack, discardSetAside, dealtWith, offerNextPark, forgetSetAside, setAsideTyping };
}
