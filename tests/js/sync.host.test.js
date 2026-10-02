import assert from 'node:assert/strict';
import test from 'node:test';

import { createSyncEngine } from '../../resources/js/editor/sync/engine.js';
import {
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    SAVE_EXPIRY_MS,
    createSyncHost,
    createTabId,
    unloadSaveBody,
} from '../../resources/js/editor/sync/host.js';

/**
 * The page's half of the sync state machine (sync/host.js), driven together
 * with the REAL polling engine (sync/engine.js) against fakes for everything
 * outside those two:
 *
 *   - a fake server that keeps a document, a version and the versions it was
 *     told to keep, and answers saves as Editor::saveContent() does
 *     ({ok, conflict, version}) and polls as the sync endpoint does;
 *   - a fake editor handle that follows the bundle's own rules for `pending`
 *     (the debounce is armed or a flush is owed) and for applyRemote() (it
 *     refuses over unsaved typing unless forced);
 *   - a fake draft store, an injected clock and a captured timer;
 *   - saves and polls that are answered BY HAND, so "a save in the air" is a
 *     state a test can hold for as long as it needs;
 *   - `leave()`, the page going away: the bundle's pagehide flush, which
 *     goes by beacon to the fake server's autosave endpoint, after which
 *     nothing answers that tab any more.
 *
 * What stands in for the Blade component is `view`, a plain object with the
 * component's state fields, and `open()` / `startSync()`, which make the same
 * calls the component's init() makes. Every call builds its host from the view
 * afresh, exactly as the component's one-line delegates do.
 */

/** Let every pending promise callback run. */
const settle = () => new Promise((resolve) => setImmediate(resolve));
const clone = (value) => JSON.parse(JSON.stringify(value));
const doc = (text) => ({ type: 'doc', text });
const UUID = 'doc-uuid';
const STALE = `stale-${UUID}`;

class FakeServer {
    constructor(text) {
        this.version = 1;
        this.json = doc(text);
        /** Versions kept in the history before being replaced on purpose. */
        this.history = [];
        this.present = new Map();
        /** The next save is rejected, as DocumentSchema rejects a bad document. */
        this.rejectNext = false;
    }

    /** Editor::saveContent($content, $baseVersion, $overwrite). */
    save(json, base, overwrite = false) {
        if (this.rejectNext) {
            this.rejectNext = false;

            return { ok: false, conflict: false, version: this.version };
        }

        if (base === null || base === undefined) {
            return { ok: false, conflict: false, version: this.version };
        }

        if (base !== this.version) {
            return { ok: false, conflict: true, version: this.version };
        }

        if (overwrite) {
            this.history.push({ version: this.version, text: this.json.text });
        }

        this.version += 1;
        this.json = clone(json);

        return { ok: true, conflict: false, version: this.version };
    }

    /**
     * POST /documents/{uuid}/autosave, the unload beacon's endpoint
     * (DocumentAutosaveController): the same save, stated in a JSON body.
     */
    autosave(body) {
        const valid =
            body &&
            body.content &&
            body.content.type === 'doc' &&
            Number.isInteger(body.base_version) &&
            body.base_version >= 1 &&
            // Laravel's `boolean` rule, when the field is there at all.
            (!('overwrite' in body) || [true, false, 1, 0, '1', '0'].includes(body.overwrite));
        if (!valid) {
            return { status: 422, body: { message: 'The given data was invalid.' } };
        }

        const result = this.save(body.content, body.base_version, [true, 1, '1'].includes(body.overwrite));
        if (result.ok) {
            return { status: 200, body: { version: result.version } };
        }

        return result.conflict
            ? { status: 409, body: { conflict: true, version: result.version } }
            : { status: 422, body: { message: 'The given data was invalid.' } };
    }

    /** A save that states no base: a style change, an accepted suggestion. */
    replace(json) {
        this.version += 1;
        this.json = clone(json);

        return this.version;
    }

    /** POST /documents/{uuid}/sync. */
    sync(payload) {
        this.present.set(payload.tab, true);

        const body = {
            version: this.version,
            changed: false,
            members: [...this.present.keys()].map((_, index) => ({ id: index + 1 })),
            others: this.present.size - 1,
        };

        if (this.version > payload.version) {
            body.changed = true;
            body.json = clone(this.json);
            body.outline = { numbers: {}, of: this.version };
            body.css = `.paper{--v:${this.version}}`;
        }

        return { status: 200, body };
    }
}

