import assert from 'node:assert/strict';
import test from 'node:test';

import { isRailShortcut, nextPanelState, openingPanelState, panelStateAtWidth, themeChoiceIn, watchOverlayBreakpoints } from '../../resources/js/shell.js';

/*
 * The theme toggle's label has to match what is ON SCREEN. With no `theme`
 * cookie the page follows the reader's OS through the `prefers-color-scheme`
 * guard in shell.css, so the boot-time sync stamps the class that matches - and
 * that stamping is what stops the guard following the OS afterwards, which is
 * why "has this reader actually chosen?" has to be answerable.
 */

test('an explicit theme choice is read back off the cookie', () => {
    assert.equal(themeChoiceIn('theme=dark'), 'dark');
    assert.equal(themeChoiceIn('theme=light'), 'light');
    assert.equal(themeChoiceIn('XSRF-TOKEN=abc; theme=dark; other=1'), 'dark');
    assert.equal(themeChoiceIn('other=1; theme=light'), 'light');
});

test('no choice at all is not a choice', () => {
    assert.equal(themeChoiceIn(''), null);
    assert.equal(themeChoiceIn('XSRF-TOKEN=abc; session=zz'), null);
    assert.equal(themeChoiceIn(undefined), null);

    // A cookie whose NAME merely ends in "theme" is somebody else's.
    assert.equal(themeChoiceIn('mytheme=dark'), null);
    // ...and a value this file never writes is not a theme either.
    assert.equal(themeChoiceIn('theme=sepia'), null);
});

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

// Spec §6: below its breakpoint a panel is a sheet over the page, not a
// column. It therefore boots SHUT whatever the server rendered and whatever
// the reader last chose at a desktop width - otherwise the overlay arrives
// already covering the page, and the top bar offers "Hide the panel" for a
// panel that is not on screen, so the first press of it does nothing visible.
test('an overlay-width panel always boots shut', () => {
    assert.equal(openingPanelState(null, true), 'collapsed');
    assert.equal(openingPanelState('expanded', true), 'collapsed');
    assert.equal(openingPanelState('collapsed', true), 'collapsed');
});

test('at a column width the stored preference is what boots', () => {
    assert.equal(openingPanelState('expanded', false), 'expanded');
    assert.equal(openingPanelState('collapsed', false), 'collapsed');
});

test('nothing stored at a column width leaves the server default alone', () => {
    assert.equal(openingPanelState(null, false), null);
    assert.equal(openingPanelState('', false), null);
    assert.equal(openingPanelState('nonsense', false), null);
});

// The breakpoint is crossed LIVE as well as at load - a window dragged narrow,
// a tablet rotated. Without this the fix above only held for the width the page
// happened to open at: drag a 1200px window with the rail open down past 900px
// and the overlay arrived already covering the page, which is the exact bug it
// was meant to close.
test('crossing into overlay width shuts the panel whatever is stored', () => {
    assert.equal(panelStateAtWidth('expanded', true, 'expanded'), 'collapsed');
    assert.equal(panelStateAtWidth(null, true, 'expanded'), 'collapsed');
});

test('crossing back out restores the stored preference, or the server default', () => {
    assert.equal(panelStateAtWidth('expanded', false, 'collapsed'), 'expanded');
    assert.equal(panelStateAtWidth('collapsed', false, 'expanded'), 'collapsed');

    // Nothing stored: back to what the server rendered for this page - the
    // editor's collapsed rail, everywhere else's open one. openingPanelState()
    // answers null here, and null must not reach the DOM as a state.
    assert.equal(panelStateAtWidth(null, false, 'expanded'), 'expanded');
    assert.equal(panelStateAtWidth(null, false, 'collapsed'), 'collapsed');
    assert.equal(panelStateAtWidth('nonsense', false, 'collapsed'), 'collapsed');
});

test('both panels get a listener on their own breakpoint, and it reports which way it went', () => {
    const registered = [];
    const listeners = {};

    const match = (query) => {
        const list = {
            matches: false,
            addEventListener(type, handler) {
                registered.push([query, type]);
                listeners[query] = handler;
            },
        };

        return list;
    };

    const crossings = [];
    const watched = watchOverlayBreakpoints(match, (name, overlay) => crossings.push([name, overlay]));

    assert.deepEqual(watched, ['rail', 'dock']);
    assert.deepEqual(registered, [
        ['(max-width: 900px)', 'change'],
        ['(max-width: 1180px)', 'change'],
    ]);

    // The handler reads the CHANGE event, not the stale MediaQueryList it
    // closed over: Safari fires `change` on a list whose `matches` it has
    // already updated, but the event is what every browser agrees on.
    listeners['(max-width: 900px)']({ matches: true });
    listeners['(max-width: 1180px)']({ matches: false });

    assert.deepEqual(crossings, [
        ['rail', true],
        ['dock', false],
    ]);
});

test('a browser with only the legacy addListener is still watched', () => {
    const seen = [];
    const match = () => ({
        matches: false,
        addListener(handler) {
            seen.push(handler);
        },
    });

    assert.deepEqual(watchOverlayBreakpoints(match, () => {}), ['rail', 'dock']);
    assert.equal(seen.length, 2);
});

test('a browser with no matchMedia at all is left alone rather than thrown at', () => {
    assert.deepEqual(watchOverlayBreakpoints(() => null, () => {}), []);
    assert.deepEqual(watchOverlayBreakpoints(() => ({}), () => {}), []);
});
