import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

import { Schema } from 'prosemirror-model';

import {
    DERIVED,
    appendLost,
    blockWord,
    excerpt,
    isEmptyBlock,
    levelDerived,
    levelEmpty,
    lostEntry,
    mergeDocuments,
} from '../../resources/js/editor/sync/merge.js';

/**
 * The paragraph-level merge (sync/merge.js), row by row of the spec's
 * decision table (section 4.3) and rule by rule of its levelling (4.1),
 * echo (4.2), trailing paragraph (4.4), order (4.5) and attributes (4.6).
 *
 * A stand-in for the editor's schema: the node types the merge treats
 * specially, declared the way the editor declares them. A contents list and
 * a cross-reference the server stamps, a section break whose `setup` PHP
 * may send as `[]`, an `isolating` container (like table cells, figures and
 * columns), a variable chip (an inline atom with no text), the
 * document-level attrs.
 */
const schema = new Schema({
    nodes: {
        doc: { content: 'block+', attrs: { schema: { default: 1 }, style: { default: 'report' }, vars: { default: {} } } },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        heading: { group: 'block', content: 'inline*', attrs: { id: { default: null }, level: { default: 1 } } },
        toc: { group: 'block', atom: true, attrs: { id: { default: null }, entries: { default: [] } } },
        sectionBreak: { group: 'block', atom: true, attrs: { id: { default: null }, setup: { default: {} } } },
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        crossRef: {
            group: 'inline',
            inline: true,
            atom: true,
            attrs: { kind: { default: 'heading' }, targetId: { default: null }, label: { default: null } },
        },
        variable: { group: 'inline', inline: true, atom: true, attrs: { key: { default: '' } } },
        text: { group: 'inline' },
    },
});

/** A paragraph. Strings become text; anything else is an inline node. */
const para = (id, ...inline) => ({
    type: 'paragraph',
    attrs: { id },
    ...(inline.length
        ? { content: inline.map((part) => (typeof part === 'string' ? { type: 'text', text: part } : part)) }
        : {}),
});
const heading = (id, text, level = 1) => ({ type: 'heading', attrs: { id, level }, content: [{ type: 'text', text }] });
const toc = (id, entries = []) => ({ type: 'toc', attrs: { id, entries } });
const sectionBreak = (id, setup = {}) => ({ type: 'sectionBreak', attrs: { id, setup } });
const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
const ref = (targetId, label = null) => ({ type: 'crossRef', attrs: { kind: 'heading', targetId, label } });
const variable = (key) => ({ type: 'variable', attrs: { key } });
const docOf = (blocks, attrs) => ({ type: 'doc', ...(attrs ? { attrs } : {}), content: blocks });

const merge = (base, mine, theirs, extra = {}, options = undefined) =>
    mergeDocuments(schema, { base, mine, theirs, ...extra }, options);
const textOf = (block) => (block.content ?? []).map((node) => node.text ?? '').join('');
/** Every top-level block as [id, text]. */
const blocksOf = (json) => json.content.map((block) => [block.attrs.id, textOf(block)]);
const idsOf = (json) => json.content.map((block) => block.attrs.id);
const losses = (result) => result.lost.map(({ id, reason, anchors }) => ({ id, reason, anchors }));

// ───────────────────────────────────────────────────────── the decision table (4.3)

test('a3 and a2: changes in different paragraphs are both kept, mine counts as changed, nothing is lost', () => {
    const result = merge(
        docOf([para('p1', 'Hello'), para('p2', 'World')]),
        docOf([para('p1', 'Hello B'), para('p2', 'World')]),
        docOf([para('p1', 'Hello'), para('p2', 'World A')])
    );

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'Hello B'],
        ['p2', 'World A'],
    ]);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('a1 and a4: an unchanged block and the same change on both sides are theirs, and nothing counts as changed', () => {
    const base = docOf([para('p1', 'Hello'), para('p2', 'World')]);
    const same = docOf([para('p1', 'Hello'), para('p2', 'World!')]);

    const result = merge(base, same, same);

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'Hello'],
        ['p2', 'World!'],
    ]);
    assert.equal(result.changed, false);
    assert.deepEqual(result.lost, []);
});

