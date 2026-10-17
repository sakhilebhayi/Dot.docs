import assert from 'node:assert/strict';
import test from 'node:test';

import { blockWord } from '../../resources/js/editor/sync/merge.js';
import {
    DISCARD_QUESTION,
    DISMISS_LOST_CHANGED_NOTICE,
    DISMISS_LOST_FAILED_NOTICE,
    DISMISS_LOST_QUESTION,
    DISMISS_LOST_UNKEPT_QUESTION,
    DRAFT_MERGE_QUESTION,
    LOAD_THEIRS_REPLACES_QUESTION,
    MERGE_ATTEMPTS,
    MERGE_FETCH_EXPIRY_MS,
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    SAVE_EXPIRY_MS,
    SAVE_RETRY_DELAYS_MS,
    beaconWanted,
    createSyncHost,
    createTabId,
    unloadSaveBody,
} from '../../resources/js/editor/sync/host.js';
import {
    BASE,
    LOST,
    STALE,
    UUID,
    blocksOf,
    conflicted,
    doc,
    docOf,
    makeTab,
    makeView,
    pair,
    para,
    settle,
    textOf,
    world,
} from './support/syncWorld.js';

/**
 * The page's half of the sync state machine (sync/host.js), driven together
 * with the REAL polling engine (sync/engine.js) and the REAL merge
 * (sync/merge.js) against the fake world in support/syncWorld.js: a fake
 * server, a block-shaped fake editor handle, a fake draft store, an
 * injected clock, saves and polls answered by hand. `doc(text)` is the
 * one-paragraph document (block p1) every case below is written against;
 * `t.text()`, `server.text()`, a save's `.text` and a beacon's `.text` are
 * the text of that paragraph.
 */

// ───────────────────────────────────────────────────────── following

test('one person types, the other follows: the document is applied, the base moves, the draft goes', async () => {
    const { server, A, B } = await pair();
    // A leftover draft in B's browser: the server is about to hold newer content than it.
    B.drafts.set(UUID, { json: JSON.stringify(doc('start')), baseVersion: 1 });

    A.type('A1');
    assert.equal(A.view.unsaved, true);
    assert.equal(A.word(), 'Editing');
    assert.equal(A.draft(), 'A1');
    assert.equal(A.drafts.get(UUID).baseVersion, 1);

    await A.debounce();
    assert.equal(A.word(), 'Saving');
    assert.deepEqual(
        A.sent.map((save) => [save.text, save.base, save.overwrite]),
        [['A1', 1, false]]
    );

    await A.land();
    assert.equal(server.version, 2);
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.saving, 0);
    assert.equal(A.word(), 'Saved');
    assert.equal(A.draft(), null, 'the draft is dropped once the save has settled');
    assert.equal(A.outlineRefreshes, 1);

    await B.cycle();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.confirmed, JSON.stringify(doc('A1')));
    assert.equal(B.draft(), null, 'the server now holds newer content than any draft');
    assert.deepEqual(B.outlines, [{ numbers: {}, of: 2 }]);
    assert.deepEqual(B.css, ['.paper{--v:2}']);
    assert.equal(B.view.tick, 1);
    assert.equal(B.sent.length, 0, 'following saves nothing');

    await B.cycle();
    assert.equal(B.polled.at(-1).version, 2, 'the next poll states the version it now has');
});

test('a document that carries no outline and no style is still applied', async () => {
    const { A } = await pair();

    assert.equal(A.host().applyFromSync({ version: 2, json: doc('bare'), outline: null, css: null }), true);
    assert.equal(A.text(), 'bare');
    assert.equal(A.view.baseVersion, 2);
    assert.deepEqual(A.outlines, []);
    assert.deepEqual(A.css, []);
});

test('the echo of this tab own save, arriving before the answer, is neither applied nor a conflict', async () => {
    const { A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveArrives();
    // The poll comes back with version 2, which is this tab's own save.
    await A.cycle();

    assert.equal(A.host().syncState(), 'busy');
    assert.equal(A.view.conflict, null);
    assert.equal(A.applyCalls, 0);

    await A.saveReturns();
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.view.conflict, null);
    assert.equal(A.engine.pending, null, 'the engine dropped the echo when the save landed');
    assert.equal(A.applyCalls, 0);
    assert.equal(A.host().syncState(), 'clean');
});

test('a version this editor cannot open is said so in the page, and polling goes on', async () => {
    const { A, B } = await pair();
    B.refuses = true;
    A.type('A1');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.equal(B.view.syncNotice, 'A newer version could not be opened here. Reload the page.');
    assert.equal(B.text(), 'start');
    assert.equal(B.view.baseVersion, 1, 'the base does not move to a version the editor does not show');
    assert.notEqual(B.timer, null);
});

for (const [status, notice] of [
    [401, 'You have been signed out. Reload the page to keep editing.'],
    [403, 'You no longer have access to this document.'],
    [404, 'This document no longer exists.'],
]) {
    test(`a ${status} from the application ends following and the page says why`, async () => {
        const { A } = await pair();
        await A.fireTimer();
        await A.pollAnswers(status, { message: 'x' });

        assert.equal(A.view.syncNotice, notice);
        assert.deepEqual(A.reports.at(-1), { tone: 'danger', word: 'Not saved' });
    });
}

test('a stop for a reason this page does not know still says the page has stopped', async () => {
    const { A } = await pair();
    A.host().engineHost().onStopped('something-new');

    assert.equal(A.view.syncNotice, 'This page has stopped updating. Reload it.');
    assert.equal(A.word(), 'Not saved');
});

test('the presence strip is refreshed only when the set of people changes', async () => {
    const w = world();
    const A = w.tab('A');
    await A.open();

    // Alone, as the page was rendered: nothing to refresh.
    await A.poll();
    assert.equal(A.presenceRefreshes, 0);
    assert.equal(A.view.memberKey, '1');

    const B = w.tab('B');
    await B.open();
    // Somebody is already here on B's first report: the strip must show them.
    await B.poll();
    assert.equal(B.presenceRefreshes, 1);

    await A.cycle();
    assert.equal(A.presenceRefreshes, 1);
    assert.equal(A.view.memberKey, '1,2');

    await A.cycle();
    assert.equal(A.presenceRefreshes, 1, 'the same people again: no refresh');

    // Somebody leaves: one person again, but not the first report.
    A.host().membersChanged([{ id: 1 }]);
    assert.equal(A.presenceRefreshes, 2);
    assert.equal(A.view.memberKey, '1');
});

// ───────────────────────────────────────────────────────── conflicts

test('a tab with unsaved typing whose merge is impossible does not follow: the newer document is fetched, and the notice is raised with it', async () => {
    const { server, A, B } = await pair({ B: { mergeFails: true } });
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await B.debounce();
    await A.land();

    // B's save is refused. The newer document has not been downloaded yet:
    // Phase 2 fetches it to merge, and the notice comes only once the
    // fetch has answered and the merge has proved impossible.
    B.saveArrives();
    await B.saveReturns();
    assert.equal(B.view.conflict, null);
    assert.deepEqual(B.view.merging, { version: 2, since: B.env.now() });
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.baseVersion, 1);
    assert.equal(B.polls.length, 1, 'the newer document is being fetched');
    assert.equal(B.polls[0].payload.version, 1, 'asked from the base, so the document comes with the answer');

    await B.poll();
    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.deepEqual(B.reports.at(-1), { tone: 'danger', word: 'Not saved' });
    assert.equal(B.view.merging, null);
    assert.equal(B.text(), 'B1', 'nothing was written over the typing');
    assert.equal(server.text(), 'A1', 'and nothing was written over the other person');
    assert.equal(B.draft(), 'B1', 'the draft keeps the text in the meantime');

    // The engine raises it again on every poll while it stands.
    await B.cycle();
    await B.cycle();
    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.sent.length, 1, 'nothing further was sent');
    assert.equal(B.polls.length, 0, 'and nothing further is being fetched');
    assert.equal(B.polled.at(-1).version, 2);
});

test('a newer version found by the poll while this tab is typing raises the notice with the document in hand', async () => {
    const { A, B } = await pair({ B: { mergeFails: true } });
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.text(), 'B1');
    assert.equal(B.applyCalls, 0);
    assert.equal(B.polls.length, 0, 'the engine already holds the document: nothing to fetch');
});

test('while the notice stands an autosave is not sent, and says so', async () => {
    const { B } = await conflicted();

    assert.equal(B.sent.length, 0);
    assert.deepEqual(B.reports.at(-1), { tone: 'danger', word: 'Not saved' });
    assert.equal(B.view.saving, 0);
    assert.equal(B.host().syncState(), 'dirty');
});

test('while the notice stands an owed save is not even attempted', async () => {
    const { B } = await conflicted();
    B.view.resave = true;
    const said = B.reports.length;

    B.host().resendIfOwed();
    assert.equal(B.sent.length, 0);
    assert.equal(B.reports.length, said, 'nothing new is reported: the notice is already asking');
});

test('the notice keeps the newest version known to be in the way', async () => {
    const { A } = await pair();

    A.host().enterConflict(5);
    assert.deepEqual(A.view.conflict, { version: 5, ready: false });

    // An older report does not lower it, and a report with no number does not erase it.
    A.host().enterConflict(3);
    assert.deepEqual(A.view.conflict, { version: 5, ready: false });
    A.host().enterConflict(undefined);
    assert.deepEqual(A.view.conflict, { version: 5, ready: false });
});

test('the notice takes its version from the waiting document when no number came with it', async () => {
    const { B } = await conflicted();
    B.view.conflict = null;

    B.host().enterConflict(undefined);
    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
});

test('Keep mine: the base moves up, the save says it overwrites, the replaced version is kept, the other tab follows', async () => {
    const { server, A, B } = await conflicted();

    B.host().keepMine();
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.word(), 'Saving');
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [['B1', 2, true]]
    );
    assert.notEqual(B.view.conflict, null, 'the notice stays until the save has landed');
    assert.equal(B.engine.pending.version, 2, 'the waiting document stays with the engine in case the save is lost');

    await B.land();
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
    assert.equal(B.engine.pending, null);
    assert.equal(B.draft(), null);

    await A.cycle();
    assert.equal(A.text(), 'B1');
    assert.equal(A.view.baseVersion, 3);
    assert.equal(A.view.conflict, null);
});

test('Keep mine pressed inside the debounce saves what the editor holds, once', async () => {
    const { server, A, B } = await pair({ B: { mergeFails: true } });
    A.type('A1');
    await A.debounce();
    await A.land();
    B.type('B1');
    await B.cycle();

    B.host().keepMine();
    await B.land();
    assert.equal(server.text(), 'B1');
    assert.equal(B.view.unsaved, true, 'the debounce is still armed, so the save has not settled');
    assert.equal(B.draft(), 'B1');

    // The debounce fires and finds the same document... which the bundle still sends.
    await B.debounce();
    await B.land();
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.unsaved, false);
    assert.equal(server.text(), 'B1');
});

test('Keep mine does nothing when there is no conflict', async () => {
    const { A } = await pair();
    A.type('A1');
    await A.debounce();
    await A.land();

    A.host().keepMine();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.baseVersion, 2);
});

test('Keep mine does nothing when no version is known to be in the way', async () => {
    const { A } = await pair();
    A.type('A1');
    A.host().enterConflict(undefined);
    assert.deepEqual(A.view.conflict, { version: 0, ready: false });

    A.host().keepMine();
    assert.equal(A.sent.length, 0);
    assert.equal(A.view.baseVersion, 1);
});

test('Keep mine refused because somebody saved again: the next press saves over the newest version', async () => {
    const { server, A, B } = await conflicted();
    // A saves once more before B chooses; B has not polled.
    A.type('A2');
    await A.debounce();
    await A.land();

    B.host().keepMine();
    await B.land();
    assert.equal(server.text(), 'A2', 'the first press was refused: version 3 was in the way');
    assert.equal(B.view.conflict.version, 3);
    assert.equal(B.text(), 'B1');

    B.host().keepMine();
    assert.equal(B.sent.at(-1).base, 3);
    await B.land();
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 3, text: 'A2' }]);
    assert.equal(B.view.conflict, null);
});

test('Keep mine after a lost Keep mine: it saves over the newest version the engine holds', async () => {
    const { server, clock, A, B } = await conflicted();
    B.host().keepMine();
    B.saveNeverAnswers();
    // Somebody saves again. B's poll brings it while B still waits for its save.
    A.type('A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.view.conflict.version, 2, 'nothing is decided while the save is in the air');
    assert.equal(B.engine.pending.version, 3);

    clock.now += SAVE_EXPIRY_MS;
    B.host().keepMine();
    assert.equal(B.sent.at(-1).base, 3);
    assert.equal(B.sent.at(-1).overwrite, true);
    await B.land();
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 3, text: 'A2' }]);
});

test('Load theirs: this tab text is parked and set aside, and the newer document shows', async () => {
    const { server, B } = await conflicted();

    await B.host().loadTheirs();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.view.setAsideFrom, 'conflict');
    assert.equal(B.view.setAsideBase, 2, 'the version the page showed when the text was set aside');
    assert.equal(B.stale(), 'B1');
    assert.equal(B.drafts.get(STALE).baseVersion, 1);
    assert.equal(B.draft(), null);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.host().syncState(), 'clean');
    assert.deepEqual(B.reports.at(-1), { tone: 'good', word: 'Saved' });
    assert.equal(B.engine.pending, null);
    assert.match(B.infos.at(-1), /kept as stale-doc-uuid and will be removed after 7 days/);
    assert.equal(B.sent.length, 0);
    assert.equal(server.version, 2, 'nothing was saved');
});

test('Load theirs inside the debounce replaces the typing, which is set aside, and nothing is sent', async () => {
    const { A, B } = await pair({ B: { mergeFails: true } });
    A.type('A1');
    await A.debounce();
    await A.land();
    B.type('B1');
    await B.cycle();
    assert.equal(B.handle.pending, true, 'the debounce is still armed');

    await B.host().loadTheirs();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.syncNotice, '');

    await B.debounce();
    assert.equal(B.sent.length, 0, 'the typing that was replaced is not sent after all');
});

test('Put it back: the text returns, its save says it overwrites, and the replaced version is kept', async () => {
    const { server, A, B } = await conflicted();
    await B.host().loadTheirs();

    B.host().putBack();
    assert.deepEqual(B.questions, [], 'straight after Load theirs the writer knows what is on the page: no question');
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.setAside, null);
    assert.equal(B.view.setAsideFrom, null);
    assert.equal(B.view.setAsideBase, null);
    assert.equal(B.view.overwriteOwed, true);
    assert.equal(B.view.unsaved, true);

    await B.debounce();
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [['B1', 2, true]]
    );
    await B.land();
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(B.word(), 'Saved');

    await A.cycle();
    assert.equal(A.text(), 'B1');

    // The save after that is an ordinary one.
    B.type('B1 and on');
    await B.debounce();
    assert.equal(B.sent.at(-1).overwrite, false);
});

test('Put it back, refused, then Load theirs: the ordinary save that follows does not overwrite', async () => {
    const { A, B } = await conflicted();
    await B.host().loadTheirs();
    // A saves again before B puts its text back; B has not polled.
    A.type('A2');
    await A.debounce();
    await A.land();

    B.host().putBack();
    await B.debounce();
    assert.equal(B.sent.at(-1).overwrite, true);
    await B.land();
    await B.poll();
    assert.deepEqual(B.view.conflict, { version: 3, ready: true });
    assert.equal(B.view.overwriteOwed, true);

    await B.host().loadTheirs();
    assert.equal(B.text(), 'A2');
    assert.equal(B.view.overwriteOwed, false, 'what this tab had put back is no longer in the editor');

    B.type('A2 plus B');
    await B.debounce();
    assert.equal(B.sent.at(-1).overwrite, false);
});

test('Put it back does nothing when nothing was set aside', async () => {
    const { A } = await pair();

    A.host().putBack();
    assert.equal(A.text(), 'start');
    assert.equal(A.view.overwriteOwed, false);
    assert.equal(A.view.syncNotice, '');
});

test('text that cannot be put back stays set aside, and the page says so', async () => {
    const { B } = await conflicted();
    await B.host().loadTheirs();
    B.setContentThrows = true;

    B.host().putBack();
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.view.setAsideFrom, 'conflict');
    assert.equal(B.view.setAsideBase, 2);
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(B.text(), 'A1');
});

test('text that cannot be put back leaves an overwrite that was already owed as it was', async () => {
    const { B } = await conflicted();
    await B.host().loadTheirs();
    B.view.overwriteOwed = true;
    B.setContentThrows = true;

    B.host().putBack();
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');
    assert.equal(B.view.overwriteOwed, true);
});

test('Load theirs before the newer document has been downloaded fetches it and changes nothing', async () => {
    const { clock, A, B } = await pair({ B: { mergeFails: true } });
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await B.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    // The fetch the refusal started is answered by something in front of
    // the application, and keeps being: after fifteen seconds the merge is
    // given up on and the notice stands without the document.
    await B.pollAnswers(500, null);
    assert.deepEqual(B.view.merging, { version: 2, since: clock.now });
    clock.now += MERGE_FETCH_EXPIRY_MS;
    await B.fireTimer();
    await B.pollAnswers(500, null);
    assert.deepEqual(B.view.conflict, { version: 2, ready: false });
    // The notice asked for the document once more; the firewall answers that too.
    await B.pollAnswers(500, null);
    assert.equal(B.polls.length, 0);

    await B.host().loadTheirs();
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.setAside, null);
    assert.equal(B.stale(), null);
    assert.equal(B.polls.length, 1, 'it asks for the document again');
    assert.equal(B.polls[0].payload.version, 1);
});

test('Load theirs when the newer document cannot be opened: the notice stays and the page says so', async () => {
    const { B } = await conflicted();
    B.refuses = true;

    await B.host().loadTheirs();
    assert.equal(B.view.syncNotice, 'The newer version could not be opened here. Reload the page.');
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.setAside, null);
    assert.notEqual(B.view.conflict, null);
    assert.equal(B.stale(), 'B1', 'the text was parked before the attempt');
});

test('Load theirs works in a browser that cannot keep drafts', async () => {
    const { B } = await conflicted({ B: { draftStore: false } });

    await B.host().loadTheirs();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.view.conflict, null);
    assert.equal(B.infos.length, 0, 'nothing was parked, so nothing says it was');
});

test('Load theirs says Saved once', async () => {
    const { B } = await conflicted();
    assert.equal(B.word(), 'Not saved');
    const said = B.reports.length;

    await B.host().loadTheirs();
    assert.deepEqual(B.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);
});

test('Keep mine and Load theirs both do nothing while a save is in the air', async () => {
    const { server, B } = await conflicted();
    B.host().keepMine();
    assert.equal(B.host().syncState(), 'busy');

    B.host().keepMine();
    assert.equal(B.sent.length, 1, 'one choice at a time');

    await B.host().loadTheirs();
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.setAside, null);
    assert.equal(B.stale(), null);
    assert.equal(B.engine.pending.version, 2, 'the waiting document was not taken');

    await B.land();
    assert.equal(server.text(), 'B1');
    assert.equal(B.view.conflict, null);
});

// ───────────────────────────────────────────────────────── text that was set aside

test('Discard: the offer goes, nothing is sent, the page is untouched and the parked copy stays', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs();
    B.view.overwriteOwed = true;

    B.host().discardSetAside();
    assert.equal(B.view.setAside, null);
    assert.equal(B.view.setAsideFrom, null);
    assert.equal(B.view.setAsideBase, null);
    assert.equal(B.text(), 'A1');
    assert.equal(B.stale(), 'B1', 'the parked copy stays for its seven days');
    assert.equal(B.sent.length, 0);
    assert.equal(server.version, 2);
    assert.equal(B.view.overwriteOwed, true, 'nothing else is touched');
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.syncNotice, '');
    assert.deepEqual(B.questions, [DISCARD_QUESTION], 'the text is in no saved version, so Discard asks first');

    // Nothing is left to put back.
    B.view.overwriteOwed = false;
    B.host().putBack();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.overwriteOwed, false);
});

test('Discard does nothing when nothing was set aside, and works before the editor exists', async () => {
    const { A } = await pair();
    A.host().discardSetAside();
    assert.equal(A.view.setAside, null);
    assert.equal(A.text(), 'start');

    const w = world();
    const early = w.tab('early');
    early.mounted = false;
    early.view.setAside = JSON.stringify(doc('set aside'));
    early.view.setAsideFrom = 'draft';
    early.view.setAsideBase = 1;
    early.host().discardSetAside();
    assert.deepEqual(
        [early.view.setAside, early.view.setAsideFrom, early.view.setAsideBase],
        [null, null, null]
    );
});

test('Discard declined after Load theirs changes nothing: the offer stays', async () => {
    const { server, B } = await conflicted({ B: { confirmAnswer: false } });
    await B.host().loadTheirs();

    B.host().discardSetAside();
    assert.deepEqual(B.questions, [DISCARD_QUESTION]);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.view.setAsideFrom, 'conflict');
    assert.equal(B.view.setAsideBase, 2);
    assert.equal(B.text(), 'A1');
    assert.equal(B.stale(), 'B1');
    assert.equal(B.sent.length, 0);
    assert.equal(server.version, 2);

    // A yes withdraws it.
    B.confirmAnswer = true;
    B.host().discardSetAside();
    assert.deepEqual(B.questions, [DISCARD_QUESTION, DISCARD_QUESTION]);
    assert.equal(B.view.setAside, null);
    assert.equal(B.stale(), 'B1', 'the parked copy stays for its seven days');
});

test('Discard of a draft from an earlier visit does not ask: the page took nothing away', async () => {
    const { server, B } = await conflicted();
    // The same browser loads the page again: the text comes back as a draft.
    // The draft merge is declined: today's park and set-aside.
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts, confirmAnswer: false });
    await again.open();
    assert.equal(again.view.setAsideFrom, 'draft');

    again.host().discardSetAside();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION], 'Discard itself asked nothing');
    assert.equal(again.view.setAside, null);
    assert.equal(again.view.setAsideFrom, null);
    assert.equal(again.stale(), 'B1', 'the parked copy stays');
});

test('the Discard question says the text is in no version, goes from the page and stays in the browser for 7 days', () => {
    assert.match(DISCARD_QUESTION, /no saved version/);
    assert.match(DISCARD_QUESTION, /removed from this page/);
    assert.match(DISCARD_QUESTION, /kept .* in this browser/);
    assert.match(DISCARD_QUESTION, /7 days/);
});

test('Put it back after the document has moved on asks first, and a no changes nothing', async () => {
    const { server, A, B } = await conflicted({ B: { confirmAnswer: false } });
    await B.host().loadTheirs();
    // Somebody saves again and this tab follows: the offer is still there,
    // against a document that is no longer the one the writer loaded.
    A.type('A2 newer work');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.text(), 'A2 newer work');
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'following does not take the offer away');
    assert.equal(B.view.setAsideBase, 2);

    B.host().putBack();
    assert.deepEqual(B.questions, [PUT_BACK_QUESTION]);
    assert.equal(B.text(), 'A2 newer work', 'declined: the page is untouched');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'and the offer stands');
    assert.equal(B.view.setAsideFrom, 'conflict');
    assert.equal(B.view.setAsideBase, 2);
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(B.view.unsaved, false);
    await B.debounce();
    assert.equal(B.sent.length, 0);

    // Asked again, and this time answered yes.
    B.confirmAnswer = true;
    B.host().putBack();
    assert.equal(B.questions.length, 2);
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.setAside, null);
    await B.debounce();
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [['B1', 3, true]]
    );
    await B.land();
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 3, text: 'A2 newer work' }]);
});

test('Put it back after this tab own later save asks first too', async () => {
    const { server, B } = await conflicted({ B: { confirmAnswer: false } });
    await B.host().loadTheirs();
    B.type('A1 and new work by B');
    await B.debounce();
    await B.land();
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.setAsideBase, 2);

    B.host().putBack();
    assert.deepEqual(B.questions, [PUT_BACK_QUESTION]);
    assert.equal(B.text(), 'A1 and new work by B');
    assert.equal(server.text(), 'A1 and new work by B');
    assert.equal(B.sent.length, 1);
});

test('Put it back over text that is not saved yet asks first, and says that text will be lost', async () => {
    const { B } = await conflicted({ B: { confirmAnswer: false } });
    await B.host().loadTheirs();
    // Typed after Load theirs and not sent yet: it is in no saved version.
    B.type('A1 and words nobody has stored');

    B.host().putBack();
    assert.deepEqual(B.questions, [PUT_BACK_QUESTION + PUT_BACK_UNSAVED_WARNING]);
    assert.equal(B.text(), 'A1 and words nobody has stored');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.draft(), 'A1 and words nobody has stored');
});

test('Put it back does not warn about unsaved text when the only edit was undone again', async () => {
    const { B } = await conflicted();
    await B.host().loadTheirs();
    B.type('A1x');
    B.type('A1');
    await B.debounce();
    assert.equal(B.view.unsaved, true, 'no tick has settled it yet');

    B.host().putBack();
    assert.deepEqual(B.questions, [], 'the page holds exactly what the writer loaded: no question');
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.overwriteOwed, true);
});

test('the question says what will be replaced and where the replaced version goes', () => {
    assert.equal(
        PUT_BACK_QUESTION,
        'Put this text back? It will replace the text on the page now, including everything saved since this text was written. The version it replaces is kept in the history.'
    );
    assert.equal(PUT_BACK_UNSAVED_WARNING, ' Text on the page that has not been saved yet will be lost.');
});

// ───────────────────────────────────────────────────────── an overwrite on purpose is owed until a save says so

test('the unload beacon states the base and whether an overwrite is owed, both read at the moment of leaving', () => {
    let base = 4;
    let owed = false;
    const options = { csrfToken: 'token-1', getBaseVersion: () => base, getOverwrite: () => owed };

    assert.deepEqual(unloadSaveBody(doc('one'), options), {
        _token: 'token-1',
        content: doc('one'),
        base_version: 4,
        overwrite: false,
    });

    base = 7;
    owed = true;
    assert.deepEqual(unloadSaveBody(doc('two'), options), {
        _token: 'token-1',
        content: doc('two'),
        base_version: 7,
        overwrite: true,
    });
});

test('the unload beacon sends a real boolean, whatever the page hands it, and no base when the page states none', () => {
    // The server validates `overwrite` as a boolean and stores nothing when it is not one.
    for (const [given, sent] of [
        [undefined, false],
        [null, false],
        [0, false],
        ['', false],
        [1, true],
        ['yes', true],
        [{}, true],
    ]) {
        const body = unloadSaveBody(doc('x'), { getBaseVersion: () => 2, getOverwrite: () => given });
        assert.equal(body.overwrite, sent);
    }

    assert.deepEqual(unloadSaveBody(doc('x'), {}), { _token: '', content: doc('x'), base_version: null, overwrite: false });
    assert.deepEqual(unloadSaveBody(doc('x')), { _token: '', content: doc('x'), base_version: null, overwrite: false });
});

// ───────────────────────────────────────────────────────── the unload beacon carries text a failed or held save left unsent

test('beaconWanted: a changed, dirty document goes; an unsent one goes even when the bundle counts it as sent; the same content never goes twice', () => {
    const base = { dirty: false, changed: false, unsent: false, sentAlready: false };
    const table = [
        [{}, false],
        [{ dirty: true }, false, 'dirty but nothing changed since the last hand-over'],
        [{ dirty: true, changed: true }, true],
        [{ changed: true }, false, 'changed and not dirty: housekeeping, nothing the writer typed'],
        [{ unsent: true }, true, 'handed over, request failed or held: counted as sent by the bundle'],
        [{ dirty: true, changed: true, sentAlready: true }, false],
        [{ unsent: true, sentAlready: true }, false],
    ];
    for (const [given, wanted, why] of table) {
        assert.equal(beaconWanted({ ...base, ...given }), wanted, `${JSON.stringify(given)} ${why ?? ''}`);
    }
});

test('a tab closed after its save request FAILED sends the unsent text by beacon, on the same base', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('network'));
    await settle();
    assert.equal(A.word(), 'Not saved');
    assert.equal(A.view.unsaved, true);

    A.leave();

    assert.deepEqual(
        A.beacons.map((beacon) => [beacon.text, beacon.body.base_version, beacon.body.overwrite, beacon.answer.status]),
        [['A1', 1, false, 200]]
    );
    assert.equal(server.text(), 'A1');
    assert.equal(server.version, 2);
});

test('a tab closed while the back-off HOLDS its save sends the unsent text by beacon', async () => {
    const { clock, server, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('network'));
    await settle();
    clock.now += 1;
    await A.cycle();
    A.saves.shift().reject(new Error('network'));
    await settle();
    assert.ok(A.view.saveRetryAt > clock.now, 'the second failure in a row holds the next send');

    A.type('A1 more');
    A.leave();

    assert.deepEqual(A.beacons.map((beacon) => [beacon.text, beacon.body.base_version]), [['A1 more', 1]]);
    assert.equal(server.text(), 'A1 more');
});

test('a tab closed after its save was given up on (no answer in fifteen seconds, which is how a failed fetch looks) beacons the text', async () => {
    const { clock, server, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveNeverAnswers();

    // Inside the first fifteen seconds the save may still answer: nothing is sent behind it.
    clock.now += SAVE_EXPIRY_MS - 1;
    A.leave();
    assert.deepEqual(A.beacons, []);

    // Given up on: the request got no answer, the text is owed, and nothing has been confirmed since.
    clock.now += 1;
    assert.equal(A.view.unanswered, null, 'no poll tick has noticed that the save was given up on');
    assert.equal(A.view.saveFailures, 0, 'Livewire never rejected: the page cannot count it as a failure');
    A.leave();

    assert.deepEqual(A.beacons.map((beacon) => [beacon.text, beacon.body.base_version, beacon.body.overwrite]), [['A1', 1, false]]);
    assert.equal(server.text(), 'A1');
});

test('the beacon of a failed save carries an owed overwrite, and a stale base writes nothing and keeps nothing', async () => {
    const { server, A, B } = await pair();
    B.type('B1');
    await B.debounce();
    await B.land();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('network'));
    await settle();
    A.view.overwriteOwed = true;

    A.leave();

    assert.deepEqual(A.beacons.map((beacon) => [beacon.body.base_version, beacon.body.overwrite, beacon.answer.status]), [[1, true, 409]]);
    assert.equal(server.text(), 'B1', 'the server refused it: nothing was overwritten');
    assert.deepEqual(server.history, []);
    assert.equal(A.draft(), 'A1', 'the offline draft is the safety net');
});

test('the same unsent text is never beaconed twice (pagehide, then destroy)', async () => {
    const { A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('network'));
    await settle();

    A.leave();
    A.leave();
    assert.equal(A.beacons.length, 1);

    A.type('A1 and more');
    A.leave();
    assert.deepEqual(A.beacons.map((beacon) => beacon.text), ['A1', 'A1 and more'], 'new content is a new beacon');
});

test('nothing is beaconed for text that is saved, in the air without a failure, or typed on a read only page', async () => {
    const saved = await pair();
    saved.A.type('A1');
    await saved.A.debounce();
    await saved.A.land();
    saved.A.leave();
    assert.deepEqual(saved.A.beacons, [], 'saved: nothing is owed');

    const inTheAir = await pair();
    inTheAir.A.type('A1');
    await inTheAir.A.debounce();
    inTheAir.A.leave();
    assert.deepEqual(inTheAir.A.beacons, [], 'a save in the air has not failed: it is not beaconed a second time');

    const readOnly = await pair();
    readOnly.A.type('A1');
    await readOnly.A.debounce();
    readOnly.A.saves.shift().reject(new Error('network'));
    await settle();
    readOnly.A.handle.makeReadOnly();
    readOnly.A.leave();
    assert.deepEqual(readOnly.A.beacons, [], 'a read only page never writes');

    const served = world();
    const reader = served.tab('R', { readOnly: true });
    await reader.open();
    reader.view.unsaved = true;
    reader.view.saveFailures = 2;
    reader.leave();
    assert.deepEqual(reader.beacons, []);
});

test('unsentAtUnload: unsaved text after a failure or a hold, never on a read only or fail-closed page', async () => {
    const { clock, A } = await pair();
    const unsent = () => A.host().unsentAtUnload();
    assert.equal(unsent(), false, 'nothing typed');

    A.view.unsaved = true;
    assert.equal(unsent(), false, 'unsaved but no request has failed');

    A.view.saveFailures = 1;
    assert.equal(unsent(), true, 'the last request failed');

    A.view.saveFailures = 0;
    A.view.saveRetryAt = clock.now + 5000;
    assert.equal(unsent(), true, 'held by the back-off');

    A.view.saveRetryAt = clock.now - 1;
    assert.equal(unsent(), false);

    A.view.saveRetryAt = 0;
    A.view.unanswered = JSON.stringify(doc('x'));
    assert.equal(unsent(), true, 'a save was given up on or failed and nothing has been confirmed since');

    A.view.unanswered = null;
    A.view.saveFailures = 2;
    A.view.unsaved = false;
    assert.equal(unsent(), false, 'what the editor holds is what the server confirmed');

    A.view.unsaved = true;
    A.view.editable = false;
    assert.equal(unsent(), false, 'a read only page');
});

test('an ordinary tab closed inside the debounce sends a beacon that does not overwrite', async () => {
    const { server, A } = await pair();
    A.type('A last words');
    A.leave();

    assert.deepEqual(
        A.beacons.map((beacon) => [beacon.body.base_version, beacon.body.overwrite, beacon.answer.status]),
        [[1, false, 200]]
    );
    assert.deepEqual(server.history, []);
});

