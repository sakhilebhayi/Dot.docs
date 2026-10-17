import assert from 'node:assert/strict';
import test from 'node:test';

import { MERGE_ATTEMPTS, MERGE_FETCH_EXPIRY_MS, SAVE_EXPIRY_MS } from '../../resources/js/editor/sync/host.js';
import { metricKey } from '../../resources/js/editor/sync/metrics.js';
import { blocksOf, doc, makeTab, pair, settle, world } from './support/syncWorld.js';

/**
 * What the sync host tells the page's counters (env.metric, see
 * sync/metrics.js): which events count, as what, and that counting never
 * changes what the host does. The counters are built with `metrics: true`;
 * every other test file runs without them, which is the "off" case.
 *
 * Counts and kinds only: every detail below is a word from a closed set or a
 * number, never a piece of the document.
 */

const both = (options = {}) => pair({ A: { metrics: true, ...(options.A ?? {}) }, B: { metrics: true, ...(options.B ?? {}) } });

/** The events of a tab other than the presence samples (every answered poll makes one). */
const events = (tab) => tab.metrics.filter(([kind]) => kind !== 'peers');

async function twoParagraphs() {
    const w = await both();
    const { A, B } = w;
    A.insertAfter('p1', 'p2', 'World');
    await A.debounce();
    await A.land();
    await B.cycle();

    return w;
}

const failOldest = async (tab) => {
    tab.saves.shift().reject(new Error('network'));
    await settle();
};

// ─────────────────────────────────────────────────────────── (a) merges and (b) what they could not keep

test('a live merge that keeps everything counts as one clean merge and nothing lost', async () => {
    const { A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(events(B), [['merge', { source: 'merge', lost: false }]]);
    assert.deepEqual(B.blocks(), [['p1', 'Hello A'], ['p2', 'World B']]);
});

test('a same-paragraph clash (D1) counts one merge with lost entries and one both-lost offer', async () => {
    const { A, B } = await both();
    A.type('Hello A');
    B.type('Hello B');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(events(B), [
        ['merge', { source: 'merge', lost: true }],
        ['lost', { reason: 'both', source: 'merge' }],
    ]);
    assert.deepEqual(events(A), [], 'the winner counts nothing');
});

test('a deletion against typing (D2) counts a deleted-lost offer', async () => {
    const { A, B } = await twoParagraphs();
    A.insertAfter('p2', 'p3', 'Third');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.metrics.length = 0;

    B.typeIn('p2', 'World B');
    A.deleteBlock('p2');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(events(B), [
        ['merge', { source: 'merge', lost: true }],
        ['lost', { reason: 'deleted', source: 'merge' }],
    ]);
});

test('two losses in one merge count two offers, and a ping-pong counts each round', async () => {
    const { A, B } = await twoParagraphs();
    A.typeIn('p1', 'A one');
    A.typeIn('p2', 'A two');
    B.typeIn('p1', 'B one');
    B.typeIn('p2', 'B two');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(events(B).filter(([kind]) => kind === 'lost'), [
        ['lost', { reason: 'both', source: 'merge' }],
        ['lost', { reason: 'both', source: 'merge' }],
    ]);
    assert.equal(events(B).filter(([kind]) => kind === 'merge').length, 1, 'one merge');
});

test('a draft merged on reopen counts as a draft merge, its offers as draft offers', async () => {
    const { server, A, B } = await both();
    // B types in the same paragraph A saves, then the page dies with the draft behind.
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await A.land();
    B.drafts.set('base-doc-uuid', { json: JSON.stringify(doc('start')), baseVersion: 1 });

    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts, metrics: true });
    await again.open();

    assert.ok(again.questions.length > 0, 'the writer was asked');
    assert.deepEqual(events(again), [
        ['merge', { source: 'draft', lost: true }],
        ['lost', { reason: 'both', source: 'draft' }],
    ]);
});