/** The state fields of the Blade component, as a page rendered at `version` has them. */
const makeView = (version) => ({
    saving: 0,
    savingSince: 0,
    unsaved: false,
    resave: false,
    confirmed: null,
    overwriteOwed: false,
    conflict: null,
    setAside: null,
    setAsideFrom: null,
    setAsideBase: null,
    syncNotice: '',
    memberKey: '',
    docUuid: UUID,
    documentVersion: version,
    baseVersion: version,
    aiError: '',
    tick: 0,
});

function world(text = 'start') {
    const server = new FakeServer(text);
    const clock = { now: 1_000_000 };

    return { server, clock, tab: (name, options) => makeTab({ server, clock }, name, options) };
}

/**
 * One open editor tab.
 *
 * @param {{server: FakeServer, clock: {now: number}}} world
 * @param {string} name
 * @param {{failClosed?: boolean, drafts?: Map, draftStore?: boolean, confirmAnswer?: boolean}} options
 */
function makeTab({ server, clock }, name, options = {}) {
    const t = {
        name,
        view: makeView(server.version),
        /** Saves in the air, oldest first. */
        saves: [],
        /** Every save this tab has sent. */
        sent: [],
        /** Every unload beacon this tab has sent, with the server's answer (which no page ever reads). */
        beacons: [],
        /** Polls in the air, oldest first. */
        polls: [],
        /** The payload of every poll this tab has sent. */
        polled: [],
        timer: null,
        engine: null,
        visible: true,
        reports: [],
        infos: [],
        errors: [],
        outlines: [],
        css: [],
        questions: [],
        confirmAnswer: options.confirmAnswer ?? true,
        presenceRefreshes: 0,
        outlineRefreshes: 0,
        /** What the page's outline refresh does; a test swaps it to make it fail. */
        refreshOutline: () => Promise.resolve(),
        /** The browser's draft store. Shared between two page loads of one browser. */
        drafts: options.drafts ?? new Map(),
        draftCalls: [],
        applyCalls: 0,
        /** This editor cannot open what arrives. */
        refuses: false,
        setContentThrows: false,
        mounted: true,
    };

    // ---- the editor handle, with the bundle's own bookkeeping
    let content = clone(server.json);
    let dirty = false;
    let saveTimer = null;
    let lastSaved = JSON.stringify(content);
    const autosave = !options.failClosed;
    let onUpdate = () => {};

    const emitUpdate = () => {
        if (autosave) {
            dirty = true;
            saveTimer = 'armed';
        }
        onUpdate();
    };

    const flushSave = ({ beacon = false } = {}) => {
        saveTimer = null;
        if (!dirty || !autosave) {
            return false;
        }

        const json = clone(content);
        const serialised = JSON.stringify(json);
        if (serialised === lastSaved) {
            dirty = false;

            return false;
        }

        dirty = false;
        lastSaved = serialised;

        if (beacon) {
            // navigator.sendBeacon(): the browser delivers the body after
            // the page has gone. Built by the function the bundle's
            // beaconSave() builds it with, from the mount options the Blade
            // component passes: both are read now, at the moment of leaving.
            const body = clone(
                unloadSaveBody(json, {
                    csrfToken: 'csrf',
                    getBaseVersion: () => t.view.baseVersion,
                    getOverwrite: () => t.view.overwriteOwed,
                })
            );
            t.beacons.push({ body, answer: server.autosave(body) });

            return true;
        }

        // The bundle's onChange, which the page wires to persist().
        t.host().persist(json);

        return true;
    };

    t.handle = {
        editor: {
            getJSON: () => clone(content),
            commands: {
                setContent(json) {
                    if (t.setContentThrows) {
                        throw new Error('invalid content');
                    }
                    content = clone(json);
                    emitUpdate();

                    return true;
                },
            },
        },
        get autosaves() {
            return autosave;
        },
        get pending() {
            return dirty || saveTimer !== null;
        },
        applyRemote(json, { force = false } = {}) {
            t.applyCalls += 1;
            if (!json || !autosave || t.refuses) {
                return false;
            }
            if (!force && (dirty || saveTimer !== null)) {
                return false;
            }
            content = clone(json);
            saveTimer = null;
            dirty = false;
            lastSaved = JSON.stringify(content);

            return true;
        },
    };

    // ---- the browser's draft store (window.offlineDraft)
    t.draftStore =
        options.draftStore === false
            ? undefined
            : {
                  saveDraft: async (uuid, json, baseVersion) => {
                      t.draftCalls.push('saveDraft');
                      t.drafts.set(uuid, { json, baseVersion });
                  },
                  loadDraft: async (uuid) => {
                      t.draftCalls.push('loadDraft');

                      return t.drafts.get(uuid) ?? null;
                  },
                  clearDraft: async (uuid) => {
                      t.draftCalls.push('clearDraft');
                      t.drafts.delete(uuid);
                  },
                  parkStaleDraft: async (uuid, json, baseVersion) => {
                      t.draftCalls.push('parkStaleDraft');
                      t.drafts.set(`stale-${uuid}`, { json, baseVersion });
                  },
              };

    // ---- everything the page hands the module
    t.env = {
        handle: () => (t.mounted ? t.handle : null),
        engine: () => t.engine,
        save: (json, baseVersion, overwrite) =>
            new Promise((resolve, reject) => {
                const save = { json: clone(json), base: baseVersion, overwrite, resolve, reject, result: undefined };
                t.saves.push(save);
                t.sent.push(save);
            }),
        refreshOutline: () => {
            t.outlineRefreshes += 1;

            return t.refreshOutline();
        },
        refreshPresence: () => {
            t.presenceRefreshes += 1;
        },
        report: (tone, word) => t.reports.push({ tone, word }),
        drafts: () => t.draftStore,
        documentsDiffer: (a, b) => JSON.stringify(a) !== JSON.stringify(b),
        showOutline: (outline) => t.outlines.push(outline),
        showCss: (css) => t.css.push(css),
        confirm: (question) => {
            t.questions.push(question);

            return t.confirmAnswer;
        },
        now: () => clock.now,
        info: (...args) => t.infos.push(args.join(' ')),
        log: (...args) => t.errors.push(args),
    };

    /** Built afresh for every call, as the component's delegates do. */
    t.host = () => createSyncHost(t.view, t.env);

    // ---- what the component's init() does
    t.startSync = () => {
        if (t.engine) {
            return;
        }

        t.engine = createSyncEngine({
            version: t.view.baseVersion,
            tab: name,
            request: (payload) =>
                new Promise((resolve, reject) => {
                    t.polls.push({ payload, resolve, reject });
                    t.polled.push(payload);
                }),
            visible: () => t.visible,
            host: t.host().engineHost(),
            setTimer: (fn, ms) => {
                t.timer = { fn, ms };

                return t.timer;
            },
            clearTimer: (id) => {
                if (t.timer === id) {
                    t.timer = null;
                }
            },
        });
        t.engine.start();
    };

    /** The editor is mounted and the page listens to its updates. */
    t.mount = () => {
        t.host().opened();
        onUpdate = () => t.host().edited();
    };
    t.open = async () => {
        t.mount();
        t.host()
            .restoreDraft()
            .finally(() => t.startSync());
        await settle();
    };

    // ---- what a person does
    t.type = (text) => {
        content = { ...content, text };
        emitUpdate();
    };
    /** The bundle's 1.2 second debounce fires. */
    t.debounce = async () => {
        flushSave();
        await settle();
    };
    /**
     * The tab is closed, or the writer follows a link: pagehide flushes what
     * the debounce still holds by beacon. The page is gone afterwards. A
     * save already in the air may still reach the server (saveArrives()),
     * but its answer reaches nobody, and the draft store stays as it is.
     */
    t.leave = () => {
        flushSave({ beacon: true });
    };

    // ---- what the network does
    /** The oldest save that has not reached the server reaches it. */
    t.saveArrives = () => {
        const save = t.saves.find((candidate) => candidate.result === undefined);
        save.result = server.save(save.json, save.base, save.overwrite);
    };
    /** The oldest save's answer reaches the tab. */
    t.saveReturns = async () => {
        const save = t.saves.shift();
        save.resolve(save.result);
        await settle();
    };
    /** Every save in the air reaches the server and is answered, the ones that causes included. */
    t.land = async () => {
        let landed = 0;
        while (t.saves.length > 0) {
            landed += 1;
            assert.ok(landed <= 20, 'the tab keeps sending saves and never comes to rest');
            t.saveArrives();
            await t.saveReturns();
        }
    };
    /** The oldest save's answer never comes. Returned, so a test can let it answer late. */
    t.saveNeverAnswers = () => t.saves.shift();
    /** The oldest poll in the air is answered by the server. */
    t.poll = async () => {
        const poll = t.polls.shift();
        poll.resolve(server.sync(poll.payload));
        await settle();
    };
    /** The oldest poll in the air is answered with this instead. */
    t.pollAnswers = async (status, body) => {
        t.polls.shift().resolve({ status, body });
        await settle();
    };
    /** The oldest poll in the air never reaches the server: the request itself fails. */
    t.pollFails = async () => {
        t.polls.shift().reject(new Error('network'));
        await settle();
    };
    t.fireTimer = async () => {
        const { fn } = t.timer;
        t.timer = null;
        fn();
        await settle();
    };
    /** One full poll: the timer fires (unless a poll is already in the air) and the server answers. */
    t.cycle = async () => {
        if (t.polls.length === 0) {
            await t.fireTimer();
        }
        await t.poll();
    };

    // ---- what a test reads
    t.text = () => content.text;
    t.word = () => t.reports.at(-1)?.word;
    t.words = () => t.reports.map((report) => report.word);
    t.draft = () => (t.drafts.has(UUID) ? JSON.parse(t.drafts.get(UUID).json).text : null);
    t.stale = () => (t.drafts.has(STALE) ? JSON.parse(t.drafts.get(STALE).json).text : null);

    return t;
}

