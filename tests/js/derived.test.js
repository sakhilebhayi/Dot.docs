import assert from 'node:assert/strict';
import test from 'node:test';

import { documentsDiffer, stripDerived } from '../../resources/js/editor/derived.js';

// Outline::apply() stamps toc.entries and crossRef.label into stored JSON.
// Comparing an offline draft against the server document without stripping
// them makes every load look like a difference, so every load would offer a
// pointless "restore your draft?" prompt.
const doc = (...content) => ({ type: 'doc', content });

test('toc entries and crossRef labels are stripped, everything else is kept', () => {
    const stored = doc(
        { type: 'toc', attrs: { id: 'aaaaaaaa', depth: 3, entries: [{ id: 'h1', number: '1', text: 'Intro' }] } },
        {
            type: 'paragraph',
            attrs: { id: 'bbbbbbbb' },
            content: [{ type: 'crossRef', attrs: { targetId: 'h1', kind: 'heading', label: 'Section 1' } }],
        }
    );

    const stripped = stripDerived(stored);

    assert.deepEqual(stripped.content[0].attrs, { id: 'aaaaaaaa', depth: 3 });
    assert.deepEqual(stripped.content[1].content[0].attrs, { targetId: 'h1', kind: 'heading' });
});

test('stripping does not mutate the document it was given', () => {
    const stored = doc({ type: 'toc', attrs: { id: 'aaaaaaaa', depth: 3, entries: [{ id: 'h1' }] } });

    stripDerived(stored);

    assert.deepEqual(stored.content[0].attrs.entries, [{ id: 'h1' }]);
});

test('a document that differs only in derived fields does not count as different', () => {
    const draft = doc({ type: 'toc', attrs: { id: 'aaaaaaaa', depth: 3, entries: [] } });
    const server = doc({ type: 'toc', attrs: { id: 'aaaaaaaa', depth: 3, entries: [{ id: 'h1', number: '1', text: 'Intro' }] } });

    assert.equal(documentsDiffer(draft, server), false);
});

test('a real edit still counts as different', () => {
    const draft = doc({ type: 'paragraph', attrs: { id: 'aaaaaaaa' }, content: [{ type: 'text', text: 'typed' }] });
    const server = doc({ type: 'paragraph', attrs: { id: 'aaaaaaaa' }, content: [{ type: 'text', text: 'stored' }] });

    assert.equal(documentsDiffer(draft, server), true);
});

test('non-object input is handed back untouched', () => {
    assert.equal(stripDerived(null), null);
    assert.equal(stripDerived('text'), 'text');
    assert.deepEqual(stripDerived([{ type: 'toc', attrs: { entries: [] } }]), [{ type: 'toc', attrs: {} }]);
    assert.deepEqual(stripDerived({ type: 'toc' }), { type: 'toc' });
});

test('attribute order is not a difference', () => {
    // The draft was serialised by a previous page load; an attribute order
    // that changed between builds must not read as an edit.
    const draft = doc({ type: 'paragraph', attrs: { id: 'aaaaaaaa', align: 'left' }, content: [{ type: 'text', text: 'x' }] });
    const server = doc({ type: 'paragraph', attrs: { align: 'left', id: 'aaaaaaaa' }, content: [{ type: 'text', text: 'x' }] });

    assert.equal(documentsDiffer(draft, server), false);
});

test('block order is still a difference', () => {
    const a = doc({ type: 'paragraph', attrs: { id: 'a1' } }, { type: 'paragraph', attrs: { id: 'b1' } });
    const b = doc({ type: 'paragraph', attrs: { id: 'b1' } }, { type: 'paragraph', attrs: { id: 'a1' } });

    assert.equal(documentsDiffer(a, b), true);
});