test('Put it back, then the tab is closed inside the debounce: the beacon says it overwrites, and the replaced version is kept', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs();
    B.host().putBack();

    // Closed, or a link followed, before the debounce fires.
    B.leave();
    assert.deepEqual(
        B.beacons.map((beacon) => [beacon.text, beacon.body.base_version, beacon.body.overwrite]),
        [['B1', 2, true]]
    );
    assert.equal(B.beacons[0].answer.status, 200);
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }], 'the other person version is kept first');
});

test('Keep mine marks the overwrite as owed when it moves the base up, until a save is accepted', async () => {
    const { server, B } = await conflicted();
    assert.equal(B.view.overwriteOwed, false);

    B.host().keepMine();
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.overwriteOwed, true, 'owed from the moment the base states the other person version');

    await B.land();
    assert.equal(server.text(), 'B1');
    assert.equal(B.view.overwriteOwed, false, 'an accepted save that said so settles it');

    B.type('B1 and on');
    await B.debounce();
    assert.equal(B.sent.at(-1).overwrite, false, 'the save after that is an ordinary one');
});

test('a Keep mine save the server rejects leaves the overwrite owed: the beacon of what is typed next carries it', async () => {
    const { server, B } = await conflicted();
    server.rejectNext = true;
    B.host().keepMine();
    await B.land();
    assert.equal(server.text(), 'A1', 'nothing was stored');
    assert.equal(B.view.baseVersion, 2, 'the base stays moved up');
    assert.equal(B.view.overwriteOwed, true);
    assert.equal(B.word(), 'Not saved');

    // The writer types on and leaves. The page itself would not save while
    // the notice stands; the beacon has no such check, and states base 2.
    B.type('B1 and more');
    B.leave();
    assert.deepEqual(
        B.beacons.map((beacon) => [beacon.text, beacon.body.base_version, beacon.body.overwrite]),
        [['B1 and more', 2, true]]
    );
    assert.equal(server.text(), 'B1 and more');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('a Keep mine save cut off by leaving: the beacon of what was typed since carries the overwrite', async () => {
    const { server, B } = await conflicted();
    B.host().keepMine();
    // The request is aborted by the unload: it never reaches the server.
    B.type('B1 and more');
    B.leave();

    assert.deepEqual(
        B.beacons.map((beacon) => [beacon.body.base_version, beacon.body.overwrite, beacon.answer.status]),
        [[2, true, 200]]
    );
    assert.equal(server.text(), 'B1 and more');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('a Keep mine save that did land before the beacon: the beacon is refused and the version is kept once', async () => {
    const { server, B } = await conflicted();
    B.host().keepMine();
    B.type('B1 and more');
    B.saveArrives();
    B.leave();

    assert.equal(B.beacons[0].answer.status, 409, 'the base it states is no longer current: nothing is overwritten blind');
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('after Keep mine the editor being back at the old confirmed document settles nothing: the base no longer names it', async () => {
    const { server, A, B } = await pair({ B: { mergeFails: true } });
    A.type('A1');
    await A.debounce();
    await A.land();
    // B types, the notice comes up, and B takes the typing out again: the
    // editor is back at version 1, which is what this tab last had confirmed.
    B.type('B1');
    await B.cycle();
    B.type('start');
    await B.debounce();
    assert.notEqual(B.view.conflict, null);

    // Keep mine, knowingly: version 1's text over version 2. The server
    // rejects the save, so the notice stays and the base stays moved up.
    server.rejectNext = true;
    B.host().keepMine();
    await B.land();
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.confirmed, null, 'the base names version 2 now; what was confirmed is version 1');

    // A style change by this same page clears the notice (version 3).
    B.host().adoptVersion(server.replace(server.json));
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.baseVersion, 3);

    // Written off as Saved here, the tab would show version 1 on base 3,
    // owe nothing, and its next keystroke would replace the other person
    // text with no version kept. The owed save goes instead, and says so.
    assert.equal(B.view.unsaved, true);
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [
            ['start', 2, true],
            ['start', 3, true],
        ]
    );
    await B.land();
    assert.equal(server.text(), 'start');
    assert.deepEqual(server.history, [{ version: 3, text: 'A1' }]);
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(B.view.confirmed, JSON.stringify(doc('start')));
});

test('a Keep mine save refused against this tab own newer version is sent again, still as an overwrite', async () => {
    const { server, B } = await conflicted();
    B.host().keepMine();
    // A style change by this same page is stored first (version 3: the
    // other person text, restyled), and its event moves the base up.
    B.host().adoptVersion(server.replace(server.json));
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.conflict, null);

    B.saveArrives();
    await B.saveReturns();
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [
            ['B1', 2, true],
            ['B1', 3, true],
        ],
        'the retry replaces the other person text too, and says so'
    );

    await B.land();
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 3, text: 'A1' }]);
    assert.equal(B.view.overwriteOwed, false);
});

test('a Keep mine save that is lost: the ordinary autosave that goes out later still says it overwrites', async () => {
    const { server, clock, B } = await conflicted();
    B.host().keepMine();
    // The request never reaches the server.
    B.saveNeverAnswers();
    // A style change by this same page clears the notice (version 3: the
    // other person text, restyled) while that save is still waited for.
    B.host().adoptVersion(server.replace(server.json));
    assert.equal(B.view.conflict, null);
    B.type('B1 and more');
    await B.debounce();
    assert.equal(B.sent.length, 1, 'owed: the Keep mine save is still waited for');

    clock.now += SAVE_EXPIRY_MS;
    await B.cycle();
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [
            ['B1', 2, true],
            ['B1 and more', 3, true],
        ],
        'not the Keep mine save itself, and it replaces the other person text all the same'
    );
    await B.land();
    assert.equal(server.text(), 'B1 and more');
    assert.deepEqual(server.history, [{ version: 3, text: 'A1' }]);
    assert.equal(B.view.overwriteOwed, false);
});

test('Put it back while an ordinary save is in the air: that save answering does not write the overwrite off', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs();
    B.type('A1 plus an edit');
    await B.debounce();
    assert.deepEqual([B.sent[0].text, B.sent[0].base, B.sent[0].overwrite], ['A1 plus an edit', 2, false]);

    // Put it back before that save has answered (the page asks first: the
    // text on it is not saved yet).
    B.host().putBack();
    assert.equal(B.questions.length, 1);
    assert.equal(B.view.overwriteOwed, true);

    // The ordinary save is accepted. It did not carry the overwrite, so its
    // answer does not settle it.
    await B.land();
    assert.equal(server.text(), 'A1 plus an edit');
    assert.equal(B.view.overwriteOwed, true);

    await B.debounce();
    assert.deepEqual(
        B.sent.map((save) => [save.text, save.base, save.overwrite]),
        [
            ['A1 plus an edit', 2, false],
            ['B1', 3, true],
        ]
    );
    await B.land();
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 3, text: 'A1 plus an edit' }]);
});

test('Put it back, then the page dies before anything is sent: reopened, the text is set aside and its save overwrites', async () => {
    const { server, clock, B } = await conflicted();
    await B.host().loadTheirs();
    B.host().putBack();
    assert.equal(B.draft(), 'B1');
    assert.equal(
        B.drafts.get(UUID).baseVersion,
        1,
        'stamped below the version it is to replace, so no later page takes it for a draft of that version'
    );

    // A crash: no debounce, no beacon. The same browser opens the page again.
    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    await again.poll();
    assert.deepEqual(again.questions, [], 'not offered as a plain restore: that save would not say it overwrites');
    assert.equal(again.text(), 'A1');
    assert.equal(again.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(again.view.setAsideFrom, 'draft');

    again.host().putBack();
    assert.deepEqual(again.questions, [PUT_BACK_QUESTION]);
    await again.debounce();
    assert.deepEqual(
        again.sent.map((save) => [save.text, save.base, save.overwrite]),
        [['B1', 2, true]]
    );
    await again.land();
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('Keep mine fails, the writer types on, the page dies: reopened, the draft is merged against the version it was typed on and the paragraph that lost is offered back', async () => {
    const { server, clock, B } = await conflicted();
    server.rejectNext = true;
    B.host().keepMine();
    await B.land();
    B.type('B1 and more');
    assert.equal(B.drafts.get(UUID).baseVersion, 1, 'stamped one below the moved-up base');
    assert.equal(B.drafts.get(BASE).baseVersion, 1, 'nothing was confirmed since the page opened, so the row IS of that version');

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    await again.poll();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.equal(again.text(), 'A1');
    assert.equal(again.view.setAside, null);
    assert.deepEqual(
        again.view.lost.map((entry) => [entry.reason, entry.source, entry.excerpt]),
        [['both', 'draft', 'B1 and more']]
    );
    assert.equal(again.view.overwriteOwed, false, 'nothing is replaced: nothing is owed');

    assert.equal(again.host().putBackLost(0), true);
    await again.debounce();
    assert.deepEqual([again.sent.at(-1).base, again.sent.at(-1).overwrite], [2, false]);
    await again.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'A1'],
        ['fresh1', 'B1 and more'],
    ]);
    assert.deepEqual(server.history, []);
});

test('an overwrite owed from a merge is still owed after Keep mine and a reopen: the draft is set aside, not merged, and the other writer\'s words are kept', async () => {
    const holdsT31 = (json) => JSON.stringify(json).includes('t31');
    const w = world();
    const { server } = w;
    const A = w.tab('A');
    const C = w.tab('C');
    await A.open();
    await C.open();
    await A.poll();
    await C.poll();
    await A.cycle();
    await C.cycle();
    C.insertAfter('p1', 'n1', 't5');
    await C.debounce();
    await C.land(); // v2
    await A.cycle();
    A.deleteBlock('n1'); // A deletes n1, which holds t5
    C.typeIn('n1', 't5 t31');
    await C.debounce();
    await C.land(); // v3: C's words in n1
    await A.cycle(); // c2: A's deletion wins, and the merged save owes an overwrite
    assert.equal(A.view.overwriteOwed, true);
    C.typeIn('p1', 'start x');
    await C.debounce();
    await C.land(); // v4 lands before A's merged save
    A.mergeFails = true; // (or: the merge fetch goes unanswered for 15 s)
    A.saveArrives();
    await A.saveReturns(); // refused
    if (A.polls.length === 0 && A.timer) {
        await A.fireTimer();
    }
    await A.poll();
    assert.ok(A.view.conflict);
    A.host().keepMine(); // base 3 -> 4, and the Keep mine save is in the air
    A.insertAfter('p1', 'n9', 'a1');
    await A.debounce(); // owed behind the save in the air; the draft is rewritten
    assert.equal(A.drafts.get(UUID).baseVersion, 2, 'stamped below the base the owed overwrite began on, not below the moved-up base');
    A.leave(); // nothing is waiting in the debounce: no beacon
    A.saves.length = 0;
    A.polls.length = 0;
    A.engine.stop();
    A.mounted = false;

    const again = w.tab('A2', { drafts: A.drafts });
    await again.open();
    assert.deepEqual(again.questions, [], 'not offered as an ordinary draft merge');
    assert.equal(again.view.setAsideFrom, 'draft');
    await again.poll();
    await C.cycle();
    assert.ok(holdsT31(server.json), 'C\'s words are still in the document');
    assert.ok(holdsT31(C.handle.editor.getJSON()));

    again.host().putBack();
    await again.debounce();
    assert.equal(again.sent.at(-1).overwrite, true, 'putting the text back replaces the version on purpose');
    await again.land();
    assert.ok(server.kept.some((row) => holdsT31(row.json)), 'C\'s words are kept in the history');
});

test('an overwrite owed from Put it back is still owed after Keep mine and a reopen: the draft is set aside, not merged over the version it was to replace', async () => {
    const { server, clock, A, B } = await conflicted();
    await B.host().loadTheirs(); // B shows v2 (A1); B1 is set aside
    B.host().putBack(); // B1 is to replace v2 on purpose: an overwrite is owed from base 2
    assert.equal(B.view.overwriteOwed, true);
    await B.debounce(); // the put-back save leaves on base 2

    // A adds a paragraph (v3) before B's save arrives: B's save is refused,
    // the merge is impossible and the notice stands.
    await A.cycle();
    A.insertAfter('p1', 'n1', 'a2');
    await A.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    if (B.polls.length === 0 && B.timer) {
        await B.fireTimer();
    }
    await B.poll();
    assert.ok(B.view.conflict);

    B.host().keepMine(); // base 2 -> 3, and the Keep mine save is in the air
    B.type('B1 and more');
    await B.debounce(); // owed behind the save in the air; the draft is rewritten
    assert.equal(B.drafts.get(UUID).baseVersion, 1, 'stamped below the base the owed overwrite began on, not below the moved-up base');
    B.leave();
    B.saves.length = 0;
    B.polls.length = 0;
    B.engine.stop();
    B.mounted = false;

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.deepEqual(again.questions, [], 'not offered as an ordinary draft merge');
    assert.equal(again.view.setAsideFrom, 'draft');
    await again.poll();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'A1'],
        ['n1', 'a2'],
    ]);

    again.host().putBack();
    await again.debounce();
    assert.equal(again.sent.at(-1).overwrite, true, 'putting the text back replaces the version on purpose');
    await again.land();
    assert.deepEqual(server.history, [{ version: 3, text: 'A1' }], 'the version it replaced, with A1 in it, is kept');
});

test('once the overwrite has been accepted, the draft of what is typed next states the true base again', async () => {
    const { B } = await conflicted();
    B.host().keepMine();
    await B.land();
    assert.equal(B.view.baseVersion, 3);

    B.type('B1 and on');
    assert.equal(B.drafts.get(UUID).baseVersion, 3);
});

// ───────────────────────────────────────────────────────── fix A

test('an autosave fired while a Keep mine save is in the air is sent when that save answers', async () => {
    const { server, B } = await conflicted();
    B.host().keepMine();

    // The writer goes on typing and pauses: the debounce hands the new text over.
    B.type('B1 and more');
    await B.debounce();
    assert.equal(B.sent.length, 1, 'one save in the air at a time');
    assert.equal(B.view.resave, true, 'the save is remembered as owed');
    assert.notEqual(B.word(), 'Not saved');

    // The Keep mine answer arrives.
    B.saveArrives();
    await B.saveReturns();
    assert.equal(B.view.conflict, null);
    assert.equal(B.sent.length, 2, 'the owed save is sent with no further keystroke');
    assert.deepEqual([B.sent[1].text, B.sent[1].base, B.sent[1].overwrite], ['B1 and more', 3, false]);
    assert.notEqual(B.word(), 'Saved', 'the status does not say Saved while text is unsent');
    assert.equal(B.view.unsaved, true);

    await B.land();
    assert.equal(server.version, 4);
    assert.equal(server.text(), 'B1 and more');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
    assert.equal(B.draft(), null);
});

test('an autosave fired while a Keep mine save is in the air is sent even when that save answers late', async () => {
    const { server, clock, B } = await conflicted();
    B.host().keepMine();
    B.type('B1 and more');
    await B.debounce();

    // The server stores the Keep mine save, but its answer is slow.
    B.saveArrives();
    const late = B.saveNeverAnswers();
    clock.now += SAVE_EXPIRY_MS + 1000;
    await B.cycle();
    assert.equal(B.view.saving, 0, 'the tab has stopped waiting for it');
    assert.notEqual(B.view.conflict, null);
    assert.equal(B.sent.length, 1, 'nothing is sent while the notice stands');

    late.resolve(late.result);
    await settle();
    assert.equal(B.view.conflict, null);
    assert.equal(B.sent.length, 2, 'the owed save is sent with no further keystroke');
    assert.deepEqual([B.sent[1].text, B.sent[1].base], ['B1 and more', 3]);
    assert.notEqual(B.word(), 'Saved');

    await B.land();
    assert.equal(server.text(), 'B1 and more');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.saving, 0, 'an answer that comes after the tab stopped waiting is not counted twice');
    assert.equal(B.word(), 'Saved');
});

test('a save that expired answers late while its own conflict is showing: the text typed since is sent', async () => {
    const { server, clock, A } = await pair({ A: { mergeFails: true } });
    A.type('T1');
    await A.debounce();
    // Stored as version 2, but the answer does not come.
    A.saveArrives();
    const late = A.saveNeverAnswers();

    // After 15 seconds the tab sends it again, on the old base. It is
    // refused against the tab's own stored save: the notice shows although
    // nobody else touched the document, but only because mergeFails makes the
    // merge impossible here; with a merge the echo rule recognises the save
    // (see the echo cases) and no notice is raised.
    clock.now += SAVE_EXPIRY_MS + 1000;
    await A.cycle();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['T1', 1]);
    A.saveArrives();
    await A.saveReturns();
    assert.deepEqual(A.view.conflict, { version: 2, ready: true });

    // The writer types on; the autosave is refused by the notice.
    A.type('T1 more');
    await A.debounce();
    assert.equal(A.sent.length, 2);
    assert.equal(A.word(), 'Not saved');

    // Now the first save's answer arrives after all.
    late.resolve(late.result);
    await settle();
    assert.equal(A.view.conflict, null);
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.sent.length, 3, 'the text typed since is sent with no further keystroke');
    assert.deepEqual([A.sent[2].text, A.sent[2].base, A.sent[2].overwrite], ['T1 more', 2, false]);
    assert.notEqual(A.word(), 'Saved', 'the status does not say Saved while text is unsent');

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'T1 more');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.saving, 0);
    assert.equal(A.word(), 'Saved');
});

// ───────────────────────────────────────────────────────── a save given up on that answers after all

test('a Keep mine save given up on, then Load theirs: its late answer is logged and changes nothing', async () => {
    const { server, clock, B } = await conflicted();
    B.host().keepMine();
    // The Keep mine save reaches the server and is stored, but its answer is slow.
    B.saveArrives();
    const late = B.saveNeverAnswers();
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'B1');

    // The tab gives up on it, and the writer chooses Load theirs: the page
    // shows the other person's version 2.
    clock.now += SAVE_EXPIRY_MS + 1000;
    await B.host().loadTheirs();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.saving, 0);
    const reports = B.reports.length;
    const draftCalls = B.draftCalls.length;

    // Now the answer arrives: version 3 holds B1. The editor shows version
    // 2, so adopting it would put the base on a version the page does not
    // show, and the next keystroke would replace version 3 unkept.
    late.resolve(late.result);
    await settle();
    assert.equal(B.view.baseVersion, 2, 'the base does not move onto a version the editor does not show');
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.confirmed, JSON.stringify(doc('A1')));
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.saving, 0);
    assert.equal(B.reports.length, reports, 'nothing is reported');
    assert.equal(B.draftCalls.length, draftCalls, 'no draft is touched');
    assert.equal(B.sent.length, 1, 'nothing is sent off it');
    assert.equal(B.errors.length, 1, 'it is logged');
    assert.match(String(B.errors[0][0]), /given up on/);

    // The real outcome reaches the tab the ordinary way: the poll brings
    // version 3 and the clean tab follows it.
    await B.cycle();
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.baseVersion, 3);
});

test('a Keep mine save that answers in time, at the last second, is applied as before', async () => {
    const { server, clock, B } = await conflicted();
    B.host().keepMine();
    B.saveArrives();
    clock.now += SAVE_EXPIRY_MS - 1;

    await B.saveReturns();
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(B.word(), 'Saved');
    assert.equal(server.text(), 'B1');
    assert.deepEqual(B.errors, []);
});

test('a save given up on whose request then fails does not touch the save that replaced it', async () => {
    const { clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    const first = A.saveNeverAnswers();

    // Given up on; the next poll sends it again, and that save is in the air.
    clock.now += SAVE_EXPIRY_MS;
    await A.cycle();
    assert.equal(A.sent.length, 2);
    assert.equal(A.view.saving, 1);
    assert.equal(A.word(), 'Saving');

    // The first request fails outright after all (the connection it was on
    // is closed). That says nothing about the save that replaced it.
    first.reject(new Error('network'));
    await settle();
    assert.equal(A.view.saving, 1, 'the save in the air is still waited for');
    assert.equal(A.host().syncState(), 'busy');
    assert.equal(A.word(), 'Saving', 'and no failure is reported for a save that was written off');
    assert.equal(A.errors.length, 1);
    assert.match(String(A.errors[0][0]), /given up on/);

    await A.land();
    assert.equal(A.view.saving, 0);
    assert.equal(A.word(), 'Saved');
});

test('a late answer the tab can still use is applied, and does not count against the save that replaced it', async () => {
    const { server, clock, A } = await pair();
    A.type('T1');
    await A.debounce();
    // Stored as version 2; the answer is slow.
    A.saveArrives();
    const late = A.saveNeverAnswers();

    // Given up on and sent again, on the old base: that save is in the air.
    clock.now += SAVE_EXPIRY_MS;
    await A.cycle();
    assert.equal(A.sent.length, 2);
    assert.equal(A.view.saving, 1);

    // The first answer arrives. The tab has learnt nothing else since
    // (nothing confirmed, the base where it was), so the answer is good:
    // the base moves to 2 and the text is settled.
    late.resolve(late.result);
    await settle();
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.view.unsaved, false);
    assert.equal(A.word(), 'Saved');
    assert.equal(A.view.saving, 1, 'the save in the air is still counted');
    assert.deepEqual(A.errors, []);

    // The resend is refused against this tab's own version 2, which is not a conflict.
    await A.land();
    assert.equal(A.view.conflict, null);
    assert.equal(A.view.saving, 0);
    assert.equal(server.version, 2);
    assert.equal(A.sent.length, 2, 'nothing more is sent: the text is stored');
});

// ───────────────────────────────────────────────────────── one save at a time

test('autosaves in a row: the next waits for the one in the air and is sent on the new base', async () => {
    const { server, A, B } = await pair();
    A.type('one');
    await A.debounce();
    A.type('one two');
    await A.debounce();
    A.type('one two three');
    await A.debounce();
    assert.equal(A.sent.length, 1, 'one save in the air at a time');
    assert.equal(A.view.resave, true);
    assert.equal(A.word(), 'Editing', 'a save that waits reports nothing');

    // A poll is answered meanwhile: the owed save still waits for the one in the air.
    await A.cycle();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.resave, true);

    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.sent.length, 2);
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['one two three', 2]);
    assert.equal(A.draft(), 'one two three', 'the draft outlives a save that stored older text');
    assert.equal(A.view.unsaved, true);

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'one two three');
    assert.equal(A.view.conflict, null, 'the tab did not conflict with itself');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.draft(), null);

    await B.cycle();
    assert.equal(B.text(), 'one two three');
});

test('a save that answers while the writer is typing again leaves the next send to the debounce', async () => {
    const { A } = await pair();
    A.type('one');
    await A.debounce();
    A.type('one two');

    await A.land();
    assert.equal(A.sent.length, 1, 'the debounce is armed and sends the rest itself');
    assert.equal(A.view.unsaved, true);
    assert.equal(A.draft(), 'one two');
    assert.equal(A.host().syncState(), 'dirty');

    await A.debounce();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['one two', 2]);
});

test('a save refused against a version this tab is already based on is sent again, not shown as a conflict', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    // A style change by this same page is stored first (version 2) and its
    // event moves the base up before the autosave's answer arrives.
    A.host().adoptVersion(server.replace(server.json));
    assert.equal(A.view.baseVersion, 2);

    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.view.conflict, null, 'refused by this tab own version: not a conflict');
    assert.deepEqual([A.sent[1].text, A.sent[1].base, A.sent[1].overwrite], ['A1', 2, false]);

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'A1');
    assert.deepEqual(server.history, []);
    assert.equal(A.view.conflict, null);
    assert.equal(A.word(), 'Saved');
});

// ───────────────────────────────────────────────────────── an owed save and the debounce

test('an owed save that comes due while the debounce is armed over unchanged text stays owed, and Saved is not said', async () => {
    const { server, A } = await pair();
    A.type('A');
    await A.debounce();
    // Handed over while the first save is in the air: owed. The bundle now
    // counts this as the last document it handed over.
    A.type('A and B');
    await A.debounce();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.resave, true);

    // A typo and a backspace: the debounce is armed again, over the document
    // the bundle already handed over.
    A.type('A and Bx');
    A.type('A and B');

    // The first save answers.
    A.saveArrives();
    await A.saveReturns();
    assert.equal(server.text(), 'A');
    assert.equal(A.sent.length, 1, 'the debounce is armed, so nothing is sent yet');
    assert.equal(A.view.resave, true, 'and the save stays owed');
    assert.equal(A.view.unsaved, true);
    assert.notEqual(A.word(), 'Saved', 'the stored document is not what the editor holds');

    // The debounce fires and finds nothing it has not handed over already.
    await A.debounce();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.resave, true);
    assert.notEqual(A.word(), 'Saved');
    assert.equal(A.draft(), 'A and B');

    // The next tick sends it.
    await A.cycle();
    assert.deepEqual(
        A.sent.map((save) => [save.text, save.base, save.overwrite]),
        [
            ['A', 1, false],
            ['A and B', 2, false],
        ]
    );
    assert.equal(A.word(), 'Saving');

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'A and B');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.resave, false);
    assert.equal(A.word(), 'Saved');
    assert.equal(A.draft(), null);

    // And it is sent once.
    await A.cycle();
    await A.cycle();
    assert.equal(A.sent.length, 2);
});

test('Saved is said only when the stored document is what the editor holds', async () => {
    const { server, A } = await pair();
    A.type('one');
    await A.debounce();
    A.type('one two');

    // The answer arrives while the writer is typing on.
    await A.land();
    assert.equal(server.text(), 'one');
    assert.equal(A.word(), 'Editing', 'not Saved: the editor holds more than was stored');
    assert.equal(A.view.unsaved, true);

    await A.debounce();
    assert.equal(A.word(), 'Saving');
    await A.land();
    assert.equal(server.text(), 'one two');
    assert.equal(A.word(), 'Saved');
});

test('a debounce that does send what was owed is not followed by a second send', async () => {
    const { server, A } = await pair();
    A.type('A');
    await A.debounce();
    A.type('A and B');
    await A.debounce();
    assert.equal(A.view.resave, true);
    A.type('A and B and C');

    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.resave, true, 'owed while the debounce is armed');

    await A.debounce();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['A and B and C', 2]);
    await A.cycle();
    assert.equal(A.sent.length, 2, 'the owed save waits for the one the debounce sent');

    await A.land();
    await A.cycle();
    assert.equal(A.sent.length, 2);
    assert.equal(A.view.resave, false);
    assert.equal(server.text(), 'A and B and C');
    assert.equal(A.word(), 'Saved');
});

test('an owed save the debounce sends and the server rejects is not sent a second time', async () => {
    const { server, A } = await pair();
    A.type('A');
    await A.debounce();
    // Handed over while the first save is in the air: owed.
    A.type('A and B');
    await A.debounce();
    assert.equal(A.view.resave, true);
    // The writer types on, so the debounce is armed when the first save
    // answers, and the save stays owed for the debounce to send.
    A.type('A and B and C');
    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.resave, true, 'owed while the debounce is armed');

    // The debounce sends it. The server rejects it (DocumentSchema).
    server.rejectNext = true;
    await A.debounce();
    assert.equal(A.sent.length, 2);
    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.word(), 'Not saved');
    assert.equal(A.sent.length, 2, 'the rejected document is not sent again off the owed flag');
    assert.equal(A.view.resave, false);

    await A.cycle();
    await A.cycle();
    assert.equal(A.sent.length, 2);
    assert.equal(A.word(), 'Not saved');
    assert.equal(A.view.unsaved, true, 'the text still exists nowhere else');
    assert.equal(A.draft(), 'A and B and C');
});

test('adoptVersion inside the debounce, over text the debounce will not send again: the owed save sends it', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    // A style change by this same page is stored first (version 2). The
    // autosave that travelled with it is refused against that version.
    const styled = server.replace(server.json);
    A.saveArrives();
    await A.saveReturns();
    // Refused as stale: Phase 2 fetches the newer document to merge, and
    // holds the debounce meanwhile; the notice is for a merge that fails.
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.view.merging, { version: 2, since: A.env.now() });

    // A typo and a backspace, then the style change's own event arrives.
    A.type('A1x');
    A.type('A1');
    A.host().adoptVersion(styled);
    assert.equal(A.view.conflict, null);
    assert.equal(A.sent.length, 1, 'the debounce is armed: nothing yet');

    await A.debounce();
    assert.equal(A.sent.length, 1, 'the bundle finds nothing new to send');
    assert.equal(A.view.resave, true);

    await A.poll();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['A1', 2]);
    await A.land();
    assert.equal(server.text(), 'A1');
    assert.equal(A.word(), 'Saved');
});

// ───────────────────────────────────────────────────────── fix B

test('a save that has not answered is waited for, for fifteen seconds', async () => {
    const { clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveNeverAnswers();

    clock.now += SAVE_EXPIRY_MS - 1;
    assert.equal(A.host().syncState(), 'busy');
    await A.cycle();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.saving, 1);
});

// The in-page status strip hides its Saved / Ready word while `unsaved` is
// true (editor.blade.php; EditorSyncWiringTest pins the condition). That word
// is whatever the server last rendered, so the flag is the only thing that
// keeps it from saying Saved over text the server does not hold. These are
// the states in which it used to.
test('the page knows the text is unsaved from the first keystroke until a save of it is accepted', async () => {
    const { server, clock, A } = await pair();
    A.type('A1');
    assert.equal(A.view.unsaved, true, 'inside the debounce, before anything is sent');

    await A.debounce();
    assert.equal(A.view.unsaved, true, 'while the save is in the air');

    A.saveNeverAnswers();
    assert.equal(A.view.unsaved, true, 'while a save that will never answer is waited for');

    clock.now += SAVE_EXPIRY_MS;
    assert.equal(A.host().syncState(), 'dirty');
    assert.equal(A.view.unsaved, true, 'after that save was given up on');

    // The schema refuses the save that is sent again: nothing is stored.
    server.rejectNext = true;
    await A.cycle();
    await A.land();
    assert.equal(server.text(), 'start');
    assert.equal(A.view.unsaved, true, 'after a save the server refused');
    assert.equal(A.word(), 'Not saved');

    // Only an accepted save of what the editor holds clears it.
    A.type('A2');
    await A.debounce();
    await A.land();
    assert.equal(server.text(), 'A2');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.word(), 'Saved');
});

test('a save that never answers is owed again after fifteen seconds: the poll sends it', async () => {
    const { server, clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveNeverAnswers();

    clock.now += SAVE_EXPIRY_MS;
    await A.cycle();
    assert.equal(A.sent.length, 2, 'every answered poll is the moment to send a save that never answered');
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['A1', 1]);

    await A.land();
    assert.equal(server.text(), 'A1');
    assert.equal(A.view.unsaved, false);
});

test('a save that never answers is owed again after fifteen seconds when syncState() notices first', async () => {
    const { server, clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveNeverAnswers();

    clock.now += SAVE_EXPIRY_MS;
    assert.equal(A.host().syncState(), 'dirty', 'no longer busy');
    assert.equal(A.view.saving, 0);
    assert.equal(A.view.resave, true, 'and the save is owed');

    await A.cycle();
    assert.equal(A.sent.length, 2);
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['A1', 1]);

    await A.land();
    assert.equal(server.text(), 'A1');
});

test('a save that never answers does not stop the notice: when somebody else saves, the text is kept and asked about', async () => {
    const { server, clock, A, B } = await pair({ A: { mergeFails: true } });
    A.type('A long paragraph');
    await A.debounce();
    A.saveNeverAnswers();
    B.type('B1');
    await B.debounce();
    await B.land();

    await A.cycle();
    assert.equal(A.view.conflict, null, 'inside fifteen seconds nothing is decided');

    clock.now += SAVE_EXPIRY_MS;
    await A.cycle();
    await A.land();
    await A.cycle();
    assert.equal(A.view.conflict.version, 2);
    assert.equal(A.text(), 'A long paragraph');
    assert.equal(server.text(), 'B1');
});

// ───────────────────────────────────────────────────────── a save given up on while the polls are blocked

// Something in front of the application (a firewall, a rate limiter) or the
// network itself answers the poll; the application never does.
const BLOCKED_POLLS = [
    ['answered 403 with no body', (t) => t.pollAnswers(403, null)],
    ['answered 503 with no body', (t) => t.pollAnswers(503, null)],
    ['answered 500 with a JSON body', (t) => t.pollAnswers(500, { message: 'Server Error' })],
    ['answered 429 with a JSON body', (t) => t.pollAnswers(429, { message: 'Too Many Attempts.' })],
    ['that never reach the server', (t) => t.pollFails()],
];