/** Two people with the same document open, each already told the other is here. */
async function pair(options = {}) {
    const w = world();
    const A = w.tab('A', options.A);
    const B = w.tab('B', options.B);
    await A.open();
    await B.open();
    await A.poll();
    await B.poll();
    await A.cycle();

    return { ...w, A, B };
}

/**
 * Both typed at the same moment. A's save landed first (version 2); B heard
 * of it through its poll while still inside its debounce, and now shows the
 * notice. B's own autosave has fired since and was not sent.
 */
async function conflicted(options = {}) {
    const w = await pair(options);
    const { A, B } = w;
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    await B.debounce();

    return w;
}

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
        A.sent.map((save) => [save.json.text, save.base, save.overwrite]),
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

test('a tab with unsaved typing does not follow: the notice is raised, first without the document, then with it', async () => {
    const { server, A, B } = await pair();
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await B.debounce();
    await A.land();

    // B's save is refused. The newer document has not been downloaded yet.
    B.saveArrives();
    await B.saveReturns();
    assert.deepEqual(B.view.conflict, { version: 2, ready: false });
    assert.deepEqual(B.reports.at(-1), { tone: 'danger', word: 'Not saved' });
    assert.equal(B.text(), 'B1');
    assert.equal(B.view.baseVersion, 1);
    assert.equal(B.polls.length, 1, 'the newer document is being fetched');
    assert.equal(B.polls[0].payload.version, 1, 'asked from the base, so the document comes with the answer');

    await B.poll();
    assert.deepEqual(B.view.conflict, { version: 2, ready: true });
    assert.equal(B.text(), 'B1', 'nothing was written over the typing');
    assert.equal(server.json.text, 'A1', 'and nothing was written over the other person');
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
    const { A, B } = await pair();
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
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [['B1', 2, true]]
    );
    assert.notEqual(B.view.conflict, null, 'the notice stays until the save has landed');
    assert.equal(B.engine.pending.version, 2, 'the waiting document stays with the engine in case the save is lost');

    await B.land();
    assert.equal(server.version, 3);
    assert.equal(server.json.text, 'B1');
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
    const { server, A, B } = await pair();
    A.type('A1');
    await A.debounce();
    await A.land();
    B.type('B1');
    await B.cycle();

    B.host().keepMine();
    await B.land();
    assert.equal(server.json.text, 'B1');
    assert.equal(B.view.unsaved, true, 'the debounce is still armed, so the save has not settled');
    assert.equal(B.draft(), 'B1');

    // The debounce fires and finds the same document... which the bundle still sends.
    await B.debounce();
    await B.land();
    assert.equal(B.view.conflict, null);
    assert.equal(B.view.unsaved, false);
    assert.equal(server.json.text, 'B1');
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
    assert.equal(server.json.text, 'A2', 'the first press was refused: version 3 was in the way');
    assert.equal(B.view.conflict.version, 3);
    assert.equal(B.text(), 'B1');

    B.host().keepMine();
    assert.equal(B.sent.at(-1).base, 3);
    await B.land();
    assert.equal(server.json.text, 'B1');
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
    assert.equal(server.json.text, 'B1');
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
    const { A, B } = await pair();
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
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [['B1', 2, true]]
    );
    await B.land();
    assert.equal(server.json.text, 'B1');
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
    const { A, B } = await pair();
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await B.debounce();
    await A.land();
    B.saveArrives();
    await B.saveReturns();
    // The fetch the refusal started is answered by something in front of the application.
    await B.pollAnswers(500, null);
    assert.deepEqual(B.view.conflict, { version: 2, ready: false });
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
    assert.equal(server.json.text, 'B1');
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
    assert.deepEqual(B.questions, []);

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
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [['B1', 3, true]]
    );
    await B.land();
    assert.equal(server.json.text, 'B1');
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
    assert.equal(server.json.text, 'A1 and new work by B');
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
        B.beacons.map((beacon) => [beacon.body.content.text, beacon.body.base_version, beacon.body.overwrite]),
        [['B1', 2, true]]
    );
    assert.equal(B.beacons[0].answer.status, 200);
    assert.equal(server.json.text, 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }], 'the other person version is kept first');
});