test('a draft merged on reopen that loses nothing counts as a clean draft merge', async () => {
    const { server, A, B } = await twoParagraphs();
    // B types in p2 and its page dies; A saves p1 in the meantime.
    B.typeIn('p2', 'World B');
    await B.debounce();
    A.typeIn('p1', 'Hello A');
    await A.debounce();
    await A.land();
    assert.ok(B.drafts.get('doc-uuid'), 'a draft of B is behind');

    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts, metrics: true });
    await again.open();

    assert.deepEqual(events(again), [['merge', { source: 'draft', lost: false }]]);
});

test('an accepted suggestion merged over unsaved typing counts as a suggestion merge, its offers as suggestion offers', async () => {
    const { server, A, B } = await both();
    B.type('B wrote this');
    await B.debounce();
    await B.land();
    A.type('A unsaved');
    const version = server.replace(doc('the accepted suggestion'));

    A.host().applySuggestion(doc('the accepted suggestion'), version);

    assert.deepEqual(events(A), [
        ['merge', { source: 'suggestion', lost: true }],
        ['lost', { reason: 'both', source: 'suggestion' }],
    ]);
});

test('a merge that is not performed counts nothing: the notice, a fail-closed page, a read only page', async () => {
    const closed = makeTab(world(), 'C', { failClosed: true, metrics: true });
    await closed.open();
    closed.host().mergeOrConflict({ version: 2, json: doc('x'), outline: null, css: null });
    assert.deepEqual(events(closed), []);

    const w = world();
    const reader = w.tab('R', { readOnly: true, metrics: true });
    await reader.open();
    reader.host().mergeOrConflict({ version: 2, json: doc('x'), outline: null, css: null });
    assert.deepEqual(events(reader), []);
});

// ─────────────────────────────────────────────────────────── (b) what the writer does with the offers

async function clashed() {
    const w = await both();
    const { A, B } = w;
    A.type('Hello A');
    B.type('Hello B');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.metrics.length = 0;

    return w;
}

test('Put it back counts once per block that went in, and not when it could not', async () => {
    const { B } = await clashed();

    B.insertBlockFails = true;
    assert.equal(B.host().putBackLost(0), false);
    assert.deepEqual(events(B), []);

    B.insertBlockFails = false;
    assert.equal(B.host().putBackLost(0), true);
    assert.deepEqual(events(B), [['lost-action', { action: 'put_back' }]]);
});

test('Put them all back counts each block', async () => {
    const { A, B } = await twoParagraphs();
    A.typeIn('p1', 'A one');
    A.typeIn('p2', 'A two');
    B.typeIn('p1', 'B one');
    B.typeIn('p2', 'B two');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.metrics.length = 0;

    B.host().putBackAllLost();

    assert.deepEqual(events(B), [
        ['lost-action', { action: 'put_back' }],
        ['lost-action', { action: 'put_back' }],
    ]);
});

test('Dismiss all counts the entries dismissed, only when they really went', async () => {
    const { B } = await clashed();

    B.confirmAnswer = false;
    await B.host().dismissLost();
    assert.deepEqual(events(B), [], 'declined: nothing changes, nothing is counted');

    B.confirmAnswer = true;
    B.parkLostFails = true;
    await B.host().dismissLost();
    assert.deepEqual(events(B), [], 'the copy could not be kept, so nothing was dismissed');

    B.parkLostFails = false;
    B.view.syncNotice = '';
    await B.host().dismissLost();
    assert.deepEqual(events(B), [['lost-action', { action: 'dismiss', n: 1 }]]);
    assert.deepEqual(B.view.lost, []);
});

test('Dismiss all without a copy (the second question) counts too', async () => {
    const { B } = await clashed();
    B.parkLostFails = true;
    await B.host().dismissLost(); // fails and says so
    assert.deepEqual(events(B), []);

    await B.host().dismissLost(); // asks again: dismiss anyway
    assert.deepEqual(events(B), [['lost-action', { action: 'dismiss', n: 1 }]]);
});