for (const [what, blocked] of BLOCKED_POLLS) {
    test(`with polls ${what}, a save that never answers is given up on after fifteen seconds: the next autosave goes out by itself`, async () => {
        const { server, clock, A } = await pair();
        A.type('A1');
        await A.debounce();
        A.saveNeverAnswers();

        // Not one poll is answered from here on.
        await A.fireTimer();
        await blocked(A);
        assert.equal(A.view.saving, 1, 'inside fifteen seconds the save is still waited for');

        clock.now += SAVE_EXPIRY_MS;
        A.type('A2');
        await A.debounce();
        assert.deepEqual(
            A.sent.map((save) => [save.text, save.base]),
            [
                ['A1', 1],
                ['A2', 1],
            ],
            'persist() gives up on the old save itself; it does not wait for a poll to do it'
        );
        assert.equal(A.word(), 'Saving');

        await A.land();
        assert.equal(server.text(), 'A2');
        assert.equal(A.view.unsaved, false);
        assert.equal(A.view.saving, 0);
        assert.equal(A.word(), 'Saved');
        assert.equal(A.draft(), null);

        // And nothing is sent a second time on the next blocked poll.
        await A.fireTimer();
        await blocked(A);
        assert.equal(A.sent.length, 2);
    });

    test(`with polls ${what}, a tab that has stopped typing still sends its owed save once the old one is given up on`, async () => {
        const { server, clock, A } = await pair();
        A.type('A1');
        await A.debounce();
        A.saveNeverAnswers();
        // Handed over inside the fifteen seconds: owed. Then the writer stops.
        A.type('A2');
        await A.debounce();
        assert.equal(A.sent.length, 1);
        assert.equal(A.view.resave, true);

        await A.fireTimer();
        await blocked(A);
        assert.equal(A.sent.length, 1, 'still waited for');

        clock.now += SAVE_EXPIRY_MS;
        await A.fireTimer();
        await blocked(A);
        assert.deepEqual(
            A.sent.map((save) => [save.text, save.base]),
            [
                ['A1', 1],
                ['A2', 1],
            ],
            'every completed poll is a tick, answered or not'
        );

        await A.land();
        assert.equal(server.text(), 'A2');
        assert.equal(A.view.unsaved, false);
        assert.equal(A.word(), 'Saved');
    });
}

test('five minutes of typing behind blocked polls and one lost save: every autosave after the first fifteen seconds is sent', async () => {
    const { server, clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveNeverAnswers();

    for (let round = 2; round <= 30; round += 1) {
        clock.now += 10_000;
        A.type(`A${round}`);
        await A.debounce();
        await A.land();
        await A.fireTimer();
        await A.pollAnswers(403, null);
    }

    assert.equal(server.text(), 'A30');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.saving, 0);
    assert.equal(A.word(), 'Saved');
    // Round 2 came inside the fifteen seconds and waited as an owed save.
    // Round 3's autosave gave up on the lost save and went out, carrying the
    // whole document, so round 2 was never sent on its own.
    assert.deepEqual(
        A.sent.map((save) => save.text),
        ['A1', ...Array.from({ length: 28 }, (_, index) => `A${index + 3}`)]
    );
});

// ───────────────────────────────────────────────────────── what counts as unsaved

test('an edit undone inside the debounce leaves the tab clean: it follows the next save', async () => {
    const { A, B } = await pair();
    B.type('B1');
    await B.debounce();
    await B.land();
    await A.cycle();

    // A typo and a backspace inside the debounce: the bundle finds nothing to send.
    B.type('B1x');
    B.type('B1');
    await B.debounce();
    assert.equal(B.sent.length, 1);
    assert.equal(B.view.unsaved, true);

    A.type('A1');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.view.conflict, null);
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.unsaved, false);
});

test('a reader who typed a character and deleted it again sends nothing, and still follows', async () => {
    const { clock, A, B } = await pair();
    // B has never saved anything.
    B.type('startx');
    B.type('start');
    await B.debounce();
    clock.now += SAVE_EXPIRY_MS;
    await B.cycle();
    assert.equal(B.sent.length, 0, 'a copy the reader does not hold any change to is not sent');

    A.type('A1');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.conflict, null);
});

test('an edit undone inside the debounce puts the save word back to Saved, once', async () => {
    const { B } = await pair();
    B.type('startx');
    B.type('start');
    await B.debounce();
    assert.equal(B.word(), 'Editing');
    assert.equal(B.view.unsaved, true);

    const said = B.reports.length;
    assert.equal(B.host().syncState(), 'clean');
    assert.equal(B.view.unsaved, false);
    assert.deepEqual(B.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);

    // The check runs again on every tick. Only the change is reported.
    assert.equal(B.host().syncState(), 'clean');
    await B.cycle();
    await B.cycle();
    assert.deepEqual(B.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);
});

test('syncState() says nothing when nothing changed: an idle reader, and a tab whose text is still unsaved', async () => {
    const { server, A, B } = await pair();

    // An idle reader is polled and polled.
    const idle = B.reports.length;
    assert.equal(B.host().syncState(), 'clean');
    await B.cycle();
    await B.cycle();
    assert.equal(B.reports.length, idle);

    // A tab whose save was rejected still holds text that exists nowhere else.
    A.type('A text the server rejects');
    await A.debounce();
    server.rejectNext = true;
    await A.land();
    const unsent = A.reports.length;
    assert.equal(A.host().syncState(), 'dirty');
    await A.cycle();
    assert.equal(A.reports.length, unsent);
    assert.equal(A.word(), 'Not saved');
});

test('a tab that said Editing and then takes a newer document says Saved again', async () => {
    const { A } = await pair();
    // Typed and deleted again: nothing to send, and the word still says Editing.
    A.type('startx');
    A.type('start');
    await A.debounce();
    assert.equal(A.word(), 'Editing');
    assert.equal(A.view.unsaved, true);

    const said = A.reports.length;
    assert.equal(A.host().applyFromSync({ version: 2, json: doc('theirs'), outline: null, css: null }), true);
    assert.equal(A.view.unsaved, false);
    assert.deepEqual(A.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);

    // A reader with nothing unsaved follows without a word.
    assert.equal(A.host().applyFromSync({ version: 3, json: doc('theirs again'), outline: null, css: null }), true);
    assert.deepEqual(A.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);
});

test('a tab that said Editing and then follows somebody else save says Saved, once', async () => {
    const { A, B } = await pair();
    B.type('startx');
    B.type('start');
    await B.debounce();
    assert.equal(B.word(), 'Editing');
    const said = B.reports.length;

    A.type('A1');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.equal(B.text(), 'A1');
    assert.deepEqual(B.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);
});

test('typing the bundle still holds, and a standing conflict, each make the tab dirty on their own', async () => {
    const { server, A } = await pair();
    A.type('A1');

    // Alpine's newer data object has not seen the keystroke; the editor handle has.
    const later = makeView(server.version);
    later.confirmed = A.view.confirmed;
    assert.equal(createSyncHost(later, A.env).syncState(), 'dirty');

    await A.debounce();
    await A.land();
    assert.equal(A.host().syncState(), 'clean');
    A.view.conflict = { version: 3, ready: false };
    assert.equal(A.host().syncState(), 'dirty');

    // And while a conflict stands, unsaved text is never written off as an undone edit.
    A.view.unsaved = true;
    assert.equal(A.host().syncState(), 'dirty');
    assert.equal(A.view.unsaved, true);
});

test('an edit undone while the debounce is still armed is still unsaved', async () => {
    const { A } = await pair();
    A.type('x');
    A.type('start');

    assert.equal(A.host().syncState(), 'dirty');
    assert.equal(A.view.unsaved, true);
});

test('text put back and undone again inside the debounce owes no overwrite', async () => {
    const { B } = await conflicted();
    await B.host().loadTheirs();
    B.host().putBack();
    B.type('A1');
    await B.debounce();

    assert.equal(B.host().syncState(), 'clean');
    assert.equal(B.view.overwriteOwed, false);

    B.type('A1 and more');
    await B.debounce();
    assert.equal(B.sent.at(-1).overwrite, false);
});

test('a tab whose save was rejected does not follow: its text exists nowhere else', async () => {
    const { server, A, B } = await pair({ A: { mergeFails: true } });
    A.type('A text the server rejects');
    await A.debounce();
    server.rejectNext = true;
    await A.land();

    assert.deepEqual(A.reports.at(-1), { tone: 'danger', word: 'Not saved' });
    assert.equal(A.view.unsaved, true);
    assert.equal(A.view.baseVersion, 1);
    assert.equal(A.view.conflict, null);
    assert.equal(A.draft(), 'A text the server rejects', 'the draft is the only copy');
    assert.equal(A.sent.length, 1, 'a rejected save is not sent again by itself');
    assert.equal(A.host().syncState(), 'dirty');

    B.type('B1');
    await B.debounce();
    await B.land();
    await A.cycle();
    assert.deepEqual(A.view.conflict, { version: 2, ready: true });
    assert.equal(A.text(), 'A text the server rejects');
});

test('a save whose request fails outright reports Not saved, keeps the draft and stops waiting', async () => {
    const { A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('network'));
    await settle();

    assert.deepEqual(A.reports.at(-1), { tone: 'danger', word: 'Not saved' });
    assert.equal(A.view.saving, 0);
    assert.equal(A.view.unsaved, true);
    assert.equal(A.draft(), 'A1');
    assert.equal(A.host().syncState(), 'dirty');
});

// ───────────────────────────────────────────────────────── a save whose request keeps failing: back-off

const failOldest = async (tab) => {
    tab.saves.shift().reject(new Error('network'));
    await settle();
};

test('a save whose request keeps failing is resent after 0, 5, 10, 20, 40, then 60 s, the word stays Not saved and the draft keeps the text', async () => {
    const { clock, A } = await pair();
    A.type('A1');
    await A.debounce(); // the first send
    const t0 = clock.now;
    const times = [t0];
    let at = t0;

    for (const hold of [...SAVE_RETRY_DELAYS_MS, 60000]) { // the last hold is the cap again
        await failOldest(A);
        assert.equal(A.word(), 'Not saved');
        assert.equal(A.draft(), 'A1', 'the draft holds the text throughout');
        assert.equal(A.view.resave, true, 'and the save is owed');
        if (hold > 0) {
            clock.now = at + hold - 1;
            await A.cycle(); // a tick a millisecond early
            assert.equal(A.sent.length, times.length, `held for ${hold} ms`);
            assert.equal(A.word(), 'Not saved');
        }
        clock.now = at + hold;
        await A.cycle();
        assert.equal(A.sent.length, times.length + 1, `sent after ${hold} ms`);
        at = clock.now;
        times.push(at);
    }

    assert.deepEqual(
        times.map((time) => time - t0),
        [0, 0, 5000, 15000, 35000, 75000, 135000, 195000],
        'the second send is immediate (as before); then the holds grow to the 60 s cap and stay there'
    );
    assert.equal(SAVE_RETRY_DELAYS_MS.at(-1), 60000);
});

test('the hold is bounded: after any number of failures no hold is longer than the cap', async () => {
    const { clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    for (let n = 0; n < 12; n += 1) {
        await failOldest(A);
        clock.now += 60000; // the cap, never more
        await A.cycle();
        assert.equal(A.sent.length, n + 2, `failure ${n + 1} is resent within the cap`);
    }
});

test('a save that is answered resets the count: the next failure is resent at once', async () => {
    const { clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    await failOldest(A);
    await A.cycle(); // immediate resend (the 1st failure has no hold)
    await failOldest(A); // the 2nd: held 5 s
    clock.now += 5000;
    await A.cycle();
    await A.land(); // the server answers ok
    assert.equal(A.view.saveFailures, 0);

    A.type('A1 more');
    await A.debounce();
    await failOldest(A);
    const sent = A.sent.length;
    await A.cycle();
    assert.equal(A.sent.length, sent + 1, 'no hold: the count started again');
});

test('a refusal is an answer as well and resets the count', async () => {
    const { server, clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    await failOldest(A);
    await A.cycle();
    await failOldest(A);
    clock.now += 5000;
    await A.cycle();
    server.rejectNext = true;
    A.saveArrives();
    await A.saveReturns(); // refused by the server: an answer
    assert.equal(A.view.saveFailures, 0);
    assert.equal(A.view.saveRetryAt, 0);
});

test('a new edit while a send is held is sent no later than the hold ends, with the newer text', async () => {
    const { clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    await failOldest(A);
    await A.cycle();
    await failOldest(A); // held 5 s from now
    const held = A.sent.length;

    A.type('A1 more');
    await A.debounce(); // the bundle hands it over: held, not sent
    assert.equal(A.sent.length, held);
    assert.equal(A.word(), 'Not saved');
    assert.equal(A.view.resave, true);
    assert.equal(A.draft(), 'A1 more');

    clock.now += 5000;
    await A.cycle();
    assert.equal(A.sent.length, held + 1);
    assert.equal(A.sent.at(-1).text, 'A1 more');
});

test('a tab that is held does not hold another tab, and Keep mine is not held', async () => {
    const { clock, A, B } = await pair();
    A.type('A1');
    await A.debounce();
    await failOldest(A);
    await A.cycle();
    await failOldest(A); // A is held 5 s

    B.type('B1');
    await B.debounce();
    assert.equal(B.sent.length, 1, 'B is not held');
    assert.equal(B.view.saveRetryAt, 0);
    void clock;
});

test('Keep mine is a choice the writer has just made: a hold left by earlier failures does not delay it', async () => {
    const { clock, B } = await conflicted();
    B.view.saveFailures = 4;
    B.view.saveRetryAt = clock.now + 60000;
    const sent = B.sent.length;

    B.host().keepMine();

    assert.equal(B.sent.length, sent + 1);
    assert.equal(B.sent.at(-1).overwrite, true);
});

test('a save whose request failed is sent again on the next poll tick, with no further keystroke, and its answer ends in Saved', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('network'));
    await settle();
    assert.equal(A.word(), 'Not saved');
    assert.equal(A.view.resave, true, 'the save is owed again');
    assert.equal(A.sent.length, 1);

    // Nothing else happens: no keystroke, nothing from elsewhere. The next
    // completed poll is the tick that sends it.
    await A.cycle();
    assert.equal(A.sent.length, 2, 'sent again on the next tick');
    assert.deepEqual([A.sent[1].text, A.sent[1].base, A.sent[1].overwrite], ['A1', 1, false]);
    assert.equal(A.view.resave, false, 'the owed save is the one that went out');

    await A.land();
    assert.equal(server.text(), 'A1');
    assert.equal(server.version, 2);
    assert.equal(A.view.unsaved, false);
    assert.equal(A.word(), 'Saved');
    assert.equal(A.draft(), null);
});

test('a draft is dropped only when the editor still holds exactly what was stored and nothing is queued', async () => {
    const { A } = await pair();
    A.type('A1');
    const held = JSON.stringify(doc('A1'));

    assert.equal(A.host().clearDraftIfSettled(held), false, 'the debounce is still armed');
    assert.equal(A.draft(), 'A1');

    await A.debounce();
    assert.equal(A.host().clearDraftIfSettled(JSON.stringify(doc('older'))), false, 'the editor holds something else');
    assert.equal(A.draft(), 'A1');

    assert.equal(A.host().clearDraftIfSettled(held), true);
    assert.equal(A.draft(), null);
});

test('a save settles in a browser that cannot keep drafts', async () => {
    const w = world();
    const A = w.tab('A', { draftStore: false });
    await A.open();
    await A.poll();
    A.type('A1');
    await A.debounce();
    await A.land();

    assert.equal(A.view.unsaved, false);
    assert.equal(A.word(), 'Saved');
    assert.equal(A.host().syncState(), 'clean');
});

// ───────────────────────────────────────────────────────── back at what the server confirmed, on every tick

test('an edit undone inside the debounce: the next quiet poll clears it and says Saved, once', async () => {
    const { server, B } = await pair();
    B.type('startx');
    B.type('start');
    await B.debounce();
    assert.equal(B.sent.length, 0, 'the bundle found nothing to send');
    assert.equal(B.word(), 'Editing');
    assert.equal(B.view.unsaved, true);

    // Nobody has saved: the poll brings no document. The check must not
    // wait for one.
    const said = B.reports.length;
    await B.cycle();
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
    assert.deepEqual(B.reports.slice(said), [{ tone: 'good', word: 'Saved' }]);
    assert.equal(B.host().syncState(), 'clean');

    await B.cycle();
    await B.cycle();
    assert.deepEqual(B.reports.slice(said), [{ tone: 'good', word: 'Saved' }], 'only the change is reported');
    assert.equal(B.sent.length, 0);
    assert.equal(server.version, 1);
});

test('an edit undone inside the debounce is cleared by a poll that was not answered, too', async () => {
    const { B } = await pair();
    B.type('startx');
    B.type('start');
    await B.debounce();

    await B.fireTimer();
    await B.pollAnswers(403, null);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
});

test('an edit undone inside the debounce, then back online: a document the writer did not change is not sent', async () => {
    const { server, A, B } = await pair();
    B.type('startx');
    B.type('start');
    await B.debounce();
    assert.equal(B.view.unsaved, true);

    B.host().backOnline();
    assert.equal(B.sent.length, 0, 'the editor holds what the server confirmed: there is nothing to send');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');

    await B.poll();
    assert.equal(server.version, 1, 'no version was made of an unchanged document');

    // Somebody who is typing is therefore not thrown into a conflict.
    A.type('A1');
    await A.cycle();
    assert.equal(A.view.conflict, null);
});

test('an edit undone inside the debounce, then back online with the server ahead: nothing is sent and the tab follows', async () => {
    const { server, A, B } = await pair();
    B.type('startx');
    B.type('start');
    await B.debounce();
    // Somebody saves while this tab is offline; it has not polled.
    A.type('A1 newer work');
    await A.debounce();
    await A.land();

    B.host().backOnline();
    assert.equal(B.sent.length, 0);

    await B.poll();
    assert.equal(B.view.conflict, null, 'no conflict on a tab that holds no text of its own');
    assert.equal(B.text(), 'A1 newer work');
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.sent.length, 0);
    assert.equal(server.text(), 'A1 newer work');
    assert.deepEqual(server.history, []);
});

test('back online inside the debounce, then the edit is undone: nothing is sent', async () => {
    const { server, B } = await pair();
    B.type('startx');
    B.host().backOnline();
    assert.equal(B.view.resave, true, 'owed while the debounce is armed');

    B.type('start');
    await B.debounce();
    assert.equal(B.sent.length, 0, 'the bundle found nothing to send');

    await B.cycle();
    assert.equal(B.sent.length, 0, 'and the owed save is not a copy the writer did not change');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.resave, false);
    assert.equal(B.word(), 'Saved');
    assert.equal(server.version, 1);
});

test('an undo handed over while the save of the undone text is in the air is not written off by a quiet poll', async () => {
    const { server, B } = await pair();
    B.type('startx');
    await B.debounce();
    // The x is deleted again. The debounce hands the document over: owed.
    B.type('start');
    await B.debounce();
    assert.equal(B.sent.length, 1);
    assert.equal(B.view.resave, true);

    // A quiet poll is answered before the save is. The editor does hold
    // what the server last CONFIRMED, but a save of something else is in
    // the air: nothing is settled, and the owed save is not dropped.
    await B.cycle();
    assert.equal(B.view.unsaved, true);
    assert.equal(B.view.resave, true);
    assert.notEqual(B.word(), 'Saved');
    assert.equal(B.host().syncState(), 'busy');

    B.saveArrives();
    await B.saveReturns();
    assert.equal(server.text(), 'startx');
    assert.deepEqual([B.sent[1].text, B.sent[1].base], ['start', 2]);

    await B.land();
    assert.equal(server.text(), 'start', 'the server ends at what the editor shows');
    assert.equal(B.text(), 'start');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
});

for (const [who, notices] of [
    ['a poll', (B) => B.cycle()],
    ['syncState()', async (B) => B.host().syncState()],
]) {
    test(`an undo after a save whose answer was lost is not written off as Saved (${who} notices first)`, async () => {
        // The merge is impossible here, so the refusal ends in the notice as
        // it did in Phase 1; with a merge the echo rule recognises the save
        // (see the echo cases) and the undo is simply stored.
        const { server, clock, B } = await pair({ B: { mergeFails: true } });
        B.type('startx');
        await B.debounce();
        // The server stores it, and the answer never comes.
        B.saveArrives();
        B.saveNeverAnswers();
        assert.equal(server.text(), 'startx');

        B.type('start');
        await B.debounce();
        assert.equal(B.view.resave, true);

        // The save is given up on. What the server holds is no longer known,
        // so the editor being back at the last CONFIRMED document proves
        // nothing: the undo has to be sent.
        clock.now += SAVE_EXPIRY_MS;
        await notices(B);
        assert.equal(B.view.confirmed, null, 'nothing is confirmed once a save has gone unanswered');
        assert.equal(B.view.unsaved, true);
        assert.notEqual(B.word(), 'Saved');

        await B.cycle();
        assert.deepEqual([B.sent[1].text, B.sent[1].base], ['start', 1]);
        await B.land();
        await B.cycle();
        // Refused against the tab's own stored save: the notice (a stated
        // limit), never a silent return of the deleted character.
        assert.equal(B.text(), 'start');
        assert.deepEqual(B.view.conflict, { version: 2, ready: true });
        assert.equal(B.word(), 'Not saved');

        B.host().keepMine();
        await B.land();
        assert.equal(server.text(), 'start');
        assert.equal(B.view.confirmed, JSON.stringify(doc('start')), 'an accepted save is confirmed again');
        assert.equal(B.word(), 'Saved');
    });
}

test('an undo handed over while a save was in the air is sent when that save request fails outright', async () => {
    const { server, B } = await pair();
    B.type('startx');
    await B.debounce();
    B.type('start');
    await B.debounce();

    // The request fails. Whether the server stored it is not known.
    B.saves.shift().reject(new Error('network'));
    await settle();
    assert.equal(B.view.confirmed, null);
    assert.equal(B.word(), 'Not saved');

    await B.cycle();
    assert.deepEqual([B.sent[1].text, B.sent[1].base], ['start', 1], 'not written off: it is sent');
    await B.land();
    assert.equal(server.text(), 'start');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
});

test('an undo whose save the server refused outright is settled: the server still holds what it confirmed', async () => {
    const { server, B } = await pair();
    B.type('startx');
    await B.debounce();
    B.type('start');
    assert.equal(B.draft(), 'start', 'kept while the save of startx is in the air: the server may be about to hold startx');
    await B.debounce();

    // The schema rejects the save of startx: nothing was written.
    server.rejectNext = true;
    B.saveArrives();
    await B.saveReturns();

    assert.equal(B.sent.length, 1, 'the editor holds what the server holds: nothing more is sent');
    assert.equal(B.draft(), null, 'and a draft that says what the server confirmed is not kept');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.resave, false);
    assert.equal(B.word(), 'Saved');
    assert.equal(server.version, 1);
});

// ───────────────────────────────────────────────────────── adoptVersion

test('adoptVersion: exactly one past the base moves the base, and the next save is accepted', async () => {
    const { server, A } = await pair();
    A.host().adoptVersion(server.replace(server.json));
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.polls.length, 0);

    A.type('A1');
    await A.debounce();
    await A.land();
    assert.equal(server.version, 3);
    assert.equal(A.view.conflict, null);

    await A.cycle();
    assert.equal(A.applyCalls, 0, 'the engine was told: the version is not downloaded again');
});

test('adoptVersion: two past the base does not move it, and asks the engine for what was missed', async () => {
    const { server, A, B } = await pair();
    B.type('B wrote this');
    await B.debounce();
    await B.land();
    // A changes the style before its poll has brought B's save: version 3 holds B's text.
    A.host().adoptVersion(server.replace(server.json));

    assert.equal(A.view.baseVersion, 1, 'the editor does not hold their text, so the base stays');
    assert.equal(A.polls.length, 1, 'the engine is poked');

    await A.poll();
    assert.equal(A.text(), 'B wrote this');
    assert.equal(A.view.baseVersion, 3);
});

test('adoptVersion: a caller that has just loaded that version content moves the base regardless', async () => {
    const { A } = await pair();
    A.host().adoptVersion(5, { contentLoaded: true });

    assert.equal(A.view.baseVersion, 5);
    assert.equal(A.polls.length, 0);
});

test('adoptVersion: something that is not a version changes nothing', async () => {
    const { A } = await pair();
    A.host().adoptVersion(undefined);
    A.host().adoptVersion(Number.NaN, { contentLoaded: true });

    assert.equal(A.view.baseVersion, 1);
    assert.equal(A.polls.length, 0);
});

test('adoptVersion: a conflict at or below the adopted version clears, and the owed save is sent', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    // The style change is stored first; the autosave that travelled with it is refused.
    const styled = server.replace(server.json);
    A.saveArrives();
    await A.saveReturns();
    // Phase 2: the refusal starts a merge (the fetch is out), not the notice.
    // adoptVersion() ends a merge at or below the adopted version as it
    // ends a conflict: the owed text goes out on the adopted base.
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.view.merging, { version: 2, since: A.env.now() });

    A.host().adoptVersion(styled);
    assert.equal(A.view.merging, null);
    assert.equal(A.view.conflict, null);
    assert.equal(A.view.baseVersion, 2);
    assert.deepEqual([A.sent[1].text, A.sent[1].base, A.sent[1].overwrite], ['A1', 2, false]);

    await A.land();
    assert.equal(server.text(), 'A1');
    assert.deepEqual(server.history, [], 'it was never an overwrite');
});

test('adoptVersion: a merge above the adopted version stands, and its notice when the merge fails', async () => {
    const { server, A } = await pair({ A: { mergeFails: true } });
    A.type('A1');
    await A.debounce();
    const styled = server.replace(server.json);
    // Somebody else saves version 3 before the autosave arrives.
    server.replace(doc('C saved this'));
    A.saveArrives();
    await A.saveReturns();
    assert.deepEqual(A.view.merging, { version: 3, since: A.env.now() });

    A.host().adoptVersion(styled);
    assert.equal(A.view.baseVersion, 2);
    assert.deepEqual(A.view.merging, { version: 3, since: A.env.now() }, 'above the adopted version: it stands');
    assert.equal(A.sent.length, 1);

    // The fetch answers; the merge is impossible; the notice names version 3.
    await A.poll();
    assert.equal(A.view.conflict.version, 3);
    assert.equal(A.sent.length, 1);
});

// ───────────────────────────────────────────────────────── an accepted suggestion

test('an accepted suggestion over unsaved typing in the same paragraph: the suggestion wins, the typing is listed, the version is adopted', async () => {
    const { server, A, B } = await pair();
    B.type('B wrote this');
    await B.debounce();
    await B.land();
    A.type('A unsaved');
    A.view.aiError = 'an earlier problem';
    // Stored on purpose with no base: version 3, and A has not polled version 2.
    const version = server.replace(doc('the accepted suggestion'));

    A.host().applySuggestion(doc('the accepted suggestion'), version);
    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.view.baseVersion, 3);
    assert.equal(A.view.unsaved, false, 'nothing of the typing survived: a follow in disguise');
    assert.equal(A.view.confirmed, JSON.stringify(doc('the accepted suggestion')));
    assert.deepEqual(
        A.view.lost.map((entry) => [entry.reason, entry.source, entry.excerpt, entry.sentence]),
        [['both', 'suggestion', 'A unsaved', "This paragraph was rewritten by the accepted suggestion. The suggestion's version is on the page; this is yours."]]
    );
    assert.equal(A.view.aiError, '');
    assert.equal(A.view.tick, 1);
    assert.equal(A.outlineRefreshes, 1);
    assert.equal(A.sent.length, 0);
    assert.equal(A.host().syncState(), 'clean');
    assert.equal(A.word(), 'Saved', 'the typing that said Editing is offered back, and no save will answer for it');
    assert.equal(A.engine.pending, null, 'the engine was told');
});

test('an accepted suggestion on a tab with nothing unsaved says nothing', async () => {
    const { server, A } = await pair();
    const said = A.reports.length;

    A.host().applySuggestion(doc('the accepted suggestion'), server.replace(doc('the accepted suggestion')));
    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.reports.length, said);
});

test('a suggestion this editor cannot open leaves the document alone and says so', async () => {
    const { A } = await pair();
    A.refuses = true;

    A.host().applySuggestion(doc('the accepted suggestion'), 2);
    assert.equal(A.view.aiError, 'That suggestion could not be applied — the document is unchanged.');
    assert.equal(A.text(), 'start');
    assert.equal(A.view.baseVersion, 1);
    assert.equal(A.outlineRefreshes, 0);
});

// ───────────────────────────────────────────────────────── fix C

test('an outline refresh that fails after a stored save is not reported as Not saved', async () => {
    const { A } = await pair();
    A.refreshOutline = () => Promise.reject(new Error('outline failed'));
    A.type('A1');
    await A.debounce();
    await A.land();

    assert.deepEqual(A.reports.at(-1), { tone: 'good', word: 'Saved' });
    assert.equal(A.view.unsaved, false);
    assert.equal(A.errors.length, 1, 'it reaches the logger');
    assert.equal(A.errors[0].at(-1).message, 'outline failed');
});

test('an outline refresh that throws after a stored save is not reported as Not saved', async () => {
    const { A } = await pair();
    A.refreshOutline = () => {
        throw new Error('outline threw');
    };
    A.type('A1');
    await A.debounce();
    await A.land();

    assert.deepEqual(A.reports.at(-1), { tone: 'good', word: 'Saved' });
    assert.equal(A.errors.length, 1);
    assert.equal(A.errors[0].at(-1).message, 'outline threw');
});

test('an error after a stored save does not count the save as answered twice', async () => {
    const { A } = await pair();
    A.refreshOutline = () => Promise.reject(new Error('outline failed'));
    A.type('A1');
    await A.debounce();
    // A second save put in the air on purpose, as Keep mine does.
    A.host().persist(A.handle.editor.getJSON(), { force: true });
    assert.equal(A.view.saving, 2);

    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.view.saving, 1, 'one answer, one save fewer in the air');
    assert.equal(A.host().syncState(), 'busy');
});

test('an error thrown while a stored save is being settled reaches the logger, not the status', async () => {
    const { A } = await pair();
    A.engine.retry = () => {
        throw new Error('retry failed');
    };
    A.type('A1');
    await A.debounce();
    await A.land();

    assert.deepEqual(A.reports.at(-1), { tone: 'good', word: 'Saved' });
    assert.equal(A.view.saving, 0);
    assert.equal(A.errors.length, 1);
    assert.equal(A.errors[0].at(-1).message, 'retry failed');
    assert.equal(A.outlineRefreshes, 1, 'the outline is still refreshed');
});

// ───────────────────────────────────────────────────────── fail-closed

test('fail-closed: nothing is saved, nothing is applied, no draft is touched, and the engine is told closed', async () => {
    const w = world();
    const A = w.tab('A');
    // A draft that would otherwise be offered for restore.
    const drafts = new Map([[UUID, { json: JSON.stringify(doc('a draft')), baseVersion: 1 }]]);
    const R = w.tab('R', { failClosed: true, drafts });
    await A.open();
    await R.open();
    await A.poll();
    await R.poll();

    assert.deepEqual(R.reports, [{ tone: 'danger', word: 'Read only' }]);
    assert.equal(R.host().syncState(), 'closed');
    assert.deepEqual(R.questions, [], 'no draft was offered');

    A.type('A1');
    await A.debounce();
    await A.land();
    await R.cycle();
    await R.cycle();
    assert.equal(R.text(), 'start');
    assert.equal(R.view.baseVersion, 1);
    assert.equal(R.view.conflict, null);
    assert.equal(R.engine.pending.version, 2);

    // Every path that would write, pushed as far as it can be.
    const host = R.host();
    host.persist(doc('typed into a read-only page'));
    assert.equal(R.sent.length, 0);
    assert.equal(R.view.saving, 0);
    R.view.conflict = { version: 2, ready: true };
    R.view.setAside = JSON.stringify(doc('set aside'));
    R.view.unsaved = true;
    R.view.resave = true;
    host.persist(doc('typed into a read-only page'), { force: true });
    host.resendIfOwed();
    host.keepMine();
    await host.loadTheirs();
    host.putBack();
    host.backOnline();
    host.edited();
    host.applySuggestion(doc('a suggestion'), 2);
    await host.restoreDraft();
    assert.equal(host.applyFromSync({ version: 2, json: doc('A1'), outline: null, css: null }, { force: true }), false);
    assert.equal(host.clearDraftIfSettled(JSON.stringify(doc('start'))), false);

    assert.equal(R.sent.length, 0, 'nothing is saved');
    assert.equal(R.text(), 'start', 'nothing is applied');
    assert.equal(R.applyCalls, 0, 'the editor is not even asked');
    assert.deepEqual(R.draftCalls, [], 'no draft is read, written, parked or cleared');
    assert.equal(R.draft(), 'a draft');
    assert.equal(R.view.baseVersion, 1);
    assert.equal(R.view.resave, true, 'the owed save is not written off either');
    assert.equal(R.view.setAside, JSON.stringify(doc('set aside')));
    assert.deepEqual(R.questions, [], 'Put it back does not even ask');
    assert.equal(R.engine.pending.version, 2, 'the waiting document was not taken');
    assert.equal(
        R.view.aiError,
        'This document is open read-only, so the accepted suggestion was not applied here. Reload the page once the content problem is fixed.'
    );
    assert.equal(R.host().syncState(), 'closed');
});

test('before the editor exists nothing is written, read or applied', async () => {
    const w = world();
    const A = w.tab('A');
    A.drafts.set(UUID, { json: JSON.stringify(doc('a draft')), baseVersion: 1 });
    A.mounted = false;
    A.view.conflict = { version: 2, ready: true };
    A.view.setAside = JSON.stringify(doc('set aside'));
    A.view.unsaved = true;
    A.view.resave = true;

    const host = A.host();
    assert.equal(host.syncState(), 'closed');
    host.resendIfOwed();
    host.keepMine();
    await host.loadTheirs();
    host.putBack();
    host.applySuggestion(doc('a suggestion'), 2);
    await host.restoreDraft();
    host.edited();
    assert.equal(host.applyFromSync({ version: 2, json: doc('A1'), outline: null, css: null }), false);
    assert.equal(host.clearDraftIfSettled('{}'), false);

    assert.equal(A.sent.length, 0);
    assert.deepEqual(A.draftCalls, []);
    assert.equal(A.view.resave, true);
    assert.equal(A.view.baseVersion, 1);
    assert.equal(A.view.aiError, '');
    assert.equal(A.view.setAside, JSON.stringify(doc('set aside')));
});