test('Keep mine marks the overwrite as owed when it moves the base up, until a save is accepted', async () => {
    const { server, B } = await conflicted();
    assert.equal(B.view.overwriteOwed, false);

    B.host().keepMine();
    assert.equal(B.view.baseVersion, 2);
    assert.equal(B.view.overwriteOwed, true, 'owed from the moment the base states the other person version');

    await B.land();
    assert.equal(server.json.text, 'B1');
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
    assert.equal(server.json.text, 'A1', 'nothing was stored');
    assert.equal(B.view.baseVersion, 2, 'the base stays moved up');
    assert.equal(B.view.overwriteOwed, true);
    assert.equal(B.word(), 'Not saved');

    // The writer types on and leaves. The page itself would not save while
    // the notice stands; the beacon has no such check, and states base 2.
    B.type('B1 and more');
    B.leave();
    assert.deepEqual(
        B.beacons.map((beacon) => [beacon.body.content.text, beacon.body.base_version, beacon.body.overwrite]),
        [['B1 and more', 2, true]]
    );
    assert.equal(server.json.text, 'B1 and more');
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
    assert.equal(server.json.text, 'B1 and more');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('a Keep mine save that did land before the beacon: the beacon is refused and the version is kept once', async () => {
    const { server, B } = await conflicted();
    B.host().keepMine();
    B.type('B1 and more');
    B.saveArrives();
    B.leave();

    assert.equal(B.beacons[0].answer.status, 409, 'the base it states is no longer current: nothing is overwritten blind');
    assert.equal(server.json.text, 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('after Keep mine the editor being back at the old confirmed document settles nothing: the base no longer names it', async () => {
    const { server, A, B } = await pair();
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
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [
            ['start', 2, true],
            ['start', 3, true],
        ]
    );
    await B.land();
    assert.equal(server.json.text, 'start');
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
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [
            ['B1', 2, true],
            ['B1', 3, true],
        ],
        'the retry replaces the other person text too, and says so'
    );

    await B.land();
    assert.equal(server.json.text, 'B1');
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
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [
            ['B1', 2, true],
            ['B1 and more', 3, true],
        ],
        'not the Keep mine save itself, and it replaces the other person text all the same'
    );
    await B.land();
    assert.equal(server.json.text, 'B1 and more');
    assert.deepEqual(server.history, [{ version: 3, text: 'A1' }]);
    assert.equal(B.view.overwriteOwed, false);
});

test('Put it back while an ordinary save is in the air: that save answering does not write the overwrite off', async () => {
    const { server, B } = await conflicted();
    await B.host().loadTheirs();
    B.type('A1 plus an edit');
    await B.debounce();
    assert.deepEqual([B.sent[0].json.text, B.sent[0].base, B.sent[0].overwrite], ['A1 plus an edit', 2, false]);

    // Put it back before that save has answered (the page asks first: the
    // text on it is not saved yet).
    B.host().putBack();
    assert.equal(B.questions.length, 1);
    assert.equal(B.view.overwriteOwed, true);

    // The ordinary save is accepted. It did not carry the overwrite, so its
    // answer does not settle it.
    await B.land();
    assert.equal(server.json.text, 'A1 plus an edit');
    assert.equal(B.view.overwriteOwed, true);

    await B.debounce();
    assert.deepEqual(
        B.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [
            ['A1 plus an edit', 2, false],
            ['B1', 3, true],
        ]
    );
    await B.land();
    assert.equal(B.view.overwriteOwed, false);
    assert.equal(server.json.text, 'B1');
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
        again.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [['B1', 2, true]]
    );
    await again.land();
    assert.equal(server.json.text, 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

test('Keep mine fails, the writer types on, the page dies: reopened, the text is set aside and its save overwrites', async () => {
    const { server, clock, B } = await conflicted();
    server.rejectNext = true;
    B.host().keepMine();
    await B.land();
    B.type('B1 and more');
    assert.equal(B.drafts.get(UUID).baseVersion, 1);

    const again = makeTab({ server, clock }, 'B2', { drafts: B.drafts });
    await again.open();
    await again.poll();
    assert.deepEqual(again.questions, []);
    assert.equal(again.text(), 'A1');
    assert.equal(again.view.setAside, JSON.stringify(doc('B1 and more')));

    again.host().putBack();
    await again.debounce();
    assert.deepEqual(
        again.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [['B1 and more', 2, true]]
    );
    await again.land();
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
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
    assert.deepEqual([B.sent[1].json.text, B.sent[1].base, B.sent[1].overwrite], ['B1 and more', 3, false]);
    assert.notEqual(B.word(), 'Saved', 'the status does not say Saved while text is unsent');
    assert.equal(B.view.unsaved, true);

    await B.land();
    assert.equal(server.version, 4);
    assert.equal(server.json.text, 'B1 and more');
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
    assert.deepEqual([B.sent[1].json.text, B.sent[1].base], ['B1 and more', 3]);
    assert.notEqual(B.word(), 'Saved');

    await B.land();
    assert.equal(server.json.text, 'B1 and more');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.view.saving, 0, 'an answer that comes after the tab stopped waiting is not counted twice');
    assert.equal(B.word(), 'Saved');
});

test('a save that expired answers late while its own conflict is showing: the text typed since is sent', async () => {
    const { server, clock, A } = await pair();
    A.type('T1');
    await A.debounce();
    // Stored as version 2, but the answer does not come.
    A.saveArrives();
    const late = A.saveNeverAnswers();

    // After 15 seconds the tab sends it again, on the old base. It is
    // refused against the tab's own stored save: the notice shows although
    // nobody else touched the document (a stated limit of this phase).
    clock.now += SAVE_EXPIRY_MS + 1000;
    await A.cycle();
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['T1', 1]);
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
    assert.deepEqual([A.sent[2].json.text, A.sent[2].base, A.sent[2].overwrite], ['T1 more', 2, false]);
    assert.notEqual(A.word(), 'Saved', 'the status does not say Saved while text is unsent');

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.json.text, 'T1 more');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.saving, 0);
    assert.equal(A.word(), 'Saved');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['one two three', 2]);
    assert.equal(A.draft(), 'one two three', 'the draft outlives a save that stored older text');
    assert.equal(A.view.unsaved, true);

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.json.text, 'one two three');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['one two', 2]);
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base, A.sent[1].overwrite], ['A1', 2, false]);

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.json.text, 'A1');
    assert.deepEqual(server.history, []);
    assert.equal(A.view.conflict, null);
    assert.equal(A.word(), 'Saved');
});

