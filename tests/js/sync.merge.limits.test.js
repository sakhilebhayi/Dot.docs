import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema } from 'prosemirror-model';

import { mergeDocuments } from '../../resources/js/editor/sync/merge.js';

/**
 * R2-3 / F-14: the co-editing limits stated in .ai/rules/editor.md ("Known limits of Phase 2") all promise one
 * thing: NOTHING IS LOST. A writer may see a duplicate, or an item in the lost row to put back, but every word
 * either side typed is still somewhere they can reach. These tests pin that promise for the clashes people can
 * actually meet - Enter or Backspace in a paragraph somebody else is changing at the same moment - so a change to
 * the merge that starts dropping words fails here, not in a customer's document.
 */
const schema = new Schema({
    nodes: {
        doc: { content: 'block+', attrs: { schema: { default: 1 }, style: { default: 'report' }, vars: { default: {} } } },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        text: { group: 'inline' },
    },
});

const para = (id, text) => ({ type: 'paragraph', attrs: { id }, ...(text ? { content: [{ type: 'text', text }] } : {}) });
const docOf = (...blocks) => ({ type: 'doc', content: blocks });
const textOf = (block) => (block.content ?? []).map((node) => node.text ?? '').join('');
const words = (text) => text.split(/\s+/).filter(Boolean);

/** Every word the writer can still reach after a merge: in the merged document, or in the lost row. */
function reachable(result) {
    const merged = result.merged.content.map(textOf).join(' ');
    const lost = result.lost.map((entry) => textOf(entry.json)).join(' ');
    return new Set(words(`${merged} ${lost}`));
}

function assertNothingLost(result, ...sides) {
    assert.ok(result, 'the merge produced a result (not the conflict notice)');
    const have = reachable(result);
    for (const side of sides) {
        for (const word of words(side.content.map(textOf).join(' '))) {
            assert.ok(have.has(word), `"${word}" is neither in the merged document nor in the lost row`);
        }
    }
}

test('Enter in a paragraph somebody else is editing: every word survives (a duplicate is allowed, a loss is not)', () => {
    const base = docOf(para('p1', 'Hello brave world'));
    const mine = docOf(para('p1', 'Hello'), para('p9', 'brave world')); // I split before "brave"
    const theirs = docOf(para('p1', 'Hello brave new world')); // they add "new" at the same time

    assertNothingLost(mergeDocuments(schema, { base, mine, theirs }), mine, theirs);
});

test('Backspace joining two paragraphs while somebody edits the second: mine stays, theirs goes into the history', () => {
    const base = docOf(para('p1', 'Alpha one'), para('p2', 'Beta two'));
    const mine = docOf(para('p1', 'Alpha oneBeta two')); // I joined p2 onto p1
    const theirs = docOf(para('p1', 'Alpha one'), para('p2', 'Beta two three')); // they extend p2

    const result = mergeDocuments(schema, { base, mine, theirs });
    assertNothingLost(result, mine);
    // My join deleted p2, which they changed: the deletion wins on the page, and the result says it OVERWRITES,
    // which makes the save that carries it keep their version as a named version first (host.js owesOverwrite ->
    // Editor::saveContent keepReplacedAs). Their "three" is in the history, never silently gone.
    assert.equal(result.overwrites, true);
});

test('both sides change the same paragraph differently: one is kept, the other is offered back', () => {
    const base = docOf(para('p1', 'The plan'));
    const mine = docOf(para('p1', 'The draft plan'));
    const theirs = docOf(para('p1', 'The final plan'));

    const result = mergeDocuments(schema, { base, mine, theirs });
    assertNothingLost(result, mine, theirs);
    assert.ok(result.lost.length > 0 || result.merged.content.length > 1, 'the losing version is offered back, not dropped');
});

test('somebody deletes a paragraph I am still typing in: my words are offered back', () => {
    const base = docOf(para('p1', 'Keep'), para('p2', 'Old note'));
    const mine = docOf(para('p1', 'Keep'), para('p2', 'Old note with my addition'));
    const theirs = docOf(para('p1', 'Keep'));

    assertNothingLost(mergeDocuments(schema, { base, mine, theirs }), mine);
});