// ─────────────────────────────────────────────────────────── (c) the conflict notice

test('a merge that cannot be done raises the notice once and counts it as impossible', async () => {
    const w = await both({ B: { mergeFails: true } });
    const { A, B } = w;
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    await B.debounce();

    assert.deepEqual(B.view.conflict && B.view.conflict.version, 2);
    assert.deepEqual(events(B), [['conflict', { reason: 'impossible' }]]);

    // The notice standing and learning of a newer version is not a second notice.
    A.type('A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(events(B), [['conflict', { reason: 'impossible' }]]);
});

test('MERGE_ATTEMPTS refusals in a row count as attempts exhausted, once', async () => {
    const { A, B } = await twoParagraphs();
    B.typeIn('p2', 'World B');
    await B.debounce();

    for (let round = 1; round <= MERGE_ATTEMPTS + 1; round += 1) {
        A.typeIn('p1', `A${round}`);
        await A.debounce();
        await A.land();
        B.saveArrives();
        await B.saveReturns();
        if (round <= MERGE_ATTEMPTS) {
            await B.poll();
        }
    }

    assert.notEqual(B.view.conflict, null);
    assert.equal(events(B).filter(([kind]) => kind === 'conflict').length, 1);
    assert.deepEqual(events(B).filter(([kind]) => kind === 'conflict')[0], ['conflict', { reason: 'attempts' }]);
    assert.equal(events(B).filter(([kind]) => kind === 'merge').length, MERGE_ATTEMPTS, 'and each merge before it was counted');
});

test('a merge fetch that never answers counts as an expiry', async () => {
    const { clock, A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await B.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    B.metrics.length = 0;

    await B.pollAnswers(500, null);
    clock.now += MERGE_FETCH_EXPIRY_MS;
    await B.fireTimer();
    await B.pollAnswers(500, null);

    assert.notEqual(B.view.conflict, null);
    assert.deepEqual(events(B), [['conflict', { reason: 'expiry' }]]);
});

// ─────────────────────────────────────────────────────────── (d) the echo rule

test('a save whose answer was lost, recognised as the tab own, counts as resolved by the echo rule', async () => {
    const { clock, A } = await both();
    A.type('X');
    await A.debounce();
    A.saveArrives();
    A.saveNeverAnswers();
    A.type('XY');
    await A.debounce();
    clock.now += SAVE_EXPIRY_MS;
    await A.fireTimer();
    await A.pollAnswers(503, null);
    A.metrics.length = 0;

    await A.land();
    await A.poll();

    assert.equal(A.text(), 'XY');
    assert.deepEqual(A.lostTexts(), [], 'nothing was offered back: the echo rule kept the tab own words');
    assert.deepEqual(events(A), [
        ['merge', { source: 'merge', lost: false }],
        ['echo', {}],
    ]);
});

test('somebody else editing the same paragraph after the echo is a true clash: the echo rule did not decide it', async () => {
    const { clock, A, B } = await both();
    A.type('X');
    await A.debounce();
    A.saveArrives(); // version 2
    A.saveNeverAnswers();
    await B.cycle(); // B follows version 2
    B.type('X by B');
    await B.debounce();
    await B.land(); // version 3
    A.type('XY');
    clock.now += SAVE_EXPIRY_MS;
    A.metrics.length = 0;

    await A.cycle();

    assert.deepEqual(A.lostTexts(), ['XY'], 'offered back, as a true clash');
    assert.deepEqual(events(A), [
        ['merge', { source: 'merge', lost: true }],
        ['lost', { reason: 'both', source: 'merge' }],
    ]);
});

// ─────────────────────────────────────────────────────────── (e) saves resent, back-off

test('a failed request counts the back-off level it reached, and the next send counts as a resend', async () => {
    const { clock, A } = await both();
    A.type('A1');
    await A.debounce();
    assert.deepEqual(events(A), [], 'a first send is not a resend');

    for (const level of [1, 2, 3]) {
        await failOldest(A);
        clock.now += 100_000;
        await A.cycle();
        assert.deepEqual(events(A).at(-2), ['backoff', { level }]);
        assert.deepEqual(events(A).at(-1), ['resend', {}]);
    }

    // One answer ends the row: the next failure is level 1 again.
    A.saves.shift();
    await A.land().catch(() => {});
    assert.equal(events(A).filter(([kind]) => kind === 'backoff').length, 3);
});

test('a save answered, accepted or refused, is neither a failure nor a resend', async () => {
    const { A, B } = await both();
    A.type('A1');
    await A.debounce();
    await A.land();
    B.type('B1');
    await B.debounce();
    await B.land();

    assert.deepEqual(events(A).filter(([kind]) => ['backoff', 'resend'].includes(kind)), []);
    assert.deepEqual(events(B).filter(([kind]) => ['backoff', 'resend'].includes(kind)), []);
});

// ─────────────────────────────────────────────────────────── (f) read only downgrades

async function writerWithReader(options) {
    const w = world();
    const writer = w.tab('W', { metrics: true });
    const reader = w.tab('R', { readOnly: true, metrics: true });
    await writer.open();
    await reader.open();
    await writer.poll();
    await reader.poll();
    await writer.cycle();
    Object.assign(writer, options);
    writer.type('words in the air');
    await writer.debounce();

    return { ...w, writer, reader };
}

test('a 403 on a save the server confirms counts a provisional downgrade and then a confirmed one', async () => {
    const { writer } = await writerWithReader({ canEditAnswer: false });
    writer.metrics.length = 0;

    writer.host().saveRefused(403);
    await settle();

    assert.deepEqual(events(writer), [
        ['readonly', { stage: 'provisional' }],
        ['readonly', { stage: 'confirmed' }],
    ]);
});

test('a 403 the server does not stand behind counts only the provisional downgrade', async () => {
    const { writer } = await writerWithReader({ canEditAnswer: true });
    writer.metrics.length = 0;

    writer.host().saveRefused(403);
    await settle();

    assert.deepEqual(events(writer), [['readonly', { stage: 'provisional' }]]);
});

test('a second 403 while the question is out, and a status that is not a 403, count nothing more', async () => {
    const { writer } = await writerWithReader({ canEditMode: 'hang' });
    writer.metrics.length = 0;

    writer.host().saveRefused(403);
    writer.host().saveRefused(403);
    writer.host().saveRefused(500);
    await settle();

    assert.deepEqual(events(writer), [['readonly', { stage: 'provisional' }]]);
});

test('a re-render that draws the page read only counts a confirmed downgrade, once', async () => {
    const { writer } = await writerWithReader({});
    writer.metrics.length = 0;
    writer.handle.makeReadOnly();

    writer.host().readOnlyNoticed();
    writer.host().readOnlyNoticed();

    assert.deepEqual(events(writer), [['readonly', { stage: 'confirmed' }]]);
});

test('a page that was never editable counts no downgrade', async () => {
    const { reader } = await writerWithReader({});
    reader.host().readOnlyNoticed();
    await reader.cycle();

    assert.deepEqual(events(reader), []);
});

// ─────────────────────────────────────────────────────────── (g) who was here

test('every answered poll reports how many OTHER people were present', async () => {
    const { A, B } = await both();

    // pair(): A polled before B had, then again.
    assert.deepEqual(A.metrics.map(([, detail]) => detail.others), [0, 1]);
    assert.deepEqual(B.metrics.map(([, detail]) => detail.others), [1]);
    assert.ok(A.metrics.every(([kind]) => kind === 'peers'));

    await A.cycle();
    await B.cycle();
    assert.equal(A.metrics.at(-1)[1].others, 1);
});

test('a poll that failed reports nobody', async () => {
    const { A } = await both();
    const before = A.metrics.length;

    await A.fireTimer();
    await A.pollAnswers(503, null);
    await A.fireTimer();
    await A.pollFails();

    assert.equal(A.metrics.length, before);
});

test('alone, a tab reports zero others', async () => {
    const w = world();
    const solo = w.tab('S', { metrics: true });
    await solo.open();
    await solo.poll();

    assert.deepEqual(solo.metrics, [['peers', { others: 0 }]]);
});

// ─────────────────────────────────────────────────────────── counting never changes editing

/** The same D1 clash and a lost save, run with the counters in place, absent, and throwing: the page ends up identical. */
async function theSameDay(options) {
    const w = await pair({ A: options, B: options });
    const { A, B } = w;
    A.type('Hello A');
    B.type('Hello B');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.host().putBackLost(0);
    await B.debounce();
    A.type('Again');
    await A.debounce();
    await failOldest(A);
    await A.cycle();
    await A.land();
    await B.cycle();
    await A.cycle();

    return {
        server: w.server.json,
        a: [A.blocks(), A.words(), A.view.baseVersion, A.view.saveFailures, A.sent.length, A.draft()],
        b: [B.blocks(), B.words(), B.view.baseVersion, B.view.lost.length, B.sent.length, B.draft()],
    };
}

test('with the counters absent, present or throwing, the page does exactly the same', async () => {
    const without = await theSameDay({});
    const counted = await theSameDay({ metrics: true });
    const throwing = await (async () => {
        // A recorder that blows up on every event.
        const w = await pair({ A: { metrics: true }, B: { metrics: true } });
        w.A.metricThrows = true;
        w.B.metricThrows = true;
        w.A.type('Hello A');
        w.B.type('Hello B');
        await w.A.debounce();
        await w.A.land();
        await w.B.cycle();
        w.B.host().putBackLost(0);
        await w.B.debounce();
        w.A.type('Again');
        await w.A.debounce();
        await failOldest(w.A);
        await w.A.cycle();
        await w.A.land();
        await w.B.cycle();
        await w.A.cycle();

        return {
            server: w.server.json,
            a: [w.A.blocks(), w.A.words(), w.A.view.baseVersion, w.A.view.saveFailures, w.A.sent.length, w.A.draft()],
            b: [w.B.blocks(), w.B.words(), w.B.view.baseVersion, w.B.view.lost.length, w.B.sent.length, w.B.draft()],
        };
    })();

    assert.deepEqual(counted, without);
    assert.deepEqual(throwing, without);
    assert.deepEqual(blocksOf(without.server).length > 0, true);
});

test('nothing the host tells the counters is a word of the document', async () => {
    const { A, B } = await both();
    A.type('Hello A secret');
    B.type('Hello B secret');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.host().putBackLost(0);

    const dump = JSON.stringify([A.metrics, B.metrics]);
    assert.ok(!/secret|Hello/.test(dump), dump);
    // And every event maps to a name in the closed list or is the presence sample.
    for (const [kind, detail] of [...A.metrics, ...B.metrics]) {
        assert.ok(kind === 'peers' || metricKey(kind, detail) !== null, `${kind} ${JSON.stringify(detail)}`);
    }
});

test('with the counters switched off (no env.metric) the host does no extra merge work for the echo check', async () => {
    const run = async (metrics) => {
        const { clock, A } = await both({ A: { metrics } });
        A.type('X');
        await A.debounce();
        A.saveArrives();
        A.saveNeverAnswers();
        A.type('XY');
        await A.debounce();
        clock.now += SAVE_EXPIRY_MS;
        await A.fireTimer();
        await A.pollAnswers(503, null);
        const before = A.mergeCalls;
        await A.land();
        await A.poll();

        return { merges: A.mergeCalls - before, text: A.text() };
    };

    const off = await run(false);
    const on = await run(true);

    assert.equal(off.merges, 1, 'one merge');
    assert.equal(on.merges, 2, 'the echo check is the second, and only when somebody is counting');
    assert.equal(off.text, on.text);
});