test('a save whose answer is lost is sent again and raises the notice against itself (a stated limit)', async () => {
    const { server, clock, A } = await pair();
    A.type('A1');
    await A.debounce();
    A.saveArrives();
    A.saveNeverAnswers();

    clock.now += SAVE_EXPIRY_MS;
    await A.cycle();
    await A.land();
    assert.deepEqual(A.view.conflict, { version: 2, ready: true });
    assert.equal(server.json.text, 'A1');
    assert.equal(A.text(), 'A1');
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
    assert.equal(server.json.text, 'A');
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
        A.sent.map((save) => [save.json.text, save.base, save.overwrite]),
        [
            ['A', 1, false],
            ['A and B', 2, false],
        ]
    );
    assert.equal(A.word(), 'Saving');

    await A.land();
    assert.equal(server.version, 3);
    assert.equal(server.json.text, 'A and B');
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
    assert.equal(server.json.text, 'one');
    assert.equal(A.word(), 'Editing', 'not Saved: the editor holds more than was stored');
    assert.equal(A.view.unsaved, true);

    await A.debounce();
    assert.equal(A.word(), 'Saving');
    await A.land();
    assert.equal(server.json.text, 'one two');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['A and B and C', 2]);
    await A.cycle();
    assert.equal(A.sent.length, 2, 'the owed save waits for the one the debounce sent');

    await A.land();
    await A.cycle();
    assert.equal(A.sent.length, 2);
    assert.equal(A.view.resave, false);
    assert.equal(server.json.text, 'A and B and C');
    assert.equal(A.word(), 'Saved');
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
    assert.deepEqual(A.view.conflict, { version: 2, ready: false });

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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['A1', 2]);
    await A.land();
    assert.equal(server.json.text, 'A1');
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
    assert.equal(server.json.text, 'start');
    assert.equal(A.view.unsaved, true, 'after a save the server refused');
    assert.equal(A.word(), 'Not saved');

    // Only an accepted save of what the editor holds clears it.
    A.type('A2');
    await A.debounce();
    await A.land();
    assert.equal(server.json.text, 'A2');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['A1', 1]);

    await A.land();
    assert.equal(server.json.text, 'A1');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['A1', 1]);

    await A.land();
    assert.equal(server.json.text, 'A1');
});

