import assert from 'node:assert/strict';
import test from 'node:test';

import { FAST_MS, MAX_BACKOFF_MS, PROTOCOL, SLOW_MS, createSyncEngine } from '../../resources/js/editor/sync/engine.js';
import { createSyncRequest } from '../../resources/js/editor/sync/request.js';

/** Let every pending promise callback run. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

/**
 * An engine wired to fakes: requests are answered by hand, and the timer is
 * captured instead of run, so each test decides what happens and when.
 */
function harness({ version = 1, state = 'clean', visible = true, withAccess = false } = {}) {
    const h = {
        state,
        visible,
        requests: [],
        applied: [],
        conflicts: [],
        refusals: [],
        members: [],
        /** What the poll said about this person's role (`can_edit`), each time it said. */
        access: [],
        stopped: [],
        /** What the host was called with, in order: 'members', 'state', 'polled'. */
        calls: [],
        applyResult: true,
        timer: null,
    };

    h.engine = createSyncEngine({
        version,
        tab: 'tab-a',
        request: (payload) =>
            new Promise((resolve, reject) => {
                h.requests.push({ payload, resolve, reject });
            }),
        host: {
            state: () => {
                h.calls.push('state');

                return h.state;
            },
            applyRemote: (remote) => {
                h.applied.push(remote);
                if (h.applyResult instanceof Error) {
                    throw h.applyResult;
                }

                return h.applyResult;
            },
            onConflict: (remote) => {
                h.conflicts.push(remote);
                if (h.conflictThrows) {
                    throw new Error('host failed');
                }
            },
            onRefused: (remote) => h.refusals.push(remote),
            ...(withAccess
                ? {
                      onAccess: (canEdit) => {
                          h.access.push(canEdit);
                          h.calls.push('access');
                          if (h.accessThrows) {
                              throw new Error('host failed');
                          }
                      },
                  }
                : {}),
            onMembers: (members, others) => {
                h.calls.push('members');
                h.members.push({ members, others });
                if (h.membersThrow) {
                    throw new Error('host failed');
                }
            },
            onPolled: () => {
                h.calls.push('polled');
                if (h.polledThrows) {
                    throw new Error('host failed');
                }
            },
            onStopped: (reason) => {
                h.stopped.push(reason);
                if (h.stoppedThrows) {
                    throw new Error('host failed');
                }
            },
        },
        visible: () => {
            if (h.visibleThrows) {
                throw new Error('the visibility check failed');
            }

            return h.visible;
        },
        setTimer: (fn, ms) => {
            h.timer = { fn, ms };

            return h.timer;
        },
        clearTimer: (id) => {
            if (h.timer === id) {
                h.timer = null;
            }
        },
    });

    /** Answer the oldest unanswered request. */
    h.answer = async (status, body = null) => {
        h.requests.shift().resolve({ status, body });
        await settle();
    };
    h.fail = async () => {
        h.requests.shift().reject(new Error('network'));
        await settle();
    };
    /** Run the armed timer, as if its delay had passed. */
    h.fire = async () => {
        const { fn } = h.timer;
        h.timer = null;
        fn();
        await settle();
    };

    return h;
}

const quiet = (version, others = 0) => ({ version, changed: false, members: [], others });
const moved = (version, others = 1) => ({
    version,
    changed: true,
    members: [],
    others,
    json: { type: 'doc', content: [] },
    outline: { numbers: {} },
    css: '.paper{color:black}',
});

test('start polls at once, stating the version it has, its tab and the protocol it speaks', async () => {
    const h = harness({ version: 4 });
    h.engine.start();
    await settle();

    assert.deepEqual(h.requests[0].payload, { version: 4, tab: 'tab-a', protocol: 1 });
});

test('alone, it polls slowly; with company, quickly', async () => {
    const h = harness();
    h.engine.start();
    await settle();

    await h.answer(200, quiet(1, 0));
    assert.equal(h.timer.ms, SLOW_MS);

    await h.fire();
    await h.answer(200, quiet(1, 2));
    assert.equal(h.timer.ms, FAST_MS);
});

test('a newer document is applied to a clean tab and becomes the version it has', async () => {
    const h = harness({ version: 1 });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 1);
    assert.equal(h.applied[0].version, 2);
    assert.deepEqual(h.applied[0].outline, { numbers: {} });
    assert.equal(h.applied[0].css, '.paper{color:black}');
    assert.equal(h.engine.pending, null);

    await h.fire();
    assert.equal(h.requests[0].payload.version, 2);
});