test('a5 (D1): theirs stays, mine is offered back anchored to the paragraph, and nothing counts as changed', () => {
    const result = merge(docOf([para('p1', 'Hello')]), docOf([para('p1', 'Hello B')]), docOf([para('p1', 'Hello A')]));

    assert.deepEqual(blocksOf(result.merged), [['p1', 'Hello A']]);
    assert.equal(result.changed, false);
    assert.deepEqual(losses(result), [{ id: 'p1', reason: 'both', anchors: ['p1'] }]);
    assert.equal(result.lost[0].type, 'paragraph');
    assert.equal(result.lost[0].text, 'Hello B');
    assert.deepEqual(result.lost[0].json, {
        type: 'paragraph',
        attrs: { id: 'p1', align: null },
        content: [{ type: 'text', text: 'Hello B' }],
    });
});

test('b2 (D2): a paragraph they deleted while I typed in it is offered back with its preceding ids, nearest first', () => {
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y'), para('p3', 'z')]),
        docOf([para('p1', 'x'), para('p2', 'y B'), para('p3', 'z')]),
        docOf([para('p1', 'x'), para('p3', 'z')])
    );

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'x'],
        ['p3', 'z'],
    ]);
    assert.equal(result.changed, false);
    assert.deepEqual(losses(result), [{ id: 'p2', reason: 'deleted', anchors: ['p1'] }]);
    assert.equal(result.lost[0].text, 'y B');
});

test('b2 remembers at most five preceding ids', () => {
    const ids = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7'];
    const base = docOf([...ids.map((id) => para(id, id)), para('p8', 'y')]);
    const mine = docOf([...ids.map((id) => para(id, id)), para('p8', 'y B')]);
    const theirs = docOf(ids.map((id) => para(id, id)));

    assert.deepEqual(losses(merge(base, mine, theirs)), [{ id: 'p8', reason: 'deleted', anchors: ['a7', 'a6', 'a5', 'a4', 'a3'] }]);
});

test('b2 drops an empty paragraph silently but offers a paragraph holding only a variable chip', () => {
    const base = docOf([para('p1', 'x'), para('p2', 'y'), para('p3', 'z')]);
    const theirs = docOf([para('p1', 'x')]);

    const emptied = merge(base, docOf([para('p1', 'x'), para('p2'), para('p3', 'z')]), theirs);
    assert.deepEqual(losses(emptied), []);
    assert.deepEqual(idsOf(emptied.merged), ['p1']);

    const chip = merge(base, docOf([para('p1', 'x'), para('p2', variable('client')), para('p3', 'z')]), theirs);
    assert.deepEqual(losses(chip), [{ id: 'p2', reason: 'deleted', anchors: ['p1'] }]);
    assert.equal(chip.lost[0].text, '[paragraph]');
});

test('b1, c1 and g: a deletion nobody else touched is honoured, nothing is lost, nothing overwrites', () => {
    // They deleted p2 (untouched by me); I deleted p3 (untouched by them); both deleted p4.
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y'), para('p3', 'z'), para('p4', 'w')]),
        docOf([para('p1', 'x'), para('p2', 'y')]),
        docOf([para('p1', 'x'), para('p3', 'z')])
    );

    assert.deepEqual(blocksOf(result.merged), [['p1', 'x']]);
    assert.equal(result.changed, true, 'my deletion of p3 has to be saved');
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('c2: my deletion of a paragraph they edited wins, and the save must carry overwrite', () => {
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y')]),
        docOf([para('p1', 'x')]),
        docOf([para('p1', 'x'), para('p2', 'y A')])
    );

    assert.deepEqual(blocksOf(result.merged), [['p1', 'x']]);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, true);
});

test('c2 with deletionsWin false keeps their edit, loses nothing and sets no flag', () => {
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y')]),
        docOf([para('p1', 'x')]),
        docOf([para('p1', 'x'), para('p2', 'y A')]),
        {},
        { deletionsWin: false }
    );

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'x'],
        ['p2', 'y A'],
    ]);
    assert.equal(result.changed, false);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('d: my new paragraph is kept and anchored after its nearest surviving predecessor', () => {
    const result = merge(
        docOf([para('p1', '1'), para('p2', '2'), para('p3', '3')]),
        docOf([para('p1', '1'), para('p2', '2'), para('n1', 'new'), para('p3', '3')]),
        docOf([para('p1', '1'), para('p3', '3')])
    );

    assert.deepEqual(idsOf(result.merged), ['p1', 'n1', 'p3']);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
});

