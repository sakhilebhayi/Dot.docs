import { diffRange } from './narrow.js';

/**
 * The transaction that makes an editor show a document the server sent.
 *
 * This is the half of applyRemote() (../index.js) that decides WHAT changes.
 * applyRemote() keeps the half that decides WHETHER it may (the fail-closed
 * and unsaved-typing guards) and what happens around it (the transaction's
 * metas, the dispatch, the autosave bookkeeping). It lives here, apart from
 * the editor bundle, so `tests/js/sync.apply.test.js` can run every branch
 * of it under `node --test`. Once this transaction has changed the document,
 * applyRemote() also dispatches clearHistoryTransaction() (below): the local
 * undo history does not survive a remote change.
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
 *      range from there to the real change, and a caret inside that stretch
 *      is moved to its end.
 *   4. What still differs is changed BLOCK BY BLOCK (blockSteps(), below):
 *      a block absent from the arriving document is deleted, a changed
 *      textblock is replaced inside the block only (the characters that
 *      differ), any other changed block (a list, a table, columns, a
 *      callout, a figure, an atom, an attribute change) is replaced whole,
 *      and an inserted block is put in after its predecessor. A deletion
 *      and an insertion at one point are one replace, so the document
 *      never passes through an empty `doc`. The steps are probed on a copy
 *      first: a narrowed step that fails is retried whole, and if any step
 *      fails, the result is not the target, or there are more than
 *      MAX_BLOCK_STEPS steps (a restore or an import that shares few ids with
 *      the document: every step is applied twice and mapped through every
 *      plugin, which costs more than the one range), the one range that differs
 *      (see ./narrow.js) is replaced instead, as a ReplaceStep, NOT
 *      tr.replace(): tr.replace() runs ProseMirror's fitter, which
 *      re-shapes an open slice around isolating nodes and hands back a
 *      DIFFERENT document without throwing. If that too throws or differs,
 *      everything is replaced: correct, just not gentle. The caret survives
 *      because Transaction.addStep maps the selection through every step: a
 *      caret in a block no step touches maps straight through, a caret
 *      inside a narrowed replace moves only when it sits inside the changed
 *      characters, and a caret in a block replaced whole lands at the end
 *      of the replacement.
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

    // 4. Change what still differs: block by block (4a); failing that, the
    //    one range that differs (4b); failing that, everything (4c).
    //    applyBlockSteps() adds steps only when every one of them applies
    //    and the probe reproduces the target, so on false tr.doc is as it
    //    was and `range` still holds.
    const range = diffRange(tr.doc, next);

    if (range !== null && !applyBlockSteps(tr, next, ReplaceStep)) {
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

/**
 * More block steps than this are not worth taking: a document-sized change
 * (a restore, an import) is applied as the one range of Phase 1 instead.
 */
export const MAX_BLOCK_STEPS = 500;

/**
 * The edits that turn `current` into `next`, one top-level block at a time,
 * in CURRENT coordinates, sorted so they apply from the end of the document
 * backwards (so no step shifts the positions of the steps still to come).
 *
 *   1. The top-level children of both, with their ids. A block without an
 *      id, or two blocks sharing one in one document: null (the caller
 *      falls back to the one-range apply).
 *   2. The common blocks in a different relative order (a move): null.
 *   3. In current coordinates: a block absent from `next` is deleted; a
 *      block whose content differs is replaced inside the block when both
 *      versions are textblocks with the same markup (the characters that
 *      differ, found by diffRange(); the whole block is kept as `whole`, the
 *      fallback should the narrowed step fail), and whole otherwise; a block
 *      absent from `current` is inserted after the current counterpart of
 *      the nearest preceding `next` block that has one, consecutive
 *      insertions at one point as one slice.
 *   4. A deletion that starts where an insertion starts, and that insertion,
 *      are one replace: `[A] -> [B]` is one step and the document never
 *      passes through an empty `doc`.
 *   5. Sorted by `from` descending; when two edits start at one position
 *      the wider goes first (the replace of the block that starts there,
 *      then the insertion in front of it).
 *
 * Every slice is cut from `next` at top-level boundaries (closed), or from
 * inside one textblock (inline content): neither needs the fitter.
 *
 * @param {import('prosemirror-model').Node} current the document the editor holds
 * @param {import('prosemirror-model').Node} next    the document it should hold
 * @param {typeof import('prosemirror-transform').ReplaceStep} ReplaceStep
 * @returns {{step: object, whole: object|null, used: object|null}[]|null}
 */
