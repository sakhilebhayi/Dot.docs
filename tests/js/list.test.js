import assert from 'node:assert/strict';
import test from 'node:test';

import { loadEditorModule } from './moduleLoader.js';

/*
 * R2-9: the command palette (and the cross-reference and variable pickers,
 * which are the same list) is an aria-modal dialog. Tab must stay inside it,
 * and closing it must put the writer back in the document rather than on
 * <body> (WCAG 2.4.3). The project carries no jsdom, so the list runs against
 * the smallest DOM stand-in that covers what list.js touches.
 */

class FakeElement {
    constructor(tag, doc) {
        this.tagName = tag.toUpperCase();
        this.ownerDocument = doc;
        this.children = [];
        this.parentNode = null;
        this.attributes = {};
        this.dataset = {};
        this.listeners = {};
        this.value = '';
        this.disabled = false;
        const classes = new Set();
        this.classList = { add: (c) => classes.add(c), toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) };
    }
    set textContent(text) {
        this.children = [];
        this.text = text;
    }
    get isConnected() {
        let node = this;
        while (node.parentNode) node = node.parentNode;
        return node === this.ownerDocument.body;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    getAttribute(name) { return this.attributes[name] ?? null; }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    remove() {
        if (this.parentNode) this.parentNode.children = this.parentNode.children.filter((c) => c !== this);
        this.parentNode = null;
        if (!this.isConnected && this.contains(this.ownerDocument.activeElement)) this.ownerDocument.activeElement = this.ownerDocument.body;
    }
    contains(node) { return node === this || this.children.some((c) => c.contains(node)); }
    addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }
    focus() { this.ownerDocument.activeElement = this; }
    querySelectorAll() {
        const out = [];
        const walk = (el) => el.children.forEach((c) => { if (c.tagName === 'INPUT' || c.tagName === 'BUTTON') out.push(c); walk(c); });
        walk(this);
        return out;
    }
    /** Dispatch the way a key press does: on the target, then up through its ancestors. */
    press(key, extra = {}) {
        const event = { key, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
        for (let el = this; el; el = el.parentNode) (el.listeners?.keydown ?? []).forEach((fn) => fn(event));
        return event;
    }
}

function installDocument() {
    const doc = {};
    doc.createElement = (tag) => new FakeElement(tag, doc);
    doc.body = new FakeElement('body', doc);
    doc.activeElement = doc.body;
    globalThis.document = doc;
    return doc;
}

const doc = installDocument();
const { closeList, isListOpen, openList, trappedFocusIndex } = await loadEditorModule('ui/list.js');

const items = [{ key: 'bold', title: 'Bold' }, { key: 'italic', title: 'Italic' }];
const input = () => doc.body.children[0].children[0].children.find((c) => c.tagName === 'INPUT');

test('Tab and Shift+Tab walk the overlay\'s controls and wrap', () => {
    assert.equal(trappedFocusIndex(1, 0, false), 0);
    assert.equal(trappedFocusIndex(1, 0, true), 0);
    assert.equal(trappedFocusIndex(3, 2, false), 0);
    assert.equal(trappedFocusIndex(3, 0, true), 2);
    assert.equal(trappedFocusIndex(3, -1, false), 0, 'focus outside the overlay comes back to the first control');
    assert.equal(trappedFocusIndex(3, -1, true), 2);
    assert.equal(trappedFocusIndex(0, -1, false), -1);
});

test('Tab inside the open list keeps focus on its input instead of leaving the dialog', () => {
    openList({ title: 'Commands', items, onSelect() {} });
    const field = input();
    assert.equal(doc.activeElement, field);

    const forward = field.press('Tab');
    assert.ok(forward.defaultPrevented, 'the browser does not move focus out');
    assert.equal(doc.activeElement, field);

    const back = field.press('Tab', { shiftKey: true });
    assert.ok(back.defaultPrevented);
    assert.equal(doc.activeElement, field);
    assert.ok(isListOpen());
    closeList({ restoreFocus: false });
});

test('Escape closes the list and hands focus back through returnFocus', () => {
    let returned = 0;
    openList({ title: 'Commands', items, onSelect() {}, returnFocus: () => returned++ });
    input().press('Escape');
    assert.equal(isListOpen(), false);
    assert.equal(returned, 1);
});

test('choosing an item restores focus before the command runs', () => {
    const order = [];
    openList({ title: 'Commands', items, onSelect: (item) => order.push(`run ${item.key}`), returnFocus: () => order.push('focus') });
    input().press('Enter');
    assert.deepEqual(order, ['focus', 'run bold']);
});

test('without returnFocus, focus goes back to whatever held it when the list opened', () => {
    const opener = doc.body.appendChild(doc.createElement('button'));
    opener.focus();
    openList({ title: 'Commands', items, onSelect() {} });
    assert.notEqual(doc.activeElement, opener);
    closeList();
    assert.equal(doc.activeElement, opener);
    opener.remove();
});

test('the editor tearing down closes the list without reaching for focus', () => {
    let returned = 0;
    openList({ title: 'Commands', items, onSelect() {}, returnFocus: () => returned++ });
    closeList({ restoreFocus: false });
    assert.equal(returned, 0);
    assert.equal(isListOpen(), false);
});

test('opening a second list replaces the first without bouncing focus through the editor', () => {
    let returned = 0;
    openList({ title: 'Commands', items, onSelect() {}, returnFocus: () => returned++ });
    openList({ title: 'Insert variable', items, onSelect() {}, returnFocus: () => returned++ });
    assert.equal(returned, 0);
    assert.equal(doc.body.children.length, 1);
    closeList();
    assert.equal(returned, 1);
});
