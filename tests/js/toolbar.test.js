import assert from 'node:assert/strict';
import test from 'node:test';

// The task brief writes this test against `ui/bubble.js`. The decision itself
// lives in the dependency-free `ui/toolbarVariant.js` — the project's own rule
// for anything the test runner has to reach (.ai/rules/editor.md), which
// attrs.js, guards.js and validation.js all follow — and bubble.js re-exports
// both names verbatim, which is the interface the brief actually promises
// ("bubble.js exports toolbarVariantFor(selectionShape)"). The rules are
// measured against the pure module...
import { selectionShape, toolbarVariantFor } from '../../resources/js/editor/ui/toolbarVariant.js';
import { loadEditorModule } from './moduleLoader.js';

// ...and `ui/bubble.js` itself, loaded through the harness that resolves the
// extensionless specifiers Vite resolves (see tests/js/moduleLoader.js), so
// the re-export the brief's interface depends on is measured rather than
// assumed.
const bubble = await loadEditorModule('ui/bubble.js');

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

/*
 * The interface the brief names: "bubble.js exports
 * toolbarVariantFor(selectionShape)". The rules live in toolbarVariant.js and
 * bubble.js re-exports them, so a dropped `export { … } from './toolbarVariant'`
 * would leave every case above green while breaking the contract Task 3 was
 * told to rely on.
 */

test('bubble.js re-exports the decision it is the named interface for', () => {
    assert.equal(bubble.toolbarVariantFor, toolbarVariantFor);
    assert.equal(bubble.selectionShape, selectionShape);
    assert.equal(typeof bubble.installBubble, 'function');
});

/*
 * Button activation.
 *
 * `mousedown` is the only event a mouse press may use here — the default has
 * to be prevented before the editor loses its selection — but a button bound
 * to mousedown ALONE cannot be pressed from the keyboard at all, which is how
 * "Alt text", the one command whose entire purpose is accessibility, became
 * mouse-only. `bindActivation` is exported so the rule can be measured without
 * a DOM: it is a statement about which events are bound and which keys count.
 */

/** The smallest thing that answers `addEventListener`. */
function fakeButton() {
    const listeners = new Map();

    return {
        listeners,
        addEventListener(type, handler) {
            listeners.set(type, handler);
        },
        fire(type, event = {}) {
            let prevented = false;
            listeners.get(type)?.({ preventDefault: () => { prevented = true; }, ...event });

            return prevented;
        },
    };
}

test('a toolbar button answers the mouse and the keyboard, not just the mouse', () => {
    const el = fakeButton();
    let pressed = 0;
    bubble.bindActivation(el, () => { pressed += 1; });

    assert.deepEqual([...el.listeners.keys()], ['mousedown', 'keydown']);

    assert.equal(el.fire('mousedown'), true, 'the mouse press must prevent the default');
    assert.equal(pressed, 1);

    assert.equal(el.fire('keydown', { key: 'Enter' }), true);
    assert.equal(pressed, 2, 'Enter on a focused button did nothing');

    assert.equal(el.fire('keydown', { key: ' ' }), true);
    assert.equal(pressed, 3, 'Space on a focused button did nothing');
});

test('a key that is not an activation key leaves the button alone', () => {
    const el = fakeButton();
    let pressed = 0;
    bubble.bindActivation(el, () => { pressed += 1; });

    // Tab and the arrows have to travel: swallowing them would trap focus in
    // the toolbar.
    for (const key of ['Tab', 'ArrowRight', 'a', 'Escape']) {
        assert.equal(el.fire('keydown', { key }), false, `${key} was swallowed`);
    }

    assert.equal(pressed, 0);
});

test('the button knows whether it was pressed by key or by mouse', () => {
    // A registry command ends with `chain().focus()`, which puts the caret
    // back in the paper. That is right for a mouse press and wrong for a key
    // press — it ejects the writer from the toolbar after one command — so the
    // press has to say which it was.
    const el = fakeButton();
    const from = [];
    bubble.bindActivation(el, (fromKeyboard) => from.push(fromKeyboard));

    el.fire('mousedown');
    el.fire('keydown', { key: 'Enter' });

    assert.deepEqual(from, [false, true]);
});

/*
 * REACHING THE TOOLBAR WITHOUT A MOUSE.
 *
 * "Alt text" is the one command in the product whose entire purpose is
 * accessibility, and the floating toolbar is its only home. Two things have to
 * hold for it to be operable: a blur that hands focus to one of the toolbar's
 * OWN controls must not tear the toolbar down under the press, and there has to
 * be a route into it that does not depend on where the toolbar happens to sit
 * in the document (`Alt+F10`, the WAI-ARIA APG convention).
 */

/** The smallest thing that answers `contains` the way an element does. */
function fakeToolbar(...children) {
    return { hidden: false, children, contains: (el) => children.includes(el) };
}

