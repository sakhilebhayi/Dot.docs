import assert from 'node:assert/strict';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

// First: registers the resolve hook the project's extensions need (they
// import their neighbours without an extension, as Vite resolves them).
import { loadEditorModule } from './moduleLoader.js';

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import { NodeSelection, Selection, TextSelection } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { clearHistoryTransaction, remoteTransaction } from '../../resources/js/editor/sync/apply.js';
import { insertBlockTransaction } from '../../resources/js/editor/sync/insert.js';
import { mergeDocuments } from '../../resources/js/editor/sync/merge.js';

/**
 * The merge and Put it back in TipTap's own editor, with the project's own
 * extension stack: the id plugins, numbered headings, alignment, the
 * contents list and cross-references, section and page breaks, callouts,
 * columns, variables, tables and task lists. The figure and image nodes are
 * left out: their `src` rules belong to a browser upload. No DOM is needed:
 * an Editor built with `element: null` is not mounted, and
 * `editor.view.dispatch()` runs TipTap's own `dispatchTransaction`, the
 * code that decides whether `update` fires.
 *
 * index.js cannot be loaded here (it needs a browser), so the two things
 * it does with these modules are repeated: the two dispatches of
 * applyRemote() (as sync.history.test.js does) and the dispatch of
 * insertBlock().
 */
const { BlockId, BlockIdRepair, base62 } = await loadEditorModule('extensions/blockId.js');
const { HeadingNumbered } = await loadEditorModule('extensions/headingNumbered.js');
const { Align } = await loadEditorModule('extensions/textAlign.js');
const { DocTextStyle } = await loadEditorModule('extensions/textStyle.js');
const { Toc } = await loadEditorModule('extensions/toc.js');
const { CrossRef } = await loadEditorModule('extensions/crossRef.js');
const { PageBreak } = await loadEditorModule('extensions/pageBreak.js');
const { SectionBreak } = await loadEditorModule('extensions/sectionBreak.js');
const { Callout } = await loadEditorModule('extensions/callout.js');
const { Column, Columns } = await loadEditorModule('extensions/columns.js');
const { Variable } = await loadEditorModule('extensions/variable.js');
const { DocAttrs } = await loadEditorModule('extensions/docAttrs.js');

const FIXTURE = fileURLToPath(new URL('../fixtures/merge/editor-form.json', import.meta.url));

/** An editor as index.js builds it, less what needs a browser. */
function realEditor(content) {
    const editor = new Editor({
        element: null,
        extensions: [
            StarterKit.configure({ heading: false, link: { openOnClick: false, autolink: true } }),
            HeadingNumbered.configure({ levels: [1, 2, 3, 4, 5, 6] }),
            Align.configure({ types: ['heading', 'paragraph'] }),
            DocTextStyle,
            Table.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
            TaskList,
            TaskItem.configure({ nested: true }),
            Toc,
            CrossRef,
            PageBreak,
            SectionBreak,
            Callout,
            Columns,
            Column,
            Variable.configure({ vars: {} }),
            DocAttrs,
            BlockIdRepair,
            BlockId,
        ],
        content,
    });
    editor.view.updateState(editor.state.reconfigure({ plugins: editor.extensionManager.plugins }));

    const updates = [];
    editor.on('update', ({ transaction }) => updates.push(transaction));

    return { editor, updates };
}

