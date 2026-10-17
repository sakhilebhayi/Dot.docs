import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema } from 'prosemirror-model';
import { EditorState, TextSelection } from 'prosemirror-state';

import { allowDocChanges, readOnlyGuardPlugin } from '../../resources/js/editor/extensions/readOnlyGuard.js';

/**
 * A read only editor does not change its document through a command.
 * `setEditable(false)` alone only stops typing: commands still dispatch.
 */
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null } } },
        text: { group: 'inline' },
    },
});

const para = (id, text) => ({ type: 'paragraph', attrs: { id }, content: text ? [{ type: 'text', text }] : [] });

function build({ readOnly }) {
    const flags = { readOnly };
    const gate = { allowing: false };
    const state = EditorState.create({
        doc: schema.nodeFromJSON({ type: 'doc', content: [para('aaaaaaaa', 'hello')] }),
        plugins: [readOnlyGuardPlugin(() => flags.readOnly, gate)],
    });

    return { flags, gate, state };
}

const typing = (state) => state.tr.insertText(' there', 6);

test('an editable editor changes its document as ever', () => {
    const { state } = build({ readOnly: false });

    const next = state.applyTransaction(typing(state)).state;

    assert.equal(next.doc.textContent, 'hello there');
});

test('a read only editor refuses a transaction that changes the document', () => {
    const { state } = build({ readOnly: true });

    const { state: next, transactions } = state.applyTransaction(typing(state));

    assert.equal(next.doc.textContent, 'hello');
    assert.equal(transactions.length, 0);
});

test('a read only editor still moves its selection', () => {
    const { state } = build({ readOnly: true });
    const selecting = state.tr.setSelection(TextSelection.create(state.doc, 2, 4));

    const next = state.applyTransaction(selecting).state;

    assert.equal(next.selection.from, 2);
    assert.equal(next.selection.to, 4);
});

test('applying what somebody else saved is allowed, and only while allowDocChanges() runs it', () => {
    const { state, gate } = build({ readOnly: true });
    const editor = { storage: { readOnlyGuard: gate } };

    let during = null;
    const applied = allowDocChanges(editor, () => {
        during = gate.allowing;

        return state.applyTransaction(typing(state)).state;
    });

    assert.equal(during, true);
    assert.equal(applied.doc.textContent, 'hello there');
    assert.equal(gate.allowing, false, 'the guard is back on afterwards');
    assert.equal(state.applyTransaction(typing(state)).state.doc.textContent, 'hello');
});

test('the guard comes back on even when the apply throws', () => {
    const { gate } = build({ readOnly: true });
    const editor = { storage: { readOnlyGuard: gate } };

    assert.throws(() =>
        allowDocChanges(editor, () => {
            throw new Error('boom');
        })
    );
    assert.equal(gate.allowing, false);
});

test('an editor without the guard simply runs the apply', () => {
    assert.equal(allowDocChanges({ storage: {} }, () => 7), 7);
});

test('the guard follows the editor becoming read only', () => {
    const { state, flags } = build({ readOnly: false });

    flags.readOnly = true;

    assert.equal(state.applyTransaction(typing(state)).state.doc.textContent, 'hello');
});
