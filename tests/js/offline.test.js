import assert from 'node:assert/strict';
import test from 'node:test';

import {
    BASE_PREFIX,
    LOST_PREFIX,
    PARKED_PREFIXES,
    STALE_PREFIX,
    STALE_TTL_MS,
    parkedRowIsExpired,
    staleDraftIsExpired,
} from '../../resources/js/offline.js';

// A draft that cannot be restored — the writer declined it, or the document
// has been saved since it was written — is PARKED under `stale-<uuid>` rather
// than deleted, so a mis-click does not destroy somebody's only offline copy.
// This is the rule that stops those parked rows living in IndexedDB for ever.

const DAY = 24 * 60 * 60 * 1000;
const NOW = 1_757_000_000_000;

const parked = (parkedAt, uuid = 'abc') => ({ docUuid: STALE_PREFIX + uuid, json: '{}', parkedAt });

test('the parked-draft TTL is seven days', () => {
    assert.equal(STALE_TTL_MS, 7 * DAY);
});

test('a parked draft is swept once it is older than the TTL', () => {
    assert.equal(staleDraftIsExpired(parked(NOW - 8 * DAY), NOW), true);
    assert.equal(staleDraftIsExpired(parked(NOW - 6 * DAY), NOW), false);
    assert.equal(staleDraftIsExpired(parked(NOW - 7 * DAY), NOW), false, 'exactly at the TTL is still within the window');
    assert.equal(staleDraftIsExpired(parked(NOW), NOW), false);
});

test('a LIVE draft is never swept, however old it is', () => {
    // The live key holds the writer's unsaved work. Age says nothing about
    // whether it is still the only copy of something.
    assert.equal(staleDraftIsExpired({ docUuid: 'abc', json: '{}', savedAt: NOW - 400 * DAY }, NOW), false);
});

test('a parked draft from an older build is dated by savedAt', () => {
    // parkedAt is new; rows parked by the round-2 build carry only savedAt,
    // which was written at the same moment.
    assert.equal(staleDraftIsExpired({ docUuid: STALE_PREFIX + 'abc', savedAt: NOW - 9 * DAY }, NOW), true);
    assert.equal(staleDraftIsExpired({ docUuid: STALE_PREFIX + 'abc', savedAt: NOW - 2 * DAY }, NOW), false);
});

test('a parked draft with no usable timestamp is swept', () => {
    assert.equal(staleDraftIsExpired({ docUuid: STALE_PREFIX + 'abc' }, NOW), true);
    assert.equal(staleDraftIsExpired({ docUuid: STALE_PREFIX + 'abc', parkedAt: 0 }, NOW), true);
    assert.equal(staleDraftIsExpired({ docUuid: STALE_PREFIX + 'abc', parkedAt: 'yesterday' }, NOW), true);
});

test('anything that is not a stored draft row is left alone', () => {
    assert.equal(staleDraftIsExpired(null, NOW), false);
    assert.equal(staleDraftIsExpired({}, NOW), false);
    assert.equal(staleDraftIsExpired({ docUuid: 42 }, NOW), false);
});

test('the TTL is a parameter, so the sweep can be tested against any window', () => {
    assert.equal(staleDraftIsExpired(parked(NOW - 2 * DAY), NOW, DAY), true);
    assert.equal(staleDraftIsExpired(parked(NOW - 2 * DAY), NOW, 30 * DAY), false);
});

// ───────────────────────────────────────────────────────── the base row and the lost park (Phase 2)

test('base- and lost- rows expire after the TTL, dated by parkedAt or else savedAt, and undatable ones go', () => {
    assert.deepEqual(PARKED_PREFIXES, [STALE_PREFIX, LOST_PREFIX, BASE_PREFIX]);

    assert.equal(parkedRowIsExpired({ docUuid: BASE_PREFIX + 'abc', json: '{}', savedAt: NOW - 8 * DAY }, NOW), true);
    assert.equal(parkedRowIsExpired({ docUuid: BASE_PREFIX + 'abc', json: '{}', savedAt: NOW - 6 * DAY }, NOW), false);
    assert.equal(parkedRowIsExpired({ docUuid: LOST_PREFIX + 'abc', entries: [], parkedAt: NOW - 8 * DAY }, NOW), true);
    assert.equal(parkedRowIsExpired({ docUuid: LOST_PREFIX + 'abc', entries: [], parkedAt: NOW - DAY }, NOW), false);
    assert.equal(parkedRowIsExpired({ docUuid: LOST_PREFIX + 'abc', entries: [] }, NOW), true, 'a parked row that cannot be dated goes');
    assert.equal(parkedRowIsExpired(parked(NOW - 8 * DAY), NOW), true, 'a stale draft, as before');
});

test('the generalised rule never sweeps a live draft, however old, and leaves anything that is not a row alone', () => {
    assert.equal(parkedRowIsExpired({ docUuid: 'abc', json: '{}', savedAt: NOW - 400 * DAY }, NOW), false);
    assert.equal(parkedRowIsExpired(null, NOW), false);
    assert.equal(parkedRowIsExpired({}, NOW), false);
    assert.equal(parkedRowIsExpired({ docUuid: 42 }, NOW), false);
});

test('staleDraftIsExpired keeps its answers: it is the same rule', () => {
    for (const row of [
        parked(NOW - 8 * DAY),
        parked(NOW - 6 * DAY),
        { docUuid: 'abc', json: '{}', savedAt: NOW - 400 * DAY },
        { docUuid: STALE_PREFIX + 'abc' },
        { docUuid: BASE_PREFIX + 'abc', savedAt: NOW - 9 * DAY },
        null,
    ]) {
        assert.equal(staleDraftIsExpired(row, NOW), parkedRowIsExpired(row, NOW));
    }
});
