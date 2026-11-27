/**
 * The notice bar's lost row (section 6): offering what a merge could not keep
 * (offerLost(), sentenceFor()), Put it back and Put them all back
 * (putBackLost(), putBackAllLost()), Dismiss all (dismissLost()), the
 * re-offer after a reload (reofferLost()), the row's own notices, and whether
 * a keyboard press hands focus back to the paper (refocusAfterPress()).
 *
 * Part of createSyncHost() (../host.js): see there for how the parts are wired.
 */

import { focusAfterInsert } from '../insert.js';
import { appendLost, blockWord, lostEntry } from '../merge.js';
import {
    DISMISS_LOST_CHANGED_NOTICE,
    DISMISS_LOST_FAILED_NOTICE,
    DISMISS_LOST_QUESTION,
    DISMISS_LOST_UNKEPT_QUESTION,
    PUT_BACK_FAILED_NOTICE,
    lostNotices,
} from './constants.js';
import { late } from './late.js';

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
 * @param {import('../host.js').SyncView} view the Alpine data object the call was made on
 * @param {import('../host.js').SyncEnv} env
 * @param {object} h the host being built: the other parts' functions, looked up when called
 */
export function createLost(view, env, h) {
    const { metric, cannotWrite } = late(h, ['metric', 'cannotWrite']);

    /**
     * Whether a press from the keyboard in the notice bar hands the keyboard back
     * to the paper (the page's noticePressed()): only when the action completed.
     * An action that reports false (the writer answered No, or it failed, or it
     * had nothing to do) leaves the row on the page, and with it the focus where
     * it was. An action that reports nothing at all (Keep mine) is taken as done:
     * its row goes away and the focus would be left on nothing.
     *
     * @param {unknown} completed what the action returned (a boolean, or a promise's value)
     * @returns {boolean}
     */
    function refocusAfterPress(completed) {
        return completed !== false;
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
        entries.forEach((entry) => metric('lost', { reason: entry.reason, source }));
        clearLostNotices();
        env.drafts()?.parkLost(view.docUuid, view.lost, view.baseVersion, view.tabId);
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

    function setLostNotice(text) {
        if (view.syncNotice === '' || lostNotices.includes(view.syncNotice)) {
            view.syncNotice = text;
        }
    }

    /** The list changed or emptied: what these notices said about the list as it was no longer holds. */
    function clearLostNotices() {
        if (lostNotices.includes(view.syncNotice)) {
            view.syncNotice = '';
        }
    }

    /**
     * Put it back (section 6.4): the lost block at `index` goes back on the
     * page, below the version that won (a `both` block, with a fresh id) or
     * where it was before it was removed (a `deleted` block, with its own
     * id, so comments and cross-references on it come back). An ordinary
     * writer's edit: undoable, autosaved on the current base, no overwrite
     * owed. The entry goes, and the park is rewritten with the rest.
     *
     * `options.focus` is true only for a press that came from the keyboard
     * (the page passes `$event.detail === 0`): a tap on a phone must not
     * focus the paper, which brings up the on-screen keyboard and scrolls
     * (`.ai/rules/livewire.md`, the notice bar). The block is in and the
     * caret is placed either way.
     *
     * @param {number} index
     * @param {{focus?: boolean}} [options]
     * @returns {boolean} whether the block went in
     */
    function putBackLost(index, options = {}) {
        const handle = env.handle();
        // Fail-closed: nothing may be written into the editor.
        if (cannotWrite(handle)) {
            return false;
        }

        const entry = view.lost[index];
        if (!entry) {
            return false;
        }

        let inserted = false;
        try {
            inserted =
                handle.insertBlock(entry.json, {
                    anchors: entry.anchors,
                    keepId: entry.reason === 'deleted',
                    focus: options?.focus === true,
                }) === true;
        } catch (_) {
            inserted = false;
        }
        if (!inserted) {
            setLostNotice(PUT_BACK_FAILED_NOTICE);

            return false;
        }

        clearLostNotices();
        view.lost = view.lost.filter((_, at) => at !== index);
        env.drafts()?.parkLost(view.docUuid, view.lost, view.baseVersion, view.tabId);
        metric('lost-action', { action: 'put_back' });

        return true;
    }

    /**
     * Put them all back: oldest first; the first that cannot go in stops it, and stays listed.
     * The editor takes focus ONCE, at the end, and only when every item went in and the press
     * came from the keyboard (`options.focus`): a partial failure must not leave a keyboard user
     * in the paper, away from the button and the row that is still there.
     *
     * @returns {boolean} whether every item went in (false: one stayed, or there was nothing)
     */
    function putBackAllLost(options = {}) {
        if (view.lost.length === 0) {
            return false;
        }
        const each = { ...options, focus: false };
        while (view.lost.length > 0) {
            if (!putBackLost(0, each)) {
                return false;
            }
        }
        const handle = env.handle();
        if (options?.focus === true && handle?.editor?.commands) {
            focusAfterInsert(handle.editor.commands, true);
        }

        return true;
    }

    /**
     * The way out of a list this browser could not keep a copy of: asked
     * (DISMISS_LOST_UNKEPT_QUESTION), and on yes the row is emptied without a
     * copy. An earlier write may have stored the list: it is stamped dismissed,
     * best effort, so it does not come back after a reload (the copy that could
     * not be written is the only thing that is gone). A no changes nothing.
     *
     * @returns {boolean} whether the row was emptied (false: declined)
     */
    function dismissWithoutCopy() {
        if (!env.confirm(DISMISS_LOST_UNKEPT_QUESTION)) {
            return false;
        }
        clearLostNotices();
        const listed = view.lost;
        view.lost = [];
        metric('lost-action', { action: 'dismiss', n: listed.length });
        try {
            Promise.resolve(env.drafts()?.markLostDismissed(view.docUuid, view.tabId, listed)).catch(() => {});
        } catch (_) {
            // Nothing more can be done.
        }

        return true;
    }

    /**
     * Dismiss all (section 6.5): the row is emptied; the park stays for its
     * 7 days and is stamped dismissed, so the next visit does not offer it
     * again (markLostDismissed() moves the entries to the row's `dismissed`
     * list, which the next loss's parkLost() carries over). Always asked,
     * because every entry is on the page nowhere. Not fail-closed-gated:
     * like discardSetAside(), it touches neither the editor nor a draft.
     *
     * The question promises a copy kept in this browser for 7 days, so the
     * park is written FIRST, with the list as it is on the page, and the row
     * is emptied only once that write is known to have stored it (a quota or
     * a blocked database makes parkLost() resolve false; a page with no draft
     * store has nothing to write to). A write that failed keeps every entry
     * on the page and says so (DISMISS_LOST_FAILED_NOTICE). That notice is
     * also the memory of the failure: the NEXT press asks
     * DISMISS_LOST_UNKEPT_QUESTION instead, and a yes drops the text without
     * a copy, a no changes nothing. A list that changed while the write was in
     * flight keeps everything too, because the new entry is not in that copy.
     *
     * @returns {Promise<boolean>} whether the row was emptied: false when the writer
     *          declined, the copy could not be kept (and the unkept question was
     *          declined) or the list changed meanwhile. The row stays then, and
     *          the page leaves the focus where it is (noticePressed()).
     */
    async function dismissLost() {
        if (view.lost.length === 0) {
            return false;
        }

        // The last press could not keep a copy: the writer decides, knowing it.
        if (view.syncNotice === DISMISS_LOST_FAILED_NOTICE) {
            return dismissWithoutCopy();
        }

        if (!env.confirm(DISMISS_LOST_QUESTION)) {
            return false;
        }

        const drafts = env.drafts();
        const dismissing = view.lost;
        let kept = false;
        try {
            kept = drafts ? (await drafts.parkLost(view.docUuid, dismissing, view.baseVersion, view.tabId)) === true : false;
        } catch (_) {
            kept = false;
        }
        if (!kept) {
            setLostNotice(DISMISS_LOST_FAILED_NOTICE);
            // The notice is the memory of the failure. When a stop reason stands it could not be shown, and the next
            // press would start over with the ordinary question: ask the unkept question now instead.
            if (view.syncNotice !== DISMISS_LOST_FAILED_NOTICE) {
                return dismissWithoutCopy();
            }

            return false;
        }
        // Every change of the list makes a new array (offerLost, putBackLost).
        // An EMPTY list is not a change: it is a press made meanwhile that has
        // already dismissed these very entries.
        if (view.lost !== dismissing && view.lost.length > 0) {
            setLostNotice(DISMISS_LOST_CHANGED_NOTICE);

            return false;
        }

        // Our own earlier "nothing was dismissed" is no longer true. (A sync notice has no way off the page
        // but a reload, so it must not be left behind to mislead.)
        clearLostNotices();
        view.lost = [];
        metric('lost-action', { action: 'dismiss', n: dismissing.length });
        drafts.markLostDismissed(view.docUuid, view.tabId, dismissing);

        return true;
    }

    /**
     * The re-offer after a reload (section 6.6): a parked list that was not
     * dismissed comes back into the lost row, each entry marked as from the
     * last visit. Every tab's rows of the document are taken into this tab's
     * own row (adoptLost()); an entry two tabs both put back can come back once
     * after a reload (spec section 19).
     */
    async function reofferLost(drafts) {
        let row = null;
        try {
            // Every tab's rows of this document become this tab's own (adoptLost()), so each entry has
            // one owner and Put it back and Dismiss all touch no other tab's row.
            row = drafts.adoptLost ? await drafts.adoptLost(view.docUuid, view.tabId) : null;
            // Nothing adopted: no rows, or the write failed (null either way): read them.
            row ??= await drafts.loadLost(view.docUuid);
        } catch (_) {
            row = null;
        }
        if (!row || row.dismissedAt || !Array.isArray(row.entries) || row.entries.length === 0) {
            return;
        }

        view.lost = row.entries.map((entry) => ({
            ...entry,
            source: 'parked',
            sentence: entry.source === 'parked' ? entry.sentence : `From your last visit: ${entry.sentence}`,
        }));
    }

    return {
        refocusAfterPress,
        offerLost,
        sentenceFor,
        setLostNotice,
        clearLostNotices,
        putBackLost,
        putBackAllLost,
        dismissWithoutCopy,
        dismissLost,
        reofferLost,
    };
}
