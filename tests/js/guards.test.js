import assert from 'node:assert/strict';
import test from 'node:test';

import { blockInsert, isInCaption } from '../../resources/js/editor/guards.js';

// The only thing the guard needs off a real Editor is isActive(). A caption
// holds inline content only, so a block inserted there splits the figure away
// from its media — and EVERY insert path (registry, palette, slash menu,
// toolbar button, keyboard shortcut, image picker) has to pass this guard.
const editorIn = (...activeNodes) => ({
    isActive: (name) => activeNodes.includes(name),
});

test('isInCaption reads the selection off the editor', () => {
    assert.equal(isInCaption(editorIn('caption', 'figure')), true);
    assert.equal(isInCaption(editorIn('paragraph')), false);
    assert.equal(isInCaption(editorIn()), false);
});

test('isInCaption tolerates no editor at all', () => {
    assert.equal(isInCaption(null), false);
    assert.equal(isInCaption(undefined), false);
    assert.equal(isInCaption({}), false);
});

test('a block insert with a caption selection refuses and never runs', () => {
    let ran = 0;
    const guarded = blockInsert(() => {
        ran += 1;

        return true;
    });

    assert.equal(guarded(editorIn('caption', 'figure')), false);
    assert.equal(ran, 0, 'the wrapped command must not run at all inside a caption');
});

test('a block insert outside a caption runs and keeps its return value', () => {
    const guarded = blockInsert(() => true);
    const refusing = blockInsert(() => false);

    assert.equal(guarded(editorIn('paragraph')), true);
    assert.equal(refusing(editorIn('paragraph')), false, "the wrapped command's own false survives");
});

test('a block insert passes its params straight through', () => {
    const seen = [];
    const guarded = blockInsert((editor, params) => {
        seen.push(params);

        return true;
    });

    guarded(editorIn('paragraph'), { kind: 'table' });
    guarded(editorIn('paragraph'));

    assert.deepEqual(seen, [{ kind: 'table' }, {}]);
});
