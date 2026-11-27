/**
 * The editor page's half of keeping an open document in step.
 *
 * The engine (./engine.js) asks the server what is new. This is what the
 * page does about it, and about its own saves: whether a save is sent, has
 * to wait, is sent again or is refused; whether a newer document is
 * followed or raises the conflict notice; what Keep mine, Load theirs and
 * Put it back do; when a version is adopted; and what happens to the
 * offline draft. Every one of those decisions is here, so that
 * `tests/js/sync.host.test.js` can run them under `node --test` together
 * with the real engine. The Blade component
 * (resources/views/livewire/documents/editor.blade.php) keeps the reactive
 * state fields and the page glue, and its methods are one-line calls into
 * this module.
 *
 * It holds NO state of its own. Alpine re-creates the component's data
 * object whenever a render changes the x-data string, and handlers bound at
 * first load stay bound to the first object, so every call has to read and
 * write the object it was made on. `createSyncHost(view, env)` is therefore
 * built afresh from `this` for each call; it keeps nothing between calls,
 * and it assigns to `view`'s own properties (never to a copy written back
 * later), which is what keeps Alpine's reactivity working.
 *
 * No DOM, no `window`, no Livewire, no clock and no console of its own:
 * everything outside the state object arrives in `env` (the one exception is
 * paintVersion(), which paints the status strip when there is a document).
 *
 * Phase 2 (the paragraph-level merge, docs/superpowers/specs/
 * 2026-10-16-coediting-phase-2-paragraph-merge-design.md): a newer document
 * that arrives while this tab holds unsaved typing is MERGED block by block
 * (mergeOrConflict()), applied over the typing and saved on the new base;
 * a save refused as stale fetches the newer document to merge it
 * (beginMerge()); the conflict notice is for a merge that cannot be done.
 * What the merge could not keep is offered back in the notice bar's lost
 * row (offerLost(), putBackLost(), dismissLost()).
 *
 * Counters (./metrics.js): `env.metric(kind, detail)` is told what happened
 * (a merge and whether it left anything over, an offer, a conflict notice, a
 * failed save, a downgrade, who was present). It is OPTIONAL and strictly
 * one-way: nothing here reads an answer from it, nothing waits on it, a
 * throw from it is swallowed, and with no `env.metric` the module behaves
 * exactly as it did before there were counters. What it is told is counts
 * and closed words, never a piece of the document.
 */

/*
 * Layout. This file is the public entry: it re-exports every name the page
 * and the tests import, and builds the host from its parts under ./host/,
 * each one responsibility:
 *
 *   constants.js    timings, limits, questions and notices
 *   versionStrip.js paintVersion()
 *   tabId.js        createTabId()
 *   beacon.js       unloadSaveBody(), beaconWanted()
 *   core.js         counters, write gates, draft stamp, owed overwrite, save expiry
 *   save.js         the save queue: persist(), its answer, resend, back-off, settle
 *   follow.js       following the server: syncState(), applyFromSync(), adoptVersion()
 *   liveMerge.js    the live merge after a refused save or a newer document
 *   conflict.js     the conflict notice, Keep mine, Load theirs
 *   setAside.js     the set-aside text, Put it back, Discard, the kept parks
 *   lost.js         the notice bar's lost row
 *   drafts.js       the offline draft: written on edit, restored or merged on open
 *   suggestion.js   an accepted suggestion going into the page
 *   access.js       a refused save, the access question, the read only page
 *   engineHost.js   the engine's callbacks and the presence strip
 *
 * Every part is a factory over the same `view` and `env`; their functions all
 * go on one object, and a part calls another's through it, looked up when
 * called (./host/late.js), so no part imports another part.
 */

import { createAccess } from './host/access.js';
import { createConflict } from './host/conflict.js';
import { createCore } from './host/core.js';
import { createDrafts } from './host/drafts.js';
import { createEngineHost } from './host/engineHost.js';
import { createFollow } from './host/follow.js';
import { createLiveMerge } from './host/liveMerge.js';
import { createLost } from './host/lost.js';
import { createSave } from './host/save.js';
import { createSetAside } from './host/setAside.js';
import { createSuggestion } from './host/suggestion.js';

