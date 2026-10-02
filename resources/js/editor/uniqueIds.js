/**
 * No two blocks in the editor carry the same id.
 *
 * Comments and cross-references point at block ids, and the server refuses a
 * document in which two blocks share one (DocumentSchema: "Duplicate block
 * id"), so a tab that holds such a document can never save again. TipTap's
 * UniqueID (extensions/blockId.js) gives new blocks their ids, but it only
 * compares ids among the blocks inside the range a transaction changed. That
 * misses a block which takes on the id of a block somewhere else: an undo
 * that takes back the id UniqueID gave the second half of a split, after
 * somebody else's change has made the split itself impossible to undo, leaves
 * that half with the id the first half still carries.
 *
 * This is the decision (which block keeps a shared id, which gets a new one)
 * and the transaction that carries it out. The plugin that runs it after
 * every transaction is in extensions/blockId.js. Like guards.js it is
 * dependency-free, so `tests/js/uniqueIds.test.js` runs it under
 * `node --test`: the block types and the id generator are handed in, and
 * everything else is a method on the document, the transactions and the
 * state it is given.
 */

/** How many ids a generator is asked for before a block is left as it is. */
const FRESH_ID_ATTEMPTS = 8;

/**
 * Every block id in the document, and the ids more than one block carries.
 * ONE pass over the block nodes; the text inside them is not visited.
 *
 * @param {import('prosemirror-model').Node} doc
 * @param {Iterable<string>} types the node types that carry an id
 * @returns {{taken: Set<unknown>, shared: Map<unknown, number[]>}} `shared`
 *          maps an id to the positions of the blocks carrying it, in
 *          document order
 */
function scan(doc, types) {
    const carriesId = new Set(types);
    const firstAt = new Map();
    const shared = new Map();

    doc.descendants((node, pos) => {
        if (carriesId.has(node.type.name)) {
            const id = node.attrs.id;

            // A block with no id yet is UniqueID's to fill in, not a duplicate.
            if (id !== null && id !== undefined) {
                if (!firstAt.has(id)) {
                    firstAt.set(id, pos);
                } else if (shared.has(id)) {
                    shared.get(id).push(pos);
                } else {
                    shared.set(id, [firstAt.get(id), pos]);
                }
            }
        }

        // Inline content holds no blocks.
        return !node.inlineContent;
    });

    return { taken: new Set(firstAt.keys()), shared };
}

/**
 * Of the blocks that share an id, the ones that must get a new one.
 *
 * The block the transaction did NOT touch keeps the id: it is the one the
 * server's copy of the document, and every comment and cross-reference,
 * knows by it. A block counts as touched when it STARTS inside a changed
 * range, which is true of a block that was inserted, of the new half of a
 * split and of a block whose attributes were set, and not of a block that
 * was only typed in or that merely contains or follows the change. When
 * every block sharing the id was touched, or none was, the first in
 * document order keeps it.
 *
 * @param {Map<unknown, number[]>} shared
 * @param {{from: number, to: number}[]} ranges
 * @returns {number[]} positions, in document order
 */
function repairsFor(shared, ranges) {
    const touched = (pos) => ranges.some(({ from, to }) => from < pos + 1 && to > pos);
    const repairs = [];

    shared.forEach((positions) => {
        const keeper = positions.find((pos) => !touched(pos)) ?? positions[0];

        positions.forEach((pos) => {
            if (pos !== keeper) {
                repairs.push(pos);
            }
        });
    });

    return repairs.sort((a, b) => a - b);
}

/**
 * The blocks that must get a new id so that no two blocks share one.
 *
 * @param {import('prosemirror-model').Node} doc
 * @param {{from: number, to: number}[]} ranges what the transactions
 *        changed, as positions in `doc` (see changedRanges())
 * @param {Iterable<string>} types the node types that carry an id
 * @returns {number[]} where those blocks start, in document order. Empty
 *          when every id is carried by one block only.
 */
export function duplicateIds(doc, ranges, types) {
    return repairsFor(scan(doc, types).shared, ranges);
}

/**
 * What a batch of transactions changed, as ranges in the document the LAST
 * of them left behind.
 *
 * Every step's own ranges are mapped forward through the rest of its
 * transaction and through every transaction after it. A step that sets an
 * attribute moves nothing, so its map is empty: the node it changed, at
 * `step.pos`, is its range.
 *
 * @param {readonly import('prosemirror-state').Transaction[]} transactions
 *        applied one after the other
 * @returns {{from: number, to: number}[]}
 */
export function changedRanges(transactions) {
    const ranges = [];

    transactions.forEach((transaction, index) => {
        if (!transaction.docChanged) {
            return;
        }

        transaction.steps.forEach((step, n) => {
            const rest = transaction.mapping.slice(n + 1);
            const forward = (pos, side) => {
                let mapped = rest.map(pos, side);
                for (let later = index + 1; later < transactions.length; later += 1) {
                    mapped = transactions[later].mapping.map(mapped, side);
                }

                return mapped;
            };

            let moved = false;
            transaction.mapping.maps[n].forEach((_oldStart, _oldEnd, newStart, newEnd) => {
                moved = true;
                ranges.push({ from: forward(newStart, -1), to: forward(newEnd, 1) });
            });

            if (!moved && typeof step.pos === 'number') {
                // Side 1: content put in at exactly this position lands in
                // front of the node, and the node is what was changed.
                const at = forward(step.pos, 1);
                ranges.push({ from: at, to: at + 1 });
            }
        });
    });

    return ranges;
}

/**
 * The transaction that gives every block sharing an id with another block a
 * new one, or null when there is nothing to do. This is the whole of the
 * repair plugin's `appendTransaction`.
 *
 * - Nothing is looked at unless a transaction in the batch changed the
 *   document, and then the document's blocks are walked once. The changed
 *   ranges are worked out only when two blocks do share an id.
 * - The new id is set with an attribute step, which moves no position.
 * - It is tagged `addToHistory: false`: the repair is not something the
 *   writer did, and must not become an undo step of its own. It is NOT tagged
 *   `preventUpdate`: the document changed, so the autosave has to hear of it.
 * - It ends. The document it leaves has no shared id, so the next round
 *   (ProseMirror asks every plugin again after one of them appends) returns
 *   null. A new id is never one the document already uses; a generator that
 *   cannot supply an unused one leaves the block alone instead of being
 *   asked for ever.
 *
 * @param {readonly import('prosemirror-state').Transaction[]} transactions
 *        the batch a plugin's appendTransaction is given
 * @param {import('prosemirror-state').EditorState} state the state after them
 * @param {{types: Iterable<string>, generateId: () => string}} options
 * @returns {import('prosemirror-state').Transaction|null}
 */
export function uniqueIdsTransaction(transactions, state, { types, generateId }) {
    if (!transactions.some((transaction) => transaction.docChanged)) {
        return null;
    }

    const { taken, shared } = scan(state.doc, types);
    if (shared.size === 0) {
        return null;
    }

    const tr = state.tr;

    repairsFor(shared, changedRanges(transactions)).forEach((pos) => {
        for (let attempt = 0; attempt < FRESH_ID_ATTEMPTS; attempt += 1) {
            const id = generateId();

            if (!taken.has(id)) {
                taken.add(id);
                tr.setNodeAttribute(pos, 'id', id);

                return;
            }
        }
    });

    if (!tr.docChanged) {
        return null;
    }

    // A step drops the marks switched on for the next character (bold
    // pressed with nothing selected). Put them back.
    tr.setStoredMarks(state.storedMarks);
    tr.setMeta('addToHistory', false);

    return tr;
}
