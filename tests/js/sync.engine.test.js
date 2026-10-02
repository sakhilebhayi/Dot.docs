import assert from 'node:assert/strict';
import test from 'node:test';

import { FAST_MS, MAX_BACKOFF_MS, SLOW_MS, createSyncEngine } from '../../resources/js/editor/sync/engine.js';
import { createSyncRequest } from '../../resources/js/editor/sync/request.js';

/** Let every pending promise callback run. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

/**
 * An engine wired to fakes: requests are answered by hand, and the timer is
 * captured instead of run, so each test decides what happens and when.
 */
function harness({ version = 1, state = 'clean', visible = true } = {}) {
    const h = {
        state,
        visible,
        requests: [],
        applied: [],
        conflicts: [],
        refusals: [],
        members: [],
        stopped: [],
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
            state: () => h.state,
            applyRemote: (remote) => {
                h.applied.push(remote);
                if (h.applyResult instanceof Error) {
                    throw h.applyResult;
                }

                return h.applyResult;
            },
            onConflict: (remote) => h.conflicts.push(remote),
            onRefused: (remote) => h.refusals.push(remote),
            onMembers: (members, others) => {
                h.members.push({ members, others });
                if (h.membersThrow) {
                    throw new Error('host failed');
                }
            },
            onStopped: (reason) => h.stopped.push(reason),
        },
        visible: () => h.visible,
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

test('a 403 with no JSON body is a failure to retry, not a reason to stop', async () => {
    // A firewall or rate limiter in front of the application answers 403
    // with an HTML page. That is not "your access was removed".
    const h = harness();
    h.engine.start();
    await settle();
    await h.answer(403);

    assert.deepEqual(h.stopped, []);
    assert.equal(h.timer.ms, FAST_MS * 2);

    await h.fire();
    assert.equal(h.requests.length, 1, 'it polls again');
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
    assert.deepEqual(JSON.parse(calls[0].init.body), { version: 2, tab: 'tab-a' });
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