test('a newer document is NOT applied to a tab with unsaved typing: that is a conflict', async () => {
    const h = harness({ version: 1, state: 'dirty' });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 0);
    assert.equal(h.conflicts.length, 1);
    assert.equal(h.conflicts[0].version, 2);
    assert.equal(h.engine.pending.version, 2);
});

test('the document is not downloaded again while the conflict is unresolved', async () => {
    const h = harness({ version: 1, state: 'dirty' });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));
    await h.fire();

    // It has SEEN version 2, so it asks from 2 and the server sends no body.
    assert.equal(h.requests[0].payload.version, 2);
});

test('while a save is in flight the engine decides nothing, then delivers on retry', async () => {
    const h = harness({ version: 1, state: 'busy' });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 0);
    assert.equal(h.conflicts.length, 0);

    h.state = 'clean';
    h.engine.retry();

    assert.equal(h.applied.length, 1);
});

test('the echo of this tab own save is dropped, not applied and not a conflict', async () => {
    const h = harness({ version: 1, state: 'busy' });
    h.engine.start();
    await settle();
    // The poll comes back with version 2 - which is this tab's own save,
    // whose response has not arrived yet.
    await h.answer(200, moved(2));

    h.engine.saved(2);
    h.state = 'clean';
    h.engine.retry();

    assert.equal(h.applied.length, 0);
    assert.equal(h.conflicts.length, 0);
    assert.equal(h.engine.pending, null);
});

test('a poll answered after this tab saved a newer version is ignored', async () => {
    const h = harness({ version: 1 });
    h.engine.start();
    await settle();

    h.engine.saved(3);
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 0);
    assert.equal(h.engine.pending, null);
});

test('a read-only tab never has a document applied to it', async () => {
    const h = harness({ version: 1, state: 'closed' });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 0);
    assert.equal(h.conflicts.length, 0);
});

test('a document the editor refuses is not offered to it again', async () => {
    const h = harness({ version: 1 });
    h.applyResult = false;
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));
    assert.equal(h.applied.length, 1);

    h.engine.retry();
    assert.equal(h.applied.length, 1);
});

test('a refused document is reported to the host once, and polling goes on', async () => {
    const h = harness({ version: 1 });
    h.applyResult = false;
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.refusals.length, 1);
    assert.equal(h.refusals[0].version, 2);
    assert.deepEqual(h.stopped, []);
    assert.notEqual(h.timer, null, 'the next poll is armed');

    h.engine.retry();
    assert.equal(h.refusals.length, 1);
});

test('a host that throws while applying is treated as a refusal, and polling goes on', async () => {
    const h = harness({ version: 1 });
    h.applyResult = new Error('a plugin threw in dispatch');
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.refusals.length, 1);
    assert.notEqual(h.timer, null, 'the next poll is armed');

    await h.fire();
    await h.answer(200, quiet(2, 1));
    assert.equal(h.applied.length, 1, 'the version that threw is not offered again');
});

test('a host callback that throws does not end the polling', async () => {
    const h = harness();
    h.membersThrow = true;
    h.engine.start();
    await settle();
    await h.answer(200, quiet(1, 1));

    assert.equal(h.timer.ms, FAST_MS);
});

test('refetch offers a refused version to the editor again', async () => {
    const h = harness({ version: 1 });
    h.applyResult = false;
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));
    assert.equal(h.applied.length, 1);

    h.applyResult = true;
    h.engine.refetch(1);
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 2);
    assert.equal(h.engine.pending, null);
});

test('takeRemote hands over the waiting document once', async () => {
    const h = harness({ version: 1, state: 'dirty' });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.equal(h.engine.takeRemote().version, 2);
    assert.equal(h.engine.takeRemote(), null);
});

test('refetch asks again from an older version, so the document is downloaded a second time', async () => {
    const h = harness({ version: 1, state: 'dirty' });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    // The waiting document has been used up, but the conflict is not over.
    h.engine.takeRemote();
    assert.equal(h.engine.pending, null);

    h.engine.refetch(1);
    await settle();
    assert.equal(h.requests[0].payload.version, 1);

    await h.answer(200, moved(2));
    assert.equal(h.engine.pending.version, 2);
});

test('members are reported on every answered poll', async () => {
    const h = harness();
    h.engine.start();
    await settle();
    await h.answer(200, { version: 1, changed: false, members: [{ id: 7, name: 'Thandi' }], others: 1 });

    assert.deepEqual(h.members, [{ members: [{ id: 7, name: 'Thandi' }], others: 1 }]);
});