const para = (id, text, attrs = {}) => ({
    type: 'paragraph',
    attrs: { id, ...attrs },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
const heading = (id, text, level = 1) => ({ type: 'heading', attrs: { id, level }, content: [{ type: 'text', text }] });
const rule = (id) => ({ type: 'horizontalRule', attrs: { id } });
const docOf = (...content) => ({ type: 'doc', content });

/** Every top-level block as [id, text]. */
const blocksOf = (docNode) => {
    const blocks = [];
    docNode.forEach((child) => blocks.push([child.attrs.id, child.textContent]));

    return blocks;
};
const idsOf = (docNode) => {
    const ids = [];
    docNode.descendants((node) => {
        if (node.attrs && node.attrs.id !== null && node.attrs.id !== undefined) {
            ids.push(node.attrs.id);
        }
    });

    return ids;
};
const allDifferent = (ids) => new Set(ids).size === ids.length;
const VALID_ID = /^[0-9A-Za-z]{8}$/;

/** What applyRemote() dispatches: the merged document, then the transaction that empties the history. */
function dispatchAsApplyRemoteDoes(editor, json) {
    const tr = remoteTransaction(editor.state, json, ReplaceStep);
    assert.notEqual(tr, null);
    if (!tr.docChanged) {
        return;
    }
    tr.setMeta('addToHistory', false);
    tr.setMeta('preventUpdate', true);
    editor.view.dispatch(tr);
    editor.view.dispatch(clearHistoryTransaction(editor.state));
}

/** What insertBlock() dispatches. */
function dispatchAsInsertBlockDoes(editor, json, options) {
    editor.view.dispatch(
        insertBlockTransaction(editor.state, json, { ...options, generateId: () => base62(8), Selection })
    );
}

// ───────────────────────────────────────────────────────── a merge in the real editor

test('a merge applied as block steps keeps the typist caret, keeps ids unique, carries the trailing paragraph and empties the history once', () => {
    const { editor, updates } = realEditor(docOf(heading('headXX01', 'Title'), para('paraXX01', 'Hello'), para('paraXX02', 'World')));
    const base = editor.getJSON();

    // The typist works at the end of the last paragraph. A JSON text node,
    // not a string: insertContent() parses a string as HTML through
    // window.DOMParser, which node does not have.
    editor.chain().setTextSelection(editor.state.doc.content.size - 1).insertContent({ type: 'text', text: ' B' }).run();
    assert.deepEqual(blocksOf(editor.state.doc), [
        ['headXX01', 'Title'],
        ['paraXX01', 'Hello'],
        ['paraXX02', 'World B'],
    ]);
    assert.equal(updates.length, 1);
    assert.equal(editor.can().undo(), true);

    // Somebody else edited the second paragraph and ended the document with a rule.
    const theirs = docOf(heading('headXX01', 'Title'), para('paraXX01', 'Hello A'), para('paraXX02', 'World'), rule('ruleXX01'));
    const result = mergeDocuments(editor.schema, { base, mine: editor.getJSON(), theirs });
    assert.notEqual(result, null);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);

    dispatchAsApplyRemoteDoes(editor, result.merged);

    // Their paragraph, my paragraph, their rule, and the editor's own trailing paragraph after it.
    const blocks = blocksOf(editor.state.doc);
    assert.deepEqual(blocks.slice(0, 4).map(([, text]) => text), ['Title', 'Hello A', 'World B', '']);
    assert.equal(editor.state.doc.childCount, 5);
    assert.equal(editor.state.doc.lastChild.type.name, 'paragraph');
    assert.equal(editor.state.doc.lastChild.content.size, 0);
    assert.ok(allDifferent(idsOf(editor.state.doc)), `ids unique, got ${idsOf(editor.state.doc).join(', ')}`);
    // The caret stayed at the end of the typist's paragraph.
    assert.equal(editor.state.selection.$from.parent.textContent, 'World B');
    assert.equal(editor.state.selection.$from.parentOffset, 'World B'.length);
    // Nothing to undo, no update fired, so the autosave was not armed.
    assert.equal(editor.can().undo(), false);
    assert.equal(editor.can().redo(), false);
    assert.equal(updates.length, 1);

    // The merged document was stored. A follow in disguise: merging what the
    // editor holds now against the stored document is no change and no loss,
    // and `theirs` carries the trailing paragraph, so it equals the editor's
    // own serialisation (settleIfBackAtConfirmed() stays reachable).
    const again = mergeDocuments(editor.schema, { base: JSON.parse(result.theirs), mine: editor.getJSON(), theirs: result.merged });
    assert.notEqual(again, null);
    assert.equal(again.changed, false);
    assert.deepEqual(again.lost, []);
    assert.equal(again.theirs, JSON.stringify(editor.getJSON()));

    editor.destroy();
});

test('a D1 loser caret lands at the end of the winner paragraph', () => {
    const { editor } = realEditor(docOf(para('paraXX01', 'Hello'), para('paraXX02', 'World')));
    const base = editor.getJSON();
    editor.chain().setTextSelection(6).insertContent({ type: 'text', text: ' B' }).run(); // a JSON text node: no DOM under node
    assert.equal(editor.state.doc.firstChild.textContent, 'Hello B');

    const result = mergeDocuments(editor.schema, {
        base,
        mine: editor.getJSON(),
        theirs: docOf(para('paraXX01', 'Hello A, at length'), para('paraXX02', 'World')),
    });
    assert.deepEqual(result.lost.map((loss) => [loss.id, loss.reason, loss.text]), [['paraXX01', 'both', 'Hello B']]);

    dispatchAsApplyRemoteDoes(editor, result.merged);

    assert.equal(editor.state.doc.firstChild.textContent, 'Hello A, at length');
    assert.equal(editor.state.selection.$from.parent.textContent, 'Hello A, at length');
    assert.equal(editor.state.selection.$from.parentOffset, 'Hello A, at length'.length);

    editor.destroy();
});

test('a merged document that carries one id twice, nested, is repaired by the editor on apply (the limit spec section 13 states)', () => {
    // The merge checks ids at the top level only. A draft merge that moves p1 into a list while theirs edited p1
    // keeps both copies: p1 at the top level and p1 inside the list item. The editor never holds one id twice.
    const { editor } = realEditor(docOf(para('paraXX01', 'One'), para('paraXX02', 'Two')));
    const merged = docOf(
        para('paraXX01', 'One, edited by them'),
        {
            type: 'bulletList',
            attrs: { id: 'listXX01' },
            content: [{ type: 'listItem', attrs: { id: 'itemXX01' }, content: [para('paraXX01', 'One, as I moved it')] }],
        },
        para('paraXX02', 'Two')
    );

    dispatchAsApplyRemoteDoes(editor, merged);

    const ids = idsOf(editor.state.doc);
    assert.ok(allDifferent(ids), `ids unique after the apply, got ${ids.join(', ')}`);
    assert.equal(editor.state.doc.firstChild.attrs.id, 'paraXX01', 'the top-level block, which the apply did not touch, keeps the id');
    assert.equal(editor.state.doc.textBetween(0, editor.state.doc.content.size, '|').includes('One, as I moved it'), true, 'and nothing is lost');

    editor.destroy();
});

// ───────────────────────────────────────────────────────── Put it back in the real editor

test('insertBlock: a both block gets a fresh id below the winner, a deleted block keeps its id where it was, and both fire update', () => {
    const { editor, updates } = realEditor(docOf(para('paraXX01', 'One'), para('paraXX03', 'Three')));

    // D2: the deleted paragraph returns with its own id, after its predecessor.
    dispatchAsInsertBlockDoes(editor, para('paraXX02', 'Two, typed'), { anchors: ['paraXX01'], keepId: true });
    assert.deepEqual(blocksOf(editor.state.doc), [
        ['paraXX01', 'One'],
        ['paraXX02', 'Two, typed'],
        ['paraXX03', 'Three'],
    ]);
    assert.equal(updates.length, 1, 'an ordinary edit: update fires and the autosave runs');
    assert.equal(editor.can().undo(), true, 'and it is undoable');
    assert.equal(editor.state.selection.$from.parent.textContent, 'Two, typed');
    assert.equal(editor.state.selection.$from.parentOffset, 'Two, typed'.length, 'the caret is at the end of the block put back');

    // D1: my version of a paragraph goes below the winner, with a fresh id.
    dispatchAsInsertBlockDoes(editor, para('paraXX01', 'One B'), { anchors: ['paraXX01'], keepId: false });
    const ids = idsOf(editor.state.doc);
    assert.deepEqual(blocksOf(editor.state.doc).map(([, text]) => text), ['One', 'One B', 'Two, typed', 'Three']);
    assert.notEqual(ids[1], 'paraXX01');
    assert.match(ids[1], VALID_ID);
    assert.ok(allDifferent(ids));
    assert.equal(updates.length, 2);

    // keepId with an id the page holds again: a fresh one all the same.
    dispatchAsInsertBlockDoes(editor, para('paraXX03', 'Three again'), { anchors: ['paraXX03'], keepId: true });
    const after = idsOf(editor.state.doc);
    assert.equal(after.filter((id) => id === 'paraXX03').length, 1);
    assert.ok(allDifferent(after));

    editor.destroy();
});

test('insertBlock: nested ids are fresh too, anchors all gone put the block at the end, and no anchors put it at the start', () => {
    const { editor } = realEditor(docOf(para('paraXX01', 'One')));
    const list = {
        type: 'bulletList',
        attrs: { id: 'listXX01' },
        content: [
            { type: 'listItem', attrs: { id: 'itemXX01' }, content: [para('ipara001', 'Item')] },
        ],
    };

    dispatchAsInsertBlockDoes(editor, list, { anchors: ['goneXX01', 'goneXX02'], keepId: false });
    // At the end of the document; StarterKit's TrailingNode appends the editor's own empty paragraph after a list.
    const types = [];
    editor.state.doc.forEach((child) => types.push(child.type.name));
    assert.deepEqual(types, ['paragraph', 'bulletList', 'paragraph']);
    const ids = idsOf(editor.state.doc);
    assert.ok(ids.every((id) => VALID_ID.test(id)), ids.join(', '));
    assert.ok(!ids.includes('listXX01') && !ids.includes('itemXX01') && !ids.includes('ipara001'));
    assert.ok(allDifferent(ids));

    dispatchAsInsertBlockDoes(editor, para('topXXX01', 'Top'), { anchors: [], keepId: true });
    assert.equal(editor.state.doc.firstChild.attrs.id, 'topXXX01');
    assert.equal(editor.state.doc.firstChild.textContent, 'Top');

    editor.destroy();
});

test('insertBlock refuses a block this schema cannot hold, and leaves the document alone', () => {
    const { editor, updates } = realEditor(docOf(para('paraXX01', 'One')));

    assert.throws(() =>
        insertBlockTransaction(editor.state, { type: 'marquee', attrs: { id: 'marqXX01' } }, {
            anchors: ['paraXX01'],
            keepId: true,
            generateId: () => base62(8),
            Selection,
        })
    );
    assert.deepEqual(blocksOf(editor.state.doc), [['paraXX01', 'One']]);
    assert.equal(updates.length, 0);

    editor.destroy();
});

/** A generator that hands out these ids in turn, then fails loudly. */
const idsInTurn = (...ids) => {
    const queue = [...ids];

    return () => {
        assert.ok(queue.length > 0, 'the generator was asked for more ids than the test expected');

        return queue.shift();
    };
};
const putBack = (editor, json, options) =>
    insertBlockTransaction(editor.state, json, { anchors: [], keepId: false, Selection, ...options });

test('insertBlockTransaction decides the ids in tr.doc BEFORE dispatch: the editor repair plugins would hide a wrong rule', () => {
    // The dispatch tests above run TipTap's UniqueID and BlockIdRepair, which re-id a duplicate and so mask a
    // broken rule in insert.js. The transaction's own document is what insert.js decided.
    const { editor } = realEditor(docOf(para('paraXX01', 'One'), para('paraXX03', 'Three')));

    // D1 (keepId false): a fresh id, below the winner; the winner keeps its id.
    const both = putBack(editor, para('paraXX01', 'One B'), { anchors: ['paraXX01'], generateId: idsInTurn('freshAA1') });
    assert.deepEqual(blocksOf(both.doc), [
        ['paraXX01', 'One'],
        ['freshAA1', 'One B'],
        ['paraXX03', 'Three'],
    ]);

    // D2 (keepId true) with an id nobody holds: it comes back with its own id.
    const gone = putBack(editor, para('paraXX02', 'Two, typed'), {
        anchors: ['paraXX01'],
        keepId: true,
        generateId: idsInTurn(),
    });
    assert.deepEqual(blocksOf(gone.doc), [
        ['paraXX01', 'One'],
        ['paraXX02', 'Two, typed'],
        ['paraXX03', 'Three'],
    ]);

    // D2 with an id the page holds again: a fresh one, so no id is shared.
    const taken = putBack(editor, para('paraXX03', 'Three again'), {
        anchors: ['paraXX03'],
        keepId: true,
        generateId: idsInTurn('freshBB2'),
    });
    assert.deepEqual(blocksOf(taken.doc), [
        ['paraXX01', 'One'],
        ['paraXX03', 'Three'],
        ['freshBB2', 'Three again'],
    ]);
    assert.ok(allDifferent(idsOf(taken.doc)));

    // Nested ids follow the same rule: the free one stays, the taken one is replaced.
    const list = {
        type: 'bulletList',
        attrs: { id: 'listXX01' },
        content: [{ type: 'listItem', attrs: { id: 'itemXX01' }, content: [para('paraXX01', 'Nested')] }],
    };
    const nested = putBack(editor, list, { anchors: [], keepId: true, generateId: idsInTurn('freshCC3') });
    assert.deepEqual(idsOf(nested.doc), ['listXX01', 'itemXX01', 'freshCC3', 'paraXX01', 'paraXX03']);

    // A generator that keeps offering a taken id leaves the block without an id (UniqueID fills it in); one that
    // offers the same new id twice is asked again.
    const stubborn = putBack(editor, para('paraXX01', 'x'), { anchors: [], generateId: () => 'paraXX03' });
    assert.equal(stubborn.doc.firstChild.attrs.id, null);
    const twice = putBack(editor, list, {
        anchors: [],
        generateId: idsInTurn('dupXXXX1', 'dupXXXX1', 'dupXXXX2', 'dupXXXX3'),
    });
    assert.deepEqual(idsOf(twice.doc).slice(0, 3), ['dupXXXX1', 'dupXXXX2', 'dupXXXX3'], 'the repeated id is asked for again');

    editor.destroy();
});

test('putting back an atom (rule, page break, section break, contents list) leaves a caret after it, never a selection on it', () => {
    const atoms = [
        ['horizontalRule', rule('atomXX01')],
        ['pageBreak', { type: 'pageBreak', attrs: { id: 'atomXX01' } }],
        ['sectionBreak', { type: 'sectionBreak', attrs: { id: 'atomXX01', setup: {} } }],
        ['toc', { type: 'toc', attrs: { id: 'atomXX01', depth: 3, entries: [] } }],
    ];

    for (const [type, json] of atoms) {
        // Between two paragraphs: the caret is at the start of the one after, so typing goes there and the atom stays.
        const { editor } = realEditor(docOf(para('paraXX01', 'One'), para('paraXX03', 'Three')));
        dispatchAsInsertBlockDoes(editor, json, { anchors: ['paraXX01'], keepId: true });

        const types = [];
        editor.state.doc.forEach((child) => types.push(child.type.name));
        assert.deepEqual(types, ['paragraph', type, 'paragraph'], type);
        assert.ok(!(editor.state.selection instanceof NodeSelection), `${type}: not a NodeSelection on the restored atom`);
        assert.ok(editor.state.selection instanceof TextSelection, `${type}: a text caret`);
        assert.equal(editor.state.selection.$from.parent.attrs.id, 'paraXX03', `${type}: after the atom`);
        assert.equal(editor.state.selection.$from.parentOffset, 0, type);

        // Typing at the caret leaves the atom where it is.
        editor.chain().insertContent({ type: 'text', text: 'x' }).run();
        assert.deepEqual(blocksOf(editor.state.doc).map(([, text]) => text), ['One', '', 'xThree'], type);
        editor.destroy();

        // Nothing but an atom can follow: the caret still is not a selection on it (it stays in the paragraph before).
        const last = realEditor(docOf(para('paraXX01', 'One')));
        dispatchAsInsertBlockDoes(last.editor, json, { anchors: ['paraXX01'], keepId: true });
        assert.ok(!(last.editor.state.selection instanceof NodeSelection), `${type} at the end: not a NodeSelection`);
        assert.ok(last.editor.state.selection instanceof TextSelection, `${type} at the end: a text caret`);
        last.editor.destroy();
    }
});

test('insertBlockTransaction refuses a block the schema accepts but check() does not, and the document is untouched', () => {
    const { editor, updates } = realEditor(docOf(para('paraXX01', 'One')));

    // nodeFromJSON() builds a list with no item without a word; only check() knows a list needs one.
    const empty = { type: 'bulletList', attrs: { id: 'listXX01' }, content: [] };
    assert.doesNotThrow(() => editor.schema.nodeFromJSON(empty));
    assert.throws(() => putBack(editor, empty, { anchors: ['paraXX01'], generateId: idsInTurn('freshDD4') }), /Invalid content/);
    assert.deepEqual(blocksOf(editor.state.doc), [['paraXX01', 'One']]);
    assert.equal(updates.length, 0);

    editor.destroy();
});

// ───────────────────────────────────────────────────────── which block conversions keep the id, and which give a new one

test('toggleHeading and toggleCodeBlock keep the block id, so a merge sees one changed block and not a deletion plus an insertion', () => {
    // Section 13 of the spec names this as a limit to pin. If a row ever
    // fails, TipTap re-ids the block on that conversion: move the row to
    // the wrapping test below, keep both tests, and the limit stands as
    // written.
    for (const [command, args, type] of [
        ['toggleHeading', [{ level: 2 }], 'heading'],
        ['toggleCodeBlock', [], 'codeBlock'],
    ]) {
        const { editor } = realEditor(docOf(para('paraXX01', 'Becomes converted'), para('paraXX02', 'Stays')));

        assert.equal(editor.chain().setTextSelection(2)[command](...args).run(), true, command);

        assert.equal(editor.state.doc.firstChild.type.name, type, command);
        assert.equal(editor.state.doc.firstChild.attrs.id, 'paraXX01', command);
        assert.deepEqual(idsOf(editor.state.doc), ['paraXX01', 'paraXX02'], command);

        editor.destroy();
    }
});

test('wrapping conversions give the block a NEW top-level id, the paragraph id moves inside it: a merge sees a deletion plus an insertion (a stated limit)', () => {
    for (const [command, type] of [
        ['toggleBulletList', 'bulletList'],
        ['toggleOrderedList', 'orderedList'],
        ['toggleTaskList', 'taskList'],
        ['toggleBlockquote', 'blockquote'],
    ]) {
        const { editor } = realEditor(docOf(para('paraXX01', 'Becomes wrapped'), para('paraXX02', 'Stays')));

        assert.equal(editor.chain().setTextSelection(2)[command]().run(), true, command);
        assert.equal(editor.state.doc.firstChild.type.name, type, command);
        assert.notEqual(editor.state.doc.firstChild.attrs.id, 'paraXX01', `${command} re-ids the top-level block`);
        assert.ok(idsOf(editor.state.doc).includes('paraXX01'), `${command}: the paragraph id is still there, nested`);

        editor.destroy();
    }
});

// ───────────────────────────────────────────────────────── the real fixture: this editor's serialisation

/** One of everything the editor stack above can hold, with the ids the server accepts. */
const FIXTURE_DOC = {
    type: 'doc',
    attrs: { schema: 1, style: 'report', vars: {} },
    content: [
        { type: 'heading', attrs: { id: 'fxHead01', level: 1, numbered: true }, content: [{ type: 'text', text: 'Introduction' }] },
        {
            type: 'paragraph',
            attrs: { id: 'fxPara01' },
            content: [
                { type: 'text', text: 'See ' },
                { type: 'crossRef', attrs: { kind: 'heading', targetId: 'fxHead01', label: null } },
                { type: 'text', text: ' and ' },
                { type: 'variable', attrs: { key: 'client' } },
                { type: 'text', text: ', ' },
                { type: 'text', marks: [{ type: 'bold' }], text: 'in bold' },
                { type: 'text', text: '.' },
            ],
        },
        { type: 'toc', attrs: { id: 'fxTocc01', depth: 3, entries: [] } },
        {
            type: 'bulletList',
            attrs: { id: 'fxList01' },
            content: [
                { type: 'listItem', attrs: { id: 'fxItem01' }, content: [para('fxIpar01', 'First')] },
                { type: 'listItem', attrs: { id: 'fxItem02' }, content: [para('fxIpar02', 'Second')] },
            ],
        },
        {
            type: 'orderedList',
            attrs: { id: 'fxOlst01' },
            content: [{ type: 'listItem', attrs: { id: 'fxOitm01' }, content: [para('fxOpar01', 'Step one')] }],
        },
        {
            type: 'taskList',
            attrs: { id: 'fxTask01' },
            content: [{ type: 'taskItem', attrs: { id: 'fxTitm01', checked: true }, content: [para('fxTpar01', 'Done')] }],
        },
        { type: 'blockquote', attrs: { id: 'fxQuot01' }, content: [para('fxQpar01', 'Quoted')] },
        { type: 'codeBlock', attrs: { id: 'fxCode01' }, content: [{ type: 'text', text: 'const x = 1;' }] },
        { type: 'horizontalRule', attrs: { id: 'fxRule01' } },
        {
            type: 'table',
            attrs: { id: 'fxTabl01' },
            content: [
                {
                    type: 'tableRow',
                    attrs: { id: 'fxTrow01' },
                    content: [
                        { type: 'tableHeader', attrs: { id: 'fxThdr01' }, content: [para('fxHpar01', 'Head')] },
                        { type: 'tableHeader', attrs: { id: 'fxThdr02' }, content: [para('fxHpar02', 'Head 2')] },
                    ],
                },
                {
                    type: 'tableRow',
                    attrs: { id: 'fxTrow02' },
                    content: [
                        { type: 'tableCell', attrs: { id: 'fxTcel01' }, content: [para('fxCpar01', 'Cell')] },
                        { type: 'tableCell', attrs: { id: 'fxTcel02' }, content: [para('fxCpar02', 'Cell 2')] },
                    ],
                },
            ],
        },
        { type: 'callout', attrs: { id: 'fxCall01', tone: 'note' }, content: [para('fxCapa01', 'Note this')] },
        {
            type: 'columns',
            attrs: { id: 'fxCols01', count: 2 },
            content: [
                { type: 'column', attrs: { id: 'fxColm01' }, content: [para('fxC1pa01', 'Left')] },
                { type: 'column', attrs: { id: 'fxColm02' }, content: [para('fxC2pa01', 'Right')] },
            ],
        },
        { type: 'sectionBreak', attrs: { id: 'fxSect01', setup: {} } },
        { type: 'pageBreak', attrs: { id: 'fxPage01' } },
        para('fxPara02', 'Centred end.', { align: 'center' }),
    ],
};

test('editor-form.json is what this editor serialises for the fixture document', () => {
    const { editor } = realEditor(FIXTURE_DOC);
    // A plain copy: Node.toJSON() builds attrs with Object.create(null), which
    // strict deepEqual tells apart from the parsed file.
    const captured = JSON.parse(JSON.stringify(editor.getJSON()));

    assert.equal(captured.content.length, FIXTURE_DOC.content.length, 'no block was dropped or added on the way in');
    assert.deepEqual(
        captured.content.map((block) => block.attrs.id),
        FIXTURE_DOC.content.map((block) => block.attrs.id)
    );

    if (!existsSync(FIXTURE) || process.env.MERGE_FIXTURE_WRITE) {
        writeFileSync(FIXTURE, `${JSON.stringify(captured, null, 2)}\n`);
        console.info(`[fixture] wrote ${FIXTURE}; run MergeFixtureTest to produce stored-form.json`);
    }

    assert.deepEqual(JSON.parse(readFileSync(FIXTURE, 'utf8')), captured);

    editor.destroy();
});

// ───────────────────────────────────────────────────────── the real pair: the editor's form against the server's

const STORED = fileURLToPath(new URL('../fixtures/merge/stored-form.json', import.meta.url));

test(
    'the stored form merges with the editor form as no change and no loss, both ways round, and a real edit stays mine',
    { skip: !existsSync(STORED) && 'stored-form.json is produced by MergeFixtureTest: php artisan test --compact tests/Feature/Documents/MergeFixtureTest.php' },
    () => {
        const editorForm = JSON.parse(readFileSync(FIXTURE, 'utf8'));
        const storedForm = JSON.parse(readFileSync(STORED, 'utf8'));
        const { editor } = realEditor(editorForm);
        const schema = editor.schema;

        // The page typed nothing; the server re-stamped and re-serialised. A follow: nothing of mine.
        const stored = mergeDocuments(schema, { base: editorForm, mine: editorForm, theirs: storedForm });
        assert.notEqual(stored, null);
        assert.equal(stored.changed, false, 'a canonicalisation difference is not a change');
        assert.deepEqual(stored.lost, []);
        assert.equal(stored.overwrites, false);

        // The other way round: a tab that loaded the stored form against the editor's form.
        const back = mergeDocuments(schema, { base: storedForm, mine: storedForm, theirs: editorForm });
        assert.notEqual(back, null);
        assert.equal(back.changed, false);
        assert.deepEqual(back.lost, []);

        // The writer edited ONE paragraph after the server stored the document: only that block is mine,
        // and the stamps the server wrote are on screen.
        const typed = JSON.parse(JSON.stringify(editorForm));
        typed.content[typed.content.length - 1].content = [{ type: 'text', text: 'Centred end, and more.' }];
        const edited = mergeDocuments(schema, { base: editorForm, mine: typed, theirs: storedForm });
        assert.notEqual(edited, null);
        assert.equal(edited.changed, true);
        assert.deepEqual(edited.lost, []);
        assert.equal(edited.merged.content.at(-1).content[0].text, 'Centred end, and more.');
        assert.deepEqual(edited.merged.content[2].attrs.entries, storedForm.content[2].attrs.entries, 'the stamped contents list is on screen');

        // The writer also edited the paragraph that holds the cross-reference (the server stamped its label) and
        // the contents list (the server stamped its entries): both blocks are mine, and nothing is offered back.
        const stamped = JSON.parse(JSON.stringify(editorForm));
        stamped.content[1].content.push({ type: 'text', text: ' (checked)' });
        stamped.content[2].attrs.depth = 2;
        const stampedMerge = mergeDocuments(schema, { base: editorForm, mine: stamped, theirs: storedForm });
        assert.notEqual(stampedMerge, null);
        assert.deepEqual(stampedMerge.lost, [], 'a server stamp inside a block the writer edited is not a change by somebody else');
        assert.ok(stampedMerge.merged.content[1].content.at(-1).text.endsWith(' (checked)'));
        assert.equal(stampedMerge.merged.content[2].attrs.depth, 2);

        editor.destroy();
    }
);