test('d: chained insertions keep their order, a block with no predecessor goes first, and one whose predecessors are all gone goes last', () => {
    const chained = merge(
        docOf([para('p1', '1'), para('p2', '2')]),
        docOf([para('n0', 'top'), para('p1', '1'), para('n1', 'a'), para('n2', 'b'), para('p2', '2')]),
        docOf([para('p1', '1'), para('p2', '2 A')])
    );
    assert.deepEqual(idsOf(chained.merged), ['n0', 'p1', 'n1', 'n2', 'p2']);
    assert.equal(textOf(chained.merged.content[4]), '2 A');

    // Every predecessor of the typist's new paragraph was replaced by an
    // import: it lands at the end, not before the imported title.
    const orphan = merge(
        docOf([para('p1', 'old'), para('p2', 'old too')]),
        docOf([para('p1', 'old'), para('p2', 'old too'), para('n1', 'typed')]),
        docOf([heading('h1', 'Imported title'), para('q1', 'Imported body')])
    );
    assert.deepEqual(idsOf(orphan.merged), ['h1', 'q1', 'n1']);
    assert.equal(orphan.changed, true);
    assert.deepEqual(orphan.lost, []);
});

test('e and f: their new block is taken; a block both pasted is one copy when equal and a D1 when not', () => {
    const base = docOf([para('p1', '1')]);

    const taken = merge(base, docOf([para('p1', '1')]), docOf([para('p1', '1'), para('q1', 'theirs')]));
    assert.deepEqual(idsOf(taken.merged), ['p1', 'q1']);
    assert.equal(taken.changed, false);

    const equal = merge(base, docOf([para('p1', '1'), para('q7', 'pasted')]), docOf([para('p1', '1'), para('q7', 'pasted')]));
    assert.deepEqual(blocksOf(equal.merged), [
        ['p1', '1'],
        ['q7', 'pasted'],
    ]);
    assert.equal(equal.changed, false);
    assert.deepEqual(equal.lost, []);

    const differing = merge(base, docOf([para('p1', '1'), para('q7', 'mine')]), docOf([para('p1', '1'), para('q7', 'theirs')]));
    assert.deepEqual(blocksOf(differing.merged), [
        ['p1', '1'],
        ['q7', 'theirs'],
    ]);
    assert.deepEqual(losses(differing), [{ id: 'q7', reason: 'both', anchors: ['q7'] }]);
});

// ───────────────────────────────────────────────────────── levelling (4.1), trailing paragraph (4.4), attrs (4.6), echo (4.2), order (4.5)

test('levelling: stamps, [] setups, [] vars and align null count as unchanged, and the output keeps the stamps', () => {
    const base = docOf([toc('t1', []), para('p1', 'See ', ref('h1', null)), sectionBreak('s1', {}), para('p2', 'end')], { vars: {} });
    const mine = docOf(
        [
            toc('t1', []),
            { type: 'paragraph', attrs: { id: 'p1', align: null }, content: [{ type: 'text', text: 'See ' }, ref('h1', null)] },
            sectionBreak('s1', {}),
            para('p2', 'end B'),
        ],
        { vars: {} }
    );
    // As the server stores it: stamped entries and label, [] for the empties, no align.
    const theirs = docOf(
        [
            toc('t1', [{ id: 'h1', text: 'Intro' }]),
            { type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: 'See ' }, ref('h1', '1 Intro')] },
            sectionBreak('s1', []),
            para('p2', 'end'),
        ],
        { vars: [] }
    );

    const result = merge(base, mine, theirs);

    assert.deepEqual(idsOf(result.merged), ['t1', 'p1', 's1', 'p2']);
    assert.deepEqual(result.lost, []);
    assert.equal(result.changed, true, 'only p2 is mine');
    assert.deepEqual(result.merged.content[0].attrs.entries, [{ id: 'h1', text: 'Intro' }], 'the stamped entries stay on screen');
    assert.equal(result.merged.content[1].content[1].attrs.label, '1 Intro');
    assert.deepEqual(result.merged.content[2].attrs.setup, {});
    assert.deepEqual(result.merged.attrs.vars, {});
    assert.equal(textOf(result.merged.content[3]), 'end B');
});