test('a failed request backs off, and recovers to the normal pace', async () => {
    const h = harness();
    h.engine.start();
    await settle();

    await h.fail();
    assert.equal(h.timer.ms, FAST_MS * 2);

    await h.fire();
    await h.answer(500);
    assert.equal(h.timer.ms, FAST_MS * 4);

    await h.fire();
    await h.answer(200, quiet(1, 0));
    assert.equal(h.timer.ms, SLOW_MS);
});

test('the back-off is capped', async () => {
    const h = harness();
    h.engine.start();
    await settle();

    for (let i = 0; i < 12; i += 1) {
        await h.fail();
        if (i < 11) {
            await h.fire();
        }
    }

    assert.equal(h.timer.ms, MAX_BACKOFF_MS);
});

for (const [status, reason] of [[401, 'signed-out'], [419, 'signed-out'], [403, 'forbidden'], [404, 'gone']]) {
    test(`a ${status} from the application stops the engine for good and says why (${reason})`, async () => {
        const h = harness();
        h.engine.start();
        await settle();
        // The application answers these with a JSON body.
        await h.answer(status, { message: 'x' });

        assert.deepEqual(h.stopped, [reason]);
        assert.equal(h.timer, null);

        h.engine.poke();
        await settle();
        assert.equal(h.requests.length, 0);
    });
}

for (const status of [401, 419, 403, 404]) {
    test(`a ${status} with no JSON body is a failure to retry, not a reason to stop`, async () => {
        // A firewall or rate limiter in front of the application answers
        // with an HTML page. That is not "your access was removed", "you
        // were signed out" or "the document is gone".
        const h = harness();
        h.engine.start();
        await settle();
        await h.answer(status);

        assert.deepEqual(h.stopped, []);
        assert.equal(h.timer.ms, FAST_MS * 2);

        await h.fire();
        assert.equal(h.requests.length, 1, 'it polls again');
    });
}

// ───────────────────────────────────────────────────────── a host that throws, and a visibility check that throws

test('a host whose onStopped throws does not throw out of the engine', async () => {
    const h = harness();
    h.stoppedThrows = true;
    const run = h.engine.start();
    await settle();
    await h.answer(403, { message: 'x' });

    await assert.doesNotReject(run);
    assert.deepEqual(h.stopped, ['forbidden']);
    assert.equal(h.timer, null, 'stopped for good all the same');
});

test('a visibility check that throws counts as visible: the next poll is still armed', async () => {
    const h = harness();
    h.visibleThrows = true;
    const run = h.engine.start();
    await settle();
    await h.answer(200, quiet(1, 1));

    await assert.doesNotReject(run);
    assert.notEqual(h.timer, null, 'the next poll is armed');
    assert.equal(h.timer.ms, FAST_MS);

    // And a visibility change polls rather than disarming.
    await h.fire();
    await h.answer(200, quiet(1, 1));
    assert.doesNotThrow(() => h.engine.visibilityChanged());
    await settle();
    assert.equal(h.requests.length, 1);
});

test('a host whose onConflict throws through retry() does not reach the caller', async () => {
    const h = harness({ version: 1, state: 'dirty' });
    h.conflictThrows = true;
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));
    assert.equal(h.conflicts.length, 1);
    assert.notEqual(h.timer, null, 'the throw inside the poll was already survived');

    // retry() is what persist() calls when a save answers: a throw here
    // would be reported as the failure of a save the server stored.
    assert.doesNotThrow(() => h.engine.retry());
    assert.equal(h.conflicts.length, 2);
});

// ───────────────────────────────────────────────────────── a tick for the host on every completed poll

const ticks = (h) => h.calls.filter((call) => call === 'polled').length;

test('a poll that is answered gives the host a tick, after the members and after the document was offered', async () => {
    const h = harness({ version: 1 });
    h.engine.start();
    await settle();
    await h.answer(200, moved(2));

    assert.deepEqual(h.calls, ['members', 'state', 'polled']);

    await h.fire();
    await h.answer(200, quiet(2, 1));
    assert.equal(ticks(h), 2, 'one tick for each poll, also when nothing changed');
});

for (const [what, complete] of [
    ['is answered 403 with no body', (h) => h.answer(403)],
    ['is answered 503 with no body', (h) => h.answer(503)],
    ['is answered 500 with a JSON body', (h) => h.answer(500, { message: 'Server Error' })],
    ['is answered 429 with a JSON body', (h) => h.answer(429, { message: 'Too Many Attempts.' })],
    ['is answered 200 with no body', (h) => h.answer(200)],
    ['never reaches the server', (h) => h.fail()],
    ['times out (the request reports status 0 and no body)', (h) => h.answer(0)],
]) {
    test(`a poll that ${what} still gives the host a tick`, async () => {
        // The host re-sends a save nobody answered from this tick. If only an
        // ANSWERED poll gave one, a blocked poll would stop the tab saving.
        const h = harness();
        h.engine.start();
        await settle();
        await complete(h);

        assert.deepEqual(h.calls, ['polled'], 'no members, no document: only the tick');
        assert.equal(h.timer.ms, FAST_MS * 2, 'and it is still a failure to back off from');

        await h.fire();
        await complete(h);
        assert.equal(ticks(h), 2);
    });
}

