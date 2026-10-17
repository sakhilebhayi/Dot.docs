import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema, Slice } from 'prosemirror-model';
import { EditorState, TextSelection } from 'prosemirror-state';
import { ReplaceStep, StepResult } from 'prosemirror-transform';

import { MAX_BLOCK_STEPS, blockSteps, remoteTransaction } from '../../resources/js/editor/sync/apply.js';

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

test('a change inside an isolating node plus a deletion after it are two block steps that reproduce the target', () => {
    // The box is not a textblock, so it is replaced whole: a closed slice at
    // a top-level boundary, which never needs the fitter. The deleted
    // paragraph is a second step. tr.replace() would have fitted ONE open
    // slice by nesting a second copy of the box inside the first.
    const held = doc([box('b1', para('p1', 'In the box')), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([box('b1', para('p1', 'In the box, edited')), para('p2', 'Two')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace', 'replace']);
    assert.equal(tr.steps[0].from, startOf(state.doc, 'p3'), 'the deletion first: steps run from the end of the document backwards');
    assert.equal(tr.steps[1].from, 0);
    assert.equal(tr.steps[1].to, state.doc.child(0).nodeSize, 'the box, whole');
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

test('when the whole-document replace cannot be applied either, the result is null and the editor is left alone', () => {
    class Throws {
        constructor() {
            throw new RangeError('this slice does not fit');
        }
    }
    const held = doc([para('p1', 'First'), para('p2', 'Second')]);
    const arriving = doc([para('p1', 'First, edited'), para('p2', 'Second')]);
    const real = stateOf(held);

    // A state whose transaction cannot replace the whole document either:
    // the last-resort tr.replaceWith() throws. Forced on the transaction,
    // because a document this schema can parse can always be replaced
    // wholesale in a real state; the branch is for the case where it
    // cannot, and applyRemote() must then leave what is on screen alone.
    const state = {
        doc: real.doc,
        schema: real.schema,
        get tr() {
            const tr = real.tr;
            tr.replaceWith = () => {
                throw new RangeError('cannot replace');
            };

            return tr;
        },
    };

    assert.equal(remoteTransaction(state, arriving, Throws), null);
    assert.ok(real.doc.eq(schema.nodeFromJSON(held)), 'nothing was applied to the real state');
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

// ───────────────────────────────────────────────────────── per-block steps (Phase 2)

test('two separate changes are two steps, and a caret between them stays put', () => {
    const held = doc([para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third')]);
    const arriving = doc([para('p1', 'First, longer'), para('p2', 'Second'), para('p3', 'Third, longer')]);
    const state = stateOf(held);
    // The caret after "Sec" in the middle paragraph.
    const caret = startOf(state.doc, 'p2') + 1 + 3;
    const typing = state.apply(state.tr.setSelection(TextSelection.create(state.doc, caret)));

    const tr = remoteTransaction(typing, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace', 'replace']);
    assert.ok(tr.steps[0].from > tr.steps[1].to, 'applied from the end of the document backwards');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
    assert.equal(tr.doc.textBetween(tr.selection.from - 3, tr.selection.from), 'Sec');
    assert.equal(tr.doc.textBetween(tr.selection.from, tr.selection.from + 3), 'ond');
});

test('a change inside a textblock is narrowed to the characters that changed', () => {
    const held = doc([para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third')]);
    const arriving = doc([para('p1', 'First'), para('p2', 'Second, edited'), para('p3', 'Third')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    const p2 = startOf(state.doc, 'p2');
    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, p2 + 1 + 'Second'.length);
    assert.equal(tr.steps[0].to, p2 + 1 + 'Second'.length);
    assert.equal(tr.steps[0].slice.content.textBetween(0, tr.steps[0].slice.content.size), ', edited');
});

test('a changed block that is not a textblock is replaced whole', () => {
    const held = doc([box('b1', para('p1', 'In the box')), para('p2', 'Two')]);
    const arriving = doc([box('b1', para('p1', 'In the box, edited')), para('p2', 'Two')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.child(0).nodeSize);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('an inserted, a deleted and a changed block are three steps', () => {
    const held = doc([para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([para('p1', 'One!'), para('p3', 'Three'), para('p4', 'Four')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace', 'replace', 'replace']);
    assert.equal(tr.steps[0].from, state.doc.content.size, 'the insertion comes first: it is furthest down');
    assert.equal(tr.steps[1].from, startOf(state.doc, 'p2'));
    assert.equal(tr.steps[1].to, startOf(state.doc, 'p3'), 'the deletion, whole');
    assert.ok(tr.steps[2].from > 0 && tr.steps[2].to < startOf(state.doc, 'p2'), 'the change in p1, narrowed');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('[A] to [B] is one coalesced step, never an empty document', () => {
    const held = doc([para('p1', 'A')]);
    const arriving = doc([para('p2', 'B')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.content.size);
    assert.equal(tr.steps[0].slice.content.childCount, 1);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));

    // A block replaced in the middle: the deletion and the insertion at one point are one step too.
    const replaced = doc([para('p1', 'One'), para('n1', 'New'), para('p3', 'Three')]);
    const middle = remoteTransaction(stateOf(doc([para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three')])), replaced, ReplaceStep);
    assert.deepEqual(stepTypes(middle), ['replace']);
    assert.ok(middle.doc.eq(schema.nodeFromJSON(replaced)));
});

test('a move falls back to the one-range apply', () => {
    const held = doc([para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([para('p2', 'Two'), para('p1', 'One'), para('p3', 'Three')]);
    const state = stateOf(held);

    assert.equal(blockSteps(state.doc, schema.nodeFromJSON(arriving), ReplaceStep), null);

    const tr = remoteTransaction(state, arriving, ReplaceStep);
    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    // findDiffEnd stops exactly where the moved pair ends, which is where p3 starts.
    assert.equal(tr.steps[0].to, startOf(state.doc, 'p3'), 'one range over the two moved blocks, not the whole document');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a narrowed step that fails is retried whole', () => {
    // Stands in for a narrowed slice the document refuses: a step that
    // starts inside a block fails, and the same change as a whole-block
    // step (which starts at a top-level boundary) applies.
    class FailsNarrow extends ReplaceStep {
        apply(docNode) {
            if (docNode.resolve(this.from).depth > 0) {
                return StepResult.fail('a narrowed step this document refuses');
            }

            return super.apply(docNode);
        }
    }
    const held = doc([para('p1', 'First'), para('p2', 'Second')]);
    const arriving = doc([para('p1', 'First, edited'), para('p2', 'Second')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, FailsNarrow);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.child(0).nodeSize, 'the whole first paragraph');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('blockSteps gives null for a block without an id or two blocks sharing one, and nothing for identical documents', () => {
    const okay = schema.nodeFromJSON(doc([para('p1', 'One')]));

    assert.equal(blockSteps(schema.nodeFromJSON(doc([para(null, 'One')])), okay, ReplaceStep), null);
    assert.equal(blockSteps(okay, schema.nodeFromJSON(doc([para('p1', 'One'), para('p1', 'Again')])), ReplaceStep), null);
    assert.deepEqual(blockSteps(okay, okay, ReplaceStep), []);
});

test('more than MAX_BLOCK_STEPS block steps fall back to the one-range apply: a document-sized change is one step', () => {
    const paragraphs = (count, text) => Array.from({ length: count }, (_, index) => para(`p${index}`, `${text} ${index}`));

    // At the bound: every paragraph changed, one step each.
    const atBound = remoteTransaction(stateOf(doc(paragraphs(MAX_BLOCK_STEPS, 'old'))), doc(paragraphs(MAX_BLOCK_STEPS, 'new')), ReplaceStep);
    assert.equal(atBound.steps.length, MAX_BLOCK_STEPS);
    assert.ok(atBound.doc.eq(schema.nodeFromJSON(doc(paragraphs(MAX_BLOCK_STEPS, 'new')))));

    // One more: the whole range, as Phase 1 applied it.
    const held = stateOf(doc(paragraphs(MAX_BLOCK_STEPS + 1, 'old')));
    const arriving = doc(paragraphs(MAX_BLOCK_STEPS + 1, 'new'));
    assert.equal(blockSteps(held.doc, schema.nodeFromJSON(arriving), ReplaceStep).length, MAX_BLOCK_STEPS + 1, 'blockSteps itself does not cap');

    const beyond = remoteTransaction(held, arriving, ReplaceStep);
    assert.deepEqual(stepTypes(beyond), ['replace']);
    assert.ok(beyond.doc.eq(schema.nodeFromJSON(arriving)));
});
