import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema } from 'prosemirror-model';
import { EditorState, TextSelection } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { diffRange } from '../../resources/js/editor/sync/narrow.js';

// A stand-in for the editor's schema: blocks with ids, like every Dot.Doc
// block. diffRange() only uses Fragment.findDiffStart/findDiffEnd, so the
// real schema is not needed to test it.
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'text*', attrs: { id: { default: null }, align: { default: null } } },
        // Like the editor's table cells, figures and columns: `isolating`.
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        text: {},
    },
});

const para = (id, text, attrs = {}) => ({
    type: 'paragraph',
    attrs: { id, ...attrs },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
const doc = (...content) => schema.nodeFromJSON({ type: 'doc', content });

/** Apply the range diffRange() reports, the way remoteTransaction() does (sync/apply.js). */
function follow(state, next) {
    const range = diffRange(state.doc, next);
    if (range === null) {
        return state;
    }

    return state.apply(state.tr.step(new ReplaceStep(range.from, range.toA, next.slice(range.from, range.toB))));
}

test('identical documents need no change at all', () => {
    const a = doc(para('p1', 'One'), para('p2', 'Two'));
    const b = doc(para('p1', 'One'), para('p2', 'Two'));

    assert.equal(diffRange(a, b), null);
});

test('a document the server stored without align equals the editor copy that has align null', () => {
    // DocumentSchema::normalise() drops `align` when it is null; the editor
    // emits it on every paragraph. As ProseMirror nodes they are the same.
    const fromEditor = doc(para('p1', 'One', { align: null }));
    const fromServer = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: 'One' }] }] });

    assert.equal(diffRange(fromEditor, fromServer), null);
});

test('a change inside one paragraph touches only that paragraph', () => {
    const before = doc(para('p1', 'First paragraph'), para('p2', 'Second paragraph'), para('p3', 'Third paragraph'));
    const after = doc(para('p1', 'First paragraph'), para('p2', 'Second, edited paragraph'), para('p3', 'Third paragraph'));

    const range = diffRange(before, after);
    const secondStart = before.child(0).nodeSize;
    const secondEnd = secondStart + before.child(1).nodeSize;

    assert.ok(range.from > secondStart && range.toA < secondEnd, 'the changed range stays inside the second paragraph');

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});

test('a caret in an untouched paragraph stays exactly where it was', () => {
    const before = doc(para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third paragraph'));
    const after = doc(para('p1', 'First, with more words added by somebody else'), para('p2', 'Second'), para('p3', 'Third paragraph'));

    // Caret after "Third" in the last paragraph.
    const thirdStart = before.child(0).nodeSize + before.child(1).nodeSize + 1;
    const state = EditorState.create({ doc: before, selection: TextSelection.create(before, thirdStart + 5) });

    const result = follow(state, after);

    assert.ok(result.doc.eq(after));
    assert.equal(result.doc.textBetween(result.selection.from - 5, result.selection.from), 'Third');
});

test('an inserted paragraph is added without touching its neighbours', () => {
    const before = doc(para('p1', 'One'), para('p3', 'Three'));
    const after = doc(para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three'));

    const result = follow(EditorState.create({ doc: before }), after);

    assert.ok(result.doc.eq(after));
});

test('a removed paragraph is removed', () => {
    const before = doc(para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three'));
    const after = doc(para('p1', 'One'), para('p3', 'Three'));

    const result = follow(EditorState.create({ doc: before }), after);

    assert.ok(result.doc.eq(after));
});

test('repeated characters do not make the start and end of the range cross', () => {
    // "aa" -> "aaa": the first difference is at the end and so is the last
    // one. Without the overlap correction the range comes out inverted.
    const before = doc(para('p1', 'aa'));
    const after = doc(para('p1', 'aaa'));

    const range = diffRange(before, after);
    assert.ok(range.toA >= range.from && range.toB >= range.from);

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});

test('a shrinking run of repeated characters is handled too', () => {
    const before = doc(para('p1', 'aaaa'));
    const after = doc(para('p1', 'aa'));

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});

test('a change inside an isolating node plus a change after it is reproduced exactly', () => {
    // tr.replace() "fits" this slice by nesting a second copy of the box
    // inside the first, without throwing. A ReplaceStep applies it as is.
    const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
    const before = doc(box('b1', para('p1', 'In the box')), para('p2', 'Two'), para('p3', 'Three'));
    const after = doc(box('b1', para('p1', 'In the box, edited')), para('p2', 'Two'));

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});
