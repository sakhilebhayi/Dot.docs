import assert from 'node:assert/strict';
import test from 'node:test';

import { history, redo, undo, undoDepth } from 'prosemirror-history';
import { Fragment, Schema, Slice } from 'prosemirror-model';
import { EditorState, Plugin } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { BLOCK_TYPES, blockIdRepairPlugin } from '../../resources/js/editor/extensions/blockId.js';
import { remoteTransaction } from '../../resources/js/editor/sync/apply.js';
import { changedRanges, duplicateIds, uniqueIdsTransaction } from '../../resources/js/editor/uniqueIds.js';

/**
 * Block ids stay unique whatever a transaction did.
 *
 * Two things are under test. `uniqueIds.js` is the decision (which block
 * keeps an id two blocks share, and which gets a new one) and the transaction
 * that carries it; it imports nothing. `blockIdRepairPlugin()` is the
 * ProseMirror plugin the editor registers (extensions/blockId.js), run here
 * in a real EditorState next to prosemirror-history, with the real block
 * types and the real id generator.
 *
 * The schema is a stand-in for the editor's, with the editor's own node
 * names, so the real BLOCK_TYPES list applies to it: top-level paragraphs, a
 * list (blocks nested in blocks) and a table (isolating cells).
 */
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        bulletList: { group: 'block', content: 'listItem+', attrs: { id: { default: null } } },
        listItem: { content: 'paragraph block*', attrs: { id: { default: null } } },
        table: { group: 'block', content: 'tableRow+', isolating: true, attrs: { id: { default: null } } },
        tableRow: { content: 'tableCell+', attrs: { id: { default: null } } },
        tableCell: { content: 'block+', isolating: true, attrs: { id: { default: null } } },
        text: { group: 'inline' },
    },
    marks: { bold: {} },
});

/** What the server accepts as a block id (DocumentSchema, BlockId). */
const VALID_ID = /^[0-9A-Za-z]{8}$/;