test('levelling: a paragraph B typed in is kept when A\'s save only re-stamped the cross-reference label inside it', () => {
    const base = docOf([para('p1', 'See ', ref('h1', null), ' for details')]);
    const mine = docOf([para('p1', 'See ', ref('h1', null), ' for details, and my addition')]);
    // As the server stores it after A renumbered the headings: the same paragraph, a new label.
    const theirs = docOf([para('p1', 'See ', ref('h1', 'Section 3'), ' for details')]);

    const result = merge(base, mine, theirs);

    assert.deepEqual(result.lost, [], 'a re-stamped label is not a change by A, so there is no D1');
    assert.equal(result.changed, true);
    assert.equal(textOf(result.merged.content[0]), 'See  for details, and my addition');
});

test('trailing paragraph: appended to merged AND theirs when theirs ends in a box; dropped when theirs ends in an empty paragraph; never counted as changed', () => {
    const base = docOf([para('p1', 'one'), box('b1', para('q1', 'in'))]);
    // This editor added its own empty paragraph after the box; the server's document does not have it.
    const mine = docOf([para('p1', 'one'), box('b1', para('q1', 'in')), para('t1')]);

    const carried = merge(base, mine, docOf([para('p1', 'one A'), box('b1', para('q1', 'in'))]));
    assert.deepEqual(idsOf(carried.merged), ['p1', 'b1', 't1']);
    assert.equal(carried.changed, false, 'the carried paragraph is in theirs too, so nothing of mine has to be saved');
    assert.deepEqual(idsOf(JSON.parse(carried.theirs)), ['p1', 'b1', 't1']);
    assert.deepEqual(carried.lost, []);

    // Theirs ends in an empty paragraph of its own (the other tab's trailing paragraph): mine is dropped.
    const dropped = merge(base, mine, docOf([para('p1', 'one A'), box('b1', para('q1', 'in')), para('t2')]));
    assert.deepEqual(idsOf(dropped.merged), ['p1', 'b1', 't2']);
    assert.equal(dropped.changed, false);
    assert.deepEqual(dropped.lost, []);

    // Theirs ends in a paragraph with text: the empty paragraph is an ordinary insertion of mine, anchored after the box.
    // A stray blank paragraph between the two is the cost, and it is intended (spec 4.4, as amended in its section 17): an
    // empty paragraph cannot be told from one the writer typed on purpose, nothing is lost, and either writer can delete it.
    const ordinary = merge(base, mine, docOf([para('p1', 'one A'), box('b1', para('q1', 'in')), para('t3', 'typed')]));
    assert.deepEqual(idsOf(ordinary.merged), ['p1', 'b1', 't1', 't3']);
    assert.equal(ordinary.changed, true);
});

test('doc attrs: per key, mine when mine changed it, else theirs; {} and [] are one empty value', () => {
    const blocks = [para('p1', 'x')];

    const result = merge(
        docOf(blocks, { style: 'report', vars: {} }),
        docOf(blocks, { style: 'legal', vars: {} }),
        docOf(blocks, { style: 'report', vars: { client: 'Acme' } })
    );
    assert.equal(result.merged.attrs.style, 'legal');
    assert.deepEqual(result.merged.attrs.vars, { client: 'Acme' });
    assert.equal(result.changed, true);

    const empties = merge(docOf(blocks, { vars: {} }), docOf(blocks, { vars: {} }), docOf(blocks, { vars: [] }));
    assert.equal(empties.changed, false);
    assert.deepEqual(empties.merged.attrs.vars, {});
});

test('echo: a block equal to the echo makes mine win, a block absent from both is my insertion, and theirs unlike the echo is a true D1', () => {
    const known = docOf([para('p1')]);
    const echo = docOf([para('p1', 'X')]);
    const mine = docOf([para('p1', 'XY'), para('n1', 'new since')]);

    const own = merge(known, mine, docOf([para('p1', 'X')]), { echo });
    assert.deepEqual(blocksOf(own.merged), [
        ['p1', 'XY'],
        ['n1', 'new since'],
    ]);
    assert.equal(own.changed, true);
    assert.deepEqual(own.lost, []);

    const edited = merge(known, mine, docOf([para('p1', 'X by somebody else')]), { echo });
    assert.deepEqual(blocksOf(edited.merged), [
        ['p1', 'X by somebody else'],
        ['n1', 'new since'],
    ]);
    assert.deepEqual(losses(edited), [{ id: 'p1', reason: 'both', anchors: ['p1'] }]);
});

