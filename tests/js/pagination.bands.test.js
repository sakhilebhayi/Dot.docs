import assert from 'node:assert/strict';
import test from 'node:test';

import { renderBand } from '../../resources/js/editor/pagination/bands.js';

// This module touches the DOM (firstChild/removeChild/appendChild) but
// needs no ProseMirror/TipTap import, so a minimal hand-rolled DOM stand-in
// is enough - no jsdom dependency required (none exists in package.json).
// firstChild/removeChild are implemented for real (not stubbed as
// undefined/no-ops): renderBand()'s own clearing loop uses exactly these
// two, and a fake that didn't support them would let a broken "clear the
// container first" implementation pass every test below anyway, since
// each test here starts from a fresh, already-empty container.
function fakeContainer() {
    const children = [];
    return {
        get children() {
            return children;
        },
        get firstChild() {
            return children[0] ?? null;
        },
        removeChild(node) {
            const index = children.indexOf(node);
            if (index !== -1) {
                children.splice(index, 1);
            }
            return node;
        },
        appendChild(node) {
            children.push(node);
            return node;
        },
        get renderedText() {
            return children.map((c) => c.text ?? '').join('');
        },
    };
}

function textNode(text) {
    return { nodeType: 3, text };
}

// bands.js is expected to use `document.createTextNode`; under `node --test`
// there is no global `document`, so this suite stubs the minimal piece it
// needs rather than pulling in a DOM dependency the project does not carry.
global.document = { createTextNode: (t) => textNode(t) };

test('a text-only band renders its literal value', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: 'Confidential' }], 1, 5);
    assert.equal(el.renderedText, 'Confidential');
});

test('a PAGE field renders the current page number', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: 'Page ' }, { type: 'field', value: 'PAGE' }], 3, 10);
    assert.equal(el.renderedText, 'Page 3');
});

test('a NUMPAGES field renders the total page count', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'field', value: 'PAGE' }, { type: 'text', value: ' of ' }, { type: 'field', value: 'NUMPAGES' }], 3, 10);
    assert.equal(el.renderedText, '3 of 10');
});

test('an empty segment list renders nothing', () => {
    const el = fakeContainer();
    renderBand(el, [], 1, 1);
    assert.equal(el.children.length, 0);
});

test('every piece is appended as a TEXT NODE, never innerHTML - segments are never parsed as markup', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: '<b>not markup</b>' }], 1, 1);
    assert.equal(el.renderedText, '<b>not markup</b>', 'the angle brackets must survive as literal text');
    assert.equal(el.children.every((c) => c.nodeType === 3), true);
});

test('a second render call clears whatever the container held before', () => {
    // decorations.js calls renderBand() on the SAME footer/header DOM
    // elements every repagination pass - without a real clear, stale text
    // from an earlier page count/index would accumulate instead of being
    // replaced.
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: 'Page ' }, { type: 'field', value: 'PAGE' }], 1, 5);
    assert.equal(el.renderedText, 'Page 1');

    renderBand(el, [{ type: 'text', value: 'Page ' }, { type: 'field', value: 'PAGE' }], 2, 5);
    assert.equal(el.renderedText, 'Page 2', 'the previous render must not remain alongside the new one');
    assert.equal(el.children.length, 2, 'exactly this render\'s two segments, not an accumulation');
});