test('a host whose tick throws does not end the polling', async () => {
    const h = harness();
    h.polledThrows = true;
    h.engine.start();
    await settle();

    await h.fail();
    assert.equal(h.timer.ms, FAST_MS * 2);

    await h.fire();
    await h.answer(200, quiet(1, 1));
    assert.equal(h.timer.ms, FAST_MS);
    assert.equal(ticks(h), 2);
});

test('a poll that stops the engine gives no tick, and neither does one answered after stop()', async () => {
    const stoppedByTheApplication = harness();
    stoppedByTheApplication.engine.start();
    await settle();
    await stoppedByTheApplication.answer(403, { message: 'This action is unauthorized.' });
    assert.deepEqual(stoppedByTheApplication.stopped, ['forbidden']);
    assert.equal(ticks(stoppedByTheApplication), 0);

    const stoppedByThePage = harness();
    stoppedByThePage.engine.start();
    await settle();
    stoppedByThePage.engine.stop();
    await stoppedByThePage.answer(200, quiet(1, 1));
    assert.equal(ticks(stoppedByThePage), 0);
});

test('a host with no tick of its own is polled as before', async () => {
    const requests = [];
    let timer = null;
    const engine = createSyncEngine({
        version: 1,
        tab: 'tab-a',
        request: () => new Promise((resolve) => requests.push(resolve)),
        host: {
            state: () => 'clean',
            applyRemote: () => true,
            onConflict: () => {},
            onRefused: () => {},
            onMembers: () => {},
            onStopped: () => {},
        },
        setTimer: (fn, ms) => {
            timer = { fn, ms };

            return timer;
        },
        clearTimer: () => {
            timer = null;
        },
    });
    engine.start();
    await settle();
    requests.shift()({ status: 503, body: null });
    await settle();

    assert.equal(timer.ms, FAST_MS * 2, 'the next poll is armed');
});

test('a hidden tab does not poll; becoming visible polls at once', async () => {
    const h = harness({ visible: false });
    h.engine.start();
    await settle();
    await h.answer(200, quiet(1, 3));

    assert.equal(h.timer, null);

    h.visible = true;
    h.engine.visibilityChanged();
    await settle();

    assert.equal(h.requests.length, 1);
});

test('a tab that becomes hidden disarms the next poll, and polls no more until it is visible again', async () => {
    const h = harness();
    h.engine.start();
    await settle();
    await h.answer(200, quiet(1, 1));
    assert.notEqual(h.timer, null);

    h.visible = false;
    h.engine.visibilityChanged();
    await settle();
    assert.equal(h.timer, null, 'the armed poll is disarmed');
    assert.equal(h.requests.length, 0, 'and no poll was sent');
});

test('a poke during a request runs exactly one more poll after it', async () => {
    const h = harness();
    h.engine.start();
    await settle();

    h.engine.poke();
    h.engine.poke();
    await settle();
    assert.equal(h.requests.length, 1);

    await h.answer(200, quiet(1));
    assert.equal(h.requests.length, 1, 'the queued poke ran');

    await h.answer(200, quiet(1));
    assert.equal(h.requests.length, 0);
});

test('stop ends polling', async () => {
    const h = harness();
    h.engine.start();
    await settle();
    h.engine.stop();
    await h.answer(200, moved(2));

    assert.equal(h.applied.length, 0);
    assert.equal(h.timer, null);
});

test('createSyncRequest posts JSON with the CSRF token and returns status and body', async () => {
    const calls = [];
    const request = createSyncRequest('/documents/u/sync', 'token-123', async (url, init) => {
        calls.push({ url, init });

        return { status: 200, json: async () => ({ version: 3 }) };
    });

    const result = await request({ version: 2, tab: 'tab-a' });

    assert.deepEqual(result, { status: 200, body: { version: 3 } });
    assert.equal(calls[0].url, '/documents/u/sync');
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.headers['X-CSRF-TOKEN'], 'token-123');
    assert.equal(calls[0].init.headers.Accept, 'application/json');
    assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
    assert.equal(calls[0].init.credentials, 'same-origin');
    assert.deepEqual(JSON.parse(calls[0].init.body), { version: 2, tab: 'tab-a' });
});