test('echo: a block in the base and in mine that is missing from both theirs and the echo is kept as my insertion, not read as their deletion', () => {
    // I sent a document without p2 (the echo), nobody else has it either, and my page shows it again (an undo, a paste).
    const known = docOf([para('p1', 'one'), para('p2', 'two')]);
    const echo = docOf([para('p1', 'one')]);
    const theirs = docOf([para('p1', 'one')]);
    const untouched = docOf([para('p1', 'one'), para('p2', 'two')]);
    const typed = docOf([para('p1', 'one'), para('p2', 'two, typed in')]);

    const kept = merge(known, untouched, theirs, { echo });
    assert.deepEqual(blocksOf(kept.merged), [
        ['p1', 'one'],
        ['p2', 'two'],
    ]);
    assert.equal(kept.changed, true, 'it is something of mine that the server does not have');
    assert.deepEqual(kept.lost, [], 'a block they deleted and I did not touch (b1) would be dropped without a word; this one is not');

    const keptTyped = merge(known, typed, theirs, { echo });
    assert.deepEqual(blocksOf(keptTyped.merged), [
        ['p1', 'one'],
        ['p2', 'two, typed in'],
    ]);
    assert.deepEqual(keptTyped.lost, [], 'a my-insertion, not a D2 loss');

    // Without the echo the same three documents are a plain deletion by them (b1) / a D2 (b2).
    const plain = merge(known, untouched, theirs);
    assert.deepEqual(blocksOf(plain.merged), [['p1', 'one']]);
    assert.equal(plain.changed, false);
    assert.deepEqual(losses(merge(known, typed, theirs)), [{ id: 'p2', reason: 'deleted', anchors: ['p1'] }]);
});

test('spine: only I reordered keeps my order; only they reordered keeps theirs; both reordered is theirs, and nothing is lost', () => {
    const base = docOf([para('p1', '1'), para('p2', '2'), para('p3', '3')]);
    const mineOrder = docOf([para('p3', '3'), para('p1', '1'), para('p2', '2')]);
    const theirsOrder = docOf([para('p2', '2'), para('p3', '3'), para('p1', '1')]);

    const onlyMe = merge(base, mineOrder, docOf([para('p1', '1'), para('p2', '2 A'), para('p3', '3')]));
    assert.deepEqual(blocksOf(onlyMe.merged), [
        ['p3', '3'],
        ['p1', '1'],
        ['p2', '2 A'],
    ]);
    assert.equal(onlyMe.changed, true);

    const onlyThem = merge(base, docOf([para('p1', '1 B'), para('p2', '2'), para('p3', '3')]), theirsOrder);
    assert.deepEqual(blocksOf(onlyThem.merged), [
        ['p2', '2'],
        ['p3', '3'],
        ['p1', '1 B'],
    ]);

    const both = merge(base, mineOrder, theirsOrder);
    assert.deepEqual(idsOf(both.merged), ['p2', 'p3', 'p1']);
    assert.equal(both.changed, false);
    assert.deepEqual(both.lost, []);

    // Their new block, when my order is the spine, follows its nearest preceding block of theirs that is on the page.
    const theirInsert = merge(base, mineOrder, docOf([para('p1', '1'), para('q1', 'theirs'), para('p2', '2'), para('p3', '3')]));
    assert.deepEqual(idsOf(theirInsert.merged), ['p3', 'p1', 'q1', 'p2']);
});

// ───────────────────────────────────────────────────────── the result, and the impossible

test('theirs is theirs as this schema serialises it, and merged round-trips through the schema', () => {
    const theirs = docOf(
        [{ type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: 'x' }] }, sectionBreak('s1', [])],
        { vars: [] }
    );

    const result = merge(docOf([para('p1', 'x')]), docOf([para('p1', 'x')]), theirs);

    assert.equal(result.theirs, JSON.stringify(schema.nodeFromJSON(levelEmpty(theirs)).toJSON()));
    assert.match(result.theirs, /"align":null/);
    assert.deepEqual(schema.nodeFromJSON(result.merged).toJSON(), result.merged);
    assert.equal(result.changed, false);
});

