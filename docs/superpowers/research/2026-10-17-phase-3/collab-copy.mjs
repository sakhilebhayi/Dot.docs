/*
 * Evidence copy of prosemirror-collab 1.3.1 (dist/index.js), with two marked changes:
 * the inverse of a rebased step is computed before the step is committed and a throw
 * is treated as a step that cannot be rebased, and every dropped step is reported in
 * `rebaseReport`. Not product code.
 *
 * Copyright (C) 2015-2017 by Marijn Haverbeke <marijn@haverbeke.berlin> and others
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
import { PluginKey, Plugin, TextSelection } from 'prosemirror-state';

class Rebaseable {
    constructor(step, inverted, origin) {
        this.step = step;
        this.inverted = inverted;
        this.origin = origin;
    }
}
/**
Undo a given set of steps, apply a set of other steps, and then
redo them @internal
*/
export const rebaseReport = { invertThrows: 0, dropped: [] };
function rebaseSteps(steps, over, transform) {
    for (let i = steps.length - 1; i >= 0; i--)
        transform.step(steps[i].inverted);
    for (let i = 0; i < over.length; i++)
        transform.step(over[i]);
    let result = [];
    for (let i = 0, mapFrom = steps.length; i < steps.length; i++) {
        let mapped = steps[i].step.map(transform.mapping.slice(mapFrom));
        mapFrom--;
        if (mapped) {
            // Copy change 1: compute the inverse BEFORE committing the step, and treat an
            // inverse that throws (seen: ReplaceAroundStep.invert, 'Position -n outside of
            // fragment') as a step that could not be rebased, instead of throwing out of
            // receiveTransaction() and losing every unconfirmed step.
            let inverted = null;
            try {
                inverted = mapped.invert(transform.doc);
            } catch (e) {
                rebaseReport.invertThrows += 1;
                rebaseReport.dropped.push(steps[i].step);
                continue;
            }
            if (!transform.maybeStep(mapped).failed) {
                transform.mapping.setMirror(mapFrom, transform.steps.length - 1);
                result.push(new Rebaseable(mapped, inverted, steps[i].origin));
                continue;
            }
        }
        // Copy change 2: report what was dropped, so the page can offer the text back.
        rebaseReport.dropped.push(steps[i].step);
    }
    return result;
}
// This state field accumulates changes that have to be sent to the
// central authority in the collaborating group and makes it possible
// to integrate changes made by peers into our local document. It is
// defined by the plugin, and will be available as the `collab` field
// in the resulting editor state.
class CollabState {
    constructor(
    // The version number of the last update received from the central
    // authority. Starts at 0 or the value of the `version` property
    // in the option object, for the editor's value when the option
    // was enabled.
    version, 
    // The local steps that havent been successfully sent to the
    // server yet.
    unconfirmed) {
        this.version = version;
        this.unconfirmed = unconfirmed;
    }
}
function unconfirmedFrom(transform) {
    let result = [];
    for (let i = 0; i < transform.steps.length; i++)
        result.push(new Rebaseable(transform.steps[i], transform.steps[i].invert(transform.docs[i]), transform));
    return result;
}
const collabKey = new PluginKey("collab");
/**
Creates a plugin that enables the collaborative editing framework
for the editor.
*/
function collab(config = {}) {
    let conf = {
        version: config.version || 0,
        clientID: config.clientID == null ? Math.floor(Math.random() * 0xFFFFFFFF) : config.clientID
    };
    return new Plugin({
        key: collabKey,
        state: {
            init: () => new CollabState(conf.version, []),
            apply(tr, collab) {
                let newState = tr.getMeta(collabKey);
                if (newState)
                    return newState;
                if (tr.docChanged)
                    return new CollabState(collab.version, collab.unconfirmed.concat(unconfirmedFrom(tr)));
                return collab;
            }
        },
        config: conf,
        // This is used to notify the history plugin to not merge steps,
        // so that the history can be rebased.
        historyPreserveItems: true
    });
}
/**
Create a transaction that represents a set of new steps received from
the authority. Applying this transaction moves the state forward to
adjust to the authority's view of the document.
*/
function receiveTransaction(state, steps, clientIDs, options = {}) {
    // Pushes a set of steps (received from the central authority) into
    // the editor state (which should have the collab plugin enabled).
    // Will recognize its own changes, and confirm unconfirmed steps as
    // appropriate. Remaining unconfirmed steps will be rebased over
    // remote steps.
    let collabState = collabKey.getState(state);
    let version = collabState.version + steps.length;
    let ourID = collabKey.get(state).spec.config.clientID;
    // Find out which prefix of the steps originated with us
    let ours = 0;
    while (ours < clientIDs.length && clientIDs[ours] == ourID)
        ++ours;
    let unconfirmed = collabState.unconfirmed.slice(ours);
    steps = ours ? steps.slice(ours) : steps;
    // If all steps originated with us, we're done.
    if (!steps.length)
        return state.tr.setMeta(collabKey, new CollabState(version, unconfirmed));
    let nUnconfirmed = unconfirmed.length;
    let tr = state.tr;
    if (nUnconfirmed) {
        unconfirmed = rebaseSteps(unconfirmed, steps, tr);
    }
    else {
        for (let i = 0; i < steps.length; i++)
            tr.step(steps[i]);
        unconfirmed = [];
    }
    let newCollabState = new CollabState(version, unconfirmed);
    if (options && options.mapSelectionBackward && state.selection instanceof TextSelection) {
        tr.setSelection(TextSelection.between(tr.doc.resolve(tr.mapping.map(state.selection.anchor, -1)), tr.doc.resolve(tr.mapping.map(state.selection.head, -1)), -1));
        tr.updated &= ~1;
    }
    return tr.setMeta("rebased", nUnconfirmed).setMeta("addToHistory", false).setMeta(collabKey, newCollabState);
}
/**
Provides data describing the editor's unconfirmed steps, which need
to be sent to the central authority. Returns null when there is
nothing to send.

`origins` holds the _original_ transactions that produced each
steps. This can be useful for looking up time stamps and other
metadata for the steps, but note that the steps may have been
rebased, whereas the origin transactions are still the old,
unchanged objects.
*/
function sendableSteps(state) {
    let collabState = collabKey.getState(state);
    if (collabState.unconfirmed.length == 0)
        return null;
    return {
        version: collabState.version,
        steps: collabState.unconfirmed.map(s => s.step),
        clientID: collabKey.get(state).spec.config.clientID,
        get origins() {
            return this._origins || (this._origins = collabState.unconfirmed.map(s => s.origin));
        }
    };
}
/**
Get the version up to which the collab plugin has synced with the
central authority.
*/
function getVersion(state) {
    return collabKey.getState(state).version;
}

export { collab, getVersion, rebaseSteps, receiveTransaction, sendableSteps };
