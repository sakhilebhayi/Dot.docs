import assert from 'node:assert/strict';
import test from 'node:test';

import { isRailShortcut, nextPanelState } from '../../resources/js/shell.js';

// Spec §3 gives the left panel a keyboard route of its own: ⌘\ on a Mac,
// Ctrl+\ everywhere else. shell.js keeps the predicate separate from the
// handler so the one thing worth pinning - WHICH chord counts - is testable
// without a DOM.
const key = (overrides = {}) => ({
    key: '\\',
    metaKey: false,
    ctrlKey: false,
    altKey: false,
    shiftKey: false,
    repeat: false,
    target: { tagName: 'BODY', isContentEditable: false },
    ...overrides,
});

test('cmd+\\ and ctrl+\\ both reach the rail', () => {
    assert.equal(isRailShortcut(key({ metaKey: true })), true);
    assert.equal(isRailShortcut(key({ ctrlKey: true })), true);
});

test('the bare backslash is a character, not a shortcut', () => {
    assert.equal(isRailShortcut(key()), false);
});

test('a modifier the chord does not name never triggers it', () => {
    // ⌥\ is a real character on a Mac keyboard ("«"), and ⇧\ is the pipe.
    assert.equal(isRailShortcut(key({ metaKey: true, altKey: true })), false);
    assert.equal(isRailShortcut(key({ ctrlKey: true, shiftKey: true })), false);
    assert.equal(isRailShortcut(key({ metaKey: true, key: 'k' })), false);
});

test('a held key does not toggle the panel once per repeat', () => {
    assert.equal(isRailShortcut(key({ metaKey: true, repeat: true })), false);
});

test('both modifiers together are somebody else\'s chord', () => {
    assert.equal(isRailShortcut(key({ metaKey: true, ctrlKey: true })), false);
});

// A bare-key shortcut has to stand down inside a field. This one does not: it
// types nothing, and the writer with a caret in the paper is exactly the person
// reaching for the panel - standing down there would disable it on the one
// route the spec asks for it.
test('the chord still works with the caret in a field or in the document', () => {
    const typing = [
        { tagName: 'INPUT', isContentEditable: false },
        { tagName: 'TEXTAREA', isContentEditable: false },
        { tagName: 'DIV', isContentEditable: true },
    ];

    typing.forEach((target) => {
        assert.equal(isRailShortcut(key({ metaKey: true, target })), true, `${target.tagName} swallowed the chord`);
    });
});

test('an event with no target at all is tolerated', () => {
    assert.equal(isRailShortcut(key({ metaKey: true, target: null })), true);
    assert.equal(isRailShortcut(null), false);
});

test('the toggle flips, and anything it does not recognise opens', () => {
    assert.equal(nextPanelState('expanded'), 'collapsed');
    assert.equal(nextPanelState('collapsed'), 'expanded');
    assert.equal(nextPanelState(null), 'expanded');
});
