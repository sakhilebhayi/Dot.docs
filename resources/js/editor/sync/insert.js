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
 * typing in it.
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
    // The end of the block's content; after the block for an atom.
    const end = Math.min(node.isAtom || node.isLeaf ? pos + node.nodeSize : pos + node.nodeSize - 1, tr.doc.content.size);

    return tr.setSelection(Selection.near(tr.doc.resolve(end), -1)).scrollIntoView();
}
