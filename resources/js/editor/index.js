import { DotDoc } from './mount/api';
import {
    clearDraft,
    loadBase,
    loadDraft,
    adoptLost,
    loadLost,
    markLostDismissed,
    nextParkToOffer,
    parkLost,
    parkStaleDraft,
    purgeStaleDrafts,
    resolveParked,
    saveBase,
    saveDraft,
} from '../offline';

/*
 * The editor page's bundle entry: it sets the two globals the Blade bridge
 * calls. The editor is built in mount/: extensions.js (the extension list),
 * mount.js (mount() and its handle), documentOps.js (the handle's
 * applyHtml/applyRemote/merge/insertBlock), images.js (image upload),
 * selection.js, and api.js (the window.DotDoc object).
 */
export { DotDoc };

window.DotDoc = DotDoc;

// Offline draft helpers the Blade bridge calls (kept from the pre-JSON editor).
// parkStaleDraft() is how a draft that cannot be restored is kept instead of
// deleted; purgeStaleDrafts() (also run on app boot) is what stops those
// parked rows accumulating in IndexedDB for ever. saveBase()/loadBase() keep
// the server document a tab's typing is based on, so a stale draft can be
// merged on the next visit; parkLost()/loadLost()/markLostDismissed() keep
// the blocks a merge could not keep, for the notice bar's lost row (one row
// per tab, adoptLost() on open); nextParkToOffer()/resolveParked() are the
// queue of set-aside texts the page offers one after another.
window.offlineDraft = {
    saveDraft,
    loadDraft,
    clearDraft,
    parkStaleDraft,
    purgeStaleDrafts,
    saveBase,
    loadBase,
    parkLost,
    loadLost,
    adoptLost,
    markLostDismissed,
    nextParkToOffer,
    resolveParked,
};

export default DotDoc;