const para = (id, text) => ({
    type: 'paragraph',
    attrs: { id },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
const list = (id, ...items) => ({ type: 'bulletList', attrs: { id }, content: items });
const item = (id, ...content) => ({ type: 'listItem', attrs: { id }, content });
const table = (id, ...rows) => ({ type: 'table', attrs: { id }, content: rows });
const row = (id, ...cells) => ({ type: 'tableRow', attrs: { id }, content: cells });
const cell = (id, ...content) => ({ type: 'tableCell', attrs: { id }, content });
const doc = (...content) => ({ type: 'doc', content });
const node = (json) => schema.nodeFromJSON(json);

/** Every block, in document order, as [id, position]. */
const blocksOf = (docNode) => {
    const blocks = [];
    docNode.descendants((child, pos) => {
        if (BLOCK_TYPES.includes(child.type.name)) {
            blocks.push([child.attrs.id, pos]);
        }
    });

    return blocks;
};
const idsOf = (docNode) => blocksOf(docNode).map(([id]) => id);
/** Where the first block with this id starts. */
const posOf = (docNode, id) => blocksOf(docNode).find(([candidate]) => candidate === id)[1];
const allDifferent = (ids) => new Set(ids).size === ids.length;
const texts = (docNode) => {
    const out = [];
    docNode.forEach((child) => out.push(child.textContent));

    return out;
};

/** An editor with the plugin the bundle registers, and an undo history. */
const editorOf = (json, plugins = [blockIdRepairPlugin(), history()]) =>
    EditorState.create({ doc: node(json), plugins });

/** The two ways a transaction can set an id: UniqueID's (a step that has a range) and an attribute step (which has none). */
const SET_ID = {
    setNodeMarkup: (tr, pos, id) => tr.setNodeMarkup(pos, undefined, { ...tr.doc.nodeAt(pos).attrs, id }),
    setNodeAttribute: (tr, pos, id) => tr.setNodeAttribute(pos, 'id', id),
};

// ───────────────────────────────────────────────────────── the sequence that was observed

const ALPHA_PARAGRAPH = '8S2WiF8i';
const ALPHA_SPLIT = 'qcDWHF1c';
const BETA_PARAGRAPH = 'SSUk7s5E';

/**
 * A stand-in for the part of TipTap's UniqueID this sequence goes through
 * (the real plugin needs an Editor). After a transaction it looks ONLY at the
 * blocks inside the range that changed: a block there whose id another block
 * there already carries gets a new one, with setNodeMarkup, in an appended
 * transaction. It never compares with a block outside that range, which is
 * why it does not see the duplicate the undo below leaves behind.
 */
const uniqueIdStandIn = (newIds) =>
    new Plugin({
        appendTransaction(transactions, _oldState, newState) {
            const tr = newState.tr;

            transactions.forEach((transaction, index) => {
                transaction.mapping.maps.forEach((map, step) => {
                    map.forEach((_oldStart, _oldEnd, newStart, newEnd) => {
                        const rest = transaction.mapping.slice(step + 1);
                        let from = rest.map(newStart, -1);
                        let to = rest.map(newEnd, 1);
                        transactions.slice(index + 1).forEach((later) => {
                            from = later.mapping.map(from, -1);
                            to = later.mapping.map(to, 1);
                        });

                        const inRange = new Set();
                        newState.doc.nodesBetween(from, to, (child, pos) => {
                            const id = child.attrs.id;
                            if (id === null || id === undefined) {
                                return;
                            }
                            if (inRange.has(id) && tr.doc.nodeAt(pos).attrs.id === id) {
                                tr.setNodeMarkup(pos, undefined, { ...child.attrs, id: newIds.shift() });
                            }
                            inRange.add(id);
                        });
                    });
                });
            });

            return tr.docChanged ? tr : null;
        },
    });

/**
 * Beta's editor, taken through what was done in the two browsers:
 *
 *   1. The document is one paragraph, and Alpha and Beta both have it open.
 *   2. Beta presses Enter at the end of it and types a word. Enter copies the
 *      paragraph's id to both halves; UniqueID gives the new half its own in
 *      an appended transaction, which is in Beta's undo history.
 *   3. Alpha types a line at the START of the paragraph and presses Enter,
 *      and saves.
 *   4. Beta follows: the change is applied the way applyRemote() applies it,
 *      as the one range that differs, outside the undo history.
 *   5. Beta presses undo.
 *
 * @param {Plugin[]} repair the plugins under test, placed after UniqueID as the editor places them
 * @returns {{state: EditorState, apply: (tr: object) => void}} `apply` keeps going from where the sequence stopped
 */
function betaAfterTheUndo(repair) {
    let state = EditorState.create({
        doc: node(doc(para(ALPHA_PARAGRAPH, 'The first sentence from Alpha.'))),
        plugins: [uniqueIdStandIn([BETA_PARAGRAPH]), ...repair, history()],
    });
    const world = {
        get state() {
            return state;
        },
        apply: (tr) => {
            state = state.apply(tr);
        },
    };

    // 2. Enter at the end of the paragraph, then the word. The times put
    //    both in one undo step, as typing straight after Enter does.
    world.apply(state.tr.split(state.doc.firstChild.nodeSize - 1).setTime(1000));
    world.apply(state.tr.insertText('BetaWord', state.doc.content.size - 1).setTime(1100));
    assert.deepEqual(idsOf(state.doc), [ALPHA_PARAGRAPH, BETA_PARAGRAPH]);

    // 3 and 4. Alpha's saved document arrives.
    const alphas = doc(
        para(ALPHA_PARAGRAPH, 'Top line by Alpha.'),
        para(ALPHA_SPLIT, 'The first sentence from Alpha.'),
        para(BETA_PARAGRAPH, 'BetaWord')
    );
    world.apply(remoteTransaction(state, alphas, ReplaceStep).setMeta('addToHistory', false).setMeta('preventUpdate', true));
    assert.ok(state.doc.eq(node(alphas)), 'Beta now shows what Alpha saved');

    // 5. Undo.
    assert.equal(undo(state, world.apply), true);

    return world;
}

test('the defect: without the repair, an undo after somebody else split the paragraph leaves two blocks with one id', () => {
    const { state } = betaAfterTheUndo([]);

    // The word is gone and the id UniqueID assigned is taken back. The split
    // itself is not undone: its position was inside the range Alpha's change
    // replaced. So the emptied paragraph gets the id it had when the split
    // made it, which the first paragraph still carries.
    assert.deepEqual(texts(state.doc), ['Top line by Alpha.', 'The first sentence from Alpha.', '']);
    assert.deepEqual(idsOf(state.doc), [ALPHA_PARAGRAPH, ALPHA_SPLIT, ALPHA_PARAGRAPH]);
});

test('with the repair, that undo leaves three blocks with three ids, and the block it did not touch keeps its own', () => {
    const world = betaAfterTheUndo([blockIdRepairPlugin()]);
    const ids = idsOf(world.state.doc);

    assert.deepEqual(texts(world.state.doc), ['Top line by Alpha.', 'The first sentence from Alpha.', '']);
    assert.equal(ids.length, 3);
    assert.ok(allDifferent(ids), `three different ids, got ${ids.join(', ')}`);
    assert.equal(ids[0], ALPHA_PARAGRAPH, 'the paragraph the undo did not touch still owns its id');
    assert.equal(ids[1], ALPHA_SPLIT);
    assert.match(ids[2], VALID_ID, 'the paragraph the undo touched has a new id the server accepts');

    // A redo and another undo are not thrown off by the repair.
    assert.equal(redo(world.state, world.apply), true);
    assert.deepEqual(texts(world.state.doc), ['Top line by Alpha.', 'The first sentence from Alpha.', 'BetaWord']);
    assert.deepEqual(idsOf(world.state.doc), [ALPHA_PARAGRAPH, ALPHA_SPLIT, BETA_PARAGRAPH]);

    assert.equal(undo(world.state, world.apply), true);
    const again = idsOf(world.state.doc);
    assert.deepEqual(texts(world.state.doc), ['Top line by Alpha.', 'The first sentence from Alpha.', '']);
    assert.ok(allDifferent(again), `three different ids, got ${again.join(', ')}`);
    assert.equal(again[0], ALPHA_PARAGRAPH);
    assert.match(again[2], VALID_ID);
});

// ───────────────────────────────────────────────────────── which block keeps the id

for (const [how, setId] of Object.entries(SET_ID)) {
    test(`a transaction that sets one block id to another's (${how}): the changed block gets a new id, the other keeps its own`, () => {
        const state = editorOf(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two'), para('ccccccc3', 'Three')));

        // The LAST block takes the first one's id.
        const last = state.apply(setId(state.tr, posOf(state.doc, 'ccccccc3'), 'aaaaaaa1'));
        const afterLast = idsOf(last.doc);
        assert.equal(afterLast[0], 'aaaaaaa1');
        assert.equal(afterLast[1], 'bbbbbbb2');
        assert.match(afterLast[2], VALID_ID);
        assert.ok(allDifferent(afterLast));

        // The FIRST block takes the last one's id. It is first in document
        // order, and it is still the one that changes: the transaction
        // touched it, and the last block is the one that owned the id.
        const first = state.apply(setId(state.tr, posOf(state.doc, 'aaaaaaa1'), 'ccccccc3'));
        const afterFirst = idsOf(first.doc);
        assert.match(afterFirst[0], VALID_ID);
        assert.notEqual(afterFirst[0], 'ccccccc3');
        assert.equal(afterFirst[1], 'bbbbbbb2');
        assert.equal(afterFirst[2], 'ccccccc3');
        assert.ok(allDifferent(afterFirst));
    });
}

test('pasted blocks that carry an existing block id: the existing block keeps it, wherever the paste lands', () => {
    const state = editorOf(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two')));
    const pasted = () => new Slice(Fragment.from([node(para('aaaaaaa1', 'Copy')), node(para('aaaaaaa1', 'Copy again'))]), 0, 0);

    // Directly BEFORE the block that owns the id: the copies come first in
    // document order, and still the existing block keeps it.
    const before = state.apply(state.tr.replace(0, 0, pasted()));
    const idsBefore = idsOf(before.doc);
    assert.deepEqual(texts(before.doc), ['Copy', 'Copy again', 'One', 'Two']);
    assert.equal(idsBefore[2], 'aaaaaaa1');
    assert.match(idsBefore[0], VALID_ID);
    assert.match(idsBefore[1], VALID_ID);
    assert.ok(allDifferent(idsBefore), `four different ids, got ${idsBefore.join(', ')}`);

    // Directly after it.
    const at = state.doc.firstChild.nodeSize;
    const after = state.apply(state.tr.replace(at, at, pasted()));
    const idsAfter = idsOf(after.doc);
    assert.deepEqual(texts(after.doc), ['One', 'Copy', 'Copy again', 'Two']);
    assert.equal(idsAfter[0], 'aaaaaaa1');
    assert.match(idsAfter[1], VALID_ID);
    assert.match(idsAfter[2], VALID_ID);
    assert.ok(allDifferent(idsAfter), `four different ids, got ${idsAfter.join(', ')}`);
});

test('a block split twice in one transaction: the first part keeps the id, both new parts get their own', () => {
    const state = editorOf(doc(para('aaaaaaa1', 'OneTwoThree'), para('bbbbbbb2', 'Four')));

    // "One|Two|Three". Each split copies the block's attributes to the new half.
    const next = state.apply(state.tr.split(4).split(9));
    const ids = idsOf(next.doc);

    assert.deepEqual(texts(next.doc), ['One', 'Two', 'Three', 'Four']);
    assert.equal(ids[0], 'aaaaaaa1');
    assert.match(ids[1], VALID_ID);
    assert.match(ids[2], VALID_ID);
    assert.equal(ids[3], 'bbbbbbb2');
    assert.ok(allDifferent(ids), `four different ids, got ${ids.join(', ')}`);
});

test('both blocks that share an id are inside the changed range: the first in document order keeps it', () => {
    const state = editorOf(doc(para('aaaaaaa1', 'One')));

    const next = state.apply(
        state.tr.replaceWith(0, state.doc.content.size, [node(para('xxxxxxx9', 'First')), node(para('xxxxxxx9', 'Second'))])
    );
    const ids = idsOf(next.doc);

    assert.deepEqual(texts(next.doc), ['First', 'Second']);
    assert.equal(ids[0], 'xxxxxxx9');
    assert.match(ids[1], VALID_ID);
    assert.notEqual(ids[1], 'xxxxxxx9');
});

for (const [how, setId] of Object.entries(SET_ID)) {
    test(`nested blocks are covered (${how}): list items, their paragraphs, table cells`, () => {
        const state = editorOf(
            doc(
                list('listlist', item('itemone1', para('paraone1', 'one')), item('itemtwo2', para('paratwo2', 'two'))),
                table('tabletab', row('rowrowro', cell('cellone1', para('parainc1', 'c1')), cell('celltwo2', para('parainc2', 'c2'))))
            )
        );
        const before = idsOf(state.doc);
        const at = (id) => before.indexOf(id);

        // A table cell takes a list item's id.
        const cellAsItem = idsOf(state.apply(setId(state.tr, posOf(state.doc, 'celltwo2'), 'itemone1')).doc);
        assert.equal(cellAsItem[at('itemone1')], 'itemone1');
        assert.match(cellAsItem[at('celltwo2')], VALID_ID);
        assert.ok(allDifferent(cellAsItem));

        // A paragraph inside a list item takes the id of a paragraph inside a cell.
        const nestedParagraph = idsOf(state.apply(setId(state.tr, posOf(state.doc, 'paraone1'), 'parainc2')).doc);
        assert.equal(nestedParagraph[at('parainc2')], 'parainc2');
        assert.match(nestedParagraph[at('paraone1')], VALID_ID);
        assert.ok(allDifferent(nestedParagraph));

        // A list item takes the id of ITS OWN paragraph. The paragraph sits
        // inside the item the transaction changed, and was not itself touched.
        const itemAsOwnChild = idsOf(state.apply(setId(state.tr, posOf(state.doc, 'itemtwo2'), 'paratwo2')).doc);
        assert.equal(itemAsOwnChild[at('paratwo2')], 'paratwo2');
        assert.match(itemAsOwnChild[at('itemtwo2')], VALID_ID);
        assert.ok(allDifferent(itemAsOwnChild));

        // Everything else is as it was.
        assert.deepEqual(
            itemAsOwnChild.filter((_, index) => index !== at('itemtwo2')),
            before.filter((_, index) => index !== at('itemtwo2'))
        );
    });
}

// ───────────────────────────────────────────────────────── the pure decision

test('the decision, given three blocks that share one id', () => {
    const three = node(doc(para('samesame', 'One'), para('samesame', 'Two'), para('samesame', 'Three'), para('otherone', 'Four')));
    const [first, second, third] = blocksOf(three).map(([, pos]) => pos);
    const whole = (pos) => ({ from: pos, to: pos + three.nodeAt(pos).nodeSize });

    // Nothing changed, or nothing that reaches them: the first keeps it.
    assert.deepEqual(duplicateIds(three, [], BLOCK_TYPES), [second, third]);

    // The first was changed: the first UNTOUCHED block keeps it.
    assert.deepEqual(duplicateIds(three, [whole(first)], BLOCK_TYPES), [first, third]);

    // The first two were changed: the third keeps it.
    assert.deepEqual(duplicateIds(three, [whole(first), whole(second)], BLOCK_TYPES), [first, second]);

    // All three were changed: back to document order.
    assert.deepEqual(duplicateIds(three, [{ from: 0, to: three.content.size }], BLOCK_TYPES), [second, third]);
});

test('the decision looks at where a block STARTS: typing inside it, or a change that ends where it begins, does not touch it', () => {
    const two = node(doc(para('samesame', 'One'), para('samesame', 'Two')));
    const [first, second] = blocksOf(two).map(([, pos]) => pos);

    // Inside the first block's text.
    assert.deepEqual(duplicateIds(two, [{ from: first + 2, to: first + 3 }], BLOCK_TYPES), [second]);
    // A range that ends exactly where the second block begins.
    assert.deepEqual(duplicateIds(two, [{ from: first + 2, to: second }], BLOCK_TYPES), [second]);
    // A range that begins exactly where the first block's opening ends.
    assert.deepEqual(duplicateIds(two, [{ from: first + 1, to: first + 3 }], BLOCK_TYPES), [second]);
    // A range over the first block's opening.
    assert.deepEqual(duplicateIds(two, [{ from: first, to: first + 1 }], BLOCK_TYPES), [first]);
});

test('the decision leaves alone blocks with no id, types that carry none, and a document with nothing shared', () => {
    // No id yet is UniqueID's to fill in, not a duplicate.
    assert.deepEqual(duplicateIds(node(doc(para(null, 'One'), para(null, 'Two'))), [], BLOCK_TYPES), []);

    // Only the types it is given.
    const shared = node(doc(para('samesame', 'One'), list('samesame', item('itemone1', para('paraone1', 'x')))));
    assert.deepEqual(duplicateIds(shared, [], ['bulletList']), []);
    assert.equal(duplicateIds(shared, [], BLOCK_TYPES).length, 1);

    assert.deepEqual(duplicateIds(node(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two'))), [], BLOCK_TYPES), []);
});

test('the changed ranges are positions in the document as the whole batch left it', () => {
    const state = EditorState.create({ doc: node(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two'), para('ccccccc3', 'Three'))) });

    // An attribute step moves nothing, so its own map is empty: the block it
    // changed is the range. Then a second transaction puts a paragraph in
    // front of that block, at the very position the block started at.
    const setsId = state.tr.setNodeAttribute(0, 'id', 'ccccccc3');
    const middle = state.apply(setsId);
    const inserts = middle.tr.insert(0, node(para('nnnnnnn0', 'New')));
    const final = middle.apply(inserts);
    const moved = posOf(final.doc, 'ccccccc3');

    assert.deepEqual(changedRanges([setsId, inserts]), [
        { from: moved, to: moved + 1 },
        { from: 0, to: moved },
    ]);

    // So the block that was changed is the one that gets the new id, not the
    // block that owned it.
    const tr = uniqueIdsTransaction([setsId, inserts], final, { types: BLOCK_TYPES, generateId: () => 'freshid1' });
    assert.deepEqual(idsOf(tr.doc), ['nnnnnnn0', 'freshid1', 'bbbbbbb2', 'ccccccc3']);
});

// ───────────────────────────────────────────────────────── the transaction

test('a document whose ids are all different gets no transaction', () => {
    const plugin = blockIdRepairPlugin();
    const state = editorOf(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two')), [plugin, history()]);
    const typed = state.tr.insertText('!', 4);
    const { state: next, transactions } = state.applyTransaction(typed);

    assert.equal(transactions.length, 1, 'nothing was appended');
    assert.equal(plugin.spec.appendTransaction([typed], state, next) ?? null, null);
    assert.deepEqual(idsOf(next.doc), ['aaaaaaa1', 'bbbbbbb2']);
});

test('when no transaction in the batch changed the document, the document is not walked at all', () => {
    const state = editorOf(doc(para('samesame', 'One'), para('samesame', 'Two')));
    const selectionOnly = state.tr.setMeta('something', true);
    const unwalkable = {
        get doc() {
            throw new Error('the document was read');
        },
    };

    assert.equal(uniqueIdsTransaction([selectionOnly], unwalkable, { types: BLOCK_TYPES, generateId: () => 'freshid1' }), null);
});

test('the repair is not an undo step, does not carry preventUpdate, and ends: the next round appends nothing', () => {
    const plugin = blockIdRepairPlugin();
    const state = editorOf(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two'), para('ccccccc3', 'Three')), [plugin, history()]);

    const root = state.tr.setNodeAttribute(posOf(state.doc, 'ccccccc3'), 'id', 'aaaaaaa1');
    const { state: next, transactions } = state.applyTransaction(root);

    assert.equal(transactions.length, 2, 'the transaction and one repair');
    const repair = transactions[1];
    assert.equal(repair.docChanged, true);
    assert.equal(repair.getMeta('addToHistory'), false);
    assert.equal(repair.getMeta('preventUpdate'), undefined, 'the document changed, so the autosave must hear of it');
    assert.ok(allDifferent(idsOf(next.doc)));

    // Asked again about its own transaction, it has nothing to add.
    assert.equal(plugin.spec.appendTransaction([repair], state, next) ?? null, null);

    // One undo step was recorded, the writer's own, and undoing it puts the
    // document back exactly: the repair is not a step of its own.
    assert.equal(undoDepth(next), 1);
    let undone = next;
    undo(next, (tr) => {
        undone = next.apply(tr);
    });
    assert.equal(undoDepth(undone), 0);
    assert.deepEqual(idsOf(undone.doc), ['aaaaaaa1', 'bbbbbbb2', 'ccccccc3']);
});

test('a transaction that carries preventUpdate (a remote apply) is repaired like any other', () => {
    const state = editorOf(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two')));

    const remote = state.tr
        .insert(state.doc.content.size, node(para('aaaaaaa1', 'Arrived')))
        .setMeta('addToHistory', false)
        .setMeta('preventUpdate', true);
    const { state: next, transactions } = state.applyTransaction(remote);
    const ids = idsOf(next.doc);

    assert.equal(transactions.length, 2);
    assert.equal(ids[0], 'aaaaaaa1');
    assert.match(ids[2], VALID_ID);
    assert.ok(allDifferent(ids));
    assert.equal(undoDepth(next), 0, 'nothing here is the writer own to undo');
});

test('the repair keeps the marks switched on for the next character', () => {
    const state = editorOf(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two')));
    const bold = schema.marks.bold.create();

    const next = state.apply(state.tr.setNodeAttribute(posOf(state.doc, 'bbbbbbb2'), 'id', 'aaaaaaa1').setStoredMarks([bold]));

    assert.ok(allDifferent(idsOf(next.doc)));
    assert.deepEqual(
        (next.storedMarks ?? []).map((mark) => mark.type.name),
        ['bold']
    );
});

test('a new id is never one the document already uses, and a generator that cannot give one ends the repair', () => {
    const state = EditorState.create({ doc: node(doc(para('aaaaaaa1', 'One'), para('bbbbbbb2', 'Two'), para('aaaaaaa1', 'Three'))) });
    const changed = state.tr.insertText('!', 2);
    const after = state.apply(changed);

    // The first two ids it offers are taken.
    const offered = ['bbbbbbb2', 'aaaaaaa1', 'freshid1'];
    const tr = uniqueIdsTransaction([changed], after, { types: BLOCK_TYPES, generateId: () => offered.shift() });
    assert.deepEqual(idsOf(tr.doc), ['aaaaaaa1', 'bbbbbbb2', 'freshid1']);

    // Two blocks to repair never get the same new id either.
    const triple = EditorState.create({ doc: node(doc(para('aaaaaaa1', 'One'), para('aaaaaaa1', 'Two'), para('aaaaaaa1', 'Three'))) });
    const typed = triple.tr.insertText('!', 2);
    const twice = ['freshid1', 'freshid1', 'freshid2'];
    const both = uniqueIdsTransaction([typed], triple.apply(typed), { types: BLOCK_TYPES, generateId: () => twice.shift() });
    assert.deepEqual(idsOf(both.doc), ['aaaaaaa1', 'freshid1', 'freshid2']);

    // A generator that only ever repeats a taken id: nothing is appended,
    // rather than appending for ever.
    let asked = 0;
    const stuck = uniqueIdsTransaction([changed], after, {
        types: BLOCK_TYPES,
        generateId: () => {
            asked += 1;

            return 'bbbbbbb2';
        },
    });
    assert.equal(stuck, null);
    assert.ok(asked > 0 && asked < 100);
});