test('before the engine has started, a save and its refusal are handled without it', async () => {
    const w = world();
    const A = w.tab('A');
    A.mount();
    A.type('A1');
    assert.equal(A.view.unsaved, true);
    await A.debounce();
    await A.land();
    assert.equal(w.server.text(), 'A1');
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.view.unsaved, false);

    // Somebody else saves; this tab's next save is refused.
    w.server.replace(doc('somebody else'));
    A.type('A2');
    await A.debounce();
    await A.land();
    assert.deepEqual(A.view.conflict, { version: 3, ready: false });

    // No engine holds the newer document, so Load theirs has nothing to show yet.
    await A.host().loadTheirs();
    assert.equal(A.text(), 'A2');
    A.host().adoptVersion(9);
    assert.equal(A.view.baseVersion, 2);
    A.host().backOnline();
    assert.equal(A.sent.length, 2);

    // Keep mine needs only the number.
    A.host().keepMine();
    await A.land();
    assert.equal(w.server.text(), 'A2');
    assert.deepEqual(w.server.history, [{ version: 3, text: 'somebody else' }]);
    assert.equal(A.view.conflict, null);
});

test('a mounted editor that has not failed closed opens as Saved, holding what the server confirmed', async () => {
    const w = world();
    const A = w.tab('A');
    await A.open();

    assert.deepEqual(A.reports, [{ tone: 'good', word: 'Saved' }]);
    assert.equal(A.view.confirmed, JSON.stringify(doc('start')));
    assert.equal(A.host().syncState(), 'clean');
});

// ───────────────────────────────────────────────────────── back online

test('back online: an idle reader sends nothing, and checks for what it missed', async () => {
    const { A, B } = await pair();
    A.type('A1');
    await A.debounce();
    await A.land();

    B.host().backOnline();
    assert.equal(B.sent.length, 0, 'a copy the reader never touched is not sent');
    assert.equal(B.polls.length, 1, 'the engine is poked');

    await B.poll();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.conflict, null);
});

test('back online: a tab with unsaved text sends it', async () => {
    const { server, clock, A } = await pair();
    A.type('typed offline');
    await A.debounce();
    A.saveNeverAnswers();
    clock.now += SAVE_EXPIRY_MS;

    A.host().backOnline();
    assert.equal(A.sent.length, 2);
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['typed offline', 1]);

    await A.land();
    assert.equal(server.text(), 'typed offline');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.draft(), null);
});

test('back online: text whose rejected save left it unsent is sent', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saves.shift().reject(new Error('offline'));
    await settle();
    assert.equal(A.sent.length, 1);

    A.host().backOnline();
    assert.equal(A.sent.length, 2);
    await A.land();
    assert.equal(server.text(), 'A1');
});

test('back online inside the debounce sends nothing: the debounce does', async () => {
    const { server, A } = await pair();
    A.type('A1');

    A.host().backOnline();
    assert.equal(A.sent.length, 0);
    // The save stays owed while the debounce is armed: the debounce may find
    // nothing to send (see the next test), and then nothing else would.
    assert.equal(A.view.resave, true);

    await A.debounce();
    assert.equal(A.sent.length, 1);

    // The debounce did send, so the owed save is not sent a second time.
    await A.land();
    await A.cycle();
    assert.equal(A.sent.length, 1);
    assert.equal(A.view.resave, false);
    assert.equal(server.text(), 'A1');
    assert.equal(A.word(), 'Saved');
});

test('back online inside the debounce, over text the debounce will not send again: the owed save sends it', async () => {
    const { server, clock, A } = await pair();
    // Typed offline: handed over, and the save never answers.
    A.type('one');
    await A.debounce();
    A.saveNeverAnswers();
    A.type('one two');
    await A.debounce();
    assert.equal(A.sent.length, 1);
    clock.now += SAVE_EXPIRY_MS;

    // A typo and a backspace: the debounce is armed, and the editor is back
    // at what the bundle last handed over. The connection returns.
    A.type('one twox');
    A.type('one two');
    A.host().backOnline();
    assert.equal(A.sent.length, 1, 'the debounce is armed: nothing yet');

    await A.debounce();
    assert.equal(A.sent.length, 1, 'the bundle finds nothing new to send');
    assert.equal(A.view.resave, true, 'so the save is still owed');
    assert.notEqual(A.word(), 'Saved');

    await A.poll();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['one two', 1]);
    await A.land();
    assert.equal(server.text(), 'one two');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.word(), 'Saved');
    assert.equal(A.draft(), null);
});

test('back online inside the debounce, after a save that failed outright: the owed save sends the text', async () => {
    const { server, A } = await pair();
    A.type('one');
    await A.debounce();
    A.saves.shift().reject(new Error('offline'));
    await settle();
    assert.equal(A.word(), 'Not saved');

    A.type('onex');
    A.type('one');
    A.host().backOnline();
    await A.debounce();
    assert.equal(A.sent.length, 1, 'the bundle finds nothing new to send');

    await A.poll();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['one', 1]);
    await A.land();
    assert.equal(server.text(), 'one');
    assert.equal(A.word(), 'Saved');
});

// ───────────────────────────────────────────────────────── the draft at page load

/** A page load in a browser that already holds a draft for this document. */
async function reopened(w, draft, options = {}) {
    const drafts = new Map();
    if (draft) {
        drafts.set(UUID, draft);
    }
    const A = w.tab('A', { drafts, ...options });
    await A.open();

    return A;
}

test('a draft based on the current version is restored, and kept until its save has settled', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 });

    assert.deepEqual(A.questions, ['An unsaved offline draft of this document was found. Restore it?']);
    assert.equal(A.text(), 'offline work');
    assert.equal(A.view.unsaved, true);
    assert.equal(A.draft(), 'offline work', 'until the save settles the draft is the only stored copy');
    assert.equal(A.polls.length, 1, 'the engine starts once the draft check has finished');

    await A.poll();
    await A.debounce();
    assert.deepEqual([A.sent[0].text, A.sent[0].base], ['offline work', 1]);
    await A.land();
    assert.equal(w.server.text(), 'offline work');
    assert.equal(A.draft(), null);
});

test('a restored draft whose save is refused is still in the draft store', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 }, { mergeFails: true });
    w.server.replace(doc('somebody saved first'));

    await A.poll();
    assert.deepEqual(A.view.conflict, { version: 2, ready: true });
    assert.equal(A.text(), 'offline work');
    assert.equal(A.draft(), 'offline work');
});

test('a draft the document has moved past is parked and set aside, and can be put back as an overwrite', async () => {
    const w = world();
    w.server.replace(doc('version 2 by somebody'));
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 });

    assert.deepEqual(A.questions, [], 'it is not offered for restore: that would overwrite a newer save');
    assert.equal(A.text(), 'version 2 by somebody');
    assert.equal(A.view.setAside, JSON.stringify(doc('offline work')));
    assert.equal(A.view.setAsideFrom, 'draft');
    assert.equal(A.view.setAsideBase, 2);
    assert.equal(A.stale(), 'offline work');
    assert.equal(A.drafts.get(STALE).baseVersion, 1);
    assert.equal(A.draft(), null);
    assert.match(A.infos.at(-1), /based on v1 was kept as stale-doc-uuid: the document is now at v2/);

    await A.poll();
    A.host().putBack();
    assert.deepEqual(A.questions, [PUT_BACK_QUESTION], 'a draft from an earlier visit is never put back unasked');
    await A.debounce();
    assert.deepEqual([A.sent[0].text, A.sent[0].base, A.sent[0].overwrite], ['offline work', 2, true]);
    await A.land();
    assert.deepEqual(w.server.history, [{ version: 2, text: 'version 2 by somebody' }]);
});

test('a draft the document has moved past is not set aside when the document already says exactly that', async () => {
    const w = world();
    // The unload beacon stored the last words: version 2 is the draft's text.
    w.server.replace(doc('last words'));
    const A = await reopened(w, { json: JSON.stringify(doc('last words')), baseVersion: 1 });

    assert.equal(A.view.setAside, null, 'there is nothing to put back');
    assert.equal(A.view.setAsideFrom, null);
    assert.equal(A.view.setAsideBase, null);
    assert.equal(A.stale(), 'last words');
    assert.equal(A.draft(), null);
});

test('a draft from a version the document has not reached is dropped', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('from the future')), baseVersion: 4 });

    assert.equal(A.draft(), null);
    assert.equal(A.stale(), null);
    assert.equal(A.view.setAside, null);
    assert.equal(A.text(), 'start');
    assert.deepEqual(A.questions, []);
});

test('a draft that says what the document says is dropped without asking', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('start')), baseVersion: 1 });

    assert.deepEqual(A.questions, []);
    assert.equal(A.draft(), null);
    assert.equal(A.stale(), null);
    assert.equal(A.view.unsaved, false);
});

test('a draft the writer declines is parked, not deleted', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 }, { confirmAnswer: false });

    assert.equal(A.questions.length, 1);
    assert.equal(A.text(), 'start');
    assert.equal(A.stale(), 'offline work');
    assert.equal(A.draft(), null);
    assert.equal(A.view.setAside, null);
    assert.match(A.infos.at(-1), /was not restored. It was kept as stale-doc-uuid/);
});

for (const [what, draft] of [
    ['is not a document', { json: JSON.stringify({ type: 'paragraph' }), baseVersion: 1 }],
    ['is empty', { json: 'null', baseVersion: 1 }],
    ['has no base version', { json: JSON.stringify(doc('from an older build')), baseVersion: null }],
]) {
    test(`a draft that ${what} is dropped`, async () => {
        const w = world();
        const A = await reopened(w, draft);

        assert.equal(A.drafts.has(UUID), false);
        assert.equal(A.stale(), null);
        assert.deepEqual(A.questions, []);
        assert.equal(A.text(), 'start');
    });
}

test('a draft that cannot be read is left where it is, and the engine still starts', async () => {
    const w = world();
    const A = await reopened(w, { json: '{not json', baseVersion: 1 });

    assert.equal(A.drafts.has(UUID), true);
    assert.equal(A.text(), 'start');
    assert.equal(A.polls.length, 1);
});

test('a draft the editor cannot open is kept rather than lost', async () => {
    const w = world();
    const drafts = new Map([[UUID, { json: JSON.stringify(doc('offline work')), baseVersion: 1 }]]);
    const A = w.tab('A', { drafts });
    A.setContentThrows = true;
    await A.open();

    assert.equal(A.text(), 'start');
    assert.equal(A.draft(), 'offline work');
    assert.equal(A.stale(), null);
    assert.match(A.infos.at(-1), /could not be applied and has been kept/);
});

test('with no draft, and in a browser that cannot keep one, the page simply opens', async () => {
    const w = world();
    const A = await reopened(w, null);
    assert.deepEqual(A.draftCalls, ['adoptLost', 'loadLost', 'loadDraft', 'saveBase']);
    assert.equal(A.polls.length, 1);

    const B = w.tab('B', { draftStore: false });
    await B.open();
    assert.equal(B.text(), 'start');
    assert.equal(B.polls.length, 1);
});

test('reloading instead of choosing: the draft is merged on reopen, and the paragraph that lost is offered back with the draft sentence', async () => {
    const { server, B } = await conflicted();

    // The same browser loads the page again: it renders version 2, and
    // holds the base row of version 1, which the draft was typed on.
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.equal(again.text(), 'A1');
    assert.equal(again.view.setAside, null);
    assert.deepEqual(
        again.view.lost.map((entry) => [entry.reason, entry.source, entry.excerpt]),
        [['both', 'draft', 'B1']]
    );
    assert.equal(again.stale(), 'B1', 'the pre-merge copy is parked, as today');
    assert.equal(again.draft(), null);

    await again.poll();
    assert.equal(again.sent.length, 0, 'nothing of the draft survived: nothing to save');

    // Put it back puts the draft's paragraph below A's, as an ordinary save.
    assert.equal(again.host().putBackLost(0), true);
    await again.debounce();
    await again.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'A1'],
        ['fresh1', 'B1'],
    ]);
    assert.deepEqual(server.history, []);
});

// ───────────────────────────────────────────────────────── a draft whose text is already in the document

test('the beacon stored the last words and nobody saved since: the draft is parked and nothing is offered', async () => {
    const { server, clock, A } = await pair();
    A.type('A last words');
    A.leave();
    assert.deepEqual(
        A.beacons.map((beacon) => [beacon.text, beacon.body.base_version, beacon.answer.status]),
        [['A last words', 1, 200]]
    );
    assert.equal(server.text(), 'A last words');
    assert.equal(A.draft(), 'A last words', 'the page was gone before it could clear its draft');

    const again = makeTab({ server, clock }, 'A2', { drafts: A.drafts });
    await again.open();
    assert.equal(again.text(), 'A last words');
    assert.equal(again.view.setAside, null);
    assert.equal(again.view.setAsideFrom, null);
    assert.deepEqual(again.questions, []);
    assert.equal(again.draft(), null);
});

test('the beacon stored the last words and others saved since: the draft is offered, and putting it back asks first', async () => {
    const { server, clock, A, B } = await pair();
    A.type('A last words');
    A.leave();
    assert.equal(server.version, 2);

    // The other writer follows and works on: two more versions.
    await B.cycle();
    B.type('A last words, then B one');
    await B.debounce();
    await B.land();
    B.type('A last words, then B one and B two');
    await B.debounce();
    await B.land();
    assert.equal(server.version, 4);

    // The first browser opens the document again. Its draft (based on
    // version 1) holds text the document has had since version 2, but the
    // page cannot know that: all it sees is a draft that differs.
    const again = makeTab({ server, clock }, 'A2', { drafts: A.drafts, confirmAnswer: false });
    await again.open();
    await again.poll();
    assert.equal(again.text(), 'A last words, then B one and B two');
    assert.equal(again.view.setAside, JSON.stringify(doc('A last words')));
    assert.equal(again.view.setAsideFrom, 'draft', 'the page knows this is a draft from an earlier visit, not text it took away');
    assert.equal(again.view.setAsideBase, 4);

    // The draft merge was declined (it would have offered the paragraph
    // back); one click must not replace two versions of somebody else's work.
    again.host().putBack();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION, PUT_BACK_QUESTION]);
    assert.equal(again.text(), 'A last words, then B one and B two');
    assert.equal(again.view.setAside, JSON.stringify(doc('A last words')));
    await again.debounce();
    assert.equal(again.sent.length, 0);
    assert.equal(server.version, 4);
    assert.deepEqual(server.history, []);

    // The writer can decline for good. The parked copy stays.
    again.host().discardSetAside();
    assert.equal(again.view.setAside, null);
    assert.equal(again.stale(), 'A last words');
    await B.cycle();
    assert.equal(B.text(), 'A last words, then B one and B two');
});

test('a save in the air when the tab closed landed, and others saved since: putting the draft back asks first', async () => {
    const { server, clock, A, B } = await pair();
    A.type('A last words');
    await A.debounce();
    // The tab is closed with the save in the air. The server stores it;
    // the answer reaches nobody, so the draft is never cleared.
    A.leave();
    assert.equal(A.beacons.length, 0, 'nothing was left for a beacon to send');
    A.saveArrives();
    assert.equal(server.text(), 'A last words');
    assert.equal(A.draft(), 'A last words');

    await B.cycle();
    B.type('A last words, then B');
    await B.debounce();
    await B.land();

    const again = makeTab({ server, clock }, 'A2', { drafts: A.drafts, confirmAnswer: false });
    await again.open();
    await again.poll();
    assert.equal(again.view.setAsideFrom, 'draft');

    again.host().putBack();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION, PUT_BACK_QUESTION]);
    assert.equal(again.text(), 'A last words, then B');
    await again.debounce();
    assert.equal(again.sent.length, 0);
    assert.equal(server.text(), 'A last words, then B');
});

test('a save in the air when the tab closed landed and nobody saved since: nothing is offered', async () => {
    const { server, clock, A } = await pair();
    A.type('A last words');
    await A.debounce();
    A.leave();
    A.saveArrives();

    const again = makeTab({ server, clock }, 'A2', { drafts: A.drafts });
    await again.open();
    assert.equal(again.view.setAside, null);
    assert.deepEqual(again.questions, []);
    assert.equal(again.text(), 'A last words');
});

test('an edit undone inside the debounce leaves no draft: after somebody saves, reopening offers nothing', async () => {
    const { server, clock, A, B } = await pair();
    B.type('startx');
    assert.equal(B.draft(), 'startx');
    B.type('start');
    assert.equal(B.draft(), null, 'a draft that says what the server confirmed is not kept');
    await B.debounce();
    assert.equal(B.sent.length, 0);
    B.leave();
    assert.equal(B.beacons.length, 0);

    A.type('A1');
    await A.debounce();
    await A.land();

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.equal(again.text(), 'A1');
    assert.equal(again.view.setAside, null, 'no offer to put back a copy that holds none of this reader text');
    assert.equal(again.view.setAsideFrom, null);
    assert.equal(again.stale(), null);
    assert.deepEqual(again.questions, []);
    assert.deepEqual(again.draftCalls, ['adoptLost', 'loadLost', 'loadDraft', 'saveBase']);
});

test('an edit undone after a save leaves no draft either: the draft store is cleared, not rewritten', async () => {
    const { A } = await pair();
    A.type('A1');
    await A.debounce();
    await A.land();
    assert.equal(A.draft(), null);

    A.type('A1 and a typo');
    assert.equal(A.draft(), 'A1 and a typo');
    A.type('A1');
    assert.equal(A.draft(), null);
    assert.equal(A.draftCalls.at(-1), 'clearDraft');
});

test('an edit undone inside the debounce in a browser that cannot keep drafts is still settled', async () => {
    const w = world();
    const A = w.tab('A', { draftStore: false });
    await A.open();
    await A.poll();
    A.type('startx');
    A.type('start');
    await A.debounce();

    await A.cycle();
    assert.equal(A.view.unsaved, false);
    assert.equal(A.word(), 'Saved');
});

test('an edit undone inside the debounce that a tick settles leaves no draft behind', async () => {
    const { clock, B } = await pair();
    // The save of the first edit is given up on: from then on nothing is
    // confirmed, so the undo is a draft worth keeping...
    B.type('startx');
    await B.debounce();
    B.saveNeverAnswers();
    clock.now += SAVE_EXPIRY_MS;
    B.type('start');
    assert.equal(B.draft(), 'start');

    // ...until a save is accepted and the server says what it holds.
    await B.debounce();
    await B.land();
    assert.equal(B.view.confirmed, JSON.stringify(doc('start')));
    assert.equal(B.draft(), null);

    // From here an undone edit is settled by the next tick, draft and all.
    B.type('start again');
    await B.debounce();
    B.type('start');
    assert.equal(B.draft(), 'start', 'kept while the save of the other text is in the air');
    await B.debounce();
    await B.land();
    assert.equal(B.view.unsaved, false);
    assert.equal(B.draft(), null);
});

// ───────────────────────────────────────────────────────── the module holds no state

test('each call reads and writes the state object it was built from', async () => {
    const { server, A, B } = await conflicted();
    // Alpine re-created the data object after a render: fresh fields, the same editor and engine.
    const later = makeView(server.version);
    const viaLater = createSyncHost(later, B.env);

    // The notice was raised on the first object, where the engine's callbacks are bound.
    viaLater.keepMine();
    assert.equal(B.sent.length, 0, 'the newer object knows of no conflict');

    viaLater.persist(B.handle.editor.getJSON());
    assert.equal(later.saving, 1);
    assert.equal(B.view.saving, 0);
    assert.equal(B.sent[0].base, 2, 'the base stated is the one on the object that was handed in');

    // Two hosts built from one object are one host.
    B.host().keepMine();
    assert.equal(B.host().syncState(), 'busy');
    assert.equal(B.view.saving, 1);
    assert.equal(A.view.saving, 0);
});

// ───────────────────────────────────────────────────────── fix D

const TAB_ID_RULE = /^[A-Za-z0-9-]{1,64}$/;

test('the tab id comes from randomUUID when the browser has it', () => {
    const id = createTabId({
        crypto: { randomUUID: () => '3b241101-e2bb-4255-8caf-4136c566a962' },
        random: () => assert.fail('Math.random must not be used'),
        now: () => 0,
    });

    assert.equal(id, '3b241101-e2bb-4255-8caf-4136c566a962');
    assert.match(id, TAB_ID_RULE);
});

test('without randomUUID the tab id is built from sixteen bytes of getRandomValues', () => {
    const id = createTabId({
        crypto: {
            getRandomValues(bytes) {
                assert.equal(bytes.length, 16);
                bytes.forEach((_, index) => {
                    bytes[index] = index * 17;
                });

                return bytes;
            },
        },
        random: () => assert.fail('Math.random must not be used'),
        now: () => 0,
    });

    assert.equal(id, 'tab-00112233445566778899aabbccddeeff');
    assert.match(id, TAB_ID_RULE);
});

test('only a browser with no crypto at all gets a tab id from Math.random', () => {
    for (const crypto of [undefined, null, {}]) {
        const id = createTabId({ crypto, random: () => 0.123456789, now: () => 1_700_000_000_000 });

        assert.match(id, /^tab-[0-9a-z]+$/);
        assert.match(id, TAB_ID_RULE);
        assert.ok(id.endsWith((1_700_000_000_000).toString(36)));
    }
});

// ───────────────────────────────────────────────────────── Phase 2: two people type, the page merges

/** Two tabs on a two-paragraph document, both following it. */
async function twoParagraphs() {
    const w = await pair();
    const { A, B } = w;
    A.insertAfter('p1', 'p2', 'World');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(B.blocks(), [
        ['p1', 'start'],
        ['p2', 'World'],
    ]);

    return w;
}

test('the knobs: five merges after refusals, fifteen seconds for a fetch', () => {
    assert.equal(MERGE_ATTEMPTS, 5);
    assert.equal(MERGE_FETCH_EXPIRY_MS, SAVE_EXPIRY_MS);
});

test('both type in different paragraphs: the refused save is merged and saved on the new base, both tabs converge, nothing is lost, no notice', async () => {
    const { server, A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await B.debounce();
    await A.land();
    assert.equal(server.version, 3);

    // B's save is refused: version 3 is in the way. B fetches it to merge.
    B.saveArrives();
    await B.saveReturns();
    assert.equal(B.view.conflict, null, 'no notice');
    assert.deepEqual(B.view.merging, { version: 3, since: B.env.now() });
    assert.equal(B.view.mergeAttempts, 1);
    assert.equal(B.polls.length, 1, 'the newer document is being fetched');
    assert.equal(B.polls[0].payload.version, 2, 'from the base, so the document comes with the answer');

    await B.poll();
    assert.equal(B.view.merging, null);
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
    assert.equal(B.view.baseVersion, 3);
    assert.deepEqual(B.lostTexts(), []);
    assert.equal(B.view.conflict, null);
    assert.equal(B.sent.length, 2, 'the merged document is sent at once');
    assert.deepEqual([B.sent[1].base, B.sent[1].overwrite], [3, false]);
    assert.equal(B.view.unsaved, true);
    assert.equal(
        B.view.confirmed,
        JSON.stringify(docOf(para('p1', 'Hello A'), para('p2', 'World'))),
        'confirmed is theirs, as this editor serialises it'
    );
    assert.deepEqual(B.view.known, { version: 3, json: B.view.confirmed });
    assert.equal(B.drafts.get(BASE).baseVersion, 3);

    await B.land();
    assert.equal(server.version, 4);
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
    assert.equal(B.view.mergeAttempts, 0);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
    assert.equal(B.draft(), null);

    await A.cycle();
    assert.deepEqual(A.blocks(), blocksOf(server.json));
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.lostTexts(), []);
});

test('a newer version arriving by the poll while this tab types is merged, not refused: no fetch, one save, and the consumed document is not merged again', async () => {
    const { server, A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await A.land();

    // B's poll brings version 3 while B is still inside its debounce.
    await B.cycle();
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.merging, null);
    assert.equal(B.view.mergeAttempts, 0, 'a poll-triggered merge is not a refusal');
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.engine.pending, null, 'the consumed document is dropped');
    assert.equal(B.sent.length, 1, 'the merged document went out at once');
    assert.deepEqual([B.sent[0].base, B.sent[0].overwrite], [3, false]);
    assert.deepEqual(B.outlines.at(-1), { numbers: {}, of: 3 });
    assert.deepEqual(B.css.at(-1), '.paper{--v:3}');

    await B.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
    await B.debounce();
    assert.equal(B.sent.length, 1, 'the debounce finds nothing new');
    await B.cycle();
    assert.equal(B.sent.length, 1);
    assert.equal(B.applyCalls, 2, 'the follow of version 2 and the merge: the consumed document is not applied again');
    assert.equal(B.word(), 'Saved');
});

test('same paragraph, same moment (D1): theirs stays on the page, mine is listed and parked, nothing is saved, no notice', async () => {
    const { server, A, B } = await pair();
    A.type('Hello A');
    B.type('Hello B');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.equal(B.text(), 'Hello A', 'theirs is on the page');
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.unsaved, false, 'nothing of mine survived: a follow in disguise');
    assert.equal(B.sent.length, 0);
    assert.equal(B.word(), 'Saved');
    assert.equal(B.view.confirmed, JSON.stringify(doc('Hello A')));
    assert.deepEqual(
        B.view.lost.map((entry) => [entry.key, entry.id, entry.type, entry.reason, entry.source, entry.excerpt, entry.anchors, entry.version]),
        [['p1:2:0', 'p1', 'paragraph', 'both', 'merge', 'Hello B', ['p1'], 2]]
    );
    assert.equal(
        B.view.lost[0].sentence,
        'This paragraph was changed elsewhere at the same moment. The other version is on the page; this is yours.'
    );
    assert.deepEqual(B.view.lost[0].json, para('p1', 'Hello B'));
    assert.deepEqual(B.lostPark().entries.map((entry) => entry.excerpt), ['Hello B']);
    assert.equal(B.lostPark().baseVersion, 2);
    assert.equal(B.draft(), null, 'the draft said Hello B, which is offered back, not kept as a draft of version 2');
    assert.match(B.infos.at(-1), /offered back/);
    assert.equal(server.version, 2);
    assert.equal(B.host().syncState(), 'clean');

    // A reader with nothing unsaved follows without a word, and nothing is said twice.
    const said = B.reports.length;
    await B.cycle();
    assert.equal(B.reports.length, said);
    assert.deepEqual(A.lostTexts(), [], 'A lost nothing: A text won');
});

test('two losses of one paragraph in a row keep both offered versions and both parked copies (the D1 ping-pong)', async () => {
    const { A, B } = await pair();
    A.type('Hello A1');
    B.type('Hello B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.text(), 'Hello A1');

    // B types on into A's version; A saves again first.
    B.type('Hello A1 B2');
    A.type('Hello A2');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.equal(B.text(), 'Hello A2');
    assert.deepEqual(B.lostTexts(), ['Hello B1', 'Hello A1 B2']);
    assert.deepEqual(B.view.lost.map((entry) => entry.key), ['p1:2:0', 'p1:3:0']);
    assert.deepEqual(B.lostPark().entries.map((entry) => entry.excerpt), ['Hello B1', 'Hello A1 B2']);
    assert.equal(B.view.conflict, null);
});

test('a paragraph removed elsewhere while I typed in it (D2): it goes, my words are listed with their place, nothing is saved', async () => {
    const { server, A, B } = await twoParagraphs();
    A.insertAfter('p2', 'p3', 'Third');
    await A.debounce();
    await A.land();
    await B.cycle();

    B.typeIn('p2', 'World B');
    A.deleteBlock('p2');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(B.blocks(), [
        ['p1', 'start'],
        ['p3', 'Third'],
    ]);
    assert.equal(B.view.conflict, null);
    assert.equal(B.sent.length, 0);
    assert.deepEqual(
        B.view.lost.map((entry) => [entry.id, entry.reason, entry.excerpt, entry.anchors]),
        [['p2', 'deleted', 'World B', ['p1']]]
    );
    assert.equal(B.view.lost[0].sentence, 'This paragraph was removed elsewhere while you were typing in it.');
    assert.equal(server.version, 4);
});

test('I deleted a paragraph they edited (c2): the merged save says it overwrites and the server keeps their version; they are not told', async () => {
    const { server, A, B } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    B.deleteBlock('p2');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(B.blocks(), [['p1', 'start']]);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.overwriteOwed, true);
    assert.deepEqual([B.sent[0].base, B.sent[0].overwrite], [3, true]);
    assert.equal(B.drafts.get(UUID).baseVersion, 2, 'the draft is stamped one below while the overwrite is owed');

    await B.land();
    assert.deepEqual(server.history, [{ version: 3, text: 'start' }]);
    assert.deepEqual(server.kept.at(-1).json, docOf(para('p1', 'start'), para('p2', 'World A')));
    assert.equal(B.view.overwriteOwed, false);
    assert.deepEqual(blocksOf(server.json), [['p1', 'start']]);

    await A.cycle();
    assert.deepEqual(A.blocks(), [['p1', 'start']]);
    assert.deepEqual(A.lostTexts(), [], 'A is not told (Q1): the text is in the history');
    assert.equal(A.view.conflict, null);
});

test('Load theirs, Put it back, then a merge inside the debounce: the merged save still overwrites and the head is kept', async () => {
    const { server, A, B } = await conflicted();
    await B.host().loadTheirs();
    assert.equal(B.text(), 'A1');
    B.host().putBack();
    assert.equal(B.view.overwriteOwed, true);
    // The merge works again from here; conflicted() made it impossible to raise the notice.
    B.mergeFails = false;

    // Inside the debounce, A saves a new paragraph and B's poll brings it.
    A.insertAfter('p1', 'p2', 'A new paragraph');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(B.blocks(), [
        ['p1', 'B1'],
        ['p2', 'A new paragraph'],
    ]);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.overwriteOwed, true, 'ORed, never assigned: the owed overwrite survives a merge that owes none');
    assert.deepEqual([B.sent.at(-1).base, B.sent.at(-1).overwrite], [3, true]);

    await B.land();
    assert.deepEqual(server.history, [{ version: 3, text: 'A1' }]);
    assert.equal(B.view.overwriteOwed, false);
});

test('c2 then c1 across two merges: the overwrite stays owed until a save that carried it is accepted', async () => {
    const { server, A, B } = await twoParagraphs();
    // B deletes p2 unsaved; A edits p2 and saves (version 3). B's merge: c2.
    B.deleteBlock('p2');
    A.typeIn('p2', 'World A');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.view.overwriteOwed, true);
    assert.equal(B.sent.length, 1);

    // A saves again (version 4) before B's merged save arrives: refused, fetched, merged again, and p2 is c1 now.
    A.typeIn('p1', 'start A');
    await A.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    assert.deepEqual(B.view.merging, { version: 4, since: B.env.now() });
    await B.poll();

    assert.deepEqual(B.blocks(), [['p1', 'start A']]);
    assert.equal(B.view.overwriteOwed, true, 'still owed: no save that carried it has been accepted');
    assert.deepEqual([B.sent.at(-1).base, B.sent.at(-1).overwrite], [4, true]);

    await B.land();
    assert.deepEqual(server.history, [{ version: 4, text: 'start A' }]);
    assert.equal(B.view.overwriteOwed, false);
});

test('an owed overwrite is dropped when a later merge leaves nothing of mine to save: both deleted the paragraph, so no save will carry it', async () => {
    const { server, A, B } = await twoParagraphs();
    B.deleteBlock('p2');
    A.typeIn('p2', 'World A');
    await A.debounce();
    await A.land(); // version 3: A edited p2
    await B.cycle(); // B merges: c2, the overwrite is owed, the merged save (base 3) is in the air
    assert.equal(B.view.overwriteOwed, true);

    // A deletes p2 as well (version 4) before B's save lands: it is refused, fetched, merged again.
    A.deleteBlock('p2');
    await A.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    await B.poll();

    assert.deepEqual(B.blocks(), blocksOf(server.json));
    assert.equal(B.view.unsaved, false, 'both deleted it: nothing of B is left to save');
    assert.equal(B.view.overwriteOwed, false, 'a save that is not going to be sent cannot carry the overwrite');
    assert.equal(B.sent.length, 1, 'no further save');
});

