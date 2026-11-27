/**
 * The host's timings, limits and the questions and notices it shows. Re-exported
 * by ../host.js under the same names; the tests read them from there.
 */

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

/**
 * How long a save is held back after its REQUEST failed (the Livewire call
 * rejected: no answer from the application), by the number of failures in a
 * row. The first failure is resent at the next poll tick as before; then the
 * hold grows to a cap of 60 s, so a document whose save fails every time (a
 * 413 or a 500 for this document) is not sent every 1.5 s or 10 s for ever. Any
 * answer to a save, accepted or refused, starts the count again. The hold is
 * measured on the clock the 15 s expiries use, and a send happens on the first
 * poll tick at or after it (a tick is at most one poll interval late).
 */
export const SAVE_RETRY_DELAYS_MS = [0, 5000, 10000, 20000, 40000, 60000];

/** How long the fetch for a merge is waited for before the conflict notice (section 5.7). */
export const MERGE_FETCH_EXPIRY_MS = SAVE_EXPIRY_MS;

/** How many item slots the notice bar's lost row has; the markup in editor.blade.php mirrors it. */
export const LOST_ROWS = 5;

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
 * What Load theirs asks when a text is already set aside and offered back: the
 * new text takes the offer's place. A browser that keeps drafts holds the
 * earlier one in a kept park (parkStaleDraft keeps earlier parks) and offers it
 * again once the new one has been put back or discarded (offerNextPark()); one
 * that does not has no copy, and the earlier text is gone.
 */
export const LOAD_THEIRS_REPLACES_QUESTION =
    'A text is already set aside from earlier. Loading theirs sets your text on the page aside instead. Where this browser keeps drafts, the earlier text is offered again once you have dealt with this one. Continue?';

/**
 * What Dismiss all asks before it empties the lost row (section 6.5). Always
 * asked: every entry is text a merge established is on the page nowhere.
 */
export const DISMISS_LOST_QUESTION =
    'Dismiss this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';

/**
 * What the notice bar says when Dismiss all could not write the browser copy
 * its question promised: nothing was dismissed, the entries are still listed.
 */
export const DISMISS_LOST_FAILED_NOTICE =
    'Your text could not be kept in this browser, so nothing was dismissed. It is still listed here.';

/** What the notice bar says when a block cannot be put back (Put it back, Put them all back, or the set-aside text). */
export const PUT_BACK_FAILED_NOTICE = 'Your text could not be put back.';

/**
 * What Dismiss all asks the press after a browser copy could not be written
 * (DISMISS_LOST_FAILED_NOTICE still stands): the one way out of a list the
 * writer wants gone and this browser cannot keep.
 */
export const DISMISS_LOST_UNKEPT_QUESTION =
    'This browser could not keep a copy of this text. Dismiss it anyway? It is removed from this page and may not be recoverable.';

/**
 * What the notice bar says when the list changed while Dismiss all was
 * writing its browser copy: the new entry was never in that copy, so nothing
 * was dismissed.
 */
export const DISMISS_LOST_CHANGED_NOTICE = 'The list changed while it was being dismissed, so nothing was dismissed. Press Dismiss all again.';

/**
 * What the page asks before it merges a draft the document has moved past
 * (section 7.1). A draft's deletion does not win there: D2 was decided for
 * live co-typing, and a days-old draft's deletion of a block somebody has
 * since changed must not remove their change.
 */
export const DRAFT_MERGE_QUESTION =
    'An unsaved draft from your last visit was found. Add its changes to the document as it is now? Changes saved by others since then are kept, and a paragraph the draft deleted that was changed since stays. Anything that could not be kept is offered back.';

/**
 * The notices the lost row writes to `syncNotice`. That field also carries
 * the stop reasons (signed out, no access, gone) and the refused-version
 * notice, which must stand: a lost-row notice is only written when the
 * field is empty or already holds one of these, and only these are ever
 * cleared here.
 */
export const lostNotices = [PUT_BACK_FAILED_NOTICE, DISMISS_LOST_FAILED_NOTICE, DISMISS_LOST_CHANGED_NOTICE];