test('a blur that hands focus to the toolbar itself does not hide it', () => {
    const altText = { name: 'Alt text' };
    const caption = { name: 'Caption' };
    const dom = fakeToolbar(altText, caption);

    // Tab out of the paper: `relatedTarget` is the element ABOUT to take
    // focus, which is the only reading available while the blur is in flight.
    assert.equal(bubble.blurLeavesToolbar(dom, altText), false, 'tabbing INTO the toolbar hid it');
    assert.equal(bubble.blurLeavesToolbar(dom, caption), false, 'moving between its own buttons hid it');

    // ...and the toolbar holds itself open for as long as focus is on one of
    // them, which is what `document.activeElement` answers once the blur has
    // settled.
    assert.equal(bubble.toolbarHoldsFocus(dom, altText), true);
});

test('a blur that leaves the toolbar for anything else does hide it', () => {
    const altText = { name: 'Alt text' };
    const dom = fakeToolbar(altText);

    assert.equal(bubble.blurLeavesToolbar(dom, { name: 'the rail toggle' }), true);
    assert.equal(bubble.blurLeavesToolbar(dom, null), true, 'a blur to nothing must still hide it');
    assert.equal(bubble.blurLeavesToolbar(dom, undefined), true);

    assert.equal(bubble.toolbarHoldsFocus(dom, { name: 'the rail toggle' }), false);
    assert.equal(bubble.toolbarHoldsFocus(dom, null), false);
});

test('Alt+F10 and F10 both reach the toolbar; nothing else does', () => {
    const key = (overrides = {}) => ({
        key: 'F10',
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        repeat: false,
        ...overrides,
    });

    assert.equal(bubble.isToolbarFocusShortcut(key({ altKey: true })), true);
    assert.equal(bubble.isToolbarFocusShortcut(key()), true);

    // A chord the shortcut never asked for belongs to somebody else, and a
    // held key would keep re-stealing focus for as long as it was down.
    assert.equal(bubble.isToolbarFocusShortcut(key({ ctrlKey: true })), false);
    assert.equal(bubble.isToolbarFocusShortcut(key({ metaKey: true })), false);
    assert.equal(bubble.isToolbarFocusShortcut(key({ shiftKey: true })), false);
    assert.equal(bubble.isToolbarFocusShortcut(key({ repeat: true })), false);
    assert.equal(bubble.isToolbarFocusShortcut(key({ key: 'F9' })), false);
    assert.equal(bubble.isToolbarFocusShortcut(null), false);
});

/*
 * ROVING TABINDEX.
 *
 * `role="toolbar"` is a single tab stop with the arrows moving inside it — a
 * toolbar whose every button is its own tab stop makes a writer Tab past nine
 * controls to leave it.
 */

test('the arrows move along the toolbar and wrap at both ends', () => {
    assert.equal(bubble.rovingMove('ArrowRight', 0, 4), 1);
    assert.equal(bubble.rovingMove('ArrowRight', 3, 4), 0, 'the right end must wrap');
    assert.equal(bubble.rovingMove('ArrowLeft', 0, 4), 3, 'the left end must wrap');
    assert.equal(bubble.rovingMove('Home', 2, 4), 0);
    assert.equal(bubble.rovingMove('End', 0, 4), 3);
});

test('a key the toolbar does not own travels on', () => {
    // Tab has to leave the toolbar, Enter has to activate the button, and a
    // letter belongs to whatever is listening for it.
    for (const key of ['Tab', 'Enter', ' ', 'a', 'ArrowUp', 'Escape']) {
        assert.equal(bubble.rovingMove(key, 0, 4), null, `${key} was taken by the roving tabindex`);
    }

    assert.equal(bubble.rovingMove('ArrowRight', 0, 0), null, 'an empty toolbar has nowhere to move');
});

/*
 * WHERE THE TOOLBAR MAY SIT.
 *
 * It floats over the first line of the document as readily as the last, so it
 * must not cover the two bars above the page. The top bar was already
 * accounted for; `.doc-bar` — the persistent row directly under it — was not,
 * and a selection in the first line put the toolbar over the title field.
 */

test('the ceiling clears every bar above the page, not just the top one', () => {
    const topbar = { bottom: 52 };
    const docBar = { bottom: 98 };

    assert.equal(bubble.toolbarCeiling([topbar, docBar]), 106);
    assert.equal(bubble.toolbarCeiling([docBar, topbar]), 106, 'the order of the bars must not matter');

    // A page with no persistent bar (anything that is not the editor) still
    // clears the top bar, and a page with neither has no ceiling but the gap.
    assert.equal(bubble.toolbarCeiling([topbar, null]), 60);
    assert.equal(bubble.toolbarCeiling([null, undefined]), 8);
});