test('MERGE_ATTEMPTS merged saves refused in a row end in the notice, with the merged document as mine', async () => {
    const { server, A, B } = await twoParagraphs();
    B.typeIn('p2', 'World B');
    await B.debounce();
    // twoParagraphs() leaves the server at version 2; every round below cuts
    // one version, and so does the refusal that ends the row.
    const last = MERGE_ATTEMPTS + 1;
    const noticed = MERGE_ATTEMPTS + 3;

    for (let round = 1; round <= MERGE_ATTEMPTS; round += 1) {
        // A saves before B's save lands, every time.
        A.typeIn('p1', `A${round}`);
        await A.debounce();
        await A.land();
        B.saveArrives();
        await B.saveReturns();
        assert.equal(B.view.mergeAttempts, round);
        assert.equal(B.view.conflict, null);
        await B.poll();
        assert.deepEqual(B.blocks(), [
            ['p1', `A${round}`],
            ['p2', 'World B'],
        ]);
    }

    // One refusal more than the bound.
    A.typeIn('p1', `A${last}`);
    await A.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    assert.deepEqual(B.view.conflict, { version: noticed, ready: false });
    assert.equal(B.view.mergeAttempts, 0);
    assert.equal(B.view.merging, null);
    assert.equal(B.word(), 'Not saved');
    assert.deepEqual(
        B.blocks(),
        [
            ['p1', `A${MERGE_ATTEMPTS}`],
            ['p2', 'World B'],
        ],
        'the merged document is what the notice is about'
    );

    await B.poll();
    assert.deepEqual(B.view.conflict, { version: noticed, ready: true });
    B.host().keepMine();
    await B.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', `A${MERGE_ATTEMPTS}`],
        ['p2', 'World B'],
    ]);
    assert.deepEqual(server.history, [{ version: noticed, text: `A${last}` }]);
    assert.equal(B.view.conflict, null);
});

test('a merge fetch that never answers degrades to the notice after fifteen seconds; the owed save goes out after the choice', async () => {
    const { server, clock, A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await B.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    assert.deepEqual(B.view.merging, { version: 3, since: clock.now });

    // The fetch is answered by something in front of the application, again and again.
    await B.pollAnswers(500, null);
    clock.now += 5_000;
    await B.fireTimer();
    await B.pollAnswers(500, null);
    assert.notEqual(B.view.merging, null, 'inside fifteen seconds the fetch is waited for');
    assert.equal(B.view.conflict, null);
    assert.equal(B.sent.length, 1, 'nothing is sent on a base known to be stale');

    clock.now += MERGE_FETCH_EXPIRY_MS;
    await B.fireTimer();
    await B.pollAnswers(500, null);
    assert.equal(B.view.merging, null);
    assert.deepEqual(B.view.conflict, { version: 3, ready: false });
    assert.equal(B.word(), 'Not saved');
    assert.equal(B.polls.length, 1, 'the notice asked for the document');

    // The application answers again: the notice gets its document, and nothing is merged behind the writer's back.
    await B.poll();
    assert.deepEqual(B.view.conflict, { version: 3, ready: true });
    assert.deepEqual(B.blocks(), [
        ['p1', 'start'],
        ['p2', 'World B'],
    ]);

    B.host().keepMine();
    await B.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'start'],
        ['p2', 'World B'],
    ]);
    assert.deepEqual(server.history, [{ version: 3, text: 'Hello A' }]);
    assert.equal(B.view.conflict, null);
});

test('while the fetch is out the debounce does not send on the stale base; the text is sent once, merged', async () => {
    const { server, A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await B.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    assert.notEqual(B.view.merging, null);

    // B keeps typing; the debounce fires while the fetch is out.
    B.typeIn('p2', 'World B and more');
    await B.debounce();
    assert.equal(B.sent.length, 1, 'nothing is sent on a base known to be stale');
    assert.equal(B.view.resave, true);

    await B.poll();
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A'],
        ['p2', 'World B and more'],
    ]);
    assert.equal(B.sent.length, 2);
    assert.equal(B.sent[1].base, 3);

    await B.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'Hello A'],
        ['p2', 'World B and more'],
    ]);
    await B.cycle();
    assert.equal(B.sent.length, 2, 'once');
});

test('a merge that is impossible raises the amended notice; Keep mine and Load theirs work as before', async () => {
    const { B } = await conflicted();

    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.mergeCalls, 1, 'the merge was tried once');
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.merging, null);
    assert.equal(B.view.mergeAttempts, 0);

    await B.host().loadTheirs();
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
});

test('after Keep mine no merge is attempted until a save is accepted: there is no base for the moved-up version', async () => {
    const { server, clock, B } = await conflicted();
    B.host().keepMine();
    B.saveNeverAnswers();
    // A style change by this page clears the notice (version 3) while the Keep mine save is still waited for.
    B.host().adoptVersion(server.replace(server.json));
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.confirmed, null);
    assert.equal(B.view.known.version, 1, 'the last document this tab held is version 1; the base has moved past it');

    clock.now += SAVE_EXPIRY_MS;
    B.mergeFails = false;
    // Somebody else saves (version 4). B holds B1, unsaved.
    server.replace(docOf(para('p1', 'A1'), para('p2', 'Somebody else')));
    // The poll's tick gives the Keep mine save up and sends B1 again FIRST
    // (resendIfOwed() runs from onMembers, before the document is
    // delivered), so the engine holds version 4 meanwhile. The resend is
    // refused against version 4, and the merge it would start has no base
    // for the moved-up version: the notice, with the document in hand.
    await B.cycle();
    assert.equal(B.engine.pending.version, 4);
    await B.land();

    assert.equal(B.mergeCalls, 1, 'the merge from conflicted() only: none here');
    assert.deepEqual(B.view.conflict, { version: 4, ready: true });
    assert.equal(B.text(), 'B1');
});

test('while the notice stands a newer version updates the notice and is not merged behind the writer back', async () => {
    const { A, B } = await conflicted();
    B.mergeFails = false;
    A.type('A2');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.deepEqual(B.view.conflict, { version: 3, ready: true });
    assert.equal(B.text(), 'B1');
    assert.equal(B.mergeCalls, 1);
});

// ───────────────────────────────────────────────────────── the echo of a lost answer

test('a save whose answer is lost is recognised as this tab own when its resend is refused: later typing is kept, nothing is offered back, no notice', async () => {
    const { server, clock, A } = await pair();
    A.type('X');
    await A.debounce();
    A.saveArrives(); // stored as version 2
    A.saveNeverAnswers();
    A.type('XY');
    await A.debounce();
    assert.equal(A.sent.length, 1, 'owed: the first save is still waited for');

    // Fifteen seconds later, with the polls blocked, the tab gives the save up and sends XY on the old base.
    clock.now += SAVE_EXPIRY_MS;
    await A.fireTimer();
    await A.pollAnswers(503, null);
    assert.equal(A.view.confirmed, null);
    assert.equal(A.view.unanswered, JSON.stringify(doc('X')), 'the lost save is remembered as the echo');
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['XY', 1]);

    // Refused against the tab's own stored save: fetched and merged, with the echo as the base of p1.
    await A.land();
    assert.deepEqual(A.view.merging, { version: 2, since: clock.now });
    await A.poll();
    assert.equal(A.text(), 'XY');
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.lostTexts(), []);
    assert.deepEqual([A.sent[2].text, A.sent[2].base], ['XY', 2]);
    assert.equal(A.view.unanswered, null, 'confirmed again');

    await A.land();
    assert.equal(server.text(), 'XY');
    assert.equal(server.version, 3);
    assert.equal(A.word(), 'Saved');
});

test('a save whose answer is lost is recognised when the poll brings it first: no resend at all', async () => {
    const { server, clock, A } = await pair();
    A.type('X');
    await A.debounce();
    A.saveArrives();
    A.saveNeverAnswers();
    A.type('XY');
    clock.now += SAVE_EXPIRY_MS;

    await A.cycle();
    assert.equal(A.text(), 'XY');
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.lostTexts(), []);
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['XY', 2]);
    await A.land();
    assert.equal(server.text(), 'XY');
});

test('a save whose request fails outright, and was stored all the same, is merged on return', async () => {
    const { server, A } = await pair();
    A.type('X');
    await A.debounce();
    A.saveArrives(); // stored as version 2
    A.saves.shift().reject(new Error('network'));
    await settle();
    assert.equal(A.view.confirmed, null);
    assert.equal(A.view.unanswered, JSON.stringify(doc('X')));
    assert.equal(A.word(), 'Not saved');

    A.type('XY');
    await A.debounce();
    assert.deepEqual([A.sent[1].text, A.sent[1].base], ['XY', 1]);
    await A.land(); // refused against version 2: fetch
    await A.poll(); // merged: p1 is this tab's own
    assert.equal(A.text(), 'XY');
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.lostTexts(), []);
    await A.land();
    assert.equal(server.text(), 'XY');
    assert.equal(server.version, 3);
});

test('somebody else edit of the same paragraph after the echo is a true D1: offered back, no notice', async () => {
    const { clock, A, B } = await pair();
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

    await A.cycle();
    assert.equal(A.text(), 'X by B');
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.lostTexts(), ['XY']);
    assert.equal(A.sent.length, 1);
});

// ───────────────────────────────────────────────────────── guards

test('a tab that undid everything while the fetch was out follows instead, and the merge is over', async () => {
    const { A, B } = await pair();
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await B.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    assert.notEqual(B.view.merging, null);

    B.type('start'); // the typing is taken out again
    await B.debounce(); // nothing new to send
    await B.poll();

    assert.equal(B.text(), 'A1');
    assert.equal(B.view.merging, null);
    assert.equal(B.view.mergeAttempts, 0);
    assert.equal(B.view.conflict, null);
    assert.equal(B.sent.length, 1);
    assert.deepEqual(B.lostTexts(), []);
});

test('an IME composition makes the tab busy; the engine delivers afterwards', async () => {
    const { A, B } = await pair();
    B.composing = true;
    A.type('A1');
    await A.debounce();
    await A.land();
    await B.cycle();

    assert.equal(B.host().syncState(), 'busy');
    assert.equal(B.text(), 'start');
    assert.equal(B.engine.pending.version, 2);

    B.composing = false;
    B.engine.retry();
    assert.equal(B.text(), 'A1');
});

test('adoptVersion after this page own restyle keeps known in step: the next remote version is merged', async () => {
    const { server, clock, A, B } = await pair();
    // A restyles (version 2: the same text) and its event moves A's base up.
    A.host().adoptVersion(server.replace(server.json));
    assert.equal(A.view.baseVersion, 2);
    assert.deepEqual(A.view.known, { version: 2, json: JSON.stringify(doc('start')) });
    assert.equal(A.drafts.get(BASE).baseVersion, 2);

    // A types; its save is lost, so confirmed is null and known is the base.
    A.type('A1');
    await A.debounce();
    A.saveNeverAnswers();
    await B.cycle(); // B follows version 2
    B.insertAfter('p1', 'p2', 'B wrote');
    await B.debounce();
    await B.land(); // version 3
    clock.now += SAVE_EXPIRY_MS;

    // The poll's tick sends A1 again first (resendIfOwed() runs from
    // onMembers, before the document is delivered); the resend is refused
    // against version 3 and the merge runs on the document the engine held.
    await A.cycle();
    await A.land();
    assert.deepEqual(A.blocks(), [
        ['p1', 'A1'],
        ['p2', 'B wrote'],
    ]);
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.lostTexts(), []);
});

test('fail-closed: a newer document over unsaved typing is not merged, and no base row is written', async () => {
    const w = world();
    const R = w.tab('R', { failClosed: true });
    await R.open();
    R.view.unsaved = true;

    R.host().mergeOrConflict({ version: 2, json: doc('A1'), outline: null, css: null });
    R.host().beginMerge(2);

    assert.equal(R.mergeCalls, 0);
    assert.equal(R.text(), 'start');
    assert.equal(R.view.merging, null);
    assert.equal(R.view.conflict, null);
    assert.deepEqual(R.view.lost, []);
    assert.deepEqual(R.draftCalls, [], 'no base row either');
    assert.equal(R.drafts.has(BASE), false);
});

test('each call reads and writes the merge fields of the state object it was built from', async () => {
    const { server, A, B } = await pair();
    A.type('A1');
    await A.debounce();
    await A.land();
    B.type('B1');

    // Alpine re-created the data object after a render: fresh fields, the same editor.
    const later = makeView(1);
    later.confirmed = B.view.confirmed;
    const viaLater = createSyncHost(later, B.env);
    viaLater.mergeOrConflict({ version: 2, json: server.json, outline: null, css: null });

    assert.equal(later.baseVersion, 2);
    assert.deepEqual(later.known, { version: 2, json: JSON.stringify(doc('A1')) });
    assert.deepEqual(later.lost.map((entry) => entry.excerpt), ['B1']);
    assert.equal(later.merging, null);
    assert.equal(B.view.baseVersion, 1, 'the first object knows nothing of it');
    assert.equal(B.view.known.version, 1);
    assert.deepEqual(B.view.lost, []);
    assert.equal(B.text(), 'A1', 'the editor is shared');
});

// ───────────────────────────────────────────────────────── what the dry run's random walk found

test('a refused save while the engine holds an OLDER document: that document is not merged behind the fetch, and the merged save goes out once, on the fetched base', async () => {
    const { server, A, B } = await twoParagraphs();
    B.typeIn('p2', 'World B');
    await B.debounce(); // B's save (base 2) is in the air
    A.typeIn('p1', 'A3');
    await A.debounce();
    await A.land(); // version 3
    await B.cycle(); // B's poll brings version 3; B is busy, so the engine holds it
    assert.equal(B.engine.pending.version, 3);
    A.typeIn('p1', 'A4');
    await A.debounce();
    await A.land(); // version 4

    B.saveArrives();
    await B.saveReturns(); // refused with version 4
    assert.equal(B.view.mergeAttempts, 1);
    assert.deepEqual(B.view.merging, { version: 4, since: B.env.now() });
    assert.equal(B.sent.length, 1, 'nothing is sent on a base known to be stale');
    assert.deepEqual(B.blocks(), [['p1', 'start'], ['p2', 'World B']], 'version 3 is not merged behind the fetch for version 4');
    assert.equal(B.engine.pending, null, 'the older document is dropped: the fetch replaces it');

    await B.poll(); // the fetch brings version 4
    assert.equal(B.view.merging, null);
    assert.deepEqual(B.blocks(), [['p1', 'A4'], ['p2', 'World B']]);
    assert.equal(B.sent.length, 2);
    assert.equal(B.sent[1].base, 4);
    await B.land();
    assert.equal(server.version, 5);
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.mergeAttempts, 0);
});

test('the late OK answer of a save given up on, arriving while the merge fetch is out, ends the merge: the text goes out and no notice follows', async () => {
    const { server, clock, A } = await pair();
    A.type('X');
    await A.debounce();
    A.saveArrives(); // stored as version 2
    const slow = A.saveNeverAnswers(); // the answer is slow, not gone
    A.type('XY');
    await A.debounce();
    clock.now += SAVE_EXPIRY_MS;
    await A.fireTimer();
    await A.pollAnswers(503, null);
    await A.land(); // the resend (base 1) is refused: version 2 is in the way, and the merge fetch is out
    assert.notEqual(A.view.merging, null);

    slow.resolve(slow.result); // the first save's answer finally comes: ok, version 2
    await settle();
    await A.poll(); // the fetch answers
    assert.equal(A.view.merging, null, 'the merge for version 2 is over: this tab is at version 2');
    await A.land();
    assert.equal(server.text(), 'XY', 'the text typed after the first save is stored');
    clock.now += MERGE_FETCH_EXPIRY_MS + 1000;
    if (A.timer) {
        await A.fireTimer();
    }
    if (A.polls.length) {
        await A.poll();
    }
    assert.equal(A.view.conflict, null, 'no notice: nothing was ever in conflict');
    assert.equal(A.view.unsaved, false);
});

test('Put it back, then a merge that leaves nothing of mine to save: no overwrite is owed any more, and the next unrelated save carries none', async () => {
    const { server, A, B } = await conflicted();
    await B.host().loadTheirs();
    B.host().putBack();
    assert.equal(B.view.overwriteOwed, true);
    B.mergeFails = false;
    A.type('A2');
    await A.debounce();
    await A.land();
    await B.cycle(); // D1: A2 stays, B1 is listed; nothing of mine survives
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.overwriteOwed, false, 'nothing is unsaved, so nothing is owed');

    B.insertAfter('p1', 'p9', 'B new');
    await B.debounce();
    assert.equal(B.sent.at(-1).overwrite, false, 'an unrelated paragraph replaces nothing');
    await B.land();
    assert.deepEqual(server.history, [], 'and no version is cut for it');
});

test('Keep mine refused by a still newer version: the notice is not ready on the older document the engine holds, and Load theirs shows the newest', async () => {
    const { server, A, B } = await conflicted();
    B.host().keepMine(); // base 2, the overwrite is owed, the save is in the air
    A.type('A2');
    await A.debounce();
    await A.land(); // version 3 lands before B's Keep mine save
    B.saveArrives();
    await B.saveReturns(); // refused: version 3 is in the way

    assert.equal(B.view.conflict.version, 3);
    assert.equal(B.view.conflict.ready, false, 'the version-2 document the engine held is not what the notice names');
    await B.poll(); // the refetch answers with the newest
    assert.equal(B.view.conflict.ready, true);

    await B.host().loadTheirs();
    assert.equal(B.text(), 'A2', 'Load theirs shows the version the notice names, not the older one');
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.setAsideBase, 3);
});

// ───────────────────────────────────────────────────────── fix round 1 (the review of the merge loop)

test('a refusal that lands during an IME composition, with the engine already holding the document, merges nothing until the composition ends; then one merge, one merged save, nothing lost', async () => {
    const { server, A, B } = await twoParagraphs();
    B.typeIn('p2', 'World B');
    await B.debounce(); // B's save (base 2) is in the air
    A.typeIn('p1', 'Hello A');
    await A.debounce();
    await A.land(); // version 3
    await B.cycle(); // B's poll brings version 3; B is busy, so the engine holds it
    assert.equal(B.engine.pending.version, 3);

    B.composing = true;
    const applied = B.applyCalls;
    B.saveArrives();
    await B.saveReturns(); // refused: version 3 is in the way, and the engine holds it

    assert.equal(B.applyCalls, applied, 'nothing is applied under a composition');
    assert.equal(B.mergeCalls, 0);
    assert.equal(B.sent.length, 1, 'nothing is sent on a base known to be stale');
    assert.equal(B.view.conflict, null);
    assert.deepEqual(B.view.merging, { version: 3, since: B.env.now() }, 'held: the merge waits for the composition');
    assert.equal(B.engine.pending.version, 3, 'the document stays with the engine');
    assert.deepEqual(B.blocks(), [
        ['p1', 'start'],
        ['p2', 'World B'],
    ]);

    // Polls during the composition leave everything as it is.
    await B.cycle();
    assert.equal(B.applyCalls, applied);
    assert.equal(B.sent.length, 1);

    B.composing = false;
    await B.cycle();
    assert.equal(B.applyCalls, applied + 1, 'exactly one apply');
    assert.equal(B.mergeCalls, 1, 'exactly one merge');
    assert.equal(B.view.merging, null);
    assert.equal(B.view.conflict, null);
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
    assert.equal(B.sent.length, 2, 'one merged save');
    assert.deepEqual([B.sent[1].base, B.sent[1].overwrite], [3, false]);
    assert.deepEqual(B.lostTexts(), []);

    await B.land();
    assert.equal(server.version, 4);
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
    assert.equal(B.word(), 'Saved');
});

test('a poll already in the air that answers with a document older than the one under merge is not merged: no save on the stale base, no attempt spent, and the newer document then merges', async () => {
    const { server, A, B } = await twoParagraphs();
    B.typeIn('p2', 'World B');
    await B.debounce(); // B's save (base 2) is in the air
    A.typeIn('p1', 'A3');
    await A.debounce();
    await A.land(); // version 3

    // B's poll goes out now, asked from version 2, and the server answers it
    // with version 3 (the answer is held back on the road).
    await B.fireTimer();
    assert.equal(B.polls.length, 1);
    const old = B.polls.shift();
    const answerForThree = server.sync(old.payload);
    assert.equal(answerForThree.body.version, 3);

    A.typeIn('p1', 'A4');
    await A.debounce();
    await A.land(); // version 4

    B.saveArrives();
    await B.saveReturns(); // refused with version 4: merging for 4; the refetch waits behind the poll in the air
    assert.deepEqual(B.view.merging, { version: 4, since: B.env.now() });
    assert.equal(B.view.mergeAttempts, 1);

    old.resolve(answerForThree); // the answer of the poll that was already in the air: version 3
    await settle();
    assert.equal(B.mergeCalls, 0, 'version 3 is older than the version under merge: not merged');
    assert.equal(B.sent.length, 1, 'no save on a base known to be stale');
    assert.equal(B.view.mergeAttempts, 1, 'no attempt spent');
    assert.deepEqual(B.view.merging, { version: 4, since: B.env.now() });
    assert.equal(B.view.conflict, null);
    assert.equal(B.engine.pending, null);

    await B.poll(); // the queued poll brings version 4
    assert.equal(B.view.merging, null);
    assert.equal(B.mergeCalls, 1);
    assert.deepEqual(B.blocks(), [
        ['p1', 'A4'],
        ['p2', 'World B'],
    ]);
    assert.equal(B.sent.length, 2);
    assert.equal(B.sent[1].base, 4);
    await B.land();
    assert.equal(server.version, 5);
    assert.equal(B.view.mergeAttempts, 0);
    assert.equal(B.view.conflict, null);
});

test('the base row is never overwritten with null: confirmed nulled while the draft check awaits, the row takes the known document of this version', async () => {
    const w = world();
    const A = w.tab('A');
    A.mount();
    A.drafts.set(BASE, { json: JSON.stringify(doc('an older row')), baseVersion: 1 });
    let release = null;
    const gate = new Promise((resolve) => {
        release = resolve;
    });
    const loadDraft = A.draftStore.loadDraft;
    A.draftStore.loadDraft = async (uuid) => {
        await gate;

        return loadDraft(uuid);
    };

    const restoring = A.host().restoreDraft();
    // A save failure or an expiry nulls confirmed while the draft check awaits.
    A.view.confirmed = null;
    release();
    await restoring;

    assert.deepEqual(A.drafts.get(BASE), { json: JSON.stringify(doc('start')), baseVersion: 1 });
});

test('the base row is not written at all when nothing is known of the version the base names: the row already stored stays', async () => {
    const w = world();
    const A = w.tab('A');
    A.mount();
    const row = { json: JSON.stringify(doc('an older row')), baseVersion: 1 };
    A.drafts.set(BASE, row);
    let release = null;
    const gate = new Promise((resolve) => {
        release = resolve;
    });
    const loadDraft = A.draftStore.loadDraft;
    A.draftStore.loadDraft = async (uuid) => {
        await gate;

        return loadDraft(uuid);
    };

    const restoring = A.host().restoreDraft();
    // Keep mine moved the base up past the document this tab held, and the save then failed.
    A.view.confirmed = null;
    A.view.baseVersion = 3;
    release();
    await restoring;

    assert.deepEqual(A.drafts.get(BASE), row);
    assert.equal(A.draftCalls.includes('saveBase'), false);
});

test('a merge that leaves nothing of mine to save ends the row of refusals: the full MERGE_ATTEMPTS refusals follow before the notice', async () => {
    const { server, A, B } = await twoParagraphs();
    // B and A type in the same paragraph; A saves first.
    B.typeIn('p1', 'B0');
    await B.debounce(); // B's save (base 2) is in the air
    A.typeIn('p1', 'A0');
    await A.debounce();
    await A.land(); // version 3
    B.saveArrives();
    await B.saveReturns(); // refused: the first of a row
    assert.equal(B.view.mergeAttempts, 1);
    await B.poll(); // D1: theirs stays, mine is listed, nothing of mine is left to save
    assert.equal(B.view.unsaved, false);
    assert.deepEqual(B.lostTexts(), ['B0']);
    assert.equal(B.view.mergeAttempts, 0, 'the row of refusals is over');

    // MERGE_ATTEMPTS refusals in a row now still merge; the next one is the notice.
    B.typeIn('p2', 'World B');
    await B.debounce();
    for (let round = 1; round <= MERGE_ATTEMPTS; round += 1) {
        A.typeIn('p1', `A${round}`);
        await A.debounce();
        await A.land();
        B.saveArrives();
        await B.saveReturns();
        assert.equal(B.view.mergeAttempts, round);
        assert.equal(B.view.conflict, null, `refusal ${round} still merges`);
        await B.poll();
        assert.equal(B.view.merging, null);
    }

    A.typeIn('p1', 'Last');
    await A.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    assert.deepEqual(B.view.conflict, { version: server.version, ready: false });
    assert.equal(B.view.merging, null);
});

/** A and B both type in p1; A's save lands; B's poll then brings it to a tab with unsaved typing. */
async function pollBringsTheirsOverTyping() {
    const w = await pair();
    const { A, B } = w;
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await A.land();

    return w;
}

test('handle.merge throwing ends in the notice: merging cleared, nothing applied, nothing sent', async () => {
    const { B } = await pollBringsTheirsOverTyping();
    B.handle.merge = () => {
        throw new Error('the merge broke');
    };
    await B.cycle();

    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.view.merging, null);
    assert.equal(B.applyCalls, 0);
    assert.equal(B.text(), 'B1');
    assert.equal(B.sent.length, 0);
    assert.deepEqual(B.view.lost, []);
});

test('handle.applyRemote throwing during a merge ends in the notice: merging cleared, nothing applied, nothing sent', async () => {
    const { A, B } = await pollBringsTheirsOverTyping();
    B.handle.applyRemote = () => {
        throw new Error('a plugin failed inside the dispatch');
    };
    await B.cycle();

    assert.equal(B.mergeCalls, 1);
    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.view.merging, null);
    assert.equal(B.text(), 'B1');
    assert.equal(B.sent.length, 0);
    assert.deepEqual(B.view.lost, []);
    assert.equal(A.view.conflict, null);
});

test('handle.applyRemote returning false during a merge ends in the notice: merging cleared, nothing applied, nothing sent', async () => {
    const { B } = await pollBringsTheirsOverTyping();
    B.handle.applyRemote = () => false;
    await B.cycle();

    assert.equal(B.mergeCalls, 1);
    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.view.merging, null);
    assert.equal(B.text(), 'B1');
    assert.equal(B.sent.length, 0);
    assert.deepEqual(B.view.lost, []);
});

// ───────────────────────────────────────────────────────── the lost row: Put it back, Put them all back, Dismiss all

/** B lost p1 to A (D1): one entry in B's lost row. */
async function lostOne() {
    const w = await pair();
    const { A, B } = w;
    A.type('Hello A');
    B.type('Hello B');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(B.lostTexts(), ['Hello B']);

    return w;
}

test('Put it back after a D1: my version goes in below theirs with a fresh id, the autosave saves it without an overwrite, and the park is rewritten', async () => {
    const { server, A, B } = await lostOne();

    assert.equal(B.host().putBackLost(0), true);
    assert.deepEqual(B.insertCalls, [{ json: para('p1', 'Hello B'), anchors: ['p1'], keepId: false }]);
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A'],
        ['fresh1', 'Hello B'],
    ]);
    assert.deepEqual(B.view.lost, []);
    assert.deepEqual(B.lostPark().entries, [], 'the park holds the rest: nothing');
    assert.equal(B.view.overwriteOwed, false, 'it replaces nothing');
    assert.equal(B.view.unsaved, true);
    assert.equal(B.view.syncNotice, '');

    await B.debounce();
    assert.deepEqual([B.sent.at(-1).base, B.sent.at(-1).overwrite], [2, false]);
    await B.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'Hello A'],
        ['fresh1', 'Hello B'],
    ]);
    assert.deepEqual(server.history, []);

    await A.cycle();
    assert.deepEqual(A.blocks(), blocksOf(server.json));
    assert.equal(A.view.conflict, null);
});

/** B lost two items to A (two D1 rounds): two entries in B's lost row. */
async function lostTwo() {
    const w = await pair();
    const { A, B } = w;
    A.type('Hello A1');
    B.type('Hello B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.type('Hello A1 B2');
    A.type('Hello A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(B.lostTexts(), ['Hello B1', 'Hello A1 B2']);

    return w;
}

test('Put it back hands the editor focus only when the press came from the keyboard: a tap leaves the on-screen keyboard down', async () => {
    const { B } = await lostOne();

    // The Blade click handler passes `$event.detail === 0`; no argument at all is a tap too.
    assert.equal(B.host().putBackLost(0), true);
    assert.deepEqual(B.insertFocus, [false], 'no options: the editor is not asked to focus');
});

test('Put it back from the keyboard asks for focus, a tap does not, and the block is in either way', async () => {
    const keyboard = await lostOne();
    assert.equal(keyboard.B.host().putBackLost(0, { focus: true }), true);
    assert.deepEqual(keyboard.B.insertFocus, [true]);

    const tap = await lostOne();
    assert.equal(tap.B.host().putBackLost(0, { focus: false }), true);
    assert.deepEqual(tap.B.insertFocus, [false]);
    assert.deepEqual(tap.B.blocks(), keyboard.B.blocks(), 'the same document either way');

    // Anything but the real boolean true is a tap.
    const odd = await lostOne();
    assert.equal(odd.B.host().putBackLost(0, { focus: 'yes' }), true);
    assert.deepEqual(odd.B.insertFocus, [false]);
});

test('Put them all back passes the press kind to every block it puts back', async () => {
    const world = await lostTwo();
    world.B.host().putBackAllLost({ focus: false });
    assert.deepEqual(world.B.insertFocus, [false, false]);

    const keyed = await lostTwo();
    keyed.B.host().putBackAllLost({ focus: true });
    assert.deepEqual(keyed.B.insertFocus, [true, true]);

    const bare = await lostTwo();
    bare.B.host().putBackAllLost();
    assert.deepEqual(bare.B.insertFocus, [false, false]);
});

test('Put it back after a D2 restores the paragraph where it was, with its original id', async () => {
    const { server, A, B } = await twoParagraphs();
    A.insertAfter('p2', 'p3', 'Third');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.typeIn('p2', 'World B');
    A.deleteBlock('p2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(B.blocks(), [
        ['p1', 'start'],
        ['p3', 'Third'],
    ]);

    assert.equal(B.host().putBackLost(0), true);
    assert.deepEqual(B.insertCalls.at(-1), { json: para('p2', 'World B'), anchors: ['p1'], keepId: true });
    assert.deepEqual(B.blocks(), [
        ['p1', 'start'],
        ['p2', 'World B'],
        ['p3', 'Third'],
    ]);

    await B.debounce();
    await B.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'start'],
        ['p2', 'World B'],
        ['p3', 'Third'],
    ]);
    assert.deepEqual(server.history, [], 'an ordinary save: nothing is replaced');
});

test('offered entries are appended, an exact duplicate is skipped, and the park holds the whole list', async () => {
    const { B } = await pair();
    const loss = { id: 'p1', type: 'paragraph', reason: 'both', json: para('p1', 'Hello B'), anchors: ['p1'], text: 'Hello B' };

    B.host().offerLost([loss], 'merge', 2);
    B.host().offerLost([loss, { ...loss, json: para('p1', 'Hello B2'), text: 'Hello B2' }], 'merge', 3);

    assert.deepEqual(B.lostTexts(), ['Hello B', 'Hello B2']);
    assert.deepEqual(B.view.lost.map((entry) => entry.key), ['p1:2:0', 'p1:3:1']);
    assert.deepEqual(B.lostPark().entries.map((entry) => entry.excerpt), ['Hello B', 'Hello B2']);
    assert.equal(B.draftCalls.filter((call) => call === 'parkLost').length, 2);
});

test('Dismiss all asks first, empties the row, keeps the park and marks it dismissed; a no changes nothing; an empty row asks nothing', async () => {
    const { B } = await lostOne();

    B.confirmAnswer = false;
    await B.host().dismissLost();
    assert.deepEqual(B.questions, [DISMISS_LOST_QUESTION]);
    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.equal(B.lostPark().dismissedAt, null);

    B.confirmAnswer = true;
    await B.host().dismissLost();
    assert.deepEqual(B.view.lost, []);
    assert.deepEqual(B.lostPark().entries, []);
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B'], 'the park keeps the dismissed text for its 7 days');
    assert.equal(B.lostPark().dismissedAt, B.env.now());
    assert.equal(B.text(), 'Hello A');
    assert.equal(B.sent.length, 0);

    await B.host().dismissLost();
    assert.equal(B.questions.length, 2, 'nothing to dismiss: no question');
});

test('Dismiss all writes the park first, with the list on the page, and only then stamps it dismissed', async () => {
    const { B } = await lostOne();
    B.draftCalls.length = 0;

    await B.host().dismissLost();

    assert.deepEqual(B.draftCalls, ['parkLost', 'markLostDismissed']);
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B']);
});

test('Dismiss all whose park write fails does not claim the 7 days: the entries stay on the page and the writer is told', async () => {
    const { B } = await lostOne();
    B.draftCalls.length = 0;
    B.parkLostFails = true;

    await B.host().dismissLost();

    assert.deepEqual(B.questions, [DISMISS_LOST_QUESTION], 'asked once, as always');
    assert.deepEqual(B.lostTexts(), ['Hello B'], 'still on the page');
    assert.deepEqual(B.draftCalls, ['parkLost'], 'markLostDismissed never ran');
    assert.equal(B.view.syncNotice, DISMISS_LOST_FAILED_NOTICE);
});

test('after a failed copy the next Dismiss all asks whether to dismiss anyway; yes drops the text without a copy, no changes nothing', async () => {
    const { B } = await lostOne();
    B.parkLostFails = true;
    await B.host().dismissLost(); // fails: the notice stands
    B.questions.length = 0;
    B.draftCalls.length = 0;

    // No: nothing changes, and the question can be asked again.
    B.confirmAnswer = false;
    await B.host().dismissLost();
    assert.deepEqual(B.questions, [DISMISS_LOST_UNKEPT_QUESTION]);
    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.equal(B.view.syncNotice, DISMISS_LOST_FAILED_NOTICE, 'the notice still says what is true');
    assert.deepEqual(B.draftCalls, [], 'nothing was written');

    // Yes: dismissed without parking and without stamping the park.
    B.confirmAnswer = true;
    await B.host().dismissLost();
    assert.deepEqual(B.questions, [DISMISS_LOST_UNKEPT_QUESTION, DISMISS_LOST_UNKEPT_QUESTION]);
    assert.deepEqual(B.view.lost, []);
    assert.equal(B.view.syncNotice, '', 'the notice is not left behind');
    assert.deepEqual(B.draftCalls, ['markLostDismissed'], 'no park write; an earlier stored list is marked dismissed, best effort');
});