test('a save that never answers does not stop the notice: when somebody else saves, the text is kept and asked about', async () => {
    const { server, clock, A, B } = await pair();
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
    assert.equal(server.json.text, 'B1');
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
            A.sent.map((save) => [save.json.text, save.base]),
            [
                ['A1', 1],
                ['A2', 1],
            ],
            'persist() gives up on the old save itself; it does not wait for a poll to do it'
        );
        assert.equal(A.word(), 'Saving');

        await A.land();
        assert.equal(server.json.text, 'A2');
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
            A.sent.map((save) => [save.json.text, save.base]),
            [
                ['A1', 1],
                ['A2', 1],
            ],
            'every completed poll is a tick, answered or not'
        );

        await A.land();
        assert.equal(server.json.text, 'A2');
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

    assert.equal(server.json.text, 'A30');
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.saving, 0);
    assert.equal(A.word(), 'Saved');
    // Round 2 came inside the fifteen seconds and waited as an owed save.
    // Round 3's autosave gave up on the lost save and went out, carrying the
    // whole document, so round 2 was never sent on its own.
    assert.deepEqual(
        A.sent.map((save) => save.json.text),
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
    const { server, A, B } = await pair();
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
    assert.equal(server.json.text, 'A1 newer work');
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
    assert.equal(server.json.text, 'startx');
    assert.deepEqual([B.sent[1].json.text, B.sent[1].base], ['start', 2]);

    await B.land();
    assert.equal(server.json.text, 'start', 'the server ends at what the editor shows');
    assert.equal(B.text(), 'start');
    assert.equal(B.view.unsaved, false);
    assert.equal(B.word(), 'Saved');
});

