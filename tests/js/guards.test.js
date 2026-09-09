import assert from 'node:assert/strict';
import test from 'node:test';

import { blockInsert, blockInsertPosition, isInCaption } from '../../resources/js/editor/guards.js';

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

// blockInsertPosition() is the guard run a SECOND time, at the moment an
// asynchronous insert (the image upload) actually lands. All it needs off a
// real editor is a resolved position: the ancestor chain at that point and
// where each ancestor ends. `after` here is the depth's end position, which
// is what ProseMirror's ResolvedPos.after(depth) returns.
const editorResolving = (size, chains) => ({
    state: {
        doc: {
            content: { size },
            resolve: (pos) => {
                const chain = chains[pos];
                if (!chain) {
                    throw new RangeError('Position ' + pos + ' out of range');
                }

                return {
                    depth: chain.names.length - 1,
                    node: (depth) => ({ type: { name: chain.names[depth] } }),
                    after: (depth) => chain.after[depth],
                };
            },
        },
    },
});

test('blockInsertPosition keeps a position that is still in open document flow', () => {
    const editor = editorResolving(120, { 12: { names: ['doc', 'paragraph'], after: [120, 20] } });

    assert.equal(blockInsertPosition(editor, 12), 12);
});

test('blockInsertPosition moves an insert that drifted into a caption to after the figure', () => {
    // doc > figure > caption: the writer clicked into the caption while the
    // upload was in flight. Inserting there would tear the figure apart.
    const editor = editorResolving(120, { 30: { names: ['doc', 'figure', 'caption'], after: [120, 44, 43] } });

    assert.equal(blockInsertPosition(editor, 30), 44, 'lands after the figure, never inside it');
});

test('blockInsertPosition moves an insert that drifted next to the media to after the figure', () => {
    // Not only captions: a figure is `(image | table) caption` and takes no
    // other child at all, so anywhere inside one is the wrong place.
    const editor = editorResolving(120, { 29: { names: ['doc', 'figure'], after: [120, 44] } });

    assert.equal(blockInsertPosition(editor, 29), 44);
});

test('blockInsertPosition clamps a position the document has since outgrown', () => {
    const editor = editorResolving(40, { 40: { names: ['doc', 'paragraph'], after: [40, 40] } });

    assert.equal(blockInsertPosition(editor, 900), 40, 'a deletion during the upload leaves the position past the end');
    assert.equal(blockInsertPosition(editor, -3), null, 'position 0 is not resolvable in this stub, and null refuses the insert');
});

test('blockInsertPosition refuses when there is no document or the position will not resolve', () => {
    assert.equal(blockInsertPosition(null, 3), null);
    assert.equal(blockInsertPosition({}, 3), null);
    assert.equal(blockInsertPosition(editorResolving(40, {}), 3), null, 'a throw from resolve() refuses the insert');
});