test('a dismissal without a copy also marks an earlier stored list dismissed, so it does not come back after a reload', async () => {
    const { server, clock, B } = await lostOne(); // offerLost stored the list in the park
    assert.deepEqual(B.lostPark().entries.map((entry) => entry.excerpt), ['Hello B']);
    B.parkLostFails = true;
    await B.host().dismissLost(); // the copy cannot be rewritten
    await B.host().dismissLost(); // dismissed anyway

    assert.deepEqual(B.view.lost, []);
    assert.deepEqual(B.lostPark().entries, []);
    assert.equal(B.lostPark().dismissedAt, B.env.now());
    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.deepEqual(again.view.lost, [], 'not offered again after a reload');
});

test('a dismissal without a copy survives a store that throws on the stamp', async () => {
    const { B } = await lostOne();
    B.parkLostFails = true;
    await B.host().dismissLost();
    B.draftStore.markLostDismissed = async () => {
        throw new Error('the store refused the stamp');
    };

    await B.host().dismissLost();

    assert.deepEqual(B.view.lost, []);
});

test('the unkept Dismiss all question says the text leaves the page and may not be recoverable', () => {
    assert.equal(
        DISMISS_LOST_UNKEPT_QUESTION,
        'This browser could not keep a copy of this text. Dismiss it anyway? It is removed from this page and may not be recoverable.'
    );
});

test('a browser with no draft store cannot keep a copy either: the same two steps', async () => {
    const w = world();
    const R = w.tab('R', { draftStore: false });
    await R.open();
    R.view.lost = [
        { key: 'p1:2:0', id: 'p1', type: 'paragraph', reason: 'both', source: 'merge', json: para('p1', 'x'), anchors: ['p1'], version: 2, sentence: 'S', excerpt: 'x' },
    ];

    await R.host().dismissLost();
    assert.equal(R.view.lost.length, 1);
    assert.equal(R.view.syncNotice, DISMISS_LOST_FAILED_NOTICE);

    await R.host().dismissLost();
    assert.deepEqual(R.questions, [DISMISS_LOST_QUESTION, DISMISS_LOST_UNKEPT_QUESTION]);
    assert.deepEqual(R.view.lost, []);
});

test('Dismiss all pressed again while the first write is in flight leaves no false "list changed" notice', async () => {
    const { B } = await lostOne();

    const first = B.host().dismissLost();
    const second = B.host().dismissLost();
    await Promise.all([first, second]);

    assert.deepEqual(B.view.lost, []);
    assert.equal(B.view.syncNotice, '');
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B'], 'dismissed once, not twice');
});

test('a Put it back notice goes with the next Put it back that works', async () => {
    const { B } = await lostOne();
    B.insertBlockFails = true;
    assert.equal(B.host().putBackLost(0), false);
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');

    B.insertBlockFails = false;
    assert.equal(B.host().putBackLost(0), true);
    assert.equal(B.view.syncNotice, '', 'the notice does not outlive the success (it hides the Saved word until a reload)');
});

test('the failed-copy notice goes when the list empties, however it empties', async () => {
    const { B } = await lostOne();
    B.parkLostFails = true;
    await B.host().dismissLost();
    assert.equal(B.view.syncNotice, DISMISS_LOST_FAILED_NOTICE);

    B.host().putBackAllLost();

    assert.deepEqual(B.view.lost, []);
    assert.equal(B.view.syncNotice, '');
});

test('the notice of a set-aside text that could not be put back never replaces a stop reason', async () => {
    const stop = 'You no longer have access to this document.';
    const { B } = await conflicted();
    await B.host().loadTheirs(); // B1 is set aside
    B.view.syncNotice = stop;
    B.setContentThrows = true;

    B.host().putBack();

    assert.equal(B.view.syncNotice, stop);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'the offer stands');

    // Without a stop reason the writer is told.
    B.view.syncNotice = '';
    B.host().putBack();
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');
});

test('lost-row notices never replace a stop reason, and Dismiss all still reaches the unkept question behind one', async () => {
    const stop = 'You have been signed out. Reload the page to keep editing.';
    const { B } = await lostOne();
    B.view.syncNotice = stop;

    B.insertBlockFails = true;
    assert.equal(B.host().putBackLost(0), false);
    assert.equal(B.view.syncNotice, stop);
    B.insertBlockFails = false;

    // The copy cannot be written and the failed-copy notice cannot be shown over the stop reason (so it cannot be
    // the memory of the failure): the unkept question is asked in the same press. No changes nothing.
    B.parkLostFails = true;
    const answers = [true, false]; // the ordinary question, then the unkept one
    B.env.confirm = (question) => {
        B.questions.push(question);

        return answers.shift() ?? true;
    };
    await B.host().dismissLost();
    assert.deepEqual(B.questions, [DISMISS_LOST_QUESTION, DISMISS_LOST_UNKEPT_QUESTION]);
    assert.equal(B.view.syncNotice, stop, 'the stop reason stands');
    assert.deepEqual(B.lostTexts(), ['Hello B']);

    // Yes: dismissed without a copy, the stop reason untouched.
    B.questions.length = 0;
    answers.push(true, true);
    await B.host().dismissLost();
    assert.deepEqual(B.questions, [DISMISS_LOST_QUESTION, DISMISS_LOST_UNKEPT_QUESTION]);
    assert.deepEqual(B.view.lost, []);
    assert.equal(B.view.syncNotice, stop);
});

test('a successful dismissal leaves a stop reason standing', async () => {
    const stop = 'This document no longer exists.';
    const { B } = await lostOne();
    B.view.syncNotice = stop;

    await B.host().dismissLost();

    assert.deepEqual(B.view.lost, []);
    assert.equal(B.view.syncNotice, stop);
});

test('a failed-copy notice from before the list changed does not make Dismiss skip the write for entries never tried', async () => {
    const { B } = await lostOne();
    B.parkLostFails = true;
    await B.host().dismissLost();
    assert.equal(B.view.syncNotice, DISMISS_LOST_FAILED_NOTICE);

    // A new loss arrives, and the store works again.
    B.parkLostFails = false;
    B.host().offerLost(
        [{ id: 'p7', type: 'paragraph', reason: 'both', json: para('p7', 'Arrived later'), anchors: ['p1'], text: 'Arrived later' }],
        'merge',
        3
    );
    assert.equal(B.view.syncNotice, '', 'the list changed: the old failure says nothing about it');
    B.questions.length = 0;
    B.draftCalls.length = 0;

    await B.host().dismissLost();

    assert.deepEqual(B.questions, [DISMISS_LOST_QUESTION]);
    assert.deepEqual(B.draftCalls, ['parkLost', 'markLostDismissed']);
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B', 'Arrived later']);
});

test('Dismiss all whose park write THROWS is a failed write too', async () => {
    const { B } = await lostOne();
    B.parkLostThrows = true;

    await B.host().dismissLost();

    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.equal(B.view.syncNotice, DISMISS_LOST_FAILED_NOTICE);
    assert.equal(B.draftCalls.includes('markLostDismissed'), false);
});

test('Dismiss all while the list changes under it dismisses nothing: what arrived meanwhile was never parked', async () => {
    const { B } = await lostOne();
    const real = B.draftStore.parkLost;
    let arrived = false;
    B.draftStore.parkLost = async (...args) => {
        const outcome = await real(...args);
        if (arrived) {
            return outcome;
        }
        arrived = true;
        // A loss arrives while the park write is in flight.
        B.host().offerLost(
            [{ id: 'p7', type: 'paragraph', reason: 'both', json: para('p7', 'Arrived meanwhile'), anchors: ['p1'], text: 'Arrived meanwhile' }],
            'merge',
            3
        );

        return outcome;
    };

    await B.host().dismissLost();

    assert.deepEqual(B.lostTexts(), ['Hello B', 'Arrived meanwhile'], 'both stay on the page');
    assert.equal(B.view.syncNotice, DISMISS_LOST_CHANGED_NOTICE);
    assert.equal(B.draftCalls.includes('markLostDismissed'), false);
});

test('the Dismiss all question says the text is in no version, goes from the page and stays in the browser for 7 days', () => {
    assert.equal(
        DISMISS_LOST_QUESTION,
        'Dismiss this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.'
    );
});

test('a reload during the lost row re-offers its entries prefixed From your last visit, and Put it back still works; a dismissed park is not re-offered', async () => {
    const { server, clock, B } = await lostOne();

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.deepEqual(
        again.view.lost.map((entry) => [entry.source, entry.reason, entry.excerpt, entry.sentence]),
        [
            [
                'parked',
                'both',
                'Hello B',
                'From your last visit: This paragraph was changed elsewhere at the same moment. The other version is on the page; this is yours.',
            ],
        ]
    );
    assert.deepEqual(again.draftCalls, ['adoptLost', 'loadDraft', 'saveBase'], 'the park is taken before the draft is read');

    assert.equal(again.host().putBackLost(0), true);
    assert.deepEqual(again.blocks(), [
        ['p1', 'Hello A'],
        ['fresh1', 'Hello B'],
    ]);
    assert.deepEqual(again.view.lost, []);
    assert.deepEqual(again.lostPark().entries, []);

    // Parked a second time (as `parked`) and reopened: the prefix is not doubled.
    const parkedAgain = makeTab({ server, clock }, 'B3', { drafts: new Map() });
    parkedAgain.drafts.set(LOST, {
        entries: [
            {
                key: 'p1:2:0',
                id: 'p1',
                type: 'paragraph',
                reason: 'both',
                source: 'parked',
                json: para('p1', 'Hello B'),
                anchors: ['p1'],
                version: 2,
                sentence: 'From your last visit: S',
                excerpt: 'Hello B',
            },
        ],
        baseVersion: 2,
        parkedAt: clock.now,
        dismissedAt: null,
    });
    await parkedAgain.open();
    assert.deepEqual(parkedAgain.view.lost.map((entry) => entry.sentence), ['From your last visit: S']);

    // Dismissed: a later page does not offer it.
    const other = await lostOne();
    await other.B.host().dismissLost();
    const after = makeTab({ server: other.server, clock: other.clock }, 'B4', { drafts: other.B.drafts });
    await after.open();
    assert.deepEqual(after.view.lost, []);
});

test('a loss after Dismiss all keeps the dismissed copy in the park for its 7 days, and a reopen offers only the live entries', async () => {
    const { server, clock, A, B } = await pair();
    A.type('Hello A1');
    B.type('Hello B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    await B.host().dismissLost();
    assert.deepEqual(B.lostPark().entries, []);
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B1']);

    // The next loss rewrites the park: the dismissed copy is carried over, not dropped.
    B.type('Hello A1 B2');
    A.type('Hello A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(B.lostTexts(), ['Hello A1 B2']);
    assert.deepEqual(B.lostPark().entries.map((entry) => entry.excerpt), ['Hello A1 B2']);
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B1'], 'the dismissed copy survives the rewrite');
    assert.equal(B.lostPark().dismissedAt, null, 'the new loss is offered again on the next visit');

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.deepEqual(again.lostTexts(), ['Hello A1 B2'], 'the dismissed entry is not re-offered');
});

test('Put them all back puts every item back oldest first, each below the winner, stops at the first failure and says so', async () => {
    const { A, B } = await pair();
    A.type('Hello A1');
    B.type('Hello B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    B.type('Hello A1 B2');
    A.type('Hello A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.deepEqual(B.lostTexts(), ['Hello B1', 'Hello A1 B2']);

    // The first goes in; the second cannot.
    const real = B.handle.insertBlock;
    let calls = 0;
    B.handle.insertBlock = (json, options) => {
        calls += 1;

        return calls === 2 ? false : real(json, options);
    };
    B.host().putBackAllLost();
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A2'],
        ['fresh1', 'Hello B1'],
    ]);
    assert.deepEqual(B.lostTexts(), ['Hello A1 B2'], 'the one that could not go in stays');
    assert.deepEqual(
        B.lostPark().entries.map((entry) => entry.excerpt),
        ['Hello A1 B2'],
        'the park was rewritten after the first went in: it holds the one left'
    );
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');

    // Working again: the rest goes in, directly below the winner.
    B.handle.insertBlock = real;
    B.host().putBackAllLost();
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A2'],
        ['fresh2', 'Hello A1 B2'],
        ['fresh1', 'Hello B1'],
    ]);
    assert.deepEqual(B.view.lost, []);
    assert.deepEqual(B.lostPark().entries, []);
});

test('a single Put it back that fails keeps the entry on the page and in the park, says so and returns false', async () => {
    const { B } = await lostOne();
    B.handle.insertBlock = () => false;

    assert.equal(B.host().putBackLost(0), false);
    assert.deepEqual(B.lostTexts(), ['Hello B'], 'the entry stays');
    assert.deepEqual(
        B.lostPark().entries.map((entry) => entry.excerpt),
        ['Hello B'],
        'the park still holds it'
    );
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');
    assert.equal(B.text(), 'Hello A');
    assert.equal(B.view.unsaved, false, 'nothing was written, so nothing is owed');
});

test('a Put it back whose insertBlock THROWS is a failure too: the entry stays, the notice is set, false comes back', async () => {
    const { B } = await lostOne();
    B.handle.insertBlock = () => {
        throw new Error('the transaction was refused');
    };

    assert.equal(B.host().putBackLost(0), false);
    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.deepEqual(B.lostPark().entries.map((entry) => entry.excerpt), ['Hello B']);
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');

    // Put them all back stops at the throw as well and leaves the entry listed.
    B.view.syncNotice = '';
    B.host().putBackAllLost();
    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.equal(B.view.syncNotice, 'Your text could not be put back.');
});

const lossOf = (id, text) => ({ id, type: 'paragraph', reason: 'both', json: para(id, text), anchors: ['p1'], text });

test('two tabs of one browser each park their own lost row: neither erases the other, and a reload offers both', async () => {
    const w = world();
    const shared = new Map();
    const X = w.tab('X', { drafts: shared });
    const Y = w.tab('Y', { drafts: shared });
    await X.open();
    await Y.open();

    X.host().offerLost([lossOf('p7', 'from tab X')], 'merge', 2);
    Y.host().offerLost([lossOf('p8', 'from tab Y')], 'merge', 2);
    X.host().offerLost([lossOf('p9', 'more from X')], 'merge', 3); // X rewrites its list: Y's row is not touched

    assert.deepEqual([...shared.keys()].filter((key) => key.startsWith('lost-')).sort(), [`lost-${UUID}@X`, `lost-${UUID}@Y`]);
    assert.deepEqual(shared.get(`lost-${UUID}@Y`).entries.map((entry) => entry.excerpt), ['from tab Y']);
    assert.deepEqual(shared.get(`lost-${UUID}@X`).entries.map((entry) => entry.excerpt), ['from tab X', 'more from X']);

    // Both pages reload: the new page is offered everything, from both tabs.
    const Z = w.tab('Z', { drafts: shared });
    await Z.open();
    assert.deepEqual(Z.lostTexts().sort(), ['from tab X', 'from tab Y', 'more from X']);
    assert.deepEqual([...shared.keys()].filter((key) => key.startsWith('lost-')), [`lost-${UUID}@Z`], 'every entry has one owner now');

    // Dismissing marks the owner's row; another reload offers nothing, and nothing was erased.
    await Z.host().dismissLost();
    const V = w.tab('V', { drafts: shared });
    await V.open();
    assert.deepEqual(V.view.lost, []);
    assert.deepEqual(shared.get(`lost-${UUID}@V`).dismissed.map((entry) => entry.excerpt).sort(), ['from tab X', 'from tab Y', 'more from X'], 'the dismissed copies travel with the row');
});

test('after a reload one tab\'s Put it back and the other tab\'s entries do not touch each other\'s rows', async () => {
    const w = world();
    const shared = new Map();
    const X = w.tab('X', { drafts: shared });
    await X.open();
    X.host().offerLost([lossOf('p7', 'from tab X')], 'merge', 2);
    const Y = w.tab('Y', { drafts: shared });
    await Y.open(); // adopts X's row
    X.host().offerLost([lossOf('p9', 'X again')], 'merge', 3); // X, still open, writes its (recreated) row

    assert.deepEqual(Y.lostTexts(), ['from tab X']);
    assert.deepEqual(shared.get(`lost-${UUID}@Y`).entries.map((entry) => entry.excerpt), ['from tab X']);
    assert.ok(shared.get(`lost-${UUID}@X`).entries.map((entry) => entry.excerpt).includes('X again'));

    assert.equal(Y.host().putBackLost(0), true);
    assert.deepEqual(shared.get(`lost-${UUID}@Y`).entries, []);
    assert.ok(shared.get(`lost-${UUID}@X`).entries.map((entry) => entry.excerpt).includes('X again'), 'X\'s row is not rewritten by Y');
});

// ───────────────────────────────────────────────────────── the queue of set-aside texts: kept parks are offered, one at a time

/** Parks as an earlier visit left them: the newest under the plain key, earlier ones under kept keys. */
function seedParks(drafts, parks) {
    parks
        .slice()
        .sort((a, b) => b.parkedAt - a.parkedAt)
        .forEach((park, index) => {
            const key = index === 0 ? STALE : `${STALE}@${park.parkedAt}`;
            drafts.set(key, { json: JSON.stringify(doc(park.text)), baseVersion: 1, parkedAt: park.parkedAt, offerable: park.offerable ?? true, ...(park.resolved ? { resolved: true } : {}) });
        });
}

test('texts parked and offered earlier come back one after another: the newest first, then each older one once the standing text is dealt with', async () => {
    const w = world();
    const drafts = new Map();
    seedParks(drafts, [
        { text: 'park C', parkedAt: 3000 },
        { text: 'park B', parkedAt: 2000 },
        { text: 'park A', parkedAt: 1000 },
    ]);
    const tab = w.tab('T', { drafts });
    await tab.open();
    await settle();

    assert.equal(tab.view.setAside, JSON.stringify(doc('park C')), 'the newest park is on offer when nothing else is');
    assert.equal(tab.view.setAsideFrom, 'draft');

    tab.host().putBack(); // asks, answered yes
    await settle();
    assert.equal(tab.text(), 'park C');
    assert.equal(tab.view.setAside, JSON.stringify(doc('park B')), 'the next park is offered');

    tab.host().discardSetAside();
    await settle();
    assert.equal(tab.view.setAside, JSON.stringify(doc('park A')));
    tab.host().discardSetAside();
    await settle();
    assert.equal(tab.view.setAside, null, 'nothing is left');
    assert.deepEqual([...drafts.values()].filter((row) => row.offerable && !row.resolved), [], 'every park is dealt with and still kept for its 7 days');
    assert.equal([...drafts.keys()].filter((key) => key.startsWith('stale-')).length, 3);
});

test('a reload in the middle of the queue offers the next text, not the one that was dealt with', async () => {
    const w = world();
    const drafts = new Map();
    seedParks(drafts, [
        { text: 'park C', parkedAt: 3000 },
        { text: 'park B', parkedAt: 2000 },
    ]);
    const tab = w.tab('T', { drafts });
    await tab.open();
    await settle();
    tab.host().discardSetAside(); // C is dealt with; B is on offer
    await settle();
    assert.equal(tab.view.setAside, JSON.stringify(doc('park B')));

    const again = w.tab('T2', { drafts }); // the page is reloaded
    await again.open();
    await settle();

    assert.equal(again.view.setAside, JSON.stringify(doc('park B')));
});

test('a text the writer discarded stays parked but is not offered again, on this page or after a reload', async () => {
    const { B, server, clock } = await conflicted();
    await B.host().loadTheirs(); // B1 is set aside and parked, made to be offered
    assert.equal(B.drafts.get(STALE).offerable, true);

    B.host().discardSetAside(); // asks, answered yes
    await settle();
    assert.equal(B.view.setAside, null);
    assert.equal(B.drafts.get(STALE).resolved, true);

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    await settle();
    assert.equal(again.view.setAside, null);
});

test('the earlier states of typing that was parked more than once are not offered again after the last one is dealt with', async () => {
    const { B } = await conflicted();
    const realPark = B.draftStore.parkStaleDraft;
    let typed = false;
    B.draftStore.parkStaleDraft = async (...args) => {
        if (!typed) {
            typed = true;
            B.type('B1 more');
            await settle();
        }

        return realPark(...args);
    };
    await B.host().loadTheirs(); // parked twice: 'B1', then 'B1 more'
    assert.equal(B.view.setAside, JSON.stringify(doc('B1 more')));

    B.host().discardSetAside();
    await settle();

    assert.equal(B.view.setAside, null, 'the first state of the same typing is not a second text');
});

test('a text set aside and never dealt with is offered again on the next visit', async () => {
    const { B, server, clock } = await conflicted();
    await B.host().loadTheirs();

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    await settle();

    assert.equal(again.view.setAside, JSON.stringify(doc('B1')));
});

test('a standing set-aside text is never replaced by a queued one; the queue waits', async () => {
    const w = world();
    const drafts = new Map();
    // The live draft of a visit the document has moved past, with no base row: set aside at open as a draft.
    drafts.set(UUID, { json: JSON.stringify(doc('draft text')), baseVersion: 0 });
    seedParks(drafts, [{ text: 'queued earlier', parkedAt: 9_000_000_000_000 }]); // newer than the draft's park: only the guard keeps it off the page
    const tab = w.tab('T', { drafts });
    await tab.open();
    await settle();

    assert.equal(tab.view.setAside, JSON.stringify(doc('draft text')), 'the draft of this visit is on offer');

    tab.host().discardSetAside();
    await settle();
    assert.equal(tab.view.setAside, JSON.stringify(doc('queued earlier')), 'then the queued one');
});

test('the park of a draft set aside at open was made to be offered: reloaded without dealing with it, it is on offer again', async () => {
    const w = world();
    const drafts = new Map();
    drafts.set(UUID, { json: JSON.stringify(doc('draft text')), baseVersion: 0 });
    const tab = w.tab('T', { drafts });
    await tab.open();
    await settle();
    assert.equal(tab.view.setAside, JSON.stringify(doc('draft text')));

    const again = w.tab('T2', { drafts });
    await again.open();
    await settle();

    assert.equal(again.view.setAside, JSON.stringify(doc('draft text')));
});

test('a text set aside while the store is being read is not replaced by the queued one that was read', async () => {
    const w = world();
    const drafts = new Map();
    seedParks(drafts, [{ text: 'queued', parkedAt: 9_000_000_000_000 }]);
    const tab = w.tab('T', { drafts });
    const realNext = tab.draftStore.nextParkToOffer;
    tab.draftStore.nextParkToOffer = async (...args) => {
        const park = await realNext(...args);
        tab.view.setAside = JSON.stringify(doc('set aside meanwhile')); // Load theirs, say, finished during the read
        tab.view.setAsideFrom = 'conflict';

        return park;
    };
    await tab.open();
    await settle();

    assert.equal(tab.view.setAside, JSON.stringify(doc('set aside meanwhile')));
});

test('the earlier text returns with the label it had, is never put back or discarded without a question, and a draft-origin one too', async () => {
    const { A, B } = await conflicted();
    await B.host().loadTheirs(); // B1 set aside (from Load theirs)
    B.type('B2 typing');
    A.type('A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    await B.debounce();
    assert.ok(B.view.conflict, 'a second conflict stands');
    await B.host().loadTheirs(); // asks LOAD_THEIRS_REPLACES_QUESTION: yes
    assert.ok(B.questions.includes(LOAD_THEIRS_REPLACES_QUESTION));
    assert.equal(B.view.setAside, JSON.stringify(doc('B2 typing')));

    B.questions.length = 0;
    B.host().discardSetAside(); // the newer text: asks
    await settle();
    assert.deepEqual(B.questions, [DISCARD_QUESTION]);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'the earlier text comes back');
    assert.equal(B.view.setAsideFrom, 'conflict', 'with the label it had: the page took it away');
    assert.equal(B.view.setAsideBase, null, 'and marked as from the queue, which is never "just loaded"');

    // Discard asks for it as well; a no changes nothing and the offer stays.
    B.questions.length = 0;
    B.confirmAnswer = false;
    B.host().discardSetAside();
    assert.deepEqual(B.questions, [DISCARD_QUESTION]);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));

    // Put it back asks too, even though nothing on the page is unsaved.
    B.questions.length = 0;
    B.host().putBack();
    assert.ok(B.questions.some((question) => question.startsWith(PUT_BACK_QUESTION)));
});

test('a draft-origin text from the queue is discarded with the question too', async () => {
    const w = world();
    const drafts = new Map();
    seedParks(drafts, [{ text: 'queued draft', parkedAt: 9_000_000_000_000 }]); // no `from`: an old build's park
    const tab = w.tab('T', { drafts });
    await tab.open();
    await settle();
    assert.equal(tab.view.setAside, JSON.stringify(doc('queued draft')));

    tab.host().discardSetAside();

    assert.deepEqual(tab.questions, [DISCARD_QUESTION]);
});

test('a second tab of the same browser that retires another tab\'s text is asked first', async () => {
    const { B, server, clock } = await conflicted();
    await B.host().loadTheirs(); // B1 is B\'s text, set aside and parked
    const C = makeTab({ server, clock }, 'C', { drafts: B.drafts }); // another tab, same browser
    await C.open();
    await settle();
    assert.equal(C.view.setAside, JSON.stringify(doc('B1')), 'offered to the second tab as well (stated limit)');

    C.confirmAnswer = false;
    C.host().discardSetAside();
    await settle();

    assert.deepEqual(C.questions, [DISCARD_QUESTION], 'it cannot be retired for the other tab without a question');
    assert.equal(B.drafts.get(STALE).resolved, undefined);
});

test('Discard while the store cannot write does not offer the same text straight back', async () => {
    const { B } = await conflicted();
    await B.host().loadTheirs();
    B.draftStore.resolveParked = async () => false; // as offline.js on a failed write

    B.host().discardSetAside();
    await settle();

    assert.equal(B.view.setAside, null);
});

test('a queued text is not offered while a conflict or a merge stands, and is offered on the tick after they clear', async () => {
    const { A } = await pair();
    seedParks(A.drafts, [{ text: 'queued', parkedAt: 9_000_000_000_000 }]);

    A.view.conflict = { version: 9, ready: false };
    await A.host().offerNextPark();
    assert.equal(A.view.setAside, null, 'not while the notice stands');

    A.view.conflict = null;
    A.view.merging = { version: 3, since: A.env.now() };
    await A.host().offerNextPark();
    assert.equal(A.view.setAside, null, 'nor while a merge is waiting for its document');

    A.view.merging = null;
    A.view.syncNotice = 'You have been signed out. Reload the page to keep editing.';
    await A.host().offerNextPark();
    assert.equal(A.view.setAside, null, 'nor behind a stop reason');
    assert.equal(A.view.parkOfferPending, true, 'the offer is held, not forgotten');

    A.view.syncNotice = '';
    await A.cycle(); // the poll tick
    await settle();
    assert.equal(A.view.setAside, JSON.stringify(doc('queued')));
    assert.equal(A.view.parkOfferPending, false);
});

test('a page whose adoption of the lost rows returns nothing still reads them', async () => {
    const w = world();
    const drafts = new Map();
    drafts.set(`lost-${UUID}@old`, { docUuid: `lost-${UUID}@old`, entries: [{ key: 'p9:2:0', id: 'p9', type: 'paragraph', reason: 'both', source: 'merge', json: para('p9', 'kept'), anchors: ['p1'], version: 2, sentence: 'S', excerpt: 'kept' }], dismissed: [], parkedAt: 1, baseVersion: 2 });
    const tab = w.tab('T', { drafts });
    tab.draftStore.adoptLost = async () => null; // as offline.js when its write fails
    await tab.open();

    assert.deepEqual(tab.lostTexts(), ['kept']);
});

test('the merged draft is stamped like any draft written while an overwrite is owed: a crash before the save does not lose the overwrite', async () => {
    const { server, clock, again } = await deadTabWithDraft(
        async (A, B) => {
            A.deleteBlock('p2');
            A.typeIn('p1', 'start A');
            B.typeIn('p2', 'World B');
            await B.debounce();
            await B.land();
            B.typeIn('p2', 'World');
            await B.debounce();
            await B.land();
        },
        { version: 4 }
    );
    await settle();
    assert.equal(again.view.overwriteOwed, true);

    // The page dies before any save leaves; the browser opens the document again.
    const third = makeTab({ server, clock }, 'A3', { drafts: again.drafts });
    await third.open();
    await settle();

    assert.ok(!third.questions.includes(DRAFT_MERGE_QUESTION), 'an owed-overwrite draft is never merged');
    assert.equal(third.view.setAside, JSON.stringify(docOf(para('p1', 'start A'))), 'it is set aside, to be put back as an overwrite');
});

test('a queued text that equals the page is dealt with without being offered, and a fail-closed tab offers nothing', async () => {
    const w = world();
    const drafts = new Map();
    seedParks(drafts, [
        { text: 'start', parkedAt: 2000 }, // the page shows exactly this
        { text: 'older and different', parkedAt: 1000 },
    ]);
    const tab = w.tab('T', { drafts });
    await tab.open();
    await settle();

    assert.equal(tab.view.setAside, JSON.stringify(doc('older and different')));
    assert.equal(drafts.get(STALE).resolved, true, 'the identical text is not on offer and is not asked about again');

    const closed = w.tab('R', { drafts: new Map(drafts), failClosed: true });
    seedParks(closed.drafts, [{ text: 'queued', parkedAt: 5000 }]);
    await closed.open();
    await settle();
    assert.equal(closed.view.setAside, null);
});

test('Put it back does nothing for an entry that is not there', async () => {
    const { B } = await lostOne();

    assert.equal(B.host().putBackLost(3), false);
    assert.deepEqual(B.insertCalls, []);
    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.equal(B.view.syncNotice, '');
});

test('fail-closed: Put it back and Put them all back do nothing; Dismiss all works', async () => {
    const w = world();
    const R = w.tab('R', { failClosed: true });
    await R.open();
    R.view.lost = [
        { key: 'p1:2:0', id: 'p1', type: 'paragraph', reason: 'both', source: 'merge', json: para('p1', 'x'), anchors: ['p1'], version: 2, sentence: 'S', excerpt: 'x' },
    ];

    assert.equal(R.host().putBackLost(0), false);
    R.host().putBackAllLost();
    assert.deepEqual(R.insertCalls, []);
    assert.equal(R.view.lost.length, 1);
    assert.equal(R.view.syncNotice, '');

    await R.host().dismissLost();
    assert.deepEqual(R.view.lost, []);
    assert.deepEqual(R.questions, [DISMISS_LOST_QUESTION]);
    assert.deepEqual(R.draftCalls, ['parkLost', 'markLostDismissed'], 'the park is written and stamped; no draft is touched');
});

test('the lost row sentences, by reason and source', async () => {
    const { A } = await pair();
    const sentence = A.host().sentenceFor;

    assert.equal(sentence('both', 'merge', 'paragraph'), 'This paragraph was changed elsewhere at the same moment. The other version is on the page; this is yours.');
    assert.equal(sentence('deleted', 'merge', 'table'), 'This table was removed elsewhere while you were typing in it.');
    assert.equal(sentence('both', 'draft', 'list'), 'This list was changed in the document after your draft was written. The document\'s version is on the page; this is the draft\'s.');
    assert.equal(sentence('deleted', 'draft', 'heading'), 'This heading was removed from the document after your draft was written.');
    assert.equal(sentence('both', 'suggestion', 'paragraph'), 'This paragraph was rewritten by the accepted suggestion. The suggestion\'s version is on the page; this is yours.');
    assert.equal(sentence('deleted', 'suggestion', 'callout'), 'The accepted suggestion removed this callout while you were typing in it.');
});

test('Put it back pressed while a save that carried the overwrite is in the air: the answer does not write off the overwrite the put-back text still owes', async () => {
    const { server, A, B } = await twoParagraphs();
    // B has a set-aside offer standing from a draft it declined (set directly: this is what a declined draft merge leaves).
    B.view.setAside = JSON.stringify(docOf(para('p1', 'older text'), para('p2', 'World')));
    B.view.setAsideFrom = 'draft';
    B.view.setAsideBase = B.view.baseVersion;
    // c2: B deletes p2 while A edits p2 and saves; B's merged save carries the overwrite.
    B.deleteBlock('p2');
    A.typeIn('p2', 'World A');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.view.overwriteOwed, true);
    assert.equal(B.sent.at(-1).overwrite, true);
    assert.equal(B.saves.length, 1, 'the flagged save is in the air');

    // While it is in the air, the writer presses Put it back and confirms.
    B.host().putBack();
    assert.equal(B.view.overwriteOwed, true);
    B.saveArrives();
    await B.saveReturns();
    assert.equal(B.view.overwriteOwed, true, 'the answer settled the text that save carried, not the text put back after it left');

    await B.debounce();
    await B.land();
    assert.equal(B.sent.at(-1).overwrite, true, 'the put-back save replaces the stored head: it says so');
    assert.ok(server.history.length >= 2, 'both replaced heads are in the history');
    assert.equal(B.view.overwriteOwed, false);
});

