import { diffRange } from './narrow.js';

/**
 * The transaction that makes an editor show a document the server sent.
 *
 * This is the half of applyRemote() (../index.js) that decides WHAT changes.
 * applyRemote() keeps the half that decides WHETHER it may (the fail-closed
 * and unsaved-typing guards) and what happens around it (the transaction's
 * metas, the dispatch, the autosave bookkeeping). It lives here, apart from
 * the editor bundle, so `tests/js/sync.apply.test.js` can run every branch
 * of it under `node --test`.
 *
 * In order:
 *
 *   1. PHP cannot tell an empty object from an empty list, so the server
 *      sends `[]` where the editor's own value is `{}`: a section break's
 *      `setup` and the document's `vars`. ProseMirror compares the two as
 *      different, so a section break would look changed on every apply. They
 *      are levelled on a COPY before parsing: an empty `setup` takes
 *      whichever empty form this editor already holds for that section break
 *      (`{}` for one it has never seen), and an empty `vars` becomes `{}`.
 *   2. TipTap's TrailingNode plugin keeps an empty paragraph after a document
 *      that does not end in one. That paragraph is this editor's own: it is
 *      not in the server's document. It is carried over, so it is not
 *      removed and re-added (with a new id) on every apply.
 *   3. The server stamps `toc.entries` and `crossRef.label` on every save, so
 *      this editor's copies go stale as soon as a heading moves. They are
 *      brought level FIRST, as attribute steps (which shift no positions):
 *      otherwise a re-stamped contents list at the top stretches the replaced
 *      range from there to the real change, and the caret and undo history
 *      inside that stretch are lost.
 *   4. The one range that still differs (see ./narrow.js) is replaced with a
 *      ReplaceStep, NOT tr.replace(): tr.replace() runs ProseMirror's fitter,
 *      which re-shapes an open slice around isolating nodes (table cells,
 *      figures, columns) and hands back a DIFFERENT document without
 *      throwing. The result is checked against the target; if the step
 *      throws or the result differs, everything is replaced instead:
 *      correct, just not gentle.
 *   5. Document-level attrs (schema, style, vars) are not content, so the
 *      range never carries them. They are set with their own steps.
 *
 * Dependency-free: `ReplaceStep` is handed in (the editor bundle passes the
 * one from `@tiptap/pm/transform`, the tests the one from
 * `prosemirror-transform`), and everything else is a method on the state it
 * is given.
 *
 * @param {import('prosemirror-state').EditorState} state the editor's state
 * @param {object} json a Dot.Doc document, as the server sent it
 * @param {typeof import('prosemirror-transform').ReplaceStep} ReplaceStep
 * @returns {import('prosemirror-state').Transaction|null} null when the
 *          document cannot be parsed by this schema or cannot be applied at
 *          all; otherwise a transaction, which has NO steps when the editor
 *          already shows the document. It carries no metas and has not been
 *          dispatched.
 */
export function remoteTransaction(state, json, ReplaceStep) {
    const isEmptyValue = (value) => value !== null && typeof value === 'object' && Object.keys(value).length === 0;

    // 1. Level `[]` against `{}`.
    const heldSetups = new Map();
    state.doc.descendants((node) => {
        if (node.type.name === 'sectionBreak') {
            heldSetups.set(node.attrs.id, node.attrs.setup);
        }
    });
    const incoming = JSON.parse(JSON.stringify(json));
    const levelEmptySetups = (node) => {
        if (node.type === 'sectionBreak' && node.attrs && isEmptyValue(node.attrs.setup)) {
            node.attrs.setup = Array.isArray(heldSetups.get(node.attrs.id)) ? [] : {};
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(levelEmptySetups);
        }
    };
    levelEmptySetups(incoming);
    if (incoming.attrs && isEmptyValue(incoming.attrs.vars)) {
        incoming.attrs.vars = {};
    }

    let next = null;
    try {
        next = state.schema.nodeFromJSON(incoming);
        next.check();
    } catch (_) {
        // A document this editor cannot parse: the caller leaves what is on
        // screen alone rather than blanking it.
        return null;
    }

    // 2. Carry the editor's own trailing paragraph over.
    const heldLast = state.doc.lastChild;
    if (
        next.lastChild &&
        next.lastChild.type.name !== 'paragraph' &&
        heldLast &&
        heldLast.type.name === 'paragraph' &&
        heldLast.content.size === 0
    ) {
        let known = false;
        next.descendants((node) => {
            known = known || node.attrs.id === heldLast.attrs.id;

            return !known;
        });
        if (!known) {
            next = next.copy(next.content.addToEnd(heldLast));
        }
    }

    const tr = state.tr;

    // 3. Level the server-stamped attributes, as attribute steps.
    const stamped = { toc: new Map(), crossRef: new Map() };
    next.descendants((node) => {
        if (node.type.name === 'toc') {
            stamped.toc.set(node.attrs.id, node.attrs.entries);
        }
        if (node.type.name === 'crossRef') {
            stamped.crossRef.set(`${node.attrs.kind}|${node.attrs.targetId}`, node.attrs.label);
        }
    });
    state.doc.descendants((node, pos) => {
        if (node.type.name === 'toc' && stamped.toc.has(node.attrs.id)) {
            const entries = stamped.toc.get(node.attrs.id);
            if (JSON.stringify(entries) !== JSON.stringify(node.attrs.entries)) {
                tr.setNodeAttribute(pos, 'entries', entries);
            }
        }
        if (node.type.name === 'crossRef') {
            const key = `${node.attrs.kind}|${node.attrs.targetId}`;
            if (stamped.crossRef.has(key) && stamped.crossRef.get(key) !== node.attrs.label) {
                tr.setNodeAttribute(pos, 'label', stamped.crossRef.get(key));
            }
        }
    });

    // 4. Replace the one range that still differs.
    const range = diffRange(tr.doc, next);

    if (range !== null) {
        try {
            tr.step(new ReplaceStep(range.from, range.toA, next.slice(range.from, range.toB)));
            if (!tr.doc.content.eq(next.content)) {
                throw new Error('The narrowed replace did not reproduce the document');
            }
        } catch (_) {
            // The narrowed slice did not fit (a structural change the open
            // slice cannot express). Sized from tr.doc, not state.doc: the
            // transaction may already hold a step.
            try {
                tr.replaceWith(0, tr.doc.content.size, next.content);
            } catch (_error) {
                return null;
            }
        }
    }

    // 5. Document-level attrs. `{}` and `[]` are the same empty value here
    // (see 1), and are not a difference.
    Object.keys(next.attrs || {}).forEach((key) => {
        const held = state.doc.attrs[key];
        const arriving = next.attrs[key];
        if (isEmptyValue(held) && isEmptyValue(arriving)) {
            return;
        }
        if (JSON.stringify(held) !== JSON.stringify(arriving)) {
            tr.setDocAttribute(key, arriving);
        }
    });

    return tr;
}