for (const [who, notices] of [
    ['a poll', (B) => B.cycle()],
    ['syncState()', async (B) => B.host().syncState()],
]) {
    test(`an undo after a save whose answer was lost is not written off as Saved (${who} notices first)`, async () => {
        const { server, clock, B } = await pair();
        B.type('startx');
        await B.debounce();
        // The server stores it, and the answer never comes.
        B.saveArrives();
        B.saveNeverAnswers();
        assert.equal(server.json.text, 'startx');

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
        assert.deepEqual([B.sent[1].json.text, B.sent[1].base], ['start', 1]);
        await B.land();
        await B.cycle();
        // Refused against the tab's own stored save: the notice (a stated
        // limit), never a silent return of the deleted character.
        assert.equal(B.text(), 'start');
        assert.deepEqual(B.view.conflict, { version: 2, ready: true });
        assert.equal(B.word(), 'Not saved');

        B.host().keepMine();
        await B.land();
        assert.equal(server.json.text, 'start');
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
    assert.deepEqual([B.sent[1].json.text, B.sent[1].base], ['start', 1], 'not written off: it is sent');
    await B.land();
    assert.equal(server.json.text, 'start');
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
    assert.deepEqual(A.view.conflict, { version: 2, ready: false });

    A.host().adoptVersion(styled);
    assert.equal(A.view.conflict, null);
    assert.equal(A.view.baseVersion, 2);
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base, A.sent[1].overwrite], ['A1', 2, false]);

    await A.land();
    assert.equal(server.json.text, 'A1');
    assert.deepEqual(server.history, [], 'it was never an overwrite');
});