test('Load theirs while a text is already set aside asks first: a no changes nothing, a yes sets the new text aside in place of the old one', async () => {
    const { server, A, B } = await conflicted({ B: { confirmAnswer: false } });
    await B.host().loadTheirs(); // asks nothing: no text was set aside yet. B1 is set aside, B shows A1
    assert.deepEqual(B.questions, []);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));

    // B types again on the loaded version, and A saves once more: the notice again.
    B.type('B2');
    A.type('A2');
    await A.debounce();
    await A.land();
    await B.cycle();
    assert.equal(B.view.conflict.version, 3);
    assert.equal(B.view.conflict.ready, true);

    await B.host().loadTheirs();
    assert.deepEqual(B.questions, [LOAD_THEIRS_REPLACES_QUESTION]);
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'a no leaves the earlier offer where it was');
    assert.equal(B.text(), 'B2');
    assert.equal(B.engine.pending.version, 3, 'and the waiting document is not taken');
    assert.notEqual(B.view.conflict, null);

    B.confirmAnswer = true;
    await B.host().loadTheirs();
    assert.equal(B.text(), 'A2');
    assert.equal(B.view.setAside, JSON.stringify(doc('B2')), 'a yes: the typing now on the page is what is offered back');
    assert.equal(B.stale(), 'B2');
    assert.equal(server.version, 3);
});

// ───────────────────────────────────────────────────────── the draft merge (section 7.1)

/**
 * A's tab dies inside the debounce with typing based on version 2; B saves
 * version 3 meanwhile; A's browser opens the document again.
 */
async function deadTabWithDraft(edit, { confirmAnswer = true, version = 3, beforeOpen = () => {} } = {}) {
    const w = await twoParagraphs();
    const { server, clock, A, B } = w;
    await edit(A, B); // may await: a case where B follows a beacon's version first
    assert.equal(A.drafts.get(UUID).baseVersion, 2, 'the live draft is of version 2');
    assert.equal(A.drafts.get(BASE).baseVersion, 2, 'and the base row holds version 2');
    await B.debounce();
    await B.land();
    assert.equal(server.version, version, 'somebody saved since the draft was written');
    const again = makeTab({ server, clock }, 'A2', { drafts: A.drafts, confirmAnswer });
    beforeOpen(again);
    await again.open();

    return { ...w, again };
}

test('a stale draft with a base row is merged after one question, saved on the first tick, and its losses are listed with the draft sentences', async () => {
    const { server, again } = await deadTabWithDraft((A, B) => {
        A.typeIn('p2', 'World A');
        A.insertAfter('p2', 'p3', 'Third by A');
        B.typeIn('p2', 'World B');
        B.typeIn('p1', 'start B');
    });

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.deepEqual(again.blocks(), [
        ['p1', 'start B'],
        ['p2', 'World B'],
        ['p3', 'Third by A'],
    ]);
    assert.deepEqual(
        again.view.lost.map((entry) => [entry.id, entry.reason, entry.source, entry.excerpt, entry.sentence]),
        [
            [
                'p2',
                'both',
                'draft',
                'World A',
                "This paragraph was changed in the document after your draft was written. The document's version is on the page; this is the draft's.",
            ],
        ]
    );
    assert.equal(again.view.unsaved, true);
    assert.equal(again.view.resave, true);
    assert.equal(again.view.setAside, null);
    assert.equal(again.stale(), 'start', 'the pre-merge copy of the draft is parked, as today');
    assert.equal(again.drafts.get(UUID).baseVersion, 3, 'the live draft is now of the merged document, on the new base');
    assert.equal(again.view.confirmed, JSON.stringify(server.json), 'opened() set it to the page document, which is theirs');
    assert.equal(again.drafts.get(BASE).baseVersion, 3, 'the base row is written after the draft check, of the page document');
    assert.match(again.infos.at(-1), /merged into the document/);

    // The first tick sends it.
    await again.poll();
    assert.deepEqual([again.sent[0].base, again.sent[0].overwrite], [3, false]);
    await again.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'start B'],
        ['p2', 'World B'],
        ['p3', 'Third by A'],
    ]);
    assert.equal(again.view.unsaved, false);
    assert.equal(again.word(), 'Saved');
});

test('a draft whose text is already in the document is cleared without a question', async () => {
    const { again } = await deadTabWithDraft(
        async (A, B) => {
            A.typeIn('p2', 'World A');
            A.leave(); // the beacon stores it: version 3 is the draft's text...
            await B.cycle(); // ...B follows it (a save on base 2 would be refused and merged, not stored)...
            B.typeIn('p1', 'start B'); // ...and B saves version 4 on top
        },
        { version: 4 }
    );

    assert.deepEqual(again.questions, []);
    assert.deepEqual(again.blocks(), [
        ['p1', 'start B'],
        ['p2', 'World A'],
    ]);
    assert.equal(again.draft(), null);
    assert.equal(again.stale(), null, 'nothing to park: the document has it');
    assert.equal(again.view.setAside, null);
    assert.deepEqual(again.view.lost, []);
    assert.equal(again.view.unsaved, false);
});

test('a draft deletion of a block changed since does not win and owes no overwrite; the rest of the draft is merged', async () => {
    const { server, again } = await deadTabWithDraft((A, B) => {
        A.deleteBlock('p2');
        A.typeIn('p1', 'start A');
        B.typeIn('p2', 'World B');
    });

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.deepEqual(again.blocks(), [
        ['p1', 'start A'],
        ['p2', 'World B'],
    ]);
    assert.equal(again.view.overwriteOwed, false);
    assert.deepEqual(again.view.lost, []);
    await again.poll();
    assert.deepEqual([again.sent[0].base, again.sent[0].overwrite], [3, false]);
    await again.land();
    assert.deepEqual(server.history, []);
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'start A'],
        ['p2', 'World B'],
    ]);
});

test('a draft deletion alone, of a block changed since, leaves nothing to merge: cleared without a question', async () => {
    const { again } = await deadTabWithDraft((A, B) => {
        A.deleteBlock('p2');
        B.typeIn('p2', 'World B');
    });

    assert.deepEqual(again.questions, []);
    assert.deepEqual(again.blocks(), [
        ['p1', 'start'],
        ['p2', 'World B'],
    ]);
    assert.equal(again.draft(), null);
    assert.equal(again.view.overwriteOwed, false);
    // Dealt with by the merge, not by today's fall-through (which would park the draft and set it aside).
    assert.equal(again.stale(), null, 'nothing parked: there was nothing of the draft to keep');
    assert.equal(again.view.setAside, null);
    assert.equal(again.mergeCalls, 1, 'the merge decided it');
});

test('a draft merge the writer said yes to whose apply fails falls through to the park and the set-aside, after one question', async () => {
    const { server, again } = await deadTabWithDraft(
        (A, B) => {
            A.typeIn('p2', 'World A');
            B.typeIn('p1', 'start B');
        },
        {
            beforeOpen: (tab) => {
                tab.handle.applyRemote = () => false;
            },
        }
    );

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION], 'asked once; the fall-through asks nothing more');
    assert.equal(again.mergeCalls, 2, 'once to decide whether to ask, once more from the editor as it is after the question');
    assert.deepEqual(again.blocks(), blocksOf(server.json), 'the page shows the document as it is');
    assert.equal(again.view.setAside, JSON.stringify(docOf(para('p1', 'start'), para('p2', 'World A'))), 'the whole draft is set aside');
    assert.equal(again.view.setAsideFrom, 'draft');
    assert.equal(again.view.setAsideBase, 3);
    assert.equal(again.stale(), 'start', 'and parked under stale-');
    assert.equal(again.draft(), null);
    assert.deepEqual(again.view.lost, []);
    assert.equal(again.view.unsaved, false);
});

test('losses of a draft merge are appended to the list re-offered from the last visit: both are on the page and in the park', async () => {
    const { again } = await deadTabWithDraft(
        (A, B) => {
            A.typeIn('p2', 'World A');
            B.typeIn('p2', 'World B');
            // An earlier visit parked a loss of its own.
            A.drafts.set(LOST, {
                entries: [
                    {
                        key: 'p9:2:0',
                        id: 'p9',
                        type: 'paragraph',
                        reason: 'both',
                        source: 'merge',
                        json: para('p9', 'Old loss'),
                        anchors: ['p1'],
                        version: 2,
                        sentence: 'S',
                        excerpt: 'Old loss',
                    },
                ],
                dismissed: [],
                baseVersion: 2,
                parkedAt: 1_000_000,
                dismissedAt: null,
            });
        }
    );

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.deepEqual(
        again.view.lost.map((entry) => [entry.source, entry.excerpt]),
        [
            ['parked', 'Old loss'],
            ['draft', 'World A'],
        ],
        'the draft merge appends to the re-offered entry; it does not replace it'
    );
    assert.equal(again.view.lost[0].sentence, 'From your last visit: S');
    assert.deepEqual(
        again.lostPark().entries.map((entry) => entry.excerpt),
        ['Old loss', 'World A'],
        'and the park holds the whole list'
    );
});

test('typing while the draft merge parks the pre-merge draft (after the yes) is merged in, kept unsaved and sent', async () => {
    const { server, again } = await deadTabWithDraft(
        (A, B) => {
            A.typeIn('p2', 'World A');
            B.typeIn('p1', 'start B');
        },
        {
            beforeOpen: (tab) => {
                const realPark = tab.draftStore.parkStaleDraft;
                tab.draftStore.parkStaleDraft = async (...args) => {
                    // The writer clicked OK and types straight away, while the park is written.
                    tab.typeIn('p1', 'start B TYPED-DURING-PARK');
                    await settle();

                    return realPark(...args);
                };
            },
        }
    );

    await settle(); // the page does not wait for the park: the typing and the merge land after open() returns
    await settle();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.deepEqual(again.blocks(), [
        ['p1', 'start B TYPED-DURING-PARK'],
        ['p2', 'World A'],
    ]);
    assert.equal(again.view.unsaved, true);
    assert.equal(again.view.resave, true);
    assert.equal(again.word(), 'Editing');
    assert.match(again.drafts.get(UUID).json, /TYPED-DURING-PARK/, 'the live draft holds the typing');

    await again.poll();
    await again.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'start B TYPED-DURING-PARK'],
        ['p2', 'World A'],
    ]);
});

test('a draft merge the writer said yes to while an IME composition is under way does not apply over it: the draft is set aside and the typing stays', async () => {
    const { server, again } = await deadTabWithDraft(
        (A, B) => {
            A.typeIn('p2', 'World A');
            B.typeIn('p1', 'start B');
        },
        {
            beforeOpen: (tab) => {
                const realPark = tab.draftStore.parkStaleDraft;
                tab.draftStore.parkStaleDraft = async (...args) => {
                    tab.typeIn('p1', 'start B COMPOSED');
                    tab.composing = true; // the composition is still open when the park is done
                    await settle();

                    return realPark(...args);
                };
            },
        }
    );
    await settle();
    await settle();

    assert.equal(again.applyCalls, 0, 'nothing was applied over the composition');
    assert.equal(again.text(), 'start B COMPOSED', 'the typing is still on the page');
    assert.equal(again.view.setAside, JSON.stringify(docOf(para('p1', 'start'), para('p2', 'World A'))), 'the draft is offered back, whole');
    assert.equal(again.view.setAsideFrom, 'draft');
    assert.ok(again.parks().some((row) => textOf(JSON.parse(row.json)) === 'start'), 'and parked');
    assert.equal(blocksOf(server.json)[0][1], 'start B');
});

test('typing while the draft merge reads the base row, when the draft adds nothing but loses a block: unsaved stays true and the typing is sent', async () => {
    const { server, again } = await deadTabWithDraft(
        (A, B) => {
            A.typeIn('p2', 'World A');
            B.typeIn('p2', 'World B'); // D1: the draft's p2 is offered back, nothing else of the draft survives
        },
        {
            beforeOpen: (tab) => {
                const realLoadBase = tab.draftStore.loadBase;
                tab.draftStore.loadBase = async (...args) => {
                    tab.typeIn('p1', 'start TYPED-DURING-LOADBASE');

                    return realLoadBase(...args);
                };
            },
        }
    );

    assert.deepEqual(again.lostTexts(), ['World A']);
    assert.equal(again.view.unsaved, true, 'the typing is not written off');
    assert.equal(again.view.resave, true);

    await again.poll();
    await again.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'start TYPED-DURING-LOADBASE'],
        ['p2', 'World B'],
    ]);
});

test('Load theirs: typing while the unsaved text is being parked is parked too, and it is the newer text that is set aside', async () => {
    const { B } = await conflicted();
    const realPark = B.draftStore.parkStaleDraft;
    let typed = false;
    B.draftStore.parkStaleDraft = async (...args) => {
        if (!typed) {
            typed = true;
            B.type('B1 TYPED-DURING-PARK');
            await settle();
        }

        return realPark(...args);
    };

    await B.host().loadTheirs();

    assert.equal(B.view.setAside, JSON.stringify(doc('B1 TYPED-DURING-PARK')), 'the newest text is on offer');
    assert.deepEqual(
        B.parks().map((row) => textOf(JSON.parse(row.json))),
        ['B1', 'B1 TYPED-DURING-PARK'],
        'and both texts are parked: parking keeps the earlier one'
    );
    assert.equal(B.text(), 'A1');
});

test('a draft deletion of a block that LOOKS unchanged across a gap of versions owes the overwrite: the replaced version is kept (seed 42519 shape)', async () => {
    const { server, again } = await deadTabWithDraft(
        async (A, B) => {
            A.deleteBlock('p2'); // the draft deletes p2
            A.typeIn('p1', 'start A');
            // Meanwhile p2 is changed and changed back by somebody else (a restore of the very content the draft was based on).
            B.typeIn('p2', 'World B');
            await B.debounce();
            await B.land();
            B.typeIn('p2', 'World');
            await B.debounce();
            await B.land();
        },
        { version: 4 }
    );
    await settle();

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.deepEqual(again.blocks(), [['p1', 'start A']], 'the draft deletion applies');
    assert.equal(again.view.overwriteOwed, true, 'and the save is owed as an overwrite');

    await again.poll();
    assert.deepEqual([again.sent[0].base, again.sent[0].overwrite], [4, true]);
    await again.land();
    assert.ok(JSON.stringify(server.kept).includes('World'), 'the version that had p2 is in the history');
});

test('a draft deletion across a gap of exactly one version does not owe the overwrite', async () => {
    const { again } = await deadTabWithDraft((A, B) => {
        A.deleteBlock('p2');
        A.typeIn('p1', 'start A');
        B.typeIn('p1', 'start B'); // one version later; p2 is untouched
    });
    await settle();

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.equal(again.view.overwriteOwed, false);
    await again.poll();
    assert.deepEqual([again.sent[0].base, again.sent[0].overwrite], [3, false]);
});

test('a live merge across a gap of versions owes the overwrite when it deletes a block that looks unchanged, and not across one version', async () => {
    const { server, A, B } = await twoParagraphs();
    B.deleteBlock('p2'); // unsaved, on base 2
    A.typeIn('p2', 'World A');
    await A.debounce();
    await A.land(); // v3
    A.typeIn('p2', 'World');
    await A.debounce();
    await A.land(); // v4: p2 is back to what it was at v2
    await B.cycle(); // B hears of v4 while it holds unsaved typing: merged

    assert.equal(server.version, 4);
    assert.deepEqual(B.blocks(), [['p1', 'start']], 'B\'s deletion stands');
    assert.equal(B.view.overwriteOwed, true);
    await B.debounce();
    assert.deepEqual([B.sent.at(-1).base, B.sent.at(-1).overwrite], [4, true]);

    // One version only: no overwrite is owed.
    const second = await twoParagraphs();
    second.B.deleteBlock('p2');
    second.A.typeIn('p1', 'start A');
    await second.A.debounce();
    await second.A.land(); // v3, p2 untouched
    await second.B.cycle();
    assert.equal(second.B.view.overwriteOwed, false);
});

test('a suggestion merge across a gap of versions owes the overwrite when it deletes a block that looks unchanged', async () => {
    const { server, A } = await twoParagraphs();
    A.deleteBlock('p2'); // unsaved typing: the deletion of p2
    server.replace(docOf(para('p1', 'start'), para('p2', 'World'))); // v3
    const suggestion = docOf(para('p1', 'Suggested start'), para('p2', 'World'));
    const version = server.replace(suggestion); // v4: p2 is as it was at v2

    A.host().applySuggestion(suggestion, version);

    assert.deepEqual(A.blocks(), [['p1', 'Suggested start']]);
    assert.equal(A.view.overwriteOwed, true);
});

test('a declined draft merge parks the draft and sets it aside, as today', async () => {
    const { server, again } = await deadTabWithDraft(
        (A, B) => {
            A.typeIn('p2', 'World A');
            B.typeIn('p1', 'start B');
        },
        { confirmAnswer: false }
    );

    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION]);
    assert.deepEqual(again.blocks(), blocksOf(server.json));
    assert.equal(again.view.setAside, JSON.stringify(docOf(para('p1', 'start'), para('p2', 'World A'))));
    assert.equal(again.view.setAsideFrom, 'draft');
    assert.equal(again.view.setAsideBase, 3);
    assert.equal(again.stale(), 'start');
    assert.equal(again.draft(), null);
    assert.deepEqual(again.view.lost, []);
    assert.equal(again.view.unsaved, false);
});

test('no base row: today\'s path, the draft is parked and set aside without a question', async () => {
    const { server, A } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    A.drafts.delete(BASE); // an older build, or the row was swept

    server.replace(docOf(para('p1', 'start B'), para('p2', 'World')));
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'A2', { drafts: A.drafts });
    await again.open();

    assert.deepEqual(again.questions, []);
    assert.equal(again.view.setAside, JSON.stringify(docOf(para('p1', 'start'), para('p2', 'World A'))));
    assert.equal(again.view.setAsideFrom, 'draft');
    assert.equal(again.stale(), 'start');
    assert.equal(again.mergeCalls, 0, 'no row, no merge');
});

test('a draft merge that is impossible falls back to today\'s path', async () => {
    const { server, A } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    server.replace(docOf(para('p1', 'start B'), para('p2', 'World')));
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'A2', { drafts: A.drafts, mergeFails: true });
    await again.open();

    assert.deepEqual(again.questions, []);
    assert.equal(again.mergeCalls, 1);
    assert.equal(again.view.setAside, JSON.stringify(docOf(para('p1', 'start'), para('p2', 'World A'))));
    assert.equal(again.view.setAsideFrom, 'draft');
});

test('the draft merge question says what is kept, what stays, and what is offered back', () => {
    assert.equal(
        DRAFT_MERGE_QUESTION,
        'An unsaved draft from your last visit was found. Add its changes to the document as it is now? Changes saved by others since then are kept, and a paragraph the draft deleted that was changed since stays. Anything that could not be kept is offered back.'
    );
});

test('a draft merge that leaves text to save does not leave the word at Saved: it says Editing until the first tick sends it', async () => {
    const { again } = await deadTabWithDraft((A, B) => {
        A.typeIn('p2', 'World A');
        B.typeIn('p1', 'start B');
    });

    assert.equal(again.view.unsaved, true);
    assert.equal(again.word(), 'Editing', 'the page said Saved when it opened; the merged text is not');

    await again.poll();
    await again.land();
    assert.equal(again.view.unsaved, false);
    assert.equal(again.word(), 'Saved');
});

// ───────────────────────────────────────────────────────── an accepted suggestion over unsaved typing (section 8)

test('an accepted suggestion over unsaved typing: blocks it did not touch survive and are saved, the block it rewrote is listed', async () => {
    const { server, A } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    A.insertAfter('p2', 'p3', 'Third by A');
    const suggestion = docOf(para('p1', 'Suggested start'), para('p2', 'Suggested world'));
    const version = server.replace(suggestion); // stored with no base: version 3

    A.host().applySuggestion(suggestion, version);

    assert.deepEqual(A.blocks(), [
        ['p1', 'Suggested start'],
        ['p2', 'Suggested world'],
        ['p3', 'Third by A'],
    ]);
    assert.equal(A.view.baseVersion, 3);
    assert.deepEqual(
        A.view.lost.map((entry) => [entry.id, entry.reason, entry.source, entry.excerpt]),
        [['p2', 'both', 'suggestion', 'World A']]
    );
    assert.equal(A.view.unsaved, true);
    assert.deepEqual([A.sent.at(-1).base, A.sent.at(-1).overwrite], [3, false]);
    assert.equal(A.view.aiError, '');
    assert.equal(A.view.setAside, null);
    assert.equal(A.view.conflict, null);

    await A.land();
    assert.deepEqual(blocksOf(server.json), [
        ['p1', 'Suggested start'],
        ['p2', 'Suggested world'],
        ['p3', 'Third by A'],
    ]);
    assert.equal(server.version, 4);
    assert.equal(A.word(), 'Saved');
});

test('an accepted suggestion over unsaved typing whose merge is impossible: the typing is set aside first, then the suggestion goes in whole', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    A.mergeFails = true;
    const version = server.replace(doc('the accepted suggestion'));

    A.host().applySuggestion(doc('the accepted suggestion'), version);

    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.view.setAside, JSON.stringify(doc('A unsaved')));
    assert.equal(A.view.setAsideFrom, 'conflict');
    assert.equal(A.view.setAsideBase, 2);
    assert.equal(A.stale(), 'A unsaved', 'parked, as after Load theirs');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.baseVersion, 2);
    assert.deepEqual(A.view.lost, []);
    assert.equal(A.view.aiError, '');
    assert.equal(A.word(), 'Saved');
});

test('an accepted suggestion over unsaved typing with no base known (after Keep mine): the typing is set aside first', async () => {
    const { server, clock, B } = await conflicted();
    B.host().keepMine();
    B.saveNeverAnswers();
    clock.now += SAVE_EXPIRY_MS;
    assert.equal(B.host().syncState(), 'dirty');
    assert.equal(B.view.confirmed, null);
    B.mergeFails = false;
    const version = server.replace(doc('the accepted suggestion'));

    B.host().applySuggestion(doc('the accepted suggestion'), version);

    assert.equal(B.mergeCalls, 1, 'the merge from conflicted() only: no base for the moved-up version');
    assert.equal(B.text(), 'the accepted suggestion');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(B.view.setAsideFrom, 'conflict');
    assert.equal(B.view.setAsideBase, 3);
    assert.equal(B.view.baseVersion, 3);
    assert.equal(B.view.conflict, null, 'the notice was about a version the suggestion replaced');
    assert.equal(B.view.unsaved, false);
});

test('a save given up on is ended inside the suggestion merge: its document does not become the echo of a later merge', async () => {
    const { server, clock, A, B } = await twoParagraphs();
    // A's save is in the air for good: p1 'X'. It never reaches the server.
    A.typeIn('p1', 'X');
    await A.debounce();
    A.saveNeverAnswers();

    // A types a new paragraph too; fifteen seconds on, with no keystroke since (so nothing but the
    // suggestion below has noticed that the save never answered), A accepts a suggestion that rewrites p1 (version 3).
    A.insertAfter('p2', 'p3', 'Third');
    clock.now += SAVE_EXPIRY_MS;
    const suggestion = docOf(para('p1', 'Suggested'), para('p2', 'World'));
    const version = server.replace(suggestion);
    A.host().applySuggestion(suggestion, version); // merged; the merged save M (base 3) goes out
    assert.equal(A.sent.at(-1).base, 3);

    // Before M arrives, B (who follows version 3) writes 'X' in p1 and saves: version 4.
    await B.cycle();
    B.typeIn('p1', 'X');
    await B.debounce();
    await B.land();
    assert.equal(server.version, 4);

    // M is refused (version 4); A fetches it and merges. B's X is a later edit than the suggestion: it stands.
    A.saveArrives();
    await A.saveReturns();
    await A.poll();
    await A.land();
    assert.equal(blocksOf(server.json).find(([id]) => id === 'p1')[1], 'X', "B's X is not replaced by the stale echo");
    assert.equal(A.view.overwriteOwed, false);
});

test('the answer of a save that was in the air when a suggestion was accepted does not move the base back or replace what the tab confirmed', async () => {
    const { server, A } = await pair();
    A.type('S1');
    await A.debounce();
    A.saveArrives(); // stored as version 2, the answer is held back
    assert.equal(server.version, 2);

    const suggestion = doc('Suggested');
    const version = server.replace(suggestion); // version 3
    A.host().applySuggestion(suggestion, version); // merged: S1 is offered back (D1), the tab is at version 3
    assert.equal(A.view.baseVersion, 3);
    A.type('Suggested Y');

    await A.saveReturns(); // the held answer: ok, version 2
    assert.equal(A.view.baseVersion, 3, 'the base does not go back to version 2');
    assert.equal(A.view.confirmed, JSON.stringify(doc('Suggested')), 'confirmed is still the suggestion');

    // Somebody restores version 2 (the text S1) while A has 'Suggested Y' unsaved.
    server.replace(doc('S1')); // version 4
    await A.debounce();
    await A.land();
    await A.cycle();
    await A.land();
    assert.equal(server.text(), 'S1', "the restore stands: A's later edit of a block they restored is a D1, not a silent overwrite");
    assert.ok(A.lostTexts().includes('Suggested Y'));
});

test('an accepted suggestion whose merge fails does not replace a text that is already set aside: it asks to deal with that first', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs(); // B1 is set aside, offered back
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')));
    B.type('B2 typed after'); // unsaved typing on the loaded version
    B.mergeFails = true;
    const suggestion = doc('suggestion');
    const version = server.replace(suggestion);

    B.host().applySuggestion(suggestion, version);

    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'the offer stands');
    assert.equal(B.text(), 'B2 typed after', 'the suggestion did not go in over the typing');
    assert.equal(
        B.view.aiError,
        'Suggestion saved, but your unsaved text could not be added to it. Put back or discard the set-aside text first.'
    );
});

test('an accepted suggestion whose merge apply throws after the change, with a text already set aside: the typing is put back in the editor, not left merged away', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs(); // B1 is set aside, offered back
    B.type('B2 typed after');
    B.mergeFails = false;
    const real = B.handle.applyRemote;
    let calls = 0;
    B.handle.applyRemote = (json, options) => {
        calls += 1;
        const out = real(json, options);
        if (calls === 1) {
            throw new Error('a plugin failed inside the dispatch');
        }

        return out;
    };
    const suggestion = doc('suggestion');
    const version = server.replace(suggestion);

    B.host().applySuggestion(suggestion, version);

    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'the offer stands');
    assert.equal(B.text(), 'B2 typed after', 'the typing is back in the editor: the throw did not lose it');
    assert.match(B.view.aiError, /Put back or discard the set-aside text first/);
});

test('an accepted suggestion that leaves nothing of mine to save drops an owed overwrite: no save is left to carry it', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs();
    B.host().putBack(); // B1 is back on the page, the overwrite is owed
    assert.equal(B.view.overwriteOwed, true);
    B.mergeFails = false;
    const suggestion = doc('suggestion');
    const version = server.replace(suggestion);

    B.host().applySuggestion(suggestion, version); // D1: the suggestion stays, B1 is offered back

    assert.equal(B.text(), 'suggestion');
    assert.deepEqual(B.lostTexts(), ['B1']);
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.overwriteOwed, false);
});

// ───────────────────────────────────────────────────────── Task 11, fix round 1

test('an accepted suggestion refused while a text is set aside leaves the typing owed: the next tick sends it and the notice follows', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs(); // B1 is set aside, offered back
    B.type('B2 typed after');
    await B.debounce(); // the save of B2 is in the air (base 2)
    assert.equal(B.view.saving, 1);
    const suggestion = doc('suggestion');
    const version = server.replace(suggestion); // version 3

    B.host().applySuggestion(suggestion, version); // refused: a text is set aside

    assert.equal(B.view.saving, 0, 'the save in the air was given up on');
    assert.equal(B.view.unsaved, true);
    assert.equal(B.view.resave, true, 'the typing is owed: nothing else would send it while polls fail');

    // Polls fail: the tick still sends what is owed.
    const sentBefore = B.sent.length;
    await B.fireTimer();
    await B.pollFails();
    assert.equal(B.sent.length, sentBefore + 1, 'the owed save went out');
    assert.equal(B.sent.at(-1).text, 'B2 typed after');
    assert.equal(B.word(), 'Saving');

    // It is refused as stale; the merge is impossible, so the notice follows.
    await B.land(); // the retired save's late answer is ignored; the new one is refused as stale
    await B.cycle(); // the poll that fetches version 3, which cannot be merged
    assert.deepEqual([B.view.conflict?.version, B.view.conflict?.ready], [3, true]);
});

test('an accepted suggestion refused while a text is set aside owes the typing again only when a save was retired', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs(); // B1 is set aside, offered back
    B.type('B2 typed after'); // typed, but no save in the air: nothing is retired
    assert.equal(B.view.saving, 0);
    assert.equal(B.view.resave, false, 'nothing is owed yet');
    const suggestion = doc('suggestion');
    const version = server.replace(suggestion);

    B.host().applySuggestion(suggestion, version); // refused: a text is set aside

    assert.match(B.view.aiError, /Put back or discard the set-aside text first/);
    assert.equal(B.view.saving, 0);
    assert.equal(B.view.unsaved, true);
    assert.equal(B.view.resave, false, 'no save was given up on, so the refusal makes nothing owed');
    assert.equal(B.view.setAside, JSON.stringify(doc('B1')), 'the offer stands');
});

test('an accepted suggestion that could not be applied leaves the typing owed when a save was retired, and not otherwise', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    await A.debounce(); // in the air
    A.mergeFails = true;
    A.refuses = true;
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    A.host().applySuggestion(suggestion, version);

    assert.equal(A.view.aiError, 'That suggestion could not be applied — the document is unchanged.');
    assert.equal(A.view.saving, 0);
    assert.equal(A.view.unsaved, true);
    assert.equal(A.view.resave, true);

    // No save in the air: nothing was retired, nothing becomes owed by this.
    const { server: server2, A: A2 } = await pair();
    A2.type('A2 unsaved');
    A2.mergeFails = true;
    A2.refuses = true;
    A2.host().applySuggestion(doc('x'), server2.replace(doc('x')));
    assert.equal(A2.view.saving, 0);
    assert.equal(A2.view.resave, false);
});

test('an accepted suggestion that could not be applied sets nothing aside: the typing is still in the editor and is not offered as set aside', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    A.mergeFails = true;
    A.refuses = true;
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    A.host().applySuggestion(suggestion, version);

    assert.equal(A.view.aiError, 'That suggestion could not be applied — the document is unchanged.');
    assert.equal(A.text(), 'A unsaved');
    assert.equal(A.view.unsaved, true);
    assert.equal(A.view.setAside, null);
    assert.equal(A.view.setAsideFrom, null);
    assert.equal(A.view.setAsideBase, null);
    assert.equal(A.stale(), null, 'nothing is parked');
    assert.equal(A.draftCalls.filter((call) => call === 'parkStaleDraft').length, 0);
});

test('a given-up Keep mine save, Load theirs, a second give-up, then the late OK: the answer is ignored and the stored Keep mine version is not replaced unkept', async () => {
    const { server, clock, B } = await conflicted();
    assert.ok(B.view.conflict, 'B shows the notice');
    B.host().keepMine(); // save on base 2, overwrite
    const keep = B.saves[0];
    assert.equal(keep.overwrite, true);
    clock.now += SAVE_EXPIRY_MS + 1;
    B.host().syncState(); // the tab gives the Keep mine save up
    assert.equal(B.view.saving, 0);
    B.saveArrives(); // ...but it reached the server: v3 = B1, A1 kept in the history
    assert.equal(server.version, 3);
    assert.equal(server.text(), 'B1');
    await B.host().loadTheirs(); // the writer chooses theirs (v2, A1): B1 is set aside
    assert.equal(B.text(), 'A1');
    assert.equal(B.view.baseVersion, 2);
    B.saves.shift(); // the Keep mine answer is still on its way (held here)
    B.type('A1 more');
    await B.debounce(); // a save on base 2 goes out
    clock.now += SAVE_EXPIRY_MS + 1;
    B.host().syncState(); // and is given up too: confirmed is null again, base still 2
    assert.equal(B.view.confirmed, null);

    keep.resolve(keep.result); // the Keep mine answer arrives late: ok, v3
    await settle();

    assert.equal(B.view.baseVersion, 2, 'the late answer does not move the base to a version the page never showed');
    assert.equal(B.view.confirmed, null, 'and confirms nothing');
    await B.land();
    const everStored = JSON.stringify([server.json, server.kept]);
    assert.ok(everStored.includes('B1'), 'version 3 (B1, stored by Keep mine) is in the head or the history');
});

test('a poll that brings the awaited document after the 15 s expiry is merged, not answered with the notice', async () => {
    const { server, clock, A, B } = await twoParagraphs();
    A.typeIn('p1', 'Hello A');
    B.typeIn('p2', 'World B');
    await A.debounce();
    await B.debounce();
    A.saveArrives();
    await A.saveReturns();
    B.saveArrives(); // refused: A's version is in the way
    B.visible = false;
    await B.saveReturns(); // beginMerge: a fetch goes out even though the tab is hidden
    await B.pollFails(); // that fetch fails; hidden, no timer is re-armed
    assert.ok(B.view.merging, 'a merge is waiting for its document');

    clock.now += MERGE_FETCH_EXPIRY_MS + 5000;
    B.visible = true;
    B.engine.visibilityChanged();
    await settle();
    await B.poll(); // the awaited document finally arrives, after the expiry

    assert.equal(B.view.conflict, null, 'no notice: the merge was possible and the document is here');
    assert.deepEqual(B.blocks(), [
        ['p1', 'Hello A'],
        ['p2', 'World B'],
    ]);
});

