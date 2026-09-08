import assert from 'node:assert/strict';
import test from 'node:test';

import { contentErrors, isContentValid, isEmptyDocument } from '../../resources/js/editor/validation.js';

// The names the editor bundle actually registers, trimmed to what these
// cases need. `textStyle` is in the list because DocumentSchema::MARKS has
// it — the whole point of the check is that a mark the editor forgets to
// register must be caught, not swallowed.
const SCHEMA = {
    nodes: ['doc', 'paragraph', 'heading', 'text', 'figure', 'caption', 'image', 'columns', 'column'],
    marks: ['bold', 'italic', 'highlight', 'textStyle'],
};

const doc = (...content) => ({ type: 'doc', content });
const para = (...content) => ({ type: 'paragraph', attrs: { id: 'aaaaaaaa' }, content });

test('a document built only from registered nodes and marks is valid', () => {
    const json = doc(
        para({ type: 'text', text: 'Hello', marks: [{ type: 'bold' }] }),
        { type: 'heading', attrs: { id: 'bbbbbbbb', level: 1 }, content: [{ type: 'text', text: 'Title' }] }
    );

    assert.deepEqual(contentErrors(json, SCHEMA), []);
    assert.equal(isContentValid(json, SCHEMA), true);
});

test('an unknown node type is reported, not swallowed', () => {
    const json = doc({ type: 'mermaidDiagram', attrs: { id: 'cccccccc' } });

    assert.equal(isContentValid(json, SCHEMA), false);
    assert.deepEqual(contentErrors(json, SCHEMA), ['Unknown node type mermaidDiagram']);
});

test('an unknown mark is reported — this is the empty-document failsafe', () => {
    // TipTap's createNodeFromContent swallows exactly this and hands back an
    // EMPTY doc; the next keystroke would then autosave the blank page over
    // the real content.
    const json = doc(para({ type: 'text', text: 'Coloured', marks: [{ type: 'textStyle', attrs: { color: '#ff0000' } }] }));

    assert.equal(isContentValid(json, SCHEMA), true);
    assert.equal(isContentValid(json, { nodes: SCHEMA.nodes, marks: ['bold'] }), false);
    assert.deepEqual(contentErrors(json, { nodes: SCHEMA.nodes, marks: ['bold'] }), ['Unknown mark type textStyle']);
});

test('nested content is walked, and each unknown name is reported once', () => {
    const json = doc({
        type: 'figure',
        attrs: { id: 'dddddddd', kind: 'image' },
        content: [
            { type: 'video', attrs: { id: 'eeeeeeee' } },
            { type: 'caption', attrs: { id: 'ffffffff' }, content: [{ type: 'video' }] },
        ],
    });

    assert.deepEqual(contentErrors(json, SCHEMA), ['Unknown node type video']);
});

test('a non-doc root and a non-object document are both rejected', () => {
    assert.deepEqual(contentErrors({ type: 'paragraph' }, SCHEMA), ['Root must be doc, got paragraph']);
    assert.deepEqual(contentErrors(null, SCHEMA), ['Document is not an object']);
    assert.deepEqual(contentErrors('<p>legacy html</p>', SCHEMA), ['Document is not an object']);
});

test('a schema given as a ProseMirror-style object of names is accepted', () => {
    const json = doc(para({ type: 'text', text: 'Hi' }));

    assert.equal(isContentValid(json, { nodes: { doc: {}, paragraph: {}, text: {} }, marks: {} }), true);
});

test('with no schema, only structural problems are reported', () => {
    assert.deepEqual(contentErrors(doc({ type: 'anything' }), {}), []);
    assert.deepEqual(contentErrors(doc({ notAType: true }), {}), ['Node has no type']);
});

// `doc` is `block+`, so ProseMirror cannot return a document with no children:
// HTML it drops entirely (a lone <script>, a stray <div>) comes back as ONE
// EMPTY PARAGRAPH. An AI "replace" that accepted that would erase the document.
test('a document of empty paragraphs is empty however many there are', () => {
    assert.equal(isEmptyDocument({ type: 'doc', content: [{ type: 'paragraph' }] }), true);
    assert.equal(isEmptyDocument({ type: 'doc', content: [{ type: 'paragraph', content: [] }, { type: 'paragraph' }] }), true);
    assert.equal(
        isEmptyDocument({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: '   \n ' }] }] }),
        true
    );
});

test('no content at all, or no document at all, is empty', () => {
    assert.equal(isEmptyDocument({ type: 'doc', content: [] }), true);
    assert.equal(isEmptyDocument({ type: 'doc' }), true);
    assert.equal(isEmptyDocument(null), true);
    assert.equal(isEmptyDocument('<p>a</p>'), true);
});

test('any real text makes a document non-empty, however deep', () => {
    assert.equal(isEmptyDocument({ type: 'doc', content: [{ type: 'heading', content: [{ type: 'text', text: 'Hi' }] }] }), false);
    assert.equal(
        isEmptyDocument({
            type: 'doc',
            content: [
                {
                    type: 'bulletList',
                    content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x' }] }] }],
                },
            ],
        }),
        false
    );
});

test('nodes that speak without text count as content', () => {
    assert.equal(isEmptyDocument({ type: 'doc', content: [{ type: 'image', attrs: { src: '/storage/a.png' } }] }), false);
    assert.equal(isEmptyDocument({ type: 'doc', content: [{ type: 'pageBreak' }] }), false);
    assert.equal(
        isEmptyDocument({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'crossRef', attrs: { targetId: 'h1' } }] }] }),
        false
    );
});