test('adoptVersion: a conflict above the adopted version stands', async () => {
    const { server, A } = await pair();
    A.type('A1');
    await A.debounce();
    const styled = server.replace(server.json);
    // Somebody else saves version 3 before the autosave arrives.
    server.replace(doc('C saved this'));
    A.saveArrives();
    await A.saveReturns();
    assert.equal(A.view.conflict.version, 3);

    A.host().adoptVersion(styled);
    assert.equal(A.view.baseVersion, 2);
    assert.equal(A.view.conflict.version, 3);
    assert.equal(A.sent.length, 1);
});

// ───────────────────────────────────────────────────────── an accepted suggestion

test('an accepted suggestion is put into the editor over unsaved typing and its version adopted', async () => {
    const { server, A, B } = await pair();
    B.type('B wrote this');
    await B.debounce();
    await B.land();
    A.type('A unsaved');
    A.view.overwriteOwed = true;
    A.view.aiError = 'an earlier problem';
    // Stored on purpose with no base: version 3, and A has not polled version 2.
    const version = server.replace(doc('the accepted suggestion'));

    A.host().applySuggestion(doc('the accepted suggestion'), version);
    assert.equal(A.text(), 'the accepted suggestion');
    assert.equal(A.view.baseVersion, 3);
    assert.equal(A.view.unsaved, false);
    assert.equal(A.view.overwriteOwed, false);
    assert.equal(A.view.confirmed, JSON.stringify(doc('the accepted suggestion')));
    assert.equal(A.view.aiError, '');
    assert.equal(A.view.tick, 1);
    assert.equal(A.outlineRefreshes, 1);
    assert.equal(A.host().syncState(), 'clean');
    assert.equal(A.word(), 'Saved', 'the typing that said Editing is gone, and no save will answer for it');
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
    assert.equal(w.server.json.text, 'A1');
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
    assert.equal(w.server.json.text, 'A2');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['typed offline', 1]);

    await A.land();
    assert.equal(server.json.text, 'typed offline');
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
    assert.equal(server.json.text, 'A1');
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
    assert.equal(server.json.text, 'A1');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['one two', 1]);
    await A.land();
    assert.equal(server.json.text, 'one two');
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
    assert.deepEqual([A.sent[1].json.text, A.sent[1].base], ['one', 1]);
    await A.land();
    assert.equal(server.json.text, 'one');
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
    assert.deepEqual([A.sent[0].json.text, A.sent[0].base], ['offline work', 1]);
    await A.land();
    assert.equal(w.server.json.text, 'offline work');
    assert.equal(A.draft(), null);
});

test('a restored draft whose save is refused is still in the draft store', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 });
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
    assert.deepEqual([A.sent[0].json.text, A.sent[0].base, A.sent[0].overwrite], ['offline work', 2, true]);
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
    assert.deepEqual(A.draftCalls, ['loadDraft']);
    assert.equal(A.polls.length, 1);

    const B = w.tab('B', { draftStore: false });
    await B.open();
    assert.equal(B.text(), 'start');
    assert.equal(B.polls.length, 1);
});

test('reloading instead of choosing: the text comes back set aside, and Put it back saves it', async () => {
    const { server, B } = await conflicted();

    // The same browser loads the page again: it renders version 2.
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.equal(again.text(), 'A1');
    assert.equal(again.view.setAside, JSON.stringify(doc('B1')));
    assert.equal(again.view.setAsideFrom, 'draft');

    await again.poll();
    again.host().putBack();
    assert.deepEqual(again.questions, [PUT_BACK_QUESTION]);
    await again.debounce();
    await again.land();
    assert.equal(server.json.text, 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});

// ───────────────────────────────────────────────────────── a draft whose text is already in the document

test('the beacon stored the last words and nobody saved since: the draft is parked and nothing is offered', async () => {
    const { server, clock, A } = await pair();
    A.type('A last words');
    A.leave();
    assert.deepEqual(
        A.beacons.map((beacon) => [beacon.body.content.text, beacon.body.base_version, beacon.answer.status]),
        [['A last words', 1, 200]]
    );
    assert.equal(server.json.text, 'A last words');
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

    // One click must not replace two versions of somebody else's work.
    again.host().putBack();
    assert.deepEqual(again.questions, [PUT_BACK_QUESTION]);
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
    assert.equal(server.json.text, 'A last words');
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
    assert.deepEqual(again.questions, [PUT_BACK_QUESTION]);
    assert.equal(again.text(), 'A last words, then B');
    await again.debounce();
    assert.equal(again.sent.length, 0);
    assert.equal(server.json.text, 'A last words, then B');
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
    assert.deepEqual(again.draftCalls, ['loadDraft']);
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