test('an accepted suggestion whose MERGE apply throws after the change: the typing, not the merged page, is set aside and parked, and the suggestion goes in whole', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    const real = A.handle.applyRemote;
    let calls = 0;
    A.handle.applyRemote = (json, options) => {
        calls += 1;
        const out = real(json, options);
        if (calls === 1) {
            throw new Error('a plugin failed inside the dispatch');
        }

        return out;
    };
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    assert.doesNotThrow(() => A.host().applySuggestion(suggestion, version));

    assert.equal(calls, 2, 'the merge apply, then the forced apply');
    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.view.setAside, JSON.stringify(doc('A unsaved')), 'what is set aside is the typing');
    assert.equal(A.view.setAsideFrom, 'conflict');
    assert.equal(A.stale(), 'A unsaved', 'and it is parked, not only in the draft row');
    assert.equal(A.draftCalls.filter((call) => call === 'parkStaleDraft').length, 1);
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.aiError, '');
});

test('an accepted suggestion whose merge apply AND forced apply both throw after the change still sets the typing aside', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    const real = A.handle.applyRemote;
    A.handle.applyRemote = (json, options) => {
        real(json, options);
        throw new Error('a plugin failed inside the dispatch');
    };
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    assert.doesNotThrow(() => A.host().applySuggestion(suggestion, version));

    assert.equal(A.view.setAside, JSON.stringify(doc('A unsaved')), 'the typing, never the merged or suggested page');
    assert.equal(A.stale(), 'A unsaved');
    assert.match(A.view.aiError, /may not have been applied cleanly/);
});

test('an accepted suggestion whose forced apply THROWS before the document changed sets nothing aside and reports it unchanged', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    A.mergeFails = true;
    A.handle.applyRemote = () => {
        throw new Error('a plugin failed before the dispatch');
    };
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    assert.doesNotThrow(() => A.host().applySuggestion(suggestion, version));

    assert.equal(A.view.aiError, 'That suggestion could not be applied — the document is unchanged.');
    assert.equal(A.text(), 'A unsaved');
    assert.equal(A.view.unsaved, true);
    assert.equal(A.view.setAside, null);
    assert.equal(A.stale(), null);
    assert.match(String(A.errors.at(-1)[0]), /accepted suggestion threw/, 'and the cause is logged');
});

test('an accepted suggestion whose forced apply THROWS after the document changed sets the typing aside: it is parked and offered back', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    A.mergeFails = true;
    const real = A.handle.applyRemote;
    A.handle.applyRemote = (json, options) => {
        real(json, options); // the dispatch went through: the typing is gone from the editor
        throw new Error('a plugin failed inside the dispatch');
    };
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    assert.doesNotThrow(() => A.host().applySuggestion(suggestion, version));

    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.view.setAside, JSON.stringify(doc('A unsaved')), 'the typing is offered back');
    assert.equal(A.view.setAsideFrom, 'conflict');
    assert.equal(A.view.setAsideBase, version);
    assert.equal(A.stale(), 'A unsaved', 'and parked');
    assert.equal(A.draftCalls.filter((call) => call === 'parkStaleDraft').length, 1);
    assert.match(A.view.aiError, /may not have been applied cleanly/, 'the document is not reported unchanged: it changed');
    assert.match(String(A.errors.at(-1)[0]), /accepted suggestion threw/, 'and the cause is logged');
});

test('an accepted suggestion that goes in whole over the typing sets it aside and parks it exactly once', async () => {
    const { server, A } = await pair();
    A.type('A unsaved');
    A.mergeFails = true;
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    A.host().applySuggestion(suggestion, version);

    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.view.setAside, JSON.stringify(doc('A unsaved')));
    assert.equal(A.view.setAsideBase, 2);
    assert.equal(A.stale(), 'A unsaved');
    assert.equal(A.draftCalls.filter((call) => call === 'parkStaleDraft').length, 1);
});

test('an accepted suggestion merged over an owed overwrite keeps the overwrite owed: the merged save says it overwrites', async () => {
    const { server, A, B } = await twoParagraphs();
    B.mergeFails = true;
    A.typeIn('p1', 'A1');
    B.typeIn('p1', 'B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    await B.debounce();
    await B.host().loadTheirs(); // B1 is set aside
    B.host().putBack(); // B1 is back, the overwrite is owed
    assert.equal(B.view.overwriteOwed, true);
    B.mergeFails = false;
    B.typeIn('p2', 'World B');
    const suggestion = docOf(para('p1', 'A1'), para('p2', 'World'), para('p3', 'Suggested third'));
    const version = server.replace(suggestion);

    B.host().applySuggestion(suggestion, version);

    assert.equal(B.view.unsaved, true);
    assert.deepEqual([B.sent.at(-1).base, B.sent.at(-1).overwrite], [version, true]);
    assert.equal(B.view.overwriteOwed, true);
});

test('an accepted suggestion merged over typing whose save then fails does not write the merged text off as saved', async () => {
    const { server, A } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    const suggestion = docOf(para('p1', 'Suggested start'), para('p2', 'World'));
    const version = server.replace(suggestion);
    server.rejectNext = true; // the merged save is rejected, as a bad document is

    A.host().applySuggestion(suggestion, version);
    await A.land();

    assert.equal(A.view.confirmed, JSON.stringify(suggestion), 'confirmed is the suggestion, not the merged document');
    await A.cycle(); // a tick runs the settle check
    assert.equal(A.view.unsaved, true, 'the merged text is still unsaved');
    assert.equal(A.word(), 'Not saved');
    assert.deepEqual(A.blocks(), [
        ['p1', 'Suggested start'],
        ['p2', 'World A'],
    ]);
});

test('an accepted suggestion tells the engine its version is this tab own: the next poll states it', async () => {
    const { server, A } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    const suggestion = docOf(para('p1', 'Suggested start'), para('p2', 'World'));
    const version = server.replace(suggestion); // version 3

    A.host().applySuggestion(suggestion, version);
    assert.equal(A.engine.pending, null);
    await A.fireTimer();

    assert.equal(A.polled.at(-1).version, version, 'the engine has seen the suggestion version');
});

test('an accepted suggestion is merged on the version the tab holds when its confirmed document is not known', async () => {
    const { server, A } = await twoParagraphs();
    A.typeIn('p2', 'World A');
    await A.debounce();
    A.saves.shift().reject(new Error('network')); // the request fails: the server may have stored it, confirmed is unknown
    await settle();
    assert.equal(A.view.confirmed, null);
    assert.equal(A.view.known.version, A.view.baseVersion);
    const suggestion = docOf(para('p1', 'Suggested start'), para('p2', 'World'));
    const version = server.replace(suggestion);

    A.host().applySuggestion(suggestion, version);

    assert.deepEqual(A.blocks(), [
        ['p1', 'Suggested start'],
        ['p2', 'World A'],
    ]);
    assert.equal(A.view.setAside, null);
    assert.equal(A.mergeCalls, 1);
});

test('an accepted suggestion merged over typing ends a standing conflict notice', async () => {
    const { server, B } = await conflicted();
    assert.notEqual(B.view.conflict, null);
    B.mergeFails = false;
    const suggestion = doc('the accepted suggestion');
    const version = server.replace(suggestion);

    B.host().applySuggestion(suggestion, version);

    assert.equal(B.mergeCalls, 2, 'merged, not set aside');
    assert.equal(B.view.setAside, null);
    assert.equal(B.view.conflict, null);
});

test('the floor survives a merge that changes the text without overwriting: a later Keep mine and reopen still sets the draft aside, nothing is lost', async () => {
    const holdsT31 = (json) => JSON.stringify(json).includes('t31');
    const w = world();
    const { server } = w;
    const A = w.tab('A');
    const C = w.tab('C');
    await A.open(); await C.open(); await A.poll(); await C.poll(); await A.cycle(); await C.cycle();
    C.insertAfter('p1', 'n1', 't5'); await C.debounce(); await C.land(); // v2
    await A.cycle();
    A.deleteBlock('n1');
    C.typeIn('n1', 't5 t31'); await C.debounce(); await C.land(); // v3
    await A.cycle(); // c2 onto v3, merged save in the air
    assert.equal(A.view.overwriteOwed, true);
    const floorAfterC2 = A.view.overwriteFloor;
    C.typeIn('p1', 'start x'); await C.debounce(); await C.land(); // v4
    A.saveArrives(); await A.saveReturns(); // refused: merge onto v4 (c1: A's deletion again)
    if (A.polls.length === 0 && A.timer) { await A.fireTimer(); }
    await A.poll();
    assert.equal(A.view.overwriteOwed, true, 'still owed');
    C.typeIn('p1', 'start x y'); await C.debounce(); await C.land(); // v5
    A.mergeFails = true;
    if (A.saves.length) { A.saveArrives(); await A.saveReturns(); }
    if (A.polls.length === 0 && A.timer) { await A.fireTimer(); }
    await A.poll();
    assert.ok(A.view.conflict);
    A.host().keepMine();
    A.insertAfter('p1', 'n9', 'a1');
    await A.debounce();
    A.leave(); A.saves.length = 0; A.polls.length = 0; A.engine.stop(); A.mounted = false;
    const again = w.tab('A2', { drafts: A.drafts });
    await again.open();
    assert.deepEqual(again.questions, [], 'not offered as an ordinary draft merge');
    await again.poll();
    if (again.saves.length) { await again.land(); }
    assert.ok(holdsT31(server.json) || server.kept.some((r) => holdsT31(r.json)), 't31 kept somewhere');
});

// ───────────────────────────────────────────────────────── read only (a person who may not change the document)

const SAVE_REQUEST = JSON.stringify({
    _token: 'x',
    components: [{ snapshot: '{}', updates: {}, calls: [{ path: '', method: 'saveContent', params: [{}, 1, false] }] }],
});

/** A page the server rendered for somebody who may read but not change the document, next to a writer. */
async function readerAndWriter(readerOptions = {}) {
    const w = world();
    const writer = w.tab('W');
    const reader = w.tab('R', { readOnly: true, ...readerOptions });
    await writer.open();
    await reader.open();
    await writer.poll();
    await reader.poll();
    await writer.cycle();

    return { ...w, writer, reader };
}

test('read only: the page opens saying Read only, in a calm tone, and never Saved', async () => {
    const { reader } = await readerAndWriter();

    assert.deepEqual(reader.reports[0], { tone: 'idle', word: 'Read only' });
    assert.ok(!reader.words().includes('Saved'));
});

test('read only: text that gets into the editor all the same is never saved, drafted or reported', async () => {
    const { reader } = await readerAndWriter();
    reader.type('sneaked in');
    await reader.debounce();
    await reader.cycle();

    assert.equal(reader.sent.length, 0);
    assert.equal(reader.beacons.length, 0);
    assert.equal(reader.view.unsaved, false, 'nothing was typed here as far as the page is concerned');
    assert.equal(reader.draft(), null, 'no offline draft is written for a page that cannot save');
    assert.deepEqual(reader.words(), ['Read only'], 'no Editing, Saving, Saved or Not saved');
});

test('read only: persist, resendIfOwed, backOnline and an unload save send nothing', async () => {
    const { reader } = await readerAndWriter();

    await reader.host().persist(doc('forced'));
    await reader.host().persist(doc('forced again'), { force: true });
    reader.view.resave = true;
    reader.view.unsaved = true;
    reader.host().resendIfOwed();
    reader.host().backOnline();
    await settle();

    assert.equal(reader.sent.length, 0);
    assert.equal(reader.view.saving, 0);
    assert.ok(!reader.words().includes('Saving'));
    assert.ok(!reader.words().includes('Not saved'));
});

test('read only: the page still follows the people who write, and says nothing about saving', async () => {
    const { server, writer, reader } = await readerAndWriter();
    reader.drafts.set(UUID, { json: JSON.stringify(doc('an old draft')), baseVersion: 1 });
    const before = reader.words().length;

    writer.type('W1');
    await writer.debounce();
    await writer.land();
    assert.equal(server.text(), 'W1');

    await reader.cycle();
    assert.equal(reader.text(), 'W1', 'their words reach the reader');
    assert.equal(reader.view.baseVersion, 2);
    assert.equal(reader.sent.length, 0);
    assert.equal(reader.words().length, before, 'following says nothing');
    assert.ok(reader.drafts.has(UUID), 'a draft in this browser is not this page to delete');
    assert.ok(!reader.draftCalls.includes('saveBase'), 'no base row is written');
    assert.equal(reader.host().syncState(), 'clean');

    writer.type('W2');
    await writer.debounce();
    await writer.land();
    await reader.cycle();
    assert.equal(reader.text(), 'W2');
});

test('read only: a draft of an earlier visit is neither restored nor deleted nor parked', async () => {
    const w = world();
    const reader = w.tab('R', { readOnly: true });
    reader.drafts.set(UUID, { json: JSON.stringify(doc('typed when I could edit')), baseVersion: 1 });
    await reader.open();

    assert.equal(reader.text(), 'start');
    assert.equal(reader.drafts.get(UUID).baseVersion, 1);
    assert.equal(reader.parks().length, 0);
    assert.deepEqual(reader.draftCalls, [], 'the draft store is not touched at all');
    assert.notEqual(reader.engine, null, 'the engine still starts: the reader follows');
    assert.equal(reader.polls.length, 1, 'and polls');
});

test('read only: nothing the page does for a writer reaches the editor (a suggestion, Keep mine, Load theirs, Put it back)', async () => {
    const { reader } = await readerAndWriter();

    reader.host().applySuggestion(doc('suggested'), 5);
    reader.host().keepMine();
    await reader.host().loadTheirs();
    reader.host().putBack();
    await settle();

    assert.equal(reader.text(), 'start');
    assert.equal(reader.sent.length, 0);
});

test('read only: when following is stopped the word does not claim a save failed', async () => {
    const { reader } = await readerAndWriter();
    await reader.fireTimer();
    await reader.pollAnswers(403, { message: 'x' });

    assert.equal(reader.view.syncNotice, 'You no longer have access to this document.');
    assert.notEqual(reader.word(), 'Not saved');
});

test('a save request is recognised in the Livewire payload, and nothing else is', async () => {
    const { reader } = await readerAndWriter();
    const host = reader.host();

    assert.equal(host.isSaveRequest(SAVE_REQUEST), true);
    assert.equal(host.isSaveRequest(JSON.stringify({ components: [{ calls: [{ method: 'refreshPresence' }] }] })), false);
    assert.equal(host.isSaveRequest(JSON.stringify({ components: [{ calls: [{ method: 'saveTitle' }] }] })), false);
    assert.equal(host.isSaveRequest(JSON.stringify({ components: [{ updates: {} }] })), false);
    assert.equal(host.isSaveRequest('not json'), false);
    assert.equal(host.isSaveRequest(undefined), false);
    // A save that travels with another action in one request (a Move beside an autosave) is still a save.
    assert.equal(
        host.isSaveRequest(
            JSON.stringify({ components: [{ calls: [{ method: 'moveTo', params: [3] }, { method: 'saveContent', params: [] }] }] })
        ),
        true
    );
    // The cheap test comes first: a payload that never says saveContent is not even parsed.
    let parsed = 0;
    const real = JSON.parse;
    JSON.parse = (...args) => {
        parsed += 1;

        return real(...args);
    };
    try {
        host.isSaveRequest(JSON.stringify({ components: [{ calls: [{ method: 'refreshPresence' }] }] }));
    } finally {
        JSON.parse = real;
    }
    assert.equal(parsed, 0);
});

test('a refused save (403) turns an editing page into a read only one, and keeps nothing waiting', async () => {
    const { writer } = await readerAndWriter();
    writer.type('W1');
    await writer.debounce();
    assert.equal(writer.saves.length, 1);

    const handled = writer.host().saveRefused(403);
    await settle();

    assert.equal(handled, true, 'the caller keeps Livewire from showing its error page');
    assert.equal(writer.canEditCalls, 1, 'the server is asked before the page is locked');
    assert.equal(writer.view.editable, false);
    assert.equal(writer.handle.readOnly, true);
    assert.equal(writer.view.saving, 0, 'the save that was refused is no longer awaited');
    assert.equal(writer.view.resave, false);
    assert.equal(writer.view.unsaved, false);
    assert.deepEqual(writer.reports.at(-1), { tone: 'idle', word: 'Read only' });
    assert.equal(writer.presenceRefreshes > 0, true, 'the page is rendered again, so the server-drawn controls follow');

    // Nothing more is ever sent from here.
    writer.type('W2');
    await writer.debounce();
    await writer.cycle();
    writer.view.resave = true;
    writer.host().resendIfOwed();
    assert.equal(writer.sent.length, 1);
});

test('a refused save keeps the unsent text: parked in this browser, kept in the draft, and said so', async () => {
    const { writer } = await readerAndWriter();
    writer.type('words nobody has stored');
    await writer.debounce();
    assert.equal(writer.draft(), 'words nobody has stored');

    writer.host().saveRefused(403);
    await settle();

    assert.equal(writer.view.unsentKept, true);
    assert.equal(writer.parks().length, 1);
    assert.equal(textOf(JSON.parse(writer.parks()[0].json)), 'words nobody has stored');
    assert.equal(writer.parks()[0].offerable, false, 'a copy for recovery by hand, not an offer');
    assert.equal(writer.draft(), 'words nobody has stored', 'the draft is not cleared');
    assert.equal(writer.text(), 'words nobody has stored', 'and the page still shows it');
});

test('a refused save with nothing unsent claims nothing is kept', async () => {
    const { writer } = await readerAndWriter();

    writer.host().saveRefused(403);
    await settle();

    assert.equal(writer.view.unsentKept, false);
    assert.equal(writer.parks().length, 0);
});

test('a refused save keeps the reader following, with the typed text safe in the park', async () => {
    const { server, writer } = await readerAndWriter();
    const other = server;
    writer.type('mine, refused');
    await writer.debounce();
    writer.host().saveRefused(403);
    await settle();

    other.version += 1;
    other.json = docOf(para('p1', 'theirs'));
    await writer.cycle();

    assert.equal(writer.text(), 'theirs', 'the page now shows what is stored');
    assert.equal(writer.parks().length, 1, 'and the words that were not stored are still kept');
});

test('only a 403 on a save makes the page read only', async () => {
    const { writer } = await readerAndWriter();

    for (const status of [0, 401, 404, 419, 422, 500, 503, undefined]) {
        assert.equal(writer.host().saveRefused(status), false, String(status));
    }
    assert.equal(writer.canEditCalls, 0);
    assert.equal(writer.view.editable, true);
    assert.equal(writer.handle.readOnly, false);
});

test('a refusal on a page that is already read only changes nothing', async () => {
    const { reader } = await readerAndWriter();
    const reports = reader.reports.length;

    assert.equal(reader.host().saveRefused(403), true);
    await settle();
    assert.equal(reader.canEditCalls, 0, 'nothing is asked of a page that is already read only');
    assert.equal(reader.reports.length, reports);
    assert.equal(reader.parks().length, 0);
});

// ───────────────────────────────────────────── a 403 on a save is provisional

/** A writer whose save has just come back 403, the server not yet asked about it. */
async function refusedWriter(options = {}) {
    const w = await readerAndWriter();
    const { writer } = w;
    Object.assign(writer, options);
    writer.type('words in the air');
    await writer.debounce();
    assert.equal(writer.saves.length, 1);

    return w;
}

test('a 403 the server does not stand behind (a firewall, a refused Move beside the save) leaves the editor editable and sends the text again', async () => {
    const { server, writer } = await refusedWriter({ canEditAnswer: true });

    assert.equal(writer.host().saveRefused(403), true);
    await settle();

    assert.equal(writer.canEditCalls, 1);
    assert.equal(writer.view.editable, true);
    assert.equal(writer.handle.readOnly, false, 'the editor is not made read only');
    assert.equal(writer.view.unsentKept, false, 'and no sentence says otherwise');
    assert.ok(!writer.words().includes('Read only'));
    assert.equal(writer.view.accessCheckSince, 0);
    assert.equal(writer.sent.length, 2, 'the owed save went out again');
    assert.equal(writer.sent[1].text, 'words in the air');

    await writer.land();
    assert.equal(server.text(), 'words in the air');
    assert.equal(writer.word(), 'Saved');
    assert.equal(writer.view.unsaved, false);
});

test('while the server is being asked, nothing is sent, and what is typed meanwhile is owed', async () => {
    const { writer } = await refusedWriter({ canEditMode: 'hang' });

    writer.host().saveRefused(403);
    await settle();
    writer.type('and a little more');
    await writer.debounce();
    await writer.cycle();

    assert.equal(writer.sent.length, 1, 'no second save while the question is out');
    assert.equal(writer.view.resave, true);
    assert.equal(writer.view.editable, true);

    writer.canEditPending.resolve(true);
    await settle();
    await writer.land();

    assert.equal(writer.sent.at(-1).text, 'and a little more', 'the newest text goes out once the server has said yes');
    assert.equal(writer.view.editable, true);
});

test('a 403 whose save was STORED after all is settled by the ordinary rules: the text is not saved twice and the word ends on Saved', async () => {
    const { server, writer } = await refusedWriter({ canEditAnswer: true });
    writer.saveArrives();
    writer.saveNeverAnswers(); // the request was refused as a whole: its promise never settles
    const versionAfterStore = server.version;
    assert.equal(server.text(), 'words in the air');

    writer.host().saveRefused(403);
    await settle();
    await writer.land();
    await writer.cycle();
    await writer.land();

    assert.equal(server.text(), 'words in the air');
    assert.equal(writer.text(), 'words in the air');
    assert.equal(writer.view.unsaved, false);
    assert.equal(writer.word(), 'Saved');
    assert.equal(writer.view.conflict, null);
    assert.ok(server.version <= versionAfterStore + 1, 'at most one more version, and the words are not duplicated');
});

test('a 403 beside a Move: the refusal belongs to the Move, the server says yes, the writer is never locked out', async () => {
    const { writer } = await refusedWriter({ canEditAnswer: true });
    const bundled = JSON.stringify({ components: [{ calls: [{ method: 'saveContent' }, { method: 'moveTo', params: [3] }] }] });

    assert.equal(writer.host().isSaveRequest(bundled), true);
    assert.equal(writer.host().saveRefused(403), true);
    await settle();

    assert.equal(writer.view.editable, true);
    assert.equal(writer.handle.readOnly, false);
    assert.equal(writer.sent.length, 2);
});

test('the words stay safe whichever way the server answers: parked and drafted before the question is asked', async () => {
    for (const canEditAnswer of [true, false]) {
        const { writer } = await refusedWriter({ canEditAnswer });
        writer.host().saveRefused(403);
        // Before the answer is read: the copy is already in the park, the draft is untouched.
        assert.equal(writer.parks().length, 1, `answer ${canEditAnswer}`);
        assert.equal(textOf(JSON.parse(writer.parks()[0].json)), 'words in the air');
        assert.equal(writer.draft(), 'words in the air');
        await settle();
        assert.equal(writer.parks().length, 1);
        assert.equal(writer.draft() === 'words in the air' || writer.draft() === null, true);
    }
});

test('a question the server could not answer is a failed request: Not saved, held back, sent again after the back-off', async () => {
    const { writer } = await refusedWriter({ canEditMode: 'fail' });

    writer.host().saveRefused(403);
    await settle();

    assert.equal(writer.view.editable, true, 'not locked out by a question that failed');
    assert.ok(writer.words().includes('Not saved'), 'said so');
    assert.equal(writer.view.saveFailures, 1, 'counted as a failed request');
    // The first failure is held for no time at all (SAVE_RETRY_DELAYS_MS): the text goes again at once.
    assert.equal(writer.sent.length, 2);
    assert.equal(writer.sent[1].text, 'words in the air');
});

test('a question that never answers ends with the save wait: the text is sent again at the next poll', async () => {
    const { writer, clock } = await refusedWriter({ canEditMode: 'hang' });

    writer.host().saveRefused(403);
    await settle();
    assert.equal(writer.sent.length, 1);

    clock.now += SAVE_EXPIRY_MS + 1;
    await writer.cycle();
    await settle();

    assert.equal(writer.sent.length, 2, 'the hold is over');
    assert.equal(writer.view.editable, true);
});

test('a second 403 while the question is out does not ask again, and a late answer of an old question is ignored', async () => {
    const { writer, clock } = await refusedWriter({ canEditMode: 'hang' });

    writer.host().saveRefused(403);
    writer.host().saveRefused(403);
    await settle();
    assert.equal(writer.canEditCalls, 1);

    const first = writer.canEditPending;
    // The first question runs out, a newer save is refused, a second question is asked.
    clock.now += SAVE_EXPIRY_MS + 1;
    writer.host().saveRefused(403);
    await settle();
    assert.equal(writer.canEditCalls, 2);

    first.resolve(false);
    await settle();
    assert.equal(writer.view.editable, true, 'the answer to a question that was replaced does nothing');
});

test('read only for real: the rows that offer text back are removed, the text stays parked, and the notice says words are kept', async () => {
    const { writer } = await refusedWriter({ canEditAnswer: false });
    writer.view.lost = [{ excerpt: 'a lost block', json: {}, anchors: [] }];
    writer.view.setAside = JSON.stringify(doc('set aside'));
    writer.view.setAsideFrom = 'conflict';
    writer.view.setAsideBase = 1;
    writer.view.parkOfferPending = true;

    writer.host().saveRefused(403);
    await settle();

    assert.equal(writer.view.editable, false);
    assert.deepEqual(writer.view.lost, []);
    assert.equal(writer.view.setAside, null);
    assert.equal(writer.view.setAsideFrom, null);
    assert.equal(writer.view.setAsideBase, null);
    assert.equal(writer.view.parkOfferPending, false);
    assert.equal(writer.view.unsentKept, true);
    assert.equal(writer.parks().length, 1);
});

test('a re-render that draws editable false makes the page say so: the same read only state, the typed words parked', async () => {
    const { writer } = await readerAndWriter();
    writer.type('typed before the render');
    await writer.debounce();
    writer.handle.makeReadOnly();

    writer.host().readOnlyNoticed();

    assert.equal(writer.view.editable, false);
    assert.equal(writer.view.unsentKept, true);
    assert.deepEqual(writer.reports.at(-1), { tone: 'idle', word: 'Read only' });
    assert.equal(writer.parks().length, 1);
    assert.equal(writer.canEditCalls, 0, 'the server already drew its answer');

    const reports = writer.reports.length;
    writer.host().readOnlyNoticed();
    assert.equal(writer.reports.length, reports, 'said once');
});

test('a column layout reads right in every lost-row sentence', async () => {
    const { A } = await pair();
    const word = blockWord('columns');
    const sentences = ['both', 'deleted'].flatMap((reason) =>
        ['merge', 'draft', 'suggestion'].map((source) => A.host().sentenceFor(reason, source, word))
    );

    assert.deepEqual(sentences, [
        'This column layout was changed elsewhere at the same moment. The other version is on the page; this is yours.',
        'This column layout was changed in the document after your draft was written. The document\'s version is on the page; this is the draft\'s.',
        'This column layout was rewritten by the accepted suggestion. The suggestion\'s version is on the page; this is yours.',
        'This column layout was removed elsewhere while you were typing in it.',
        'This column layout was removed from the document after your draft was written.',
        'The accepted suggestion removed this column layout while you were typing in it.',
    ]);
    for (const sentence of sentences) {
        assert.ok(!/columns/.test(sentence), sentence);
    }
});

// ───────────────────────────────────────────────────────── the role arrives with the poll

test('a poll that says can_edit false turns an editing page into the read only page, words parked, without asking the server again', async () => {
    const { server, writer } = await readerAndWriter();
    writer.type('typed before the downgrade');
    await writer.debounce();
    writer.saves.shift().reject(new Error('network'));
    await settle();

    server.canEditByTab.set('W', false);
    await writer.cycle();

    assert.equal(writer.view.editable, false);
    assert.equal(writer.handle.readOnly, true);
    assert.equal(writer.view.unsentKept, true);
    assert.deepEqual(writer.reports.at(-1), { tone: 'idle', word: 'Read only' });
    assert.equal(writer.parks().length, 1);
    assert.equal(textOf(JSON.parse(writer.parks()[0].json)), 'typed before the downgrade');
    assert.equal(writer.canEditCalls, 0, 'the poll is the server answering');
    assert.equal(writer.presenceRefreshes > 0, true, 'the page is rendered again so the server-drawn controls follow');

    // Nothing is sent from here, and the poll keeps following.
    const sent = writer.sent.length;
    writer.type('and more');
    await writer.debounce();
    await writer.cycle();
    assert.equal(writer.sent.length, sent);
});

test('a page that cannot be downgraded twice: a second can_edit false is said once', async () => {
    const { server, writer } = await readerAndWriter();
    server.canEditByTab.set('W', false);
    await writer.cycle();
    const reports = writer.reports.length;
    const parks = writer.parks().length;

    await writer.cycle();
    assert.equal(writer.reports.length, reports);
    assert.equal(writer.parks().length, parks);
});

test('can_edit true on a read only page does not re-enable it: a reload does that', async () => {
    const { server, writer } = await readerAndWriter();
    server.canEditByTab.set('W', false);
    await writer.cycle();
    assert.equal(writer.view.editable, false);

    server.canEditByTab.set('W', true);
    await writer.cycle();
    assert.equal(writer.view.editable, false);
    assert.equal(writer.handle.readOnly, true);
    assert.deepEqual(writer.reports.at(-1), { tone: 'idle', word: 'Read only' });

    // A page the server drew read only stays so as well.
    const { server: other, reader } = await readerAndWriter();
    other.canEditByTab.set('R', true);
    await reader.cycle();
    assert.equal(reader.view.editable, false);
    assert.equal(reader.handle.readOnly, true);
});

test('a page with no can_edit in the answer behaves as before: an old server changes nothing', async () => {
    const { writer } = await readerAndWriter();
    writer.type('W1');
    await writer.debounce();
    await writer.land();
    await writer.cycle();

    assert.equal(writer.view.editable, true);
    assert.equal(writer.handle.readOnly, false);
    assert.equal(writer.view.unsaved, false);
});

test('can_edit true answers the question a refused save asked: the hold is lifted and the text goes again', async () => {
    const { server, writer } = await refusedWriter({ canEditMode: 'hang' });
    writer.host().saveRefused(403);
    await settle();
    assert.ok(writer.view.accessCheckSince > 0, 'the page is waiting for the server to say');
    assert.equal(writer.view.editable, true);

    server.canEditByTab.set('W', true);
    await writer.cycle();

    assert.equal(writer.view.accessCheckSince, 0);
    assert.equal(writer.view.editable, true);
    assert.equal(writer.handle.readOnly, false);
    assert.equal(writer.sent.length, 2, 'the owed text went out again');
    assert.equal(writer.sent[1].text, 'words in the air');
});

test('can_edit false answers the question a refused save asked: read only at once, and the text is not parked twice', async () => {
    const { server, writer } = await refusedWriter({ canEditMode: 'hang' });
    writer.host().saveRefused(403);
    await settle();
    assert.equal(writer.parks().length, 1, 'parked when the save was refused');

    server.canEditByTab.set('W', false);
    await writer.cycle();

    assert.equal(writer.view.editable, false);
    assert.equal(writer.view.accessCheckSince, 0);
    assert.equal(writer.view.unsentKept, true);
    assert.equal(writer.parks().length, 1);

    // The question that was still out answers late: nothing happens.
    writer.canEditPending.resolve(true);
    await settle();
    assert.equal(writer.view.editable, false);
});

test('accessFromPoll takes only a real boolean: anything else changes nothing', async () => {
    const { writer } = await readerAndWriter();
    const refreshes = writer.presenceRefreshes;
    for (const odd of [undefined, null, 'false', 0, '', {}]) {
        writer.host().accessFromPoll(odd);
    }

    assert.equal(writer.view.editable, true);
    assert.equal(writer.handle.readOnly, false);
    assert.equal(writer.presenceRefreshes, refreshes);

    // Not even the question a refused save left out is answered by something that is not a boolean.
    const held = await refusedWriter({ canEditMode: 'hang' });
    held.writer.host().saveRefused(403);
    await settle();
    const since = held.writer.view.accessCheckSince;
    assert.ok(since > 0);
    held.writer.host().accessFromPoll('true');
    held.writer.host().accessFromPoll(1);
    assert.equal(held.writer.view.accessCheckSince, since);
});

test('can_edit false on a fail-closed page is the same read only word, and nothing is parked or written', async () => {
    const w = world();
    const A = w.tab('A', { failClosed: true });
    await A.open();
    w.server.canEditByTab.set('A', false);
    await A.cycle();

    assert.equal(A.parks().length, 0);
    assert.deepEqual(A.drafts.size, 0);
    assert.equal(A.sent.length, 0);
});

test('read only: a signed-out stop does not tell a reader to keep editing', async () => {
    const { reader, writer } = await readerAndWriter();

    reader.host().engineHost().onStopped('signed-out');
    writer.host().engineHost().onStopped('signed-out');

    assert.equal(reader.view.syncNotice, 'You have been signed out. Reload the page to sign in again.');
    assert.equal(writer.view.syncNotice, 'You have been signed out. Reload the page to keep editing.');
});
