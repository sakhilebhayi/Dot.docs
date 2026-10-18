import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    FIRST_FLUSH_MS,
    FLUSH_EVERY_MS,
    MAX_COUNT,
    METRIC_KINDS,
    PEERS_GAP_MS,
    createMetrics,
    metricKey,
} from '../../resources/js/editor/sync/metrics.js';

/**
 * The counters the editor page keeps (sync/metrics.js): counts and kinds
 * only, flushed at most once a minute and on leaving, and never in the way
 * of editing. The host's side (which event counts as what) is in
 * sync.host.test.js.
 */

const TOKEN = 'T'.repeat(40);

/** A metrics object on a fake clock and a fake network. */
function rig(options = {}) {
    const r = {
        clock: 1_000_000,
        sent: [],
        beacons: [],
        sendMode: 'ok',
        beaconMode: 'ok',
        /** What the page asked its timer for: [{ fn, ms }]. Nothing runs until a test calls it. */
        timers: [],
    };
    r.metrics = createMetrics({
        now: () => r.clock,
        token: () => TOKEN,
        send: (body) => {
            r.sent.push(JSON.parse(body));
            if (r.sendMode === 'throw') {
                throw new Error('no network');
            }
            if (r.sendMode === 'reject') {
                return Promise.reject(new Error('offline'));
            }
            if (r.sendMode === 'refused') {
                return Promise.resolve({ ok: false });
            }

            return Promise.resolve({ ok: true });
        },
        beacon: (body) => {
            r.beacons.push(JSON.parse(body));
            if (r.beaconMode === 'throw') {
                throw new Error('beacon blew up');
            }

            return r.beaconMode === 'ok';
        },
        schedule: (fn, ms) => {
            r.timers.push({ fn, ms });
        },
        ...options,
    });
    r.tick = (ms) => {
        r.clock += ms;
    };

    return r;
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('the closed list is the one JSON file, with no second copy in the bundle', () => {
    const file = JSON.parse(readFileSync(new URL('../../resources/js/editor/sync/metricKinds.json', import.meta.url), 'utf8'));
    assert.deepEqual(METRIC_KINDS, file);
    const source = readFileSync(new URL('../../resources/js/editor/sync/metrics.js', import.meta.url), 'utf8');
    assert.ok(source.includes("from './metricKinds.json'"));
    assert.doesNotMatch(source, /METRIC_KINDS\s*=\s*\[/);
});

test('every event the host reports is one metricKey() knows, and every name in the list can be reached', () => {
    const host = readFileSync(new URL('../../resources/js/editor/sync/host.js', import.meta.url), 'utf8');
    const events = new Set([...host.matchAll(/\bmetric\('([a-z-]+)'/g)].map((match) => match[1]));
    assert.deepEqual(
        [...events].sort(),
        ['backoff', 'conflict', 'echo', 'lost', 'lost-action', 'merge', 'peers', 'readonly', 'resend'],
        'a new event in host.js needs a mapping in metricKey() and a test'
    );
    // Reachability is asserted by the payload test at the end (every name in the list is produced by a public event).
});

test('time alone is not a kind at all', () => {
    assert.ok(!METRIC_KINDS.includes('peers_0_secs'));
    assert.deepEqual(METRIC_KINDS.filter((kind) => kind.startsWith('peers_')), ['peers_1_secs', 'peers_2_secs', 'peers_3plus_secs']);
});

test('the closed list is unique, short and made of plain names', () => {
    assert.equal(new Set(METRIC_KINDS).size, METRIC_KINDS.length);
    for (const kind of METRIC_KINDS) {
        assert.match(kind, /^[a-z0-9_]+$/);
        assert.ok(kind.length <= 32, `${kind} fits the column`);
    }
});

test('every event of the host maps to a name in the closed list, and nothing else maps at all', () => {
    const table = [
        [['merge', { source: 'merge', lost: false }], 'merge_live_clean'],
        [['merge', { source: 'merge', lost: true }], 'merge_live_lost'],
        [['merge', { source: 'draft', lost: false }], 'merge_draft_clean'],
        [['merge', { source: 'draft', lost: true }], 'merge_draft_lost'],
        [['merge', { source: 'suggestion', lost: false }], 'merge_suggestion_clean'],
        [['merge', { source: 'suggestion', lost: true }], 'merge_suggestion_lost'],
        [['lost', { reason: 'both', source: 'merge' }], 'lost_both_live'],
        [['lost', { reason: 'deleted', source: 'merge' }], 'lost_deleted_live'],
        [['lost', { reason: 'both', source: 'draft' }], 'lost_both_draft'],
        [['lost', { reason: 'deleted', source: 'draft' }], 'lost_deleted_draft'],
        [['lost', { reason: 'both', source: 'suggestion' }], 'lost_both_suggestion'],
        [['lost', { reason: 'deleted', source: 'suggestion' }], 'lost_deleted_suggestion'],
        [['lost-action', { action: 'put_back' }], 'lost_put_back'],
        [['lost-action', { action: 'dismiss' }], 'lost_dismissed'],
        [['conflict', { reason: 'impossible' }], 'conflict_impossible'],
        [['conflict', { reason: 'attempts' }], 'conflict_attempts'],
        [['conflict', { reason: 'expiry' }], 'conflict_expiry'],
        [['echo', {}], 'echo_resolved'],
        [['resend', {}], 'save_resent'],
        [['backoff', { level: 1 }], 'backoff_1'],
        [['backoff', { level: 6 }], 'backoff_6'],
        [['backoff', { level: 40 }], 'backoff_6'],
        [['readonly', { stage: 'provisional' }], 'readonly_provisional'],
        [['readonly', { stage: 'confirmed' }], 'readonly_confirmed'],
    ];
    for (const [[kind, detail], expected] of table) {
        assert.equal(metricKey(kind, detail), expected, `${kind} ${JSON.stringify(detail)}`);
        assert.ok(METRIC_KINDS.includes(expected));
    }

    // Anything that is not one of ours maps to nothing: a typo, a source or reason made up, text.
    for (const [kind, detail] of [
        ['merge', { source: 'elsewhere', lost: true }],
        ['lost', { reason: 'because', source: 'merge' }],
        ['lost', { reason: 'both', source: 'parked' }],
        ['lost-action', { action: 'erase' }],
        ['conflict', { reason: 'My secret paragraph' }],
        ['backoff', { level: 0 }],
        ['backoff', { level: 'high' }],
        ['readonly', { stage: 'maybe' }],
        ['title', { text: 'Quarterly numbers' }],
        ['', {}],
        [undefined, undefined],
        ['merge', null],
    ]) {
        assert.equal(metricKey(kind, detail), null, `${kind} ${JSON.stringify(detail)}`);
    }
    // Every name the table can produce is in the list: nothing is made up at the edges.
    assert.equal(new Set(table.map(([, name]) => name)).size, table.length - 1, 'one repeat only: level 40 and 6');
});

test('a flush carries the token and counts, and nothing else', async () => {
    const r = rig();
    r.metrics.record('merge', { source: 'merge', lost: true });
    r.metrics.record('merge', { source: 'merge', lost: true });
    r.metrics.record('lost', { reason: 'both', source: 'merge', n: 1 });
    r.metrics.record('conflict', { reason: 'expiry' });
    await r.metrics.flush();

    assert.deepEqual(r.sent, [
        { _token: TOKEN, counts: { merge_live_lost: 2, lost_both_live: 1, conflict_expiry: 1 } },
    ]);
    for (const body of r.sent) {
        assert.deepEqual(Object.keys(body).sort(), ['_token', 'counts']);
        for (const [kind, count] of Object.entries(body.counts)) {
            assert.ok(METRIC_KINDS.includes(kind));
            assert.ok(Number.isInteger(count) && count >= 1 && count <= MAX_COUNT);
        }
    }
    assert.deepEqual(r.metrics.waiting(), { pending: {}, carry: {} });
});

test('an event with a detail that is text is ignored, not stored', async () => {
    const r = rig();
    r.metrics.record('merge', { source: 'merge', lost: true, text: 'The secret paragraph', title: 'Q3' });
    r.metrics.record('lost', { reason: 'both', source: 'merge', excerpt: 'dear Thandi', n: 2 });
    await r.metrics.flush();

    const dump = JSON.stringify(r.sent);
    assert.ok(!dump.includes('secret') && !dump.includes('Thandi') && !dump.includes('Q3'));
    assert.deepEqual(r.sent[0].counts, { merge_live_lost: 1, lost_both_live: 2 });
});

test('nothing counted, nothing sent', async () => {
    const r = rig();
    assert.equal(await r.metrics.flush(), false);
    assert.equal(await r.metrics.flush({ beacon: true }), false);
    assert.deepEqual([r.sent, r.beacons], [[], []]);
});

test('flush cadence: not before a minute, then once, then not again inside the next minute', async () => {
    const r = rig();
    r.metrics.record('echo');
    r.tick(FLUSH_EVERY_MS - 1);
    r.metrics.record('echo');
    assert.equal(r.sent.length, 0, 'a minute has not passed');

    r.tick(1);
    r.metrics.record('echo');
    await settle();
    assert.equal(r.sent.length, 1, 'the first event after a minute sends');
    assert.deepEqual(r.sent[0].counts, { echo_resolved: 3 });

    for (let i = 0; i < 20; i++) {
        r.tick(1000);
        r.metrics.record('echo');
    }
    await settle();
    assert.equal(r.sent.length, 1, 'twenty seconds of events send nothing more');

    r.tick(FLUSH_EVERY_MS);
    r.metrics.record('echo');
    await settle();
    assert.equal(r.sent.length, 2);
    assert.deepEqual(r.sent[1].counts, { echo_resolved: 21 });
});

test('a flush that is still out is not followed by a second one', async () => {
    const r = rig();
    let release;
    r.metrics = createMetrics({
        now: () => r.clock,
        token: () => TOKEN,
        send: (body) => {
            r.sent.push(JSON.parse(body));

            return new Promise((resolve) => {
                release = resolve;
            });
        },
    });
    r.metrics.record('echo');
    r.tick(FLUSH_EVERY_MS);
    r.metrics.record('echo');
    assert.equal(r.sent.length, 1);

    r.tick(FLUSH_EVERY_MS);
    r.metrics.record('echo');
    assert.equal(r.sent.length, 1, 'still waiting for the first answer');

    release({ ok: true });
    await settle();
    r.tick(FLUSH_EVERY_MS);
    r.metrics.record('echo');
    assert.equal(r.sent.length, 2);
    assert.deepEqual(r.sent[1].counts, { echo_resolved: 2 });
});

test('people present are counted in seconds, by how many OTHERS were there, and time alone is not counted', async () => {
    const r = rig();
    r.metrics.record('peers', { others: 0 }); // the first poll has no earlier one to measure from
    r.tick(1500);
    r.metrics.record('peers', { others: 0 });
    r.tick(10000);
    r.metrics.record('peers', { others: 1 });
    r.tick(1500);
    r.metrics.record('peers', { others: 2 });
    r.tick(1500);
    r.metrics.record('peers', { others: 7 }); // seven others is "3 or more"
    r.tick(1500);
    r.metrics.record('peers', { others: 3 });
    await r.metrics.flush();

    // The 1.5 s alone are not counted; 10 s with one other, 1.5 s with two (1 s and 0.5 left), 3 s with three or more.
    assert.deepEqual(r.sent[0].counts, { peers_1_secs: 10, peers_2_secs: 1, peers_3plus_secs: 3 });
    assert.ok(!JSON.stringify(r.sent).includes('peers_0'));
});

test('time alone still moves the clock: the first poll with company is credited only its own interval', async () => {
    const r = rig();
    r.metrics.record('peers', { others: 0 });
    r.tick(60_000);
    r.metrics.record('peers', { others: 0 });
    r.tick(3000);
    r.metrics.record('peers', { others: 1 });
    await r.metrics.flush();

    assert.deepEqual(r.sent[0].counts, { peers_1_secs: 3 });
});

test('half seconds are kept until they make a whole one', async () => {
    const r = rig();
    r.metrics.record('peers', { others: 1 });
    for (let i = 0; i < 4; i++) {
        r.tick(500);
        r.metrics.record('peers', { others: 1 });
    }
    await r.metrics.flush();

    assert.deepEqual(r.sent[0].counts, { peers_1_secs: 2 });
});

test('a long gap between polls (a hidden tab) is credited as one poll interval, not as company all that time', async () => {
    const r = rig();
    r.metrics.record('peers', { others: 2 });
    r.tick(3_600_000);
    r.metrics.record('peers', { others: 2 });
    await r.metrics.flush();

    assert.deepEqual(r.sent[0].counts, { peers_2_secs: PEERS_GAP_MS / 1000 });
});

test('a failed flush is dropped without a word, and its counts get exactly one more try', async () => {
    const r = rig();
    r.sendMode = 'reject';

    r.metrics.record('conflict', { reason: 'attempts' });
    assert.equal(await r.metrics.flush(), false, 'resolves false, never rejects');
    assert.deepEqual(r.metrics.waiting().carry, { conflict_attempts: 1 });

    // The next flush carries the retry together with what is new.
    r.metrics.record('echo');
    r.sendMode = 'reject';
    await r.metrics.flush();
    assert.deepEqual(r.sent[1].counts, { conflict_attempts: 1, echo_resolved: 1 });
    assert.deepEqual(r.metrics.waiting().carry, { echo_resolved: 1 }, 'only the counts that were new are held again');

    // And the one after that no longer has the first.
    r.sendMode = 'ok';
    await r.metrics.flush();
    assert.deepEqual(r.sent[2].counts, { echo_resolved: 1 });
    assert.deepEqual(r.metrics.waiting(), { pending: {}, carry: {} });

    r.metrics.record('echo');
    await r.metrics.flush();
    assert.deepEqual(r.sent[3].counts, { echo_resolved: 1 }, 'nothing is left over to resend');
});

test('a refusal (429, 419, 5xx) is a failure like a lost request', async () => {
    const r = rig();
    r.sendMode = 'refused';
    r.metrics.record('echo');
    assert.equal(await r.metrics.flush(), false);
    assert.deepEqual(r.metrics.waiting().carry, { echo_resolved: 1 });
});

test('a send that throws on the spot does not escape record() or flush()', async () => {
    const r = rig();
    r.sendMode = 'throw';
    r.metrics.record('echo');
    r.tick(FLUSH_EVERY_MS);
    assert.doesNotThrow(() => r.metrics.record('echo'));
    await settle();
    assert.equal(await r.metrics.flush(), false);

    // And a token that cannot be read.
    const broken = createMetrics({ now: () => r.clock, token: () => { throw new Error('no meta tag'); }, send: () => Promise.resolve({ ok: true }) });
    broken.record('echo');
    assert.doesNotThrow(() => broken.flush());
    assert.equal(await broken.flush(), false);
});

test('a failed flush never makes a storm: one request a minute, however many events', async () => {
    const r = rig();
    r.sendMode = 'reject';
    for (let minute = 0; minute < 5; minute++) {
        for (let i = 0; i < 30; i++) {
            r.tick(2000);
            r.metrics.record('echo');
        }
        await settle();
    }
    // Five minutes of events, a send per minute at the most.
    assert.ok(r.sent.length <= 5, `${r.sent.length} sends in five minutes`);
    assert.ok(r.sent.length >= 4);
});

test('leaving the page flushes once by beacon, whatever the minute says', async () => {
    const r = rig();
    r.metrics.record('merge', { source: 'draft', lost: false });
    r.tick(5000);

    assert.equal(await r.metrics.flush({ beacon: true }), true);
    assert.deepEqual(r.beacons, [{ _token: TOKEN, counts: { merge_draft_clean: 1 } }]);
    assert.equal(r.sent.length, 0);
    assert.deepEqual(r.metrics.waiting(), { pending: {}, carry: {} });
});

test('leaving also flushes the seconds counted so far, and what a failed flush was holding', async () => {
    const r = rig();
    r.sendMode = 'reject';
    r.metrics.record('echo');
    await r.metrics.flush();
    r.metrics.record('peers', { others: 1 });
    r.tick(4000);
    r.metrics.record('peers', { others: 1 });
    await r.metrics.flush({ beacon: true });

    assert.deepEqual(r.beacons[0].counts, { echo_resolved: 1, peers_1_secs: 4 });
});

test('when the beacon is refused or missing or throws, the same body goes by send, once, and failures are swallowed', async () => {
    for (const mode of ['refuse', 'throw']) {
        const r = rig();
        r.beaconMode = mode;
        r.sendMode = 'reject';
        r.metrics.record('echo');
        assert.equal(await r.metrics.flush({ beacon: true }), false);
        await settle();
        assert.equal(r.sent.length, 1, mode);
        assert.deepEqual(r.sent[0].counts, { echo_resolved: 1 });
    }

    const none = rig({ beacon: undefined });
    none.metrics.record('echo');
    await none.metrics.flush({ beacon: true });
    assert.equal(none.sent.length, 1);

    // Nothing is held after leaving: the page is going.
    assert.deepEqual(none.metrics.waiting(), { pending: {}, carry: {} });
});

test('switched off, nothing is counted and nothing is sent', async () => {
    const r = rig({ enabled: false });
    r.metrics.record('echo');
    r.metrics.record('peers', { others: 2 });
    r.tick(FLUSH_EVERY_MS * 3);
    r.metrics.record('echo');
    assert.equal(await r.metrics.flush(), false);
    assert.equal(await r.metrics.flush({ beacon: true }), false);
    assert.deepEqual([r.sent, r.beacons, r.metrics.waiting()], [[], [], { pending: {}, carry: {} }]);
});

test('no count goes past the cap the server accepts', async () => {
    const r = rig();
    for (let i = 0; i < 5; i++) {
        r.metrics.record('echo', { n: MAX_COUNT });
    }
    await r.metrics.flush();
    assert.deepEqual(r.sent[0].counts, { echo_resolved: MAX_COUNT });
});

test('even every kind at the cap is a payload of about a kilobyte, inside the limit the server sets', async () => {
    const r = rig();
    const sent = [];
    const everything = createMetrics({
        now: () => r.clock,
        token: () => TOKEN,
        flushEveryMs: Infinity,
        send: (body) => {
            sent.push(body);

            return Promise.resolve({ ok: true });
        },
    });
    // Reach every name through the public events only.
    for (const source of ['merge', 'draft', 'suggestion']) {
        for (const lost of [true, false]) everything.record('merge', { source, lost, n: MAX_COUNT });
        for (const reason of ['both', 'deleted']) everything.record('lost', { reason, source, n: MAX_COUNT });
    }
    for (const action of ['put_back', 'dismiss']) everything.record('lost-action', { action, n: MAX_COUNT });
    for (const reason of ['impossible', 'attempts', 'expiry']) everything.record('conflict', { reason, n: MAX_COUNT });
    everything.record('open');
    everything.record('echo', { n: MAX_COUNT });
    everything.record('resend', { n: MAX_COUNT });
    for (let level = 1; level <= 6; level++) everything.record('backoff', { level, n: MAX_COUNT });
    for (const stage of ['provisional', 'confirmed']) everything.record('readonly', { stage, n: MAX_COUNT });
    // Seconds: one poll credits at most PEERS_GAP_MS, so an hour of company is 180 polls per bucket.
    everything.record('peers', { others: 0 });
    for (let poll = 0; poll < MAX_COUNT / (PEERS_GAP_MS / 1000); poll++) {
        for (const others of [0, 1, 2, 3]) {
            r.clock += PEERS_GAP_MS;
            everything.record('peers', { others });
        }
    }
    await everything.flush();

    const body = JSON.parse(sent.at(-1));
    assert.deepEqual(Object.keys(body.counts).sort(), [...METRIC_KINDS].sort(), 'every name in the list can be reached');
    assert.ok(sent.at(-1).length < 1024, `${sent.at(-1).length} bytes`);
});

// ---------------------------------------------------------------------------------------------------------------
// Editor sessions opened: the denominator for every other count, and the one that tells "the pipeline is broken"
// from "nobody co-edited". One per page mount, with no other data, solo and read only included.
// ---------------------------------------------------------------------------------------------------------------

test('editor_open is a closed kind and the page event open maps to it, and to nothing else', () => {
    assert.ok(METRIC_KINDS.includes('editor_open'));
    assert.equal(metricKey('open', {}), 'editor_open');
    assert.equal(metricKey('open'), 'editor_open');
    // A detail on it is never read: a session is a session.
    assert.equal(metricKey('open', { title: 'Quarterly numbers', source: 'merge' }), 'editor_open');
});

test('a session carries the token and one count, with no other data', async () => {
    const r = rig();
    r.metrics.record('open', { title: 'Quarterly numbers', user: 'Thandi', text: 'The secret paragraph' });
    await r.metrics.flush();

    assert.deepEqual(r.sent, [{ _token: TOKEN, counts: { editor_open: 1 } }]);
    assert.ok(!JSON.stringify(r.sent).match(/Quarterly|Thandi|secret/));
});

test('a page counts its open once, however often it is told', async () => {
    const r = rig();
    r.metrics.record('open');
    r.metrics.record('open');
    r.metrics.record('open', { n: 5 });
    await r.metrics.flush();

    assert.deepEqual(r.sent[0].counts, { editor_open: 1 });
    assert.equal(r.timers.length, 1, 'and asks for one first flush, not three');
});

test('the first flush of a page is asked for 5 seconds after it opened, not a minute', () => {
    assert.equal(FIRST_FLUSH_MS, 5000);
    assert.ok(FIRST_FLUSH_MS < FLUSH_EVERY_MS);
    const r = rig();
    r.metrics.record('open');

    assert.equal(r.timers.length, 1);
    assert.equal(r.timers[0].ms, FIRST_FLUSH_MS);
    assert.equal(r.sent.length, 0, 'the timer has not run, so nothing has gone yet');
});

test('when that timer runs, the session is sent by an ordinary request, once', async () => {
    const r = rig();
    r.metrics.record('open');
    r.tick(FIRST_FLUSH_MS);
    r.timers[0].fn();
    await settle();

    assert.deepEqual(r.sent, [{ _token: TOKEN, counts: { editor_open: 1 } }]);
    assert.deepEqual(r.beacons, []);
    assert.deepEqual(r.metrics.waiting(), { pending: {}, carry: {} });

    // Running it again (or leaving afterwards) sends nothing: the session was already counted.
    r.timers[0].fn();
    await r.metrics.flush({ beacon: true });
    await settle();
    assert.equal(r.sent.length, 1);
    assert.deepEqual(r.beacons, []);
});

test('the first flush also carries what was counted in those 5 seconds', async () => {
    const r = rig();
    r.metrics.record('open');
    r.tick(3000);
    r.metrics.record('merge', { source: 'merge', lost: false });
    r.tick(2000);
    r.timers[0].fn();
    await settle();

    assert.deepEqual(r.sent.map((body) => body.counts), [{ editor_open: 1, merge_live_clean: 1 }]);
});

test('after the early flush the minute rule is unchanged: the next regular flush is a minute after it', async () => {
    const r = rig();
    r.metrics.record('open');
    r.tick(FIRST_FLUSH_MS);
    r.timers[0].fn();
    await settle();
    assert.equal(r.sent.length, 1);

    r.tick(FLUSH_EVERY_MS - 1);
    r.metrics.record('echo');
    assert.equal(r.sent.length, 1, 'not yet: a minute has not passed since the early flush');
    r.tick(1);
    r.metrics.record('echo');
    await settle();
    assert.equal(r.sent.length, 2);
    assert.deepEqual(r.sent[1].counts, { echo_resolved: 2 });
});

test('a short visit still records: leaving before the timer ran sends the session by beacon, once', async () => {
    const r = rig();
    r.metrics.record('open');
    r.tick(1500);
    assert.equal(await r.metrics.flush({ beacon: true }), true);

    assert.deepEqual(r.beacons, [{ _token: TOKEN, counts: { editor_open: 1 } }]);
    // And the timer, which fires later on a page that is going or gone, finds nothing to send.
    r.timers[0].fn();
    await settle();
    assert.deepEqual(r.sent, []);
});

test('a failed early flush is dropped without a word and its session rides along once more', async () => {
    const r = rig();
    r.sendMode = 'reject';
    r.metrics.record('open');
    r.timers[0].fn();
    await settle();
    assert.equal(r.sent.length, 1);
    assert.deepEqual(r.metrics.waiting().carry, { editor_open: 1 });

    r.sendMode = 'ok';
    await r.metrics.flush({ beacon: true });
    assert.deepEqual(r.beacons, [{ _token: TOKEN, counts: { editor_open: 1 } }], 'once more, by the beacon on leaving');
    assert.deepEqual(r.metrics.waiting(), { pending: {}, carry: {} });
});

test('the early flush is one request at most: no storm, however the page behaves', async () => {
    const r = rig();
    r.sendMode = 'refused';
    r.metrics.record('open');
    for (let i = 0; i < 20; i++) {
        r.metrics.record('echo');
    }
    r.timers[0].fn();
    r.timers[0].fn();
    r.timers[0].fn();
    await settle();

    assert.equal(r.timers.length, 1);
    assert.equal(r.sent.length, 1, 'one request in the air at a time, and the repeats found it busy or empty');
});

test('a timer that cannot be set, or throws, never breaks the page and the session still goes on leaving', async () => {
    for (const schedule of [undefined, () => { throw new Error('no timers'); }]) {
        const r = rig({ schedule });
        assert.doesNotThrow(() => r.metrics.record('open'));
        assert.doesNotThrow(() => r.metrics.record('merge', { source: 'merge', lost: false }));
        await r.metrics.flush({ beacon: true });
        assert.deepEqual(r.beacons.map((body) => body.counts), [{ editor_open: 1, merge_live_clean: 1 }]);
    }
});

test('a timer callback that throws inside flush is swallowed', async () => {
    const r = rig({ token: () => { throw new Error('no meta tag'); } });
    r.metrics.record('open');
    assert.doesNotThrow(() => r.timers[0].fn());
    await settle();
    assert.deepEqual(r.sent, []);
});

test('switched off, a session is not counted and no timer is asked for', async () => {
    const r = rig({ enabled: false });
    r.metrics.record('open');
    await r.metrics.flush({ beacon: true });

    assert.deepEqual([r.sent, r.beacons, r.timers], [[], [], []]);
});