export function blockSteps(current, next, ReplaceStep) {
    const list = (docNode) => {
        const blocks = [];
        const seen = new Set();
        let ok = true;
        docNode.forEach((node, offset) => {
            const id = node.attrs.id;
            if (id === null || id === undefined || seen.has(id)) {
                ok = false;
            }
            seen.add(id);
            blocks.push({ id, node, from: offset, to: offset + node.nodeSize });
        });

        return ok ? blocks : null;
    };
    const a = list(current);
    const b = list(next);
    if (a === null || b === null) {
        return null;
    }
    const aById = new Map(a.map((block) => [block.id, block]));
    const bById = new Map(b.map((block) => [block.id, block]));

    // 2. A move is not a per-block edit.
    const commonA = a.filter((block) => bById.has(block.id)).map((block) => block.id);
    const commonB = b.filter((block) => aById.has(block.id)).map((block) => block.id);
    if (commonA.some((id, index) => id !== commonB[index])) {
        return null;
    }

    // 3. Deletions and replacements, in current coordinates.
    const edits = [];
    a.forEach((block) => {
        const other = bById.get(block.id);
        if (!other) {
            edits.push({ kind: 'delete', from: block.from, to: block.to, slice: next.slice(0, 0), whole: null });

            return;
        }
        if (block.node.eq(other.node)) {
            return;
        }
        const whole = { from: block.from, to: block.to, slice: next.slice(other.from, other.to) };
        if (block.node.isTextblock && other.node.isTextblock && block.node.sameMarkup(other.node)) {
            const range = diffRange(block.node, other.node);
            if (range !== null) {
                edits.push({
                    kind: 'replace',
                    from: block.from + 1 + range.from,
                    to: block.from + 1 + range.toA,
                    slice: other.node.slice(range.from, range.toB),
                    whole,
                });

                return;
            }
        }
        edits.push({ kind: 'replace', from: whole.from, to: whole.to, slice: whole.slice, whole: null });
    });

    // Insertions: after the current counterpart of the nearest preceding
    // `next` block that has one; a run of them at one point is one slice.
    let at = 0;
    let run = null;
    b.forEach((block) => {
        const counterpart = aById.get(block.id);
        if (counterpart) {
            at = counterpart.to;
            run = null;

            return;
        }
        if (run === null) {
            run = { kind: 'insert', from: at, to: at, first: block.from, last: block.to, slice: null, whole: null };
            edits.push(run);
        }
        run.last = block.to;
    });
    edits.forEach((edit) => {
        if (edit.kind === 'insert') {
            edit.slice = next.slice(edit.first, edit.last);
        }
    });

    // 4. Coalesce.
    edits
        .filter((edit) => edit.kind === 'insert')
        .forEach((insert) => {
            const deletion = edits.find((edit) => edit.kind === 'delete' && edit.from === insert.from);
            if (deletion) {
                deletion.kind = 'replace';
                deletion.slice = insert.slice;
                edits.splice(edits.indexOf(insert), 1);
            }
        });

    // 5. From the end backwards; the wider edit first at one position.
    edits.sort((x, y) => y.from - x.from || y.to - x.to);

    return edits.map((edit) => ({
        step: new ReplaceStep(edit.from, edit.to, edit.slice),
        whole: edit.whole ? new ReplaceStep(edit.whole.from, edit.whole.to, edit.whole.slice) : null,
        used: null,
    }));
}