export { beaconWanted, unloadSaveBody } from './host/beacon.js';
export {
    DISCARD_QUESTION,
    DISMISS_LOST_CHANGED_NOTICE,
    DISMISS_LOST_FAILED_NOTICE,
    DISMISS_LOST_QUESTION,
    DISMISS_LOST_UNKEPT_QUESTION,
    DRAFT_MERGE_QUESTION,
    LOAD_THEIRS_REPLACES_QUESTION,
    LOST_ROWS,
    MERGE_ATTEMPTS,
    MERGE_FETCH_EXPIRY_MS,
    PUT_BACK_FAILED_NOTICE,
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    SAVE_EXPIRY_MS,
    SAVE_RETRY_DELAYS_MS,
} from './host/constants.js';
export { createTabId } from './host/tabId.js';
export { paintVersion } from './host/versionStrip.js';

/**
 * @typedef {object} SyncView The Blade component's state fields.
 * @property {number} saving how many of this tab's own saves are in the air
 * @property {number} savingSince when the latest of them left
 * @property {number} saveSeq the number persist() gave the last save it sent;
 *           every save this tab sends gets the next one
 * @property {number} expiredSaveSeq the number of the last save this tab gave
 *           up on (saveInTheAir()): the answer to a save numbered at or below
 *           it comes too late to be trusted on its own
 * @property {boolean} unsaved true from the first local edit until a save of
 *           exactly what the editor holds has been confirmed by the server
 * @property {boolean} resave a save is owed; resendIfOwed() sends it
 * @property {string|null} confirmed the document, as a JSON string, as the
 *           server last confirmed it, which is the document of the version
 *           `baseVersion` names. Null before the editor is mounted, and
 *           again whenever that is no longer known: from the moment one of
 *           this tab's saves goes unanswered, and from the moment Keep mine
 *           moves the base up to a version the editor has never held. It is
 *           set again when a save is accepted or a server document applied
 * @property {boolean} overwriteOwed this tab's text is to replace, on
 *           purpose, the version its base now names (Keep mine moved the
 *           base up to it; Put it back replaces the version that was
 *           loaded). Every save says so (the Keep mine save, a retry, an
 *           ordinary autosave, the unload beacon) until one that said so is
 *           accepted, and the server keeps the replaced version first
 * @property {number|null} overwriteFloor the base the owed overwrite first
 *           became owed on; null while none is owed. The draft is stamped
 *           below it (draftStamp()), so a draft typed after Keep mine moved
 *           the base up is never merged against a base row written while
 *           the overwrite was already owed
 * @property {{version: number, ready: boolean}|null} conflict
 * @property {string|null} setAside the writer's own text, as a JSON string,
 *           kept so the page can offer to put it back
 * @property {'conflict'|'draft'|null} setAsideFrom where `setAside` came
 *           from: `conflict` is this tab's text, set aside a moment ago by
 *           Load theirs; `draft` is a draft found at page load that the
 *           document has moved past. Null while nothing is set aside
 * @property {number|null} setAsideBase the document version the page showed
 *           when the text was set aside. Null while nothing is set aside
 * @property {string} syncNotice
 * @property {boolean} editable whether the server rendered this page for somebody who may change the document
 *           (the one server literal in the x-data object; false turns to false here when a save is refused)
 * @property {number} accessCheckSince when the question "may I still change this?" was asked after a refused save (0: none)
 * @property {boolean} unsentKept text typed here was left unsent because a save was refused, and a copy is kept in
 *           this browser
 * @property {string} memberKey
 * @property {string} docUuid
 * @property {number} documentVersion the version this page was rendered from
 * @property {number} baseVersion the version this tab's copy is based on
 * @property {string} aiError
 * @property {number} tick
 * @property {{version: number, since: number}|null} merging set while a
 *           newer document is being fetched for a merge (beginMerge());
 *           persist() sends nothing while it is, syncState() reports dirty,
 *           and resendIfOwed() gives it up after MERGE_FETCH_EXPIRY_MS
 * @property {number} mergeAttempts merges after refused saves, in a row.
 *           Reset by an accepted save, by enterConflict() and by applyFromSync()
 * @property {{version: number, json: string}|null} known the last server
 *           document this tab held, as the editor serialises it, with its
 *           version. NEVER nulled: `confirmed` is nulled on purpose (a lost
 *           answer, a failed request, Keep mine) while the last agreed
 *           document is still the right merge base, as long as its version
 *           is the one the typing is based on
 * @property {string|null} sentSnapshot the JSON string of the save now in the air
 * @property {string|null} unanswered the JSON string of the last save given
 *           up on or whose request failed; the merge's echo (section 4.2).
 *           Null once anything is confirmed
 * @property {object[]} lost the lost row's entries (section 6.1), oldest first
 */

