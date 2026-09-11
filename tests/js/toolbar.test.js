import assert from 'node:assert/strict';
import test from 'node:test';

// The task brief writes this test against `ui/bubble.js`. Node cannot load
// that file: it imports the command registry with an extensionless specifier
// (which only Vite resolves) and the registry pulls in @tiptap/core, so
// `node --test` dies with ERR_MODULE_NOT_FOUND before a single case runs. The
// project's own rule for this (.ai/rules/editor.md) is that anything the test
// runner has to reach lives in a dependency-free module — attrs.js, guards.js
// and validation.js are all shaped this way. So the decision itself lives
// here, and `ui/bubble.js` re-exports both names verbatim, which is the
// interface the brief actually promises ("bubble.js exports
// toolbarVariantFor(selectionShape)").
import { selectionShape, toolbarVariantFor } from '../../resources/js/editor/ui/toolbarVariant.js';

test('text selection maps to the text toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'text' }), 'text');
});

test('an image or figure selection maps to the image toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'image' }), 'image');
    assert.equal(toolbarVariantFor({ type: 'figure' }), 'image');
});

test('a cell selection inside a table maps to the table toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'tableCell' }), 'table');
    assert.equal(toolbarVariantFor({ type: 'tableHeader' }), 'table');
});

test('a heading selection maps to the heading toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'heading' }), 'heading');
});

test('an unrecognised or empty selection maps to no toolbar', () => {
    assert.equal(toolbarVariantFor({ type: 'paragraph' }), null);
    assert.equal(toolbarVariantFor(null), null);
    assert.equal(toolbarVariantFor(undefined), null);
    assert.equal(toolbarVariantFor({}), null);
    assert.equal(toolbarVariantFor('text'), null);
});

/*
 * The shape itself. `selectionShape` is the half that reads a ProseMirror
 * selection, described here as plain data so the precedence rules are
 * measurable: which node a NodeSelection is ON, the node types enclosing the
 * selection head innermost-first, and whether the selection is a bare caret.
 */

test('a node selection on an image or a figure is that media, whatever encloses it', () => {
    assert.deepEqual(
        selectionShape({ nodeType: 'image', ancestors: ['figure', 'doc'], empty: false }),
        { type: 'image' },
    );
    assert.deepEqual(selectionShape({ nodeType: 'figure', ancestors: ['doc'], empty: false }), {
        type: 'figure',
    });
});

test('a node selection on anything else gets no toolbar', () => {
    assert.equal(selectionShape({ nodeType: 'pageBreak', ancestors: ['doc'], empty: false }), null);
    assert.equal(selectionShape({ nodeType: 'toc', ancestors: ['doc'], empty: false }), null);
});

test('a bare caret inside a cell still asks for the table toolbar', () => {
    // The table tools act on the row and column the CURSOR is in. Making a
    // writer select something first to reach them is a worse tool, so this is
    // the one shape that answers for an empty selection.
    assert.deepEqual(
        selectionShape({ ancestors: ['paragraph', 'tableCell', 'tableRow', 'table', 'doc'], empty: true }),
        { type: 'tableCell' },
    );
    assert.deepEqual(
        selectionShape({ ancestors: ['paragraph', 'tableHeader', 'tableRow', 'table', 'doc'], empty: true }),
        { type: 'tableHeader' },
    );
});

test('a bare caret anywhere else gets no toolbar', () => {
    assert.equal(selectionShape({ ancestors: ['paragraph', 'doc'], empty: true }), null);
    assert.equal(selectionShape({ ancestors: ['heading', 'doc'], empty: true }), null);
    assert.equal(selectionShape(), null);
});

test('a selection whose head sits in a heading is a heading, not plain text', () => {
    assert.deepEqual(selectionShape({ ancestors: ['heading', 'doc'], empty: false }), {
        type: 'heading',
    });
});

test('a selection in ordinary prose — a paragraph, a caption, a list — is text', () => {
    assert.deepEqual(selectionShape({ ancestors: ['paragraph', 'doc'], empty: false }), {
        type: 'text',
    });
    assert.deepEqual(selectionShape({ ancestors: ['caption', 'figure', 'doc'], empty: false }), {
        type: 'text',
    });
    assert.deepEqual(
        selectionShape({ ancestors: ['paragraph', 'listItem', 'bulletList', 'doc'], empty: false }),
        { type: 'text' },
    );
});

test('the shape a selection reports is a shape the variant map understands', () => {
    // The two halves are one contract: every shape `selectionShape` can
    // produce has to be a shape `toolbarVariantFor` recognises, or the toolbar
    // silently stops appearing for it.
    const shapes = [
        selectionShape({ nodeType: 'image', ancestors: ['doc'], empty: false }),
        selectionShape({ nodeType: 'figure', ancestors: ['doc'], empty: false }),
        selectionShape({ ancestors: ['paragraph', 'tableCell', 'table', 'doc'], empty: true }),
        selectionShape({ ancestors: ['paragraph', 'tableHeader', 'table', 'doc'], empty: true }),
        selectionShape({ ancestors: ['heading', 'doc'], empty: false }),
        selectionShape({ ancestors: ['paragraph', 'doc'], empty: false }),
    ];

    shapes.forEach((shape) => {
        assert.notEqual(toolbarVariantFor(shape), null, `no variant for ${JSON.stringify(shape)}`);
    });
});