test('impossible: an unknown node, a missing top-level id, a shared id, a bad echo and an empty document all give null', () => {
    const base = docOf([para('p1', 'x'), para('p2', 'y')]);

    assert.equal(merge(base, base, docOf([{ type: 'marquee' }])), null);
    assert.equal(merge(base, base, docOf([para(null, 'no id')])), null);
    assert.equal(merge(base, base, docOf([para('p1', 'x'), para('p1', 'again')])), null);
    assert.equal(merge(base, base, base, { echo: docOf([{ type: 'marquee' }]) }), null);
    assert.equal(merge(base, docOf([]), base), null);
    // Two deletions that leave nothing are NOT impossible: the next case.
});

test('both deleted what the other kept: the result is one empty paragraph under an id of mine, not an impossible merge', () => {
    const result = merge(docOf([para('p1', 'x'), para('p2', 'y')]), docOf([para('p1', 'x')]), docOf([para('p2', 'y')]));

    assert.notEqual(result, null);
    assert.deepEqual(blocksOf(result.merged), [['p1', '']]);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('the text of a lost block is cut to 80 characters, and a block without text is named by its kind', () => {
    const long = 'a'.repeat(100);
    const result = merge(
        docOf([para('p1', 'x'), sectionBreak('s1', {})]),
        docOf([para('p1', long), sectionBreak('s1', { orientation: 'landscape' })]),
        docOf([para('p1', 'y'), sectionBreak('s1', { size: 'A5' })])
    );

    assert.deepEqual(
        result.lost.map((loss) => [loss.id, loss.type, loss.text]),
        [
            ['p1', 'paragraph', `${'a'.repeat(80)}…`],
            ['s1', 'sectionBreak', '[section break]'],
        ]
    );
    assert.equal(excerpt(schema.nodeFromJSON(para('p1', 'short'))), 'short');
    assert.equal(excerpt(schema.nodeFromJSON(para('p1', 'a'.repeat(80)))), 'a'.repeat(80), 'exactly 80 is not cut');
    // A character outside the BMP that straddles the cut is kept whole or left out whole, never halved.
    const straddling = excerpt(schema.nodeFromJSON(para('p1', `${'a'.repeat(79)}\u{1F600}tail`)));
    assert.equal(straddling, `${'a'.repeat(79)}\u{1F600}…`);
    assert.ok(straddling.isWellFormed(), 'no lone surrogate in the notice');
});

test('blockWord names every block type the editor has, and anything else is a block', () => {
    assert.deepEqual(
        [
            'paragraph', 'heading', 'bulletList', 'orderedList', 'taskList', 'blockquote', 'codeBlock', 'horizontalRule',
            'image', 'figure', 'table', 'toc', 'pageBreak', 'sectionBreak', 'callout', 'columns', 'doc',
        ].map(blockWord),
        [
            'paragraph', 'heading', 'list', 'list', 'task list', 'quote', 'code block', 'rule',
            'image', 'figure', 'table', 'contents list', 'page break', 'section break', 'callout', 'columns', 'block',
        ]
    );
});

test('isEmptyBlock is content.size, never textContent: a paragraph holding only a variable chip is not empty', () => {
    assert.equal(isEmptyBlock(schema.nodeFromJSON(para('p1'))), true);
    assert.equal(isEmptyBlock(schema.nodeFromJSON({ type: 'heading', attrs: { id: 'h1' } })), true);
    assert.equal(isEmptyBlock(schema.nodeFromJSON(para('p1', variable('client')))), false);
    assert.equal(isEmptyBlock(schema.nodeFromJSON(toc('t1'))), false, 'only a paragraph or a heading can be empty padding');
});

test('levelEmpty and levelDerived copy, never mutate, and level only what they are for', () => {
    const json = docOf([toc('t1', [{ id: 'h1' }]), para('p1', ref('h1', 'Label')), sectionBreak('s1', [])], { vars: [] });

    const empty = levelEmpty(json);
    assert.deepEqual(empty.attrs.vars, {});
    assert.deepEqual(empty.content[2].attrs.setup, {});
    assert.deepEqual(empty.content[0].attrs.entries, [{ id: 'h1' }], 'stamps are left alone');

    const derived = levelDerived(json);
    assert.deepEqual(derived.content[0].attrs.entries, []);
    assert.equal(derived.content[1].content[0].attrs.label, null);
    assert.deepEqual(derived.attrs.vars, [], 'empties are left alone');

    assert.deepEqual(json.attrs.vars, []);
    assert.deepEqual(json.content[0].attrs.entries, [{ id: 'h1' }]);
    assert.equal(json.content[1].content[0].attrs.label, 'Label');
    assert.deepEqual(DERIVED, { toc: ['entries'], crossRef: ['label'] });
});

/** Every id in a document, at any depth. */
const allIds = (json) => {
    const ids = [];
    const walk = (node) => {
        if (node && typeof node === 'object') {
            if (node.attrs && node.attrs.id !== undefined && node.attrs.id !== null) {
                ids.push(node.attrs.id);
            }
            (node.content ?? []).forEach(walk);
        }
    };
    walk(json);

    return ids;
};

test('two deletions that leave nothing: the empty paragraph does not take the id of a block that is offered back', () => {
    // They deleted p2; I deleted p1 and typed in p2. Nothing is left, and p2 is in the lost row: Put it back must be
    // able to return it under its own id (comments, cross-references), so the stand-in paragraph cannot hold that id.
    const result = merge(
        docOf([para('p1', 'a'), para('p2', 'b')]),
        docOf([para('p2', 'b X')]),
        docOf([para('p1', 'a')])
    );

    assert.deepEqual(result.lost.map((loss) => [loss.id, loss.reason]), [['p2', 'deleted']]);
    assert.equal(result.merged.content.length, 1);
    assert.equal(result.merged.content[0].type, 'paragraph');
    assert.notEqual(result.merged.content[0].attrs.id, 'p2');
});

test('two deletions that leave nothing: the stand-in paragraph takes the id of the last block of mine that is not offered back', () => {
    const result = merge(
        docOf([para('p1', 'a'), para('p2', 'b'), para('p3', 'c')]),
        docOf([para('p1', 'a'), para('p2', 'b X')]), // I deleted p3 and typed in p2
        docOf([para('p3', 'c')]) // they deleted p1 and p2
    );

    assert.deepEqual(result.lost.map((loss) => loss.id), ['p2']);
    assert.deepEqual(idsOf(result.merged), ['p1']);
});

test('the trailing paragraph is carried as the editor own only when theirs holds its id nowhere, at any depth', () => {
    // Mine ends in the empty paragraph p2 (the editor's own, after a section break); theirs has an item with the id p2 NESTED
    // in a box. Carrying mine's p2 into theirs would store the id twice.
    const base = docOf([para('p1', 'One'), para('p2'), sectionBreak('s1')]);
    const mine = docOf([para('p1', 'One'), sectionBreak('s1'), para('p2')]);
    const theirs = docOf([para('p1', 'One'), box('b1', para('p2', 'Their item')), sectionBreak('s1')]);

    const result = merge(base, mine, theirs);

    const ids = allIds(JSON.parse(result.theirs));
    assert.equal(new Set(ids).size, ids.length, `theirs carries ${ids.join(',')}`);
    const mergedIds = allIds(result.merged);
    assert.equal(new Set(mergedIds).size, mergedIds.length, `merged carries ${mergedIds.join(',')}`);
});

test('levelDerived blanks every toc with an array of its own: changing one result never reaches another', () => {
    const json = docOf([toc('t1', [{ id: 'h1' }]), toc('t2', [{ id: 'h2' }])]);

    const first = levelDerived(json);
    assert.notStrictEqual(first.content[0].attrs.entries, first.content[1].attrs.entries, 'two contents lists in one document');

    // A caller that changes what it was given...
    first.content[0].attrs.entries.push({ id: 'poisoned' });
    first.content[1].attrs.entries.push({ id: 'also poisoned' });

    // ...changes nothing for the next levelling, nor for the other list.
    const second = levelDerived(json);
    assert.deepEqual(second.content[0].attrs.entries, []);
    assert.deepEqual(second.content[1].attrs.entries, []);
    assert.deepEqual(first.content[1].attrs.entries, [{ id: 'also poisoned' }]);
});

test('appendLost appends and never replaces; the only entry skipped is an exact duplicate', () => {
    const loss = (text) => ({ id: 'p5', type: 'paragraph', reason: 'both', json: para('p5', text), anchors: ['p5'], text });
    const first = lostEntry(loss('Hello B1'), { source: 'merge', version: 2, n: 0, sentence: 'S' });
    const second = lostEntry(loss('Hello B2'), { source: 'merge', version: 3, n: 0, sentence: 'S' });
    const again = lostEntry(loss('Hello B1'), { source: 'merge', version: 4, n: 0, sentence: 'S' });

    const list = appendLost(appendLost([], [first]), [second, again]);

    assert.deepEqual(
        list.map((entry) => [entry.key, entry.excerpt]),
        [
            ['p5:2:0', 'Hello B1'],
            ['p5:3:0', 'Hello B2'],
        ]
    );
    assert.deepEqual(first, {
        key: 'p5:2:0',
        id: 'p5',
        type: 'paragraph',
        reason: 'both',
        source: 'merge',
        json: para('p5', 'Hello B1'),
        anchors: ['p5'],
        version: 2,
        sentence: 'S',
        excerpt: 'Hello B1',
    });
    assert.deepEqual(appendLost([], []), []);
});

// ───────────────────────────────────────────────────────── the fixtures: one per row of 4.3 and per ordering case

const FIXTURES = new URL('../fixtures/merge/', import.meta.url);
/** The real pair is captured by sync.merge.editor.test.js and MergeFixtureTest, and merged over the editor's own schema there. */
const REAL_PAIR = ['editor-form.json', 'stored-form.json'];
const fixtures = readdirSync(FIXTURES)
    .filter((name) => name.endsWith('.json') && !REAL_PAIR.includes(name))
    .sort();

test('there is a fixture for every row of the decision table and every ordering case', () => {
    assert.deepEqual(fixtures, [
        'a1-unchanged.json',
        'a2-theirs-changed.json',
        'a3-mine-changed.json',
        'a4-same-change.json',
        'a5-both-changed.json',
        'b1-they-deleted-untouched.json',
        'b2-empty-block-is-not-offered.json',
        'b2-they-deleted-i-typed.json',
        'c1-i-deleted-untouched.json',
        'c2-draft-deletions-do-not-win.json',
        'c2-i-deleted-they-edited.json',
        'd-after-deleted-predecessor.json',
        'd-mine-inserted.json',
        'e-theirs-inserted.json',
        'echo-own-save-comes-back.json',
        'f-both-inserted-different.json',
        'f-both-inserted-equal.json',
        'g-both-deleted.json',
        'order-both-reordered.json',
        'order-chained-insertions.json',
        'order-only-i-reordered.json',
        'order-only-they-reordered.json',
        'order-start-and-end.json',
    ]);
});

/** Parses a fixture's text, naming the file when it is not JSON. */
function parseFixture(name, text) {
    try {
        return JSON.parse(text);
    } catch (cause) {
        throw new Error(`fixture ${name} cannot be read: ${cause.message}`, { cause });
    }
}

/** Reads a fixture, naming the file when it cannot be parsed. */
const loadFixture = (name) => parseFixture(name, readFileSync(new URL(name, FIXTURES), 'utf8'));

test('a malformed fixture is reported under its own file name', () => {
    assert.throws(() => parseFixture('broken.json', '{ "name": '), /fixture broken\.json cannot be read: /);
    assert.deepEqual(parseFixture('fine.json', '{"a":1}'), { a: 1 });
});

for (const name of fixtures) {
    // A malformed file fails ONE test, under its own name; the other fixtures still run.
    let fixture = null;
    let unreadable = null;
    try {
        fixture = loadFixture(name);
    } catch (error) {
        unreadable = error;
    }

    test(`${name}: ${fixture?.name ?? 'unreadable'}`, () => {
        if (unreadable) {
            throw unreadable;
        }
        const result = mergeDocuments(
            schema,
            { base: fixture.base, mine: fixture.mine, theirs: fixture.theirs, echo: fixture.echo ?? null },
            fixture.options ?? {}
        );

        assert.notEqual(result, null, 'the merge is possible');
        assert.deepEqual(blocksOf(result.merged), fixture.expected.blocks);
        assert.equal(result.changed, fixture.expected.changed);
        assert.deepEqual(losses(result), fixture.expected.lost);
        assert.equal(result.overwrites, fixture.expected.overwrites);
    });
}
