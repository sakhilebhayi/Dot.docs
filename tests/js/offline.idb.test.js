import assert from 'node:assert/strict';
import test from 'node:test';

import { installFakeIndexedDb } from './support/fakeIndexedDb.js';

import {
    adoptLost,
    loadLost,
    markLostDismissed,
    parkLost,
    purgeStaleDrafts,
} from '../../resources/js/offline.js';

/**
 * offline.js against a fake IndexedDB (support/fakeIndexedDb.js): the lost
 * park and the parked stale drafts, as rows. Each test installs its own empty
 * database.
 */

const U = 'doc-uuid';
const entry = (id, text) => ({ key: `${id}:2:0`, id, type: 'paragraph', reason: 'both', json: { type: 'paragraph', attrs: { id }, text }, excerpt: text });
const later = () => new Promise((resolve) => setTimeout(resolve, 3));

function fresh() {
    const idb = installFakeIndexedDb();

    return { rows: idb.rows, done: idb.uninstall };
}

test('two tabs of one document each write their OWN lost row: neither erases the other', async () => {
    const { rows, done } = fresh();
    try {
        assert.equal(await parkLost(U, [entry('p1', 'A text')], 2, 'tabA'), true);
        await later();
        assert.equal(await parkLost(U, [entry('p2', 'B text')], 2, 'tabB'), true);

        assert.deepEqual([...rows.keys()].sort(), [`lost-${U}@tabA`, `lost-${U}@tabB`]);
        // Tab A rewrites its list (a put-back elsewhere in its page): tab B's row is not touched.
        await parkLost(U, [entry('p1', 'A text'), entry('p3', 'A more')], 2, 'tabA');
        assert.deepEqual(rows.get(`lost-${U}@tabB`).entries.map((e) => e.id), ['p2']);
        assert.deepEqual(rows.get(`lost-${U}@tabA`).entries.map((e) => e.id), ['p1', 'p3']);
    } finally {
        done();
    }
});

test('loadLost returns every tab\'s entries, oldest row first, skips exact duplicates and still reads a plain lost-<uuid> row', async () => {
    const { rows, done } = fresh();
    try {
        rows.set(`lost-${U}`, { docUuid: `lost-${U}`, entries: [entry('p0', 'old build')], dismissed: [], parkedAt: 1000, baseVersion: 1 });
        await parkLost(U, [entry('p1', 'A text'), entry('p2', 'shared')], 2, 'tabA');
        await later();
        await parkLost(U, [entry('p2', 'shared'), entry('p3', 'B text')], 2, 'tabB');

        const union = await loadLost(U);

        assert.deepEqual(union.entries.map((e) => e.id), ['p0', 'p1', 'p2', 'p3']);
        assert.equal(union.dismissedAt, null);
    } finally {
        done();
    }
});

test('adoptLost on open moves every row into this tab\'s own row in one step: both tabs\' entries are offered, ownership stays unique', async () => {
    const { rows, done } = fresh();
    try {
        await parkLost(U, [entry('p1', 'A text')], 2, 'tabA');
        await later();
        await parkLost(U, [entry('p2', 'B text')], 2, 'tabB');

        const adopted = await adoptLost(U, 'tabC');

        assert.deepEqual(adopted.entries.map((e) => e.id), ['p1', 'p2']);
        assert.deepEqual([...rows.keys()], [`lost-${U}@tabC`], 'the adopted rows are gone');
        assert.deepEqual(rows.get(`lost-${U}@tabC`).entries.map((e) => e.id), ['p1', 'p2']);

        // Dismissing from the adopting tab marks its own row, which now holds both.
        assert.equal(await markLostDismissed(U, 'tabC'), true);
        const again = await loadLost(U);
        assert.deepEqual(again.entries, []);
        assert.deepEqual(again.dismissed.map((e) => e.id), ['p1', 'p2']);
        assert.ok(again.dismissedAt > 0);
    } finally {
        done();
    }
});

test('Dismiss all marks only the dismissing tab\'s row; the other tab\'s entries are still offered on a reload', async () => {
    const { done } = fresh();
    try {
        await parkLost(U, [entry('p1', 'A text')], 2, 'tabA');
        await parkLost(U, [entry('p2', 'B text')], 2, 'tabB');

        await markLostDismissed(U, 'tabA');

        const union = await loadLost(U);
        assert.deepEqual(union.entries.map((e) => e.id), ['p2']);
        assert.deepEqual(union.dismissed.map((e) => e.id), ['p1']);
        assert.equal(union.dismissedAt, null, 'something is still on offer');
    } finally {
        done();
    }
});

test('adoptLost keeps the dismissed copies and finds nothing to offer when everything was dismissed', async () => {
    const { rows, done } = fresh();
    try {
        await parkLost(U, [entry('p1', 'A text')], 2, 'tabA');
        await markLostDismissed(U, 'tabA');

        const adopted = await adoptLost(U, 'tabB');

        assert.deepEqual(adopted.entries, []);
        assert.deepEqual(adopted.dismissed.map((e) => e.id), ['p1']);
        assert.ok(adopted.dismissedAt > 0);
        assert.deepEqual([...rows.keys()], [`lost-${U}@tabB`]);
    } finally {
        done();
    }
});

test('with no row at all adoptLost and loadLost give null and write nothing', async () => {
    const { rows, done } = fresh();
    try {
        assert.equal(await loadLost(U), null);
        assert.equal(await adoptLost(U, 'tabA'), null);
        assert.equal(rows.size, 0);
    } finally {
        done();
    }
});

test('other documents\' lost rows are not touched, and the 7-day sweep removes an expired tab row', async () => {
    const { rows, done } = fresh();
    try {
        await parkLost('other-doc', [entry('x1', 'x')], 2, 'tabA');
        await parkLost(U, [entry('p1', 'A text')], 2, 'tabA');
        await adoptLost(U, 'tabB');
        assert.ok(rows.has('lost-other-doc@tabA'));

        // The sweep dates a lost row by its own parkedAt, whatever its key suffix.
        rows.get(`lost-${U}@tabB`).parkedAt = 1;
        assert.equal(await purgeStaleDrafts(Date.now()), 1);
        assert.deepEqual([...rows.keys()], ['lost-other-doc@tabA']);
    } finally {
        done();
    }
});