/**
 * @typedef {object} SyncEnv Everything the page hands the module.
 * @property {() => object|null|undefined} handle the editor handle the bundle
 *           parked on the element (`editor`, `autosaves`, `pending`,
 *           `applyRemote`), or nothing when no editor is mounted
 * @property {() => object|null|undefined} engine the sync engine parked on
 *           the element, or nothing before it has started
 * @property {(json: object, baseVersion: number, overwrite: boolean) => Promise<{ok: boolean, conflict: boolean, version: number|null}>} save
 *           Editor::saveContent(). `version` is null in one answer only: the
 *           refusal of a save that stated no base
 * @property {() => Promise<void>} refreshOutline pull the server's outline
 * @property {() => Promise<boolean>} canEdit Editor::canEdit(): whether this person may still change the document
 * @property {() => void} refreshPresence re-render the presence strip
 * @property {(tone: string, word: string) => void} report the status word
 * @property {() => object|null|undefined} drafts `window.offlineDraft`
 * @property {(a: object, b: object) => boolean} documentsDiffer
 * @property {(outline: object) => void} showOutline hand an outline that
 *           came with a document to the bundle
 * @property {(css: string) => void} showCss put a document style in the page
 * @property {(question: string) => boolean} confirm
 * @property {() => number} now
 * @property {(...args: unknown[]) => void} info `console.info`
 * @property {(...args: unknown[]) => void} log `console.error`
 * @property {((kind: string, detail?: object) => void)|undefined} metric the page's counters (./metrics.js); optional
 */

/**
 * @param {SyncView} view the Alpine data object the call was made on
 * @param {SyncEnv} env
 */
export function createSyncHost(view, env) {
    // The parts' functions, all on one object; each part looks the others up here when it calls them.
    const h = {};
    Object.assign(
        h,
        createCore(view, env, h),
        createSave(view, env, h),
        createFollow(view, env, h),
        createLiveMerge(view, env, h),
        createConflict(view, env, h),
        createSetAside(view, env, h),
        createLost(view, env, h),
        createDrafts(view, env, h),
        createSuggestion(view, env, h),
        createAccess(view, env, h),
        createEngineHost(view, env, h)
    );

    return {
        opened: h.opened,
        unsentAtUnload: h.unsentAtUnload,
        refocusAfterPress: h.refocusAfterPress,
        edited: h.edited,
        persist: h.persist,
        resendIfOwed: h.resendIfOwed,
        clearDraftIfSettled: h.clearDraftIfSettled,
        syncState: h.syncState,
        adoptVersion: h.adoptVersion,
        applyFromSync: h.applyFromSync,
        enterConflict: h.enterConflict,
        beginMerge: h.beginMerge,
        mergeOrConflict: h.mergeOrConflict,
        expireMerging: h.expireMerging,
        markConfirmed: h.markConfirmed,
        offerLost: h.offerLost,
        sentenceFor: h.sentenceFor,
        keepMine: h.keepMine,
        loadTheirs: h.loadTheirs,
        putBack: h.putBack,
        discardSetAside: h.discardSetAside,
        offerNextPark: h.offerNextPark,
        putBackLost: h.putBackLost,
        putBackAllLost: h.putBackAllLost,
        dismissLost: h.dismissLost,
        membersChanged: h.membersChanged,
        restoreDraft: h.restoreDraft,
        applySuggestion: h.applySuggestion,
        backOnline: h.backOnline,
        isSaveRequest: h.isSaveRequest,
        saveRefused: h.saveRefused,
        readOnlyNoticed: h.readOnlyNoticed,
        accessFromPoll: h.accessFromPoll,
        engineHost: h.engineHost,
    };
}
