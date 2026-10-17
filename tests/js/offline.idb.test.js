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

// ───────────────────────────────────────────────────────── parked stale drafts: the queue of texts to offer

import { chooseNextPark, nextParkToOffer, parkStaleDraft, resolveParked } from '../../resources/js/offline.js';

const text = (t) => JSON.stringify({ type: 'doc', content: [{ type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: t }] }] });

test('a park made to be offered keeps its flag when a newer park moves it to a kept key, and nextParkToOffer takes the newest unresolved', async () => {
    const { rows, done } = fresh();
    try {
        await parkStaleDraft(U, text('first'), 2, { offerable: true });
        await later();
        await parkStaleDraft(U, text('second'), 3, { offerable: true });
        await later();
        await parkStaleDraft(U, text('copy for recovery by hand'), 3); // not offerable
        await later();
        await parkStaleDraft(U, text('third'), 4, { offerable: true });

        const keys = [...rows.keys()].sort();
        assert.equal(keys.length, 4);
        assert.ok(rows.get(`stale-${U}`).offerable === true);
        assert.ok([...rows.values()].filter((row) => row.offerable === true).length === 3, 'the flag travelled with the earlier parks');

        const next = await nextParkToOffer(U);
        assert.equal(next.json, text('third'));
        assert.equal(next.baseVersion, 4);
    } finally {
        done();
    }
});

test('resolveParked marks every park holding that text, and the queue moves on, oldest last, until nothing is left', async () => {
    const { rows, done } = fresh();
    try {
        await parkStaleDraft(U, text('A'), 2, { offerable: true });
        await later();
        await parkStaleDraft(U, text('B'), 2, { offerable: true });
        await later();
        await parkStaleDraft(U, text('C'), 2, { offerable: true });
        await parkStaleDraft('other-doc', text('X'), 2, { offerable: true });

        assert.equal((await nextParkToOffer(U)).json, text('C'));
        assert.equal(await resolveParked(U, text('C')), true);
        assert.equal((await nextParkToOffer(U)).json, text('B'));
        await resolveParked(U, text('B'));
        assert.equal((await nextParkToOffer(U)).json, text('A'));
        await resolveParked(U, text('A'));
        assert.equal(await nextParkToOffer(U), null);

        assert.equal(rows.size, 4, 'resolving keeps the rows for their 7 days');
        assert.equal((await nextParkToOffer('other-doc')).json, text('X'), 'another document is untouched');
    } finally {
        done();
    }
});

test('chooseNextPark ignores a park without the flag, a resolved one and an old build\'s row', () => {
    assert.equal(chooseNextPark([{ docUuid: 'stale-d', json: '{}', parkedAt: 5 }]), null);
    assert.equal(chooseNextPark([{ docUuid: 'stale-d', json: '{}', parkedAt: 5, offerable: true, resolved: true }]), null);
    assert.equal(chooseNextPark([]), null);
    assert.equal(chooseNextPark([{ docUuid: 'stale-d@1', json: 'a', parkedAt: 1, offerable: true }, { docUuid: 'stale-d', json: 'b', parkedAt: 2, offerable: true }]).json, 'b');
});

test('a swept park is not offered: the 7-day sweep removes it, flag or not', async () => {
    const { rows, done } = fresh();
    try {
        await parkStaleDraft(U, text('old'), 2, { offerable: true });
        rows.get(`stale-${U}`).parkedAt = 1;

        assert.equal(await purgeStaleDrafts(Date.now()), 1);
        assert.equal(await nextParkToOffer(U), null);
    } finally {
        done();
    }
});