/**
 * Step 4a of remoteTransaction(): the block steps, probed on a copy of the
 * document before any of them is added to the transaction. A narrowed step
 * that fails is retried whole; a step that still fails, or a probe that
 * does not reproduce the target, leaves the transaction untouched, and so
 * does a list of more than MAX_BLOCK_STEPS edits, which is not probed at all.
 *
 * @returns {boolean} whether the steps were added
 */
function applyBlockSteps(tr, next, ReplaceStep) {
    let edits = null;
    try {
        edits = blockSteps(tr.doc, next, ReplaceStep);
    } catch (_) {
        return false;
    }
    if (edits === null || edits.length > MAX_BLOCK_STEPS) {
        return false;
    }

    let probe = tr.doc;
    for (const edit of edits) {
        let result = edit.step.apply(probe);
        edit.used = edit.step;
        if (result.failed && edit.whole !== null) {
            result = edit.whole.apply(probe);
            edit.used = edit.whole;
        }
        if (result.failed) {
            return false;
        }
        probe = result.doc;
    }
    if (!probe.content.eq(next.content)) {
        return false;
    }

    edits.forEach((edit) => tr.step(edit.used));

    return true;
}

/**
 * The key prosemirror-history gives its plugin (`new PluginKey('history')`).
 * The package does not export the key, and this file imports nothing, so the
 * plugin is found among the state's plugins by this string.
 */
const HISTORY_KEY = 'history$';

/**
 * The transaction that empties an editor's undo and redo history.
 *
 * applyRemote() (../index.js) dispatches it directly after a remote
 * transaction that changed the document, because the local undo history
 * must not survive a remote change:
 *
 *   prosemirror-history keeps its stacks valid across a transaction that is
 *   not an undo step by mapping every stored step through that transaction.
 *   That is exact when the other person's change arrives as the small steps
 *   they actually made. It is not safe for the ONE coarse replacement
 *   remoteTransaction() builds: a stored step whose range touches the
 *   replaced range is mapped onto the content that was inserted. Press Enter
 *   at the start of a paragraph, let somebody else type in both halves, and
 *   undo: the inverse of the split now covers exactly what they typed, and
 *   deletes it. The next autosave would store that.
 *   (`tests/js/sync.history.test.js` replays it.)
 *
 * So undo reaches back only as far as the last change that came from
 * somebody else. Keeping the stacks "when no stored step touches the range"
 * was considered and rejected: a mistake in such a check is silent data loss
 * again. Collaborative undo needs remote changes to arrive as exact steps
 * (the step log of Phase 3).
 *
 * prosemirror-history has no call that clears it. Its plugin replaces its
 * state with whatever a transaction carries under the plugin's key
 * (`historyState`; that is how its own undo and redo commands work), and an
 * empty state is what the plugin's `spec.state.init()` returns.
 *
 * It is a transaction of its OWN, never a meta on the remote transaction:
 * prosemirror-history records a transaction appended to one that carries
 * this meta on the redo stack, whatever its `addToHistory` says, and the
 * remote dispatch can have appended transactions (TipTap's TrailingNode and
 * UniqueID, the id repair). This one has no steps, so the plugins that
 * react to a changed document append nothing to it. It is tagged
 * `addToHistory: false` and `preventUpdate`: nothing changed that the writer
 * could undo or the autosave should send.
 *
 * Dependency-free, like remoteTransaction(): everything is a method on the
 * state it is given.
 *
 * @param {import('prosemirror-state').EditorState} state the editor's state,
 *        AFTER the remote transaction has been applied
 * @returns {import('prosemirror-state').Transaction|null} null when the
 *          state has no history plugin; otherwise a transaction with no
 *          steps, which changes neither the document nor the selection. It
 *          has not been dispatched.
 */
export function clearHistoryTransaction(state) {
    const plugin = state.plugins.find((candidate) => candidate.key === HISTORY_KEY);

    if (!plugin || !plugin.spec.state || typeof plugin.spec.state.init !== 'function') {
        return null;
    }

    return state.tr
        .setMeta(HISTORY_KEY, { historyState: plugin.spec.state.init() })
        .setMeta('addToHistory', false)
        .setMeta('preventUpdate', true);
}
