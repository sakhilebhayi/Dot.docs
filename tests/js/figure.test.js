import assert from 'node:assert/strict';
import test from 'node:test';

import { figureMediaIsBlank } from '../../resources/js/editor/attrs.js';

// figure.js's appendTransaction asks this of every figure's first child.
// `figure` is `(image | table) caption`, so when the writer deletes the
// picture ProseMirror cannot leave the slot empty — it refills it with an
// `image` carrying no src, and the writer is left with a numbered,
// captioned figure showing nothing.
const pmNode = (name, attrs = {}) => ({ type: { name }, attrs });

test('an image with no src is the hole ProseMirror leaves behind', () => {
    assert.equal(figureMediaIsBlank(pmNode('image', { src: null })), true);
    assert.equal(figureMediaIsBlank(pmNode('image', { src: undefined })), true);
    assert.equal(figureMediaIsBlank(pmNode('image', { src: '' })), true);
    assert.equal(figureMediaIsBlank(pmNode('image')), true);
});

test('an image that still has its picture is left alone', () => {
    assert.equal(figureMediaIsBlank(pmNode('image', { src: '/storage/document-images/a.png' })), false);
    assert.equal(figureMediaIsBlank(pmNode('image', { src: 'https://cdn.example.com/a.png' })), false);

    // A src the renderer would reject is the writer's data to fix, not a
    // reason to delete their figure.
    assert.equal(figureMediaIsBlank(pmNode('image', { src: 'data:image/png;base64,AAAA' })), false);
});

test('a table figure is never treated as blank media', () => {
    assert.equal(figureMediaIsBlank(pmNode('table')), false);
    assert.equal(figureMediaIsBlank(pmNode('caption')), false);
    assert.equal(figureMediaIsBlank(pmNode('paragraph', { src: null })), false);
});

test('a missing child is not a blank image', () => {
    // node.firstChild is null on an (impossible, but cheap to guard) empty
    // figure; the repair pass must not fire on it.
    assert.equal(figureMediaIsBlank(null), false);
    assert.equal(figureMediaIsBlank(undefined), false);
});

test('plain JSON works too, so the same check reads a stored document', () => {
    assert.equal(figureMediaIsBlank({ type: 'image', attrs: { src: null } }), true);
    assert.equal(figureMediaIsBlank({ type: 'image', attrs: { src: '/images/a.png' } }), false);
});
