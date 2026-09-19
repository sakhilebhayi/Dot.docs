import assert from 'node:assert/strict';
import test from 'node:test';

import { addDocTableClass } from '../../resources/js/editor/extensions/table.js';

// `DocTableView` itself (extensions/table.js) is not exercised here: its
// constructor calls `super()` into @tiptap/extension-table's real
// `TableView`, which calls `document.createElement` directly - this
// project carries no jsdom, so instantiating it needs a real browser (see
// this feature's live verification instead). `addDocTableClass()` is
// exactly the one line of that class this project owns, extracted so it
// is unit-testable against a hand-rolled fake table element.
function fakeTableEl() {
    const classes = [];
    return {
        classList: {
            add: (name) => classes.push(name),
        },
        get classes() {
            return classes;
        },
    };
}

test('addDocTableClass adds doc-table, the class CssBuilder::tableRules() scopes its CSS to', () => {
    const table = fakeTableEl();
    addDocTableClass(table);
    assert.deepEqual(table.classes, ['doc-table']);
});

test('addDocTableClass does not remove or replace any class already present', () => {
    const table = fakeTableEl();
    table.classList.add('some-other-class');
    addDocTableClass(table);
    assert.deepEqual(table.classes, ['some-other-class', 'doc-table']);
});
