import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema, Slice } from 'prosemirror-model';
import { EditorState } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { remoteTransaction } from '../../resources/js/editor/sync/apply.js';

// A stand-in for the editor's schema, with the node types that
// remoteTransaction() treats specially declared the way the editor declares
// them: a contents list and a cross-reference the server stamps, a section
// break whose `setup` PHP may send as `[]`, an `isolating` container (like
// table cells, figures and columns), and the document-level attrs.
const schema = new Schema({
    nodes: {
        doc: { content: 'block+', attrs: { schema: { default: 1 }, style: { default: 'report' }, vars: { default: {} } } },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        toc: { group: 'block', atom: true, attrs: { id: { default: null }, entries: { default: [] } } },
        sectionBreak: { group: 'block', atom: true, attrs: { id: { default: null }, setup: { default: {} } } },
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        crossRef: {
            group: 'inline',
            inline: true,
            atom: true,
            attrs: { kind: { default: 'heading' }, targetId: { default: null }, label: { default: null } },
        },
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
const toc = (id, entries) => ({ type: 'toc', attrs: { id, entries } });
const ref = (targetId, label) => ({ type: 'crossRef', attrs: { kind: 'heading', targetId, label } });
const sectionBreak = (id, setup) => ({ type: 'sectionBreak', attrs: { id, setup } });
const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
const doc = (content, attrs) => ({ type: 'doc', ...(attrs ? { attrs } : {}), content });

const stateOf = (json) => EditorState.create({ doc: schema.nodeFromJSON(json) });
const stepTypes = (tr) => tr.steps.map((step) => step.toJSON().stepType);
/** Where the top-level block with this id starts. */
const startOf = (node, id) => {
    let found = null;
    node.forEach((child, offset) => {
        if (child.attrs.id === id) {
            found = offset;
        }
    });

    return found;
};

test('a document the editor already shows needs no step', () => {
    const json = doc([para('p1', 'One'), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(json), json, ReplaceStep);

    assert.equal(tr.docChanged, false);
});

test('a change in one paragraph is one replace step inside that paragraph', () => {
    const held = doc([para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third')]);
    const arriving = doc([para('p1', 'First'), para('p2', 'Second, edited'), para('p3', 'Third')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.steps[0].from > startOf(state.doc, 'p2'));
    assert.ok(tr.steps[0].to < startOf(state.doc, 'p3'));
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a re-stamped contents list and cross-reference are levelled with attribute steps, not replaced', () => {
    // The server stamped the contents list and the reference label, and
    // somebody edited the LAST paragraph. Without the levelling the replaced
    // range would start at the contents list, at the top of the document.
    const held = doc([
        toc('t1', []),
        para('p1', 'See ', ref('h1', null), ' for more.'),
        para('p2', 'Second'),
        para('p3', 'Third'),
    ]);
    const arriving = doc([
        toc('t1', [{ id: 'h1', level: 1, text: 'Introduction', number: '1' }]),
        para('p1', 'See ', ref('h1', '1 Introduction'), ' for more.'),
        para('p2', 'Second'),
        para('p3', 'Third, edited'),
    ]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['attr', 'attr', 'replace']);
    assert.ok(tr.steps[2].from > startOf(state.doc, 'p3'), 'the replaced range starts inside the last paragraph');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('when only the stamped attributes differ there is no replace step at all', () => {
    const held = doc([toc('t1', []), para('p1', 'See ', ref('h1', null))]);
    const arriving = doc([toc('t1', [{ id: 'h1', text: 'Introduction' }]), para('p1', 'See ', ref('h1', '1 Introduction'))]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['attr', 'attr']);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('an empty section-break setup the server sends as [] is not a change', () => {
    // The editor holds {} for a section break it created; PHP sends [].
    const held = doc([para('p1', 'One'), sectionBreak('s1', {}), para('p2', 'Two')]);
    const arriving = doc([para('p1', 'One'), sectionBreak('s1', []), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.equal(tr.docChanged, false);
});

test('a tab that loaded the [] form keeps it, and a section break it has never seen gets {}', () => {
    const held = doc([para('p1', 'One'), sectionBreak('s1', []), para('p2', 'Two')]);
    const arriving = doc([para('p1', 'One'), sectionBreak('s1', []), para('p2', 'Two'), sectionBreak('s2', []), para('p3', 'Three')]);
    const state = stateOf(held);

    assert.equal(remoteTransaction(state, held, ReplaceStep).docChanged, false);

    const tr = remoteTransaction(state, arriving, ReplaceStep);
    const setups = [];
    tr.doc.descendants((node) => {
        if (node.type.name === 'sectionBreak') {
            setups.push(node.attrs.setup);
        }
    });

    assert.deepEqual(setups, [[], {}]);
});

test('a setup that really changed is replaced', () => {
    const held = doc([para('p1', 'One'), sectionBreak('s1', {}), para('p2', 'Two')]);
    const arriving = doc([para('p1', 'One'), sectionBreak('s1', { orientation: 'landscape' }), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('empty document variables the server sends as [] are not a change', () => {
    const content = [para('p1', 'One')];

    // The editor's own empty value is {}.
    const own = remoteTransaction(stateOf(doc(content, { vars: {} })), doc(content, { vars: [] }), ReplaceStep);
    assert.equal(own.docChanged, false);

    // A tab that loaded the server's [] holds [].
    const loaded = remoteTransaction(stateOf(doc(content, { vars: [] })), doc(content, { vars: [] }), ReplaceStep);
    assert.equal(loaded.docChanged, false);
});

test('document-level attrs that changed are set with their own steps', () => {
    const content = [para('p1', 'One')];
    const state = stateOf(doc(content, { style: 'report', vars: {} }));

    const tr = remoteTransaction(state, doc(content, { style: 'legal', vars: { client: 'Acme' } }), ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['docAttr', 'docAttr']);
    assert.equal(tr.doc.attrs.style, 'legal');
    assert.deepEqual(tr.doc.attrs.vars, { client: 'Acme' });

    // Emptied again by somebody else: [] arrives, {} is what the editor holds.
    const emptied = remoteTransaction(state.apply(tr), doc(content, { style: 'legal', vars: [] }), ReplaceStep);
    assert.deepEqual(stepTypes(emptied), ['docAttr']);
    assert.deepEqual(emptied.doc.attrs.vars, {});
});

test('a change inside an isolating node plus a change after it is one step that reproduces the target', () => {
    // tr.replace() would fit this slice by nesting a second copy of the box
    // inside the first, without throwing. A ReplaceStep applies it as is.
    const held = doc([box('b1', para('p1', 'In the box')), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([box('b1', para('p1', 'In the box, edited')), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.steps[0].from > 1, 'the step is the narrowed range, not the whole document');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a step that throws falls back to replacing the whole document', () => {
    class Throws {
        constructor() {
            throw new RangeError('this slice does not fit');
        }
    }
    const held = doc([para('p1', 'First'), para('p2', 'Second')]);
    const arriving = doc([para('p1', 'First, edited'), para('p2', 'Second')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, Throws);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.content.size);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a step that applies but does not reproduce the target falls back too', () => {
    // Stands in for ProseMirror re-shaping a slice: it applies without
    // throwing and leaves a different document behind (here: the range is
    // deleted and nothing is put in its place).
    class Wrong extends ReplaceStep {
        constructor(from, to) {
            super(from, to, Slice.empty);
        }
    }
    const held = doc([toc('t1', []), para('p1', 'First one'), para('p2', 'Second')]);
    const arriving = doc([toc('t1', [{ id: 'h1' }]), para('p1', 'First two!'), para('p2', 'Second')]);

    const tr = remoteTransaction(stateOf(held), arriving, Wrong);

    // The attribute step, the step that went wrong, then everything.
    assert.deepEqual(stepTypes(tr), ['attr', 'replace', 'replace']);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a document this schema cannot parse gives null', () => {
    const state = stateOf(doc([para('p1', 'One')]));

    assert.equal(remoteTransaction(state, doc([{ type: 'marquee' }]), ReplaceStep), null);
    // Parses, but breaks the schema: a document needs at least one block.
    assert.equal(remoteTransaction(state, doc([]), ReplaceStep), null);
});

test('the editor own empty trailing paragraph is carried over when the document does not end in a paragraph', () => {
    // TipTap's TrailingNode added `trail` after the box. The server's
    // document does not have it, and must not take it away.
    const held = doc([para('p1', 'One'), box('b1', para('p2', 'In the box')), para('trail')]);
    const arriving = doc([para('p1', 'One, edited'), box('b1', para('p2', 'In the box'))]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.steps[0].to < startOf(state.doc, 'b1'), 'only the first paragraph is touched');
    assert.equal(tr.doc.lastChild.attrs.id, 'trail');
    assert.equal(tr.doc.childCount, 3);

    // The same document again changes nothing.
    assert.equal(remoteTransaction(state.apply(tr), arriving, ReplaceStep).docChanged, false);
});

test('a trailing paragraph is not carried over when the document ends in a paragraph or already has it', () => {
    // The server's document ends in a paragraph: it is the whole truth.
    const endsInParagraph = remoteTransaction(
        stateOf(doc([para('p1', 'One'), para('trail')])),
        doc([para('p1', 'One')]),
        ReplaceStep
    );
    assert.equal(endsInParagraph.doc.childCount, 1);

    // The held last paragraph is in the server's document, further up:
    // somebody added a box after it.
    const moved = doc([para('p1', 'One'), para('trail'), box('b1', para('p2', 'In the box'))]);
    const known = remoteTransaction(stateOf(doc([para('p1', 'One'), para('trail')])), moved, ReplaceStep);
    assert.ok(known.doc.eq(schema.nodeFromJSON(moved)));

    // The held last paragraph has text in it: it is content, not padding.
    const withText = doc([para('p1', 'One'), box('b1', para('p2', 'In the box'))]);
    const typed = remoteTransaction(
        stateOf(doc([para('p1', 'One'), box('b1', para('p2', 'In the box')), para('p9', 'Typed')])),
        withText,
        ReplaceStep
    );
    assert.ok(typed.doc.eq(schema.nodeFromJSON(withText)));
});
