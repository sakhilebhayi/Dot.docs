import { insertionPoint } from './narrow.js';

/** How many ids the generator is asked for before a block is left without one (UniqueID then fills it in). */
const FRESH_ID_ATTEMPTS = 8;

/**
 * The transaction that puts a lost block back on the page (the lost row's
 * Put it back, Phase 2).
 *
 * The block arrives as JSON from the lost entry, exactly as the editor
 * serialised it when the merge could not keep it. Its ids are decided
 * first: every id is replaced by a fresh one, so a block both people
 * changed (D1) goes in as a NEW block below the winner and never shares the
 * winner's id; with `keepId`, an id the document does not hold any more
 * stays, so a block somebody deleted while I typed in it (D2) returns with
 * its own id, and the comments and cross-references that pointed at it come
 * back with it. Nested blocks (a list's items, a table's cells) follow the
 * same rule. The block is checked against the schema, inserted after the
 * first of its anchors that is on the page (see insertionPoint() in
 * ./narrow.js), and the caret is put at its end so the writer can carry on
 * typing in it (after an atom, never on it: see caretAfterInsert()).
 *
 * An ordinary writer's edit: the caller dispatches it without
 * `addToHistory: false` or `preventUpdate`, so it is undoable, `update`
 * fires and the autosave sends it on the current base. It replaces
 * nothing, so no overwrite is owed.
 *
 * Dependency-free: the id generator and `Selection` are handed in (the
 * bundle passes base62() and @tiptap/pm/state's; the tests pass
 * prosemirror-state's).
 *
 * @param {import('prosemirror-state').EditorState} state
 * @param {object} json the block
 * @param {{anchors?: string[], keepId?: boolean, generateId: () => string, Selection: typeof import('prosemirror-state').Selection}} options
 * @returns {import('prosemirror-state').Transaction} not dispatched; a throw means the block cannot go in
 */
export function insertBlockTransaction(state, json, { anchors = [], keepId = false, generateId, Selection }) {
    const block = JSON.parse(JSON.stringify(json));

    const taken = new Set();
    state.doc.descendants((node) => {
        if (node.attrs && node.attrs.id !== null && node.attrs.id !== undefined) {
            taken.add(node.attrs.id);
        }
    });
    const fresh = () => {
        for (let attempt = 0; attempt < FRESH_ID_ATTEMPTS; attempt += 1) {
            const id = generateId();
            if (!taken.has(id)) {
                taken.add(id);

                return id;
            }
        }

        return null;
    };
    const reId = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        if (node.attrs && Object.prototype.hasOwnProperty.call(node.attrs, 'id')) {
            const own = node.attrs.id;
            if (keepId && own !== null && own !== undefined && !taken.has(own)) {
                taken.add(own);
            } else {
                node.attrs.id = fresh();
            }
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(reId);
        }
    };
    reId(block);

    const node = state.schema.nodeFromJSON(block);
    node.check();

    const pos = insertionPoint(state.doc, anchors);
    const tr = state.tr.insert(pos, node);

    return tr.setSelection(caretAfterInsert(tr.doc, pos, node, Selection)).scrollIntoView();
}

/**
 * Where the caret goes once the block is in. A block with content: the end of
 * it, so the writer can carry on typing. An atom (a rule, a page break, a
 * section break, a contents list) has nothing to type in, and a selection ON
 * it would be replaced by the first keystroke: the caret goes to the start of
 * the next text block, else to the end of the one before, and only a page
 * with no text block at all falls back to whatever is nearest.
 *
 * @param {import('prosemirror-model').Node} docNode the document with the block in it
 * @param {number} pos where the block starts
 * @param {import('prosemirror-model').Node} node the block
 * @param {typeof import('prosemirror-state').Selection} Selection
 * @returns {import('prosemirror-state').Selection}
 */
function caretAfterInsert(docNode, pos, node, Selection) {
    if (node.isAtom || node.isLeaf) {
        const $after = docNode.resolve(Math.min(pos + node.nodeSize, docNode.content.size));

        return Selection.findFrom($after, 1, true) ?? Selection.findFrom($after, -1, true) ?? Selection.near($after, -1);
    }

    return Selection.near(docNode.resolve(Math.min(pos + node.nodeSize - 1, docNode.content.size)), -1);
}

/**
 * Hand the keyboard to the paper after Put it back, but only when the press
 * came from the keyboard. A tap on a phone must leave the on-screen keyboard
 * down and the page where it is: `focus()` brings the keyboard up and
 * scrolls. The block is in and the caret is placed either way (the
 * transaction set the selection); this is only about focus.
 *
 * @param {{focus: () => unknown}} commands the editor's commands
 * @param {boolean} focus true for a press from the keyboard
 * @returns {boolean} whether focus was asked for
 */
export function focusAfterInsert(commands, focus) {
    if (focus !== true) {
        return false;
    }
    try {
        commands.focus();
    } catch (_) {
        // The block is in and the caret is set; focus is a nicety.
    }

    return true;
}
