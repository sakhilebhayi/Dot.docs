import assert from 'node:assert/strict';
import test from 'node:test';

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { history, redo, redoDepth, undo, undoDepth } from 'prosemirror-history';
import { Schema } from 'prosemirror-model';
import { EditorState, TextSelection } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { BlockId, BlockIdRepair, blockIdRepairPlugin } from '../../resources/js/editor/extensions/blockId.js';
import { clearHistoryTransaction, remoteTransaction } from '../../resources/js/editor/sync/apply.js';

/**
 * A remote change ends the local undo history.
 *
 * applyRemote() (resources/js/editor/index.js) applies somebody else's saved
 * document as ONE replaced range, outside the undo history. prosemirror-history
 * keeps its stacks across such a transaction by mapping every stored step
 * through it, and a stored step whose range touches the replaced range is
 * mapped onto the text that arrived: the undo then deletes it. So applyRemote()
 * dispatches a second transaction, built by clearHistoryTransaction()
 * (sync/apply.js), that empties both stacks.
 *
 * First with the real prosemirror-history in a bare EditorState, then with
 * TipTap's own editor and extension stack.
 */
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        text: { group: 'inline' },
    },
});

const para = (id, text) => ({
    type: 'paragraph',
    attrs: { id },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
const doc = (...content) => ({ type: 'doc', content });
const node = (json) => schema.nodeFromJSON(json);

/** Every top-level block as [id, text]. */
const blocksOf = (docNode) => {
    const blocks = [];
    docNode.forEach((child) => blocks.push([child.attrs.id, child.textContent]));

    return blocks;
};
const textsOf = (docNode) => blocksOf(docNode).map(([, text]) => text);
const idsOf = (docNode) => blocksOf(docNode).map(([id]) => id);

/** An editor state with an undo history, and a dispatch that keeps it current. */
const editorOf = (json, plugins = [history()]) => {
    let state = EditorState.create({ doc: node(json), plugins });

    return {
        get state() {
            return state;
        },
        apply: (tr) => {
            state = state.apply(tr);
        },
    };
};

/** The first of applyRemote()'s two dispatches: the newer document, outside the undo history. */
const remote = (state, json) =>
    remoteTransaction(state, json, ReplaceStep).setMeta('addToHistory', false).setMeta('preventUpdate', true);

// ───────────────────────────────────────────────────────── the case that loses text

const MINE = doc(para('ownerXX1', 'Hello'), para('tailXXX2', 'Tail'));
const THEIRS = doc(para('freshXX0', 'Above'), para('ownerXX1', 'Well, Hello'), para('tailXXX2', 'Tail'));

/**
 * This tab, taken to the moment before the undo:
 *
 *   1. The document is "Hello" and "Tail".
 *   2. This tab presses Enter at the START of "Hello". The new, empty
 *      paragraph above it gets an id of its own (UniqueID's part, done here
 *      in the same transaction). That is one undo step.
 *   3. Somebody else types in both halves and saves, and this tab follows:
 *      the one range that differs, from just inside the empty paragraph to
 *      just before "Hello", is replaced by `Above</p><p>Well, `.
 */
function afterTheirChange() {
    const world = editorOf(MINE);

    world.apply(world.state.tr.split(1).setNodeAttribute(0, 'id', 'freshXX0'));
    assert.deepEqual(blocksOf(world.state.doc), [
        ['freshXX0', ''],
        ['ownerXX1', 'Hello'],
        ['tailXXX2', 'Tail'],
    ]);
    assert.equal(undoDepth(world.state), 1);

    world.apply(remote(world.state, THEIRS));
    assert.ok(world.state.doc.eq(node(THEIRS)), 'this tab now shows what the other person saved');

    return world;
}

test('the danger: with the history kept, an undo after somebody else change deletes the text that arrived', () => {
    const world = afterTheirChange();

    // prosemirror-history kept the step across the remote transaction.
    assert.equal(undoDepth(world.state), 1);
    assert.equal(undo(world.state, world.apply), true);

    // The inverse of the split deletes the range between the two paragraph
    // starts, and that range now holds what the other person typed: "Above"
    // and "Well, " are gone. If this ever stops failing this way,
    // prosemirror-history has changed how it maps a stored step, and the
    // decision to empty the history can be looked at again.
    assert.deepEqual(textsOf(world.state.doc), ['Hello', 'Tail']);
    assert.ok(!world.state.doc.textContent.includes('Above'));
    assert.ok(!world.state.doc.textContent.includes('Well, '));
});

test('with the clearing transaction there is nothing to undo, and the text that arrived stays', () => {
    const world = afterTheirChange();

    world.apply(clearHistoryTransaction(world.state));

    assert.equal(undoDepth(world.state), 0);
    assert.equal(redoDepth(world.state), 0);
    assert.equal(undo(world.state, world.apply), false);
    assert.equal(redo(world.state, world.apply), false);
    assert.ok(world.state.doc.eq(node(THEIRS)));
});

test('the sequence that left two blocks with one id: with the clearing transaction, undo changes nothing and the ids stay unique', () => {
    // tests/js/uniqueIds.test.js replays this with the history kept. Beta
    // presses Enter at the END of the paragraph (the new half gets its own
    // id) and types a word; Alpha's change, which splits the paragraph at
    // its START, arrives; Beta presses undo.
    const world = editorOf(doc(para('8S2WiF8i', 'The first sentence from Alpha.')), [blockIdRepairPlugin(), history()]);
    const end = world.state.doc.firstChild.nodeSize - 1;

    world.apply(world.state.tr.split(end).setNodeAttribute(end + 1, 'id', 'SSUk7s5E').setTime(1000));
    world.apply(world.state.tr.insertText('BetaWord', world.state.doc.content.size - 1).setTime(1100));
    assert.deepEqual(idsOf(world.state.doc), ['8S2WiF8i', 'SSUk7s5E']);
    assert.equal(undoDepth(world.state), 1);

    const alphas = doc(
        para('8S2WiF8i', 'Top line by Alpha.'),
        para('qcDWHF1c', 'The first sentence from Alpha.'),
        para('SSUk7s5E', 'BetaWord')
    );
    world.apply(remote(world.state, alphas));
    world.apply(clearHistoryTransaction(world.state));

    assert.equal(undo(world.state, world.apply), false);
    assert.ok(world.state.doc.eq(node(alphas)));
    assert.deepEqual(idsOf(world.state.doc), ['8S2WiF8i', 'qcDWHF1c', 'SSUk7s5E']);
});

test('the redo stack is emptied too', () => {
    const world = editorOf(MINE);

    world.apply(world.state.tr.insertText('!', 6));
    assert.equal(undo(world.state, world.apply), true);
    assert.equal(redoDepth(world.state), 1);

    const theirs = doc(para('ownerXX1', 'Hello'), para('tailXXX2', 'Tail, edited'));
    world.apply(remote(world.state, theirs));
    assert.equal(redoDepth(world.state), 1, 'the remote transaction alone leaves the redo stack as it was');

    world.apply(clearHistoryTransaction(world.state));

    assert.equal(redoDepth(world.state), 0);
    assert.equal(undoDepth(world.state), 0);
    assert.equal(redo(world.state, world.apply), false);
    assert.ok(world.state.doc.eq(node(theirs)));
});

test('an edit made after the remote change is undoable, and undoing it gives back exactly the remote document', () => {
    const world = afterTheirChange();
    world.apply(clearHistoryTransaction(world.state));

    world.apply(world.state.tr.insertText(' And mine.', world.state.doc.content.size - 1));
    assert.deepEqual(textsOf(world.state.doc), ['Above', 'Well, Hello', 'Tail And mine.']);
    assert.equal(undoDepth(world.state), 1);

    assert.equal(undo(world.state, world.apply), true);
    assert.ok(world.state.doc.eq(node(THEIRS)));
    assert.equal(undoDepth(world.state), 0);

    // And the history works on from there as usual.
    assert.equal(redo(world.state, world.apply), true);
    assert.deepEqual(textsOf(world.state.doc), ['Above', 'Well, Hello', 'Tail And mine.']);
});

test('a state with no history plugin gets no clearing transaction', () => {
    assert.equal(clearHistoryTransaction(EditorState.create({ doc: node(MINE) })), null);
    assert.equal(clearHistoryTransaction(EditorState.create({ doc: node(MINE), plugins: [blockIdRepairPlugin()] })), null);
});

test('the clearing transaction has no steps, stays out of the history and the autosave, and leaves the document and the selection alone', () => {
    const world = editorOf(MINE);
    world.apply(world.state.tr.insertText('!', 6));
    world.apply(world.state.tr.setSelection(TextSelection.create(world.state.doc, 2, 4)));
    const before = world.state;

    const tr = clearHistoryTransaction(before);

    assert.equal(tr.steps.length, 0);
    assert.equal(tr.docChanged, false);
    assert.equal(tr.selectionSet, false);
    assert.equal(tr.scrolledIntoView, false);
    assert.equal(tr.getMeta('addToHistory'), false);
    assert.equal(tr.getMeta('preventUpdate'), true);

    const after = before.apply(tr);

    assert.equal(after.doc, before.doc, 'the very same document node');
    assert.ok(after.selection.eq(before.selection));
    assert.equal(undoDepth(before), 1);
    assert.equal(undoDepth(after), 0);
});

// ───────────────────────────────────────────────────────── TipTap's own editor

/**
 * TipTap's editor with its real extension stack: StarterKit (UndoRedo, which
 * is prosemirror-history; TrailingNode), the real UniqueID as the bundle
 * configures it, and the id repair. No DOM is needed: an Editor built with
 * `element: null` is not mounted, and `editor.view.dispatch()` then runs
 * TipTap's own `dispatchTransaction`, the code that decides whether `update`
 * fires. An unmounted editor has no plugins yet; mounting attaches them with
 * exactly this reconfigure (`Editor.createView()`).
 */
function tiptapEditor(content) {
    const editor = new Editor({ element: null, extensions: [StarterKit, BlockIdRepair, BlockId], content });
    editor.view.updateState(editor.state.reconfigure({ plugins: editor.extensionManager.plugins }));

    const updates = [];
    editor.on('update', ({ transaction }) => updates.push(transaction));
    // Every dispatch, as [its own steps, the steps of each transaction plugins appended to it].
    const dispatches = [];
    editor.on('transaction', ({ transaction, appendedTransactions }) =>
        dispatches.push([transaction.steps.length, appendedTransactions.map((appended) => appended.steps.length)])
    );

    return { editor, updates, dispatches };
}

/**
 * What applyRemote() dispatches (resources/js/editor/index.js; it cannot be
 * loaded here, it needs a browser): the newer document, and directly after
 * it the transaction that empties the history.
 */
function dispatchAsApplyRemoteDoes(editor, json, { clearHistory = true } = {}) {
    const tr = remoteTransaction(editor.state, json, ReplaceStep);

    if (!tr.docChanged) {
        return;
    }

    tr.setMeta('addToHistory', false);
    tr.setMeta('preventUpdate', true);
    editor.view.dispatch(tr);

    const clearing = clearHistory ? clearHistoryTransaction(editor.state) : null;
    if (clearing !== null) {
        editor.view.dispatch(clearing);
    }
}

/** The same case as above, with TipTap's own Enter and its own UniqueID. */
function tiptapAfterTheirChange(options) {
    const { editor, updates } = tiptapEditor(MINE);

    assert.equal(editor.chain().setTextSelection(1).splitBlock().run(), true);
    const fresh = editor.state.doc.firstChild.attrs.id;
    assert.deepEqual(blocksOf(editor.state.doc), [
        [fresh, ''],
        ['ownerXX1', 'Hello'],
        ['tailXXX2', 'Tail'],
    ]);
    assert.equal(updates.length, 1, 'the writer own Enter fired update');
    assert.equal(editor.can().undo(), true);

    const theirs = doc(para(fresh, 'Above'), para('ownerXX1', 'Well, Hello'), para('tailXXX2', 'Tail'));
    dispatchAsApplyRemoteDoes(editor, theirs, options);
    assert.deepEqual(blocksOf(editor.state.doc), [
        [fresh, 'Above'],
        ['ownerXX1', 'Well, Hello'],
        ['tailXXX2', 'Tail'],
    ]);

    return { editor, updates, theirs: editor.schema.nodeFromJSON(theirs) };
}

test('in TipTap own editor, with the history kept, undo deletes the text that arrived', () => {
    const { editor } = tiptapAfterTheirChange({ clearHistory: false });

    assert.equal(editor.can().undo(), true);
    assert.equal(editor.commands.undo(), true);
    assert.deepEqual(textsOf(editor.state.doc), ['Hello', 'Tail']);

    editor.destroy();
});

test('in TipTap own editor, the two dispatches of applyRemote() leave nothing to undo and fire no update', () => {
    const { editor, updates, theirs } = tiptapAfterTheirChange();

    assert.equal(updates.length, 1, 'neither dispatch fired update, so the autosave is not armed');
    assert.equal(editor.can().undo(), false);
    assert.equal(editor.can().redo(), false);
    assert.equal(editor.commands.undo(), false);
    assert.ok(editor.state.doc.eq(theirs));

    // The writer's next edit fires update and is undoable, back to exactly
    // the document that arrived.
    editor.view.dispatch(editor.state.tr.insertText(' And mine.', editor.state.doc.content.size - 1));
    assert.equal(updates.length, 2);
    assert.equal(editor.can().undo(), true);
    assert.equal(editor.commands.undo(), true);
    assert.ok(editor.state.doc.eq(theirs));

    editor.destroy();
});

test('in TipTap own editor, plugins append to the remote dispatch and nothing to the clearing one, and both stacks end empty', () => {
    const { editor, updates, dispatches } = tiptapEditor(MINE);

    // Something to undo and something to redo.
    editor.view.dispatch(editor.state.tr.insertText('!', 6));
    editor.view.dispatch(editor.state.tr.insertText('?', 12).setTime(Date.now() + 5000));
    assert.equal(editor.commands.undo(), true);
    assert.equal(editor.can().undo(), true);
    assert.equal(editor.can().redo(), true);
    const before = { updates: updates.length, dispatches: dispatches.length };

    // The newer document ends in a rule, so TrailingNode appends an empty
    // paragraph inside the remote dispatch and UniqueID gives it an id.
    dispatchAsApplyRemoteDoes(
        editor,
        doc(para('ownerXX1', 'Hello!'), para('tailXXX2', 'Tail, edited'), { type: 'horizontalRule', attrs: { id: 'ruleXXX3' } })
    );

    assert.deepEqual(dispatches.slice(before.dispatches), [
        [1, [1, 1]],
        [0, []],
    ]);
    assert.equal(updates.length, before.updates, 'neither dispatch fired update');
    assert.equal(editor.can().undo(), false);
    assert.equal(editor.can().redo(), false);
    assert.deepEqual(textsOf(editor.state.doc), ['Hello!', 'Tail, edited', '', '']);
    assert.equal(editor.state.doc.lastChild.type.name, 'paragraph');

    editor.destroy();
});

test('in TipTap own editor, a remote document the editor already shows leaves the history alone', () => {
    const { editor, updates, dispatches } = tiptapEditor(MINE);

    editor.view.dispatch(editor.state.tr.insertText('!', 6));
    assert.equal(editor.can().undo(), true);

    dispatchAsApplyRemoteDoes(editor, editor.getJSON());

    assert.equal(editor.can().undo(), true);
    assert.equal(updates.length, 1);
    assert.equal(dispatches.length, 1, 'nothing was dispatched');

    editor.destroy();
});