/** Injected timers: each call is recorded, never run, so a test fires it by hand. */
function fakeTimers() {
    const timers = [];

    return {
        timers,
        setTimeout: (fn, ms) => {
            timers.push({ fn, ms, cleared: false });

            return timers.length;
        },
        clearTimeout: (id) => {
            timers[id - 1].cleared = true;
        },
    };
}

test('createSyncRequest gives up on a poll that has not answered in twenty seconds and reports a failure', async () => {
    const clock = fakeTimers();
    let init = null;
    const request = createSyncRequest(
        '/documents/u/sync',
        'token-123',
        (url, options) => {
            init = options;

            // A request that never answers: a stalled connection, a proxy holding it.
            return new Promise(() => {});
        },
        clock
    );

    const answer = request({ version: 1, tab: 'tab-a' });
    assert.equal(clock.timers.length, 1, 'a time limit is set');
    assert.equal(clock.timers[0].ms, 20000);
    assert.equal(init.signal.aborted, false, 'the fetch was given a signal to abort on');

    clock.timers[0].fn();
    // The engine treats this as a completed poll that failed: a tick for
    // the host, and a retry with back-off.
    assert.deepEqual(await answer, { status: 0, body: null });
    assert.equal(init.signal.aborted, true, 'and the fetch was aborted');
});

test('createSyncRequest clears its time limit once the poll has answered, and keeps the timers default', async () => {
    const clock = fakeTimers();
    const request = createSyncRequest('/x', 't', async () => ({ status: 200, json: async () => ({ version: 1 }) }), clock);

    assert.deepEqual(await request({ version: 1, tab: 'tab-a' }), { status: 200, body: { version: 1 } });
    assert.equal(clock.timers[0].cleared, true);

    // With nothing injected the global timers are used, and an answered request still resolves.
    const plain = createSyncRequest('/x', 't', async () => ({ status: 200, json: async () => ({ version: 2 }) }));
    assert.deepEqual(await plain({ version: 1, tab: 'tab-a' }), { status: 200, body: { version: 2 } });
});

test('createSyncRequest survives a response that is not JSON', async () => {
    const request = createSyncRequest('/x', 't', async () => ({
        status: 419,
        json: async () => {
            throw new Error('not json');
        },
    }));

    assert.deepEqual(await request({ version: 1, tab: 'tab-a' }), { status: 419, body: null });
});

// ───────────────────────────────────────────────────────── the poll carries the person's role

test('a poll that says can_edit hands it to the host before the members, and one that does not says nothing', async () => {
    const h = harness({ withAccess: true });
    h.engine.start();
    await settle();

    await h.answer(200, { ...quiet(1), can_edit: false });
    assert.deepEqual(h.access, [false]);
    assert.deepEqual(h.calls.slice(0, 2), ['access', 'members'], 'the role is dealt with before the page sends anything else');

    await h.fire();
    await h.answer(200, { ...quiet(1), can_edit: true });
    assert.deepEqual(h.access, [false, true]);

    // An old server: no field, no call. Not a boolean: no call.
    await h.fire();
    await h.answer(200, quiet(1));
    await h.fire();
    await h.answer(200, { ...quiet(1), can_edit: 'false' });
    await h.fire();
    await h.answer(200, { ...quiet(1), can_edit: null });
    assert.deepEqual(h.access, [false, true]);
});

test('a host that throws from onAccess, or has none at all, does not end the loop; a failed poll says nothing about the role', async () => {
    const throwing = harness({ withAccess: true });
    throwing.accessThrows = true;
    throwing.engine.start();
    await settle();
    await throwing.answer(200, { ...quiet(1), can_edit: false });
    assert.ok(throwing.timer, 'the next poll is armed');
    assert.deepEqual(throwing.calls.includes('polled'), true);

    const old = harness();
    old.engine.start();
    await settle();
    await old.answer(200, { ...quiet(1), can_edit: false });
    assert.ok(old.timer, 'a host written before the field existed ignores it');

    const failing = harness({ withAccess: true });
    failing.engine.start();
    await settle();
    await failing.fail();
    await failing.fire();
    await failing.answer(500, { can_edit: false });
    assert.deepEqual(failing.access, []);
});

test('the protocol the engine speaks is unchanged: the field is additive and old clients ignore it', async () => {
    assert.equal(PROTOCOL, 1);
    const h = harness();
    h.engine.start();
    await settle();
    assert.equal(h.requests[0].payload.protocol, 1);
});
