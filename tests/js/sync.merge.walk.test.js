import assert from 'node:assert/strict';
import test from 'node:test';

import { LOST, blocksOf, deletionLedger, world } from './support/syncWorld.js';

/**
 * The random-walk convergence harness (section 12 of the Phase 2 spec).
 *
 * Two or three tabs on the shared block-shaped fake world (support/
 * syncWorld.js) take 200 random steps: typing a unique token into a block,
 * inserting, deleting, moving and re-aligning blocks, letting the debounce
 * fire, letting saves reach the server and answer, polling, letting the
 * clock move, and now and then putting the lost row back or dismissing it.
 * Then everything that is in flight is let land (quiesce), and six things
 * must hold at the end, and three about the saving itself throughout:
 *
 *   1. every tab's document equals the server's (convergence);
 *   2. no token typed by anybody is silently lost: each is in the server
 *      document, or in some tab's lost row, or in that tab's lost park, or
 *      in a version the server kept, or was knowingly deleted (it was in
 *      the deleter's own copy of the block when they deleted it);
 *   3. ids are unique in every document;
 *   4. no merge was ever impossible (nothing here is: two deletions that
 *      leave nothing are a merge too), and no conflict notice stands once
 *      everything has landed. The one notice the walk can raise is the
 *      MERGE_ATTEMPTS bound; the quiesce answers it with Keep mine, as a
 *      writer would, and counts it in `tab.notices`;
 *   5. no tab owes an overwrite once everything has landed;
 *   6. every accepted save that dropped a token from the head that nobody
 *      knowingly deleted said it overwrites, so the head is in the history.
 *      A deletion excuses only the saves of the person who made it; once it
 *      is stored and the person has seen that, a token that reappears on the
 *      server (put back, restored) is no longer excused, so a later silent
 *      drop of it fails (see deletionLedger in support/syncWorld.js).
 *
 * and, from the repairs of dry run 2 (section 11), at every request and step:
 *
 *   HELD       no save request leaves a tab while its merge fetch is out
 *              (`view.merging`);
 *   STALEBASE  no save states a base lower than the version the tab already
 *              knows (`view.known.version`): a merged save goes out on the
 *              version it was merged onto;
 *   BACK       a tab's `baseVersion` never decreases;
 *   PARK-KEPT  a park is never dropped by a later park (parkStaleDraft keeps
 *              the one it would replace), and every park counts as a place
 *              where its words are kept.
 *
 * Seeds 1 to 50 by default; `WALK_SEEDS=<n>` runs seeds 1 to n (the dry run
 * ran 2000 once: 4000 tests, about 12 seconds); `WALK_SEED=<n>` replays one.
 * On a failure, whatever its kind (an invariant, or a quiesce that does not
 * come to rest), the message carries the seed, the tab count, the replay
 * command and the last forty steps.
 *
 * This walk never fails a request or loses an answer: the failures of
 * section 11 (a lost or late save answer, a failed save or poll, the clock
 * past the 15 s expiries, composition, an impossible merge, a restore or an
 * import, an accepted suggestion, a tab that dies over its draft, an
 * individual Put it back, a browser without drafts) are in
 * sync.merge.walk.failures.test.js.
 */

const STEPS = 200;
const QUIESCE_ROUNDS = 50;
const SEEDS = process.env.WALK_SEED
    ? [Number(process.env.WALK_SEED)]
    : Array.from({ length: Number(process.env.WALK_SEEDS ?? 50) }, (_, index) => index + 1);
const TOKEN = /^t\d+$/;
const TRACE_TAIL = 40;
const replayWith = (seed) => `replay with WALK_SEED=${seed} node --test tests/js/sync.merge.walk.test.js`;

/** A small seeded generator: the same seed gives the same walk on every machine. */
function mulberry32(seed) {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Every token in a document (or in `{content: [block]}`). */
function tokensIn(json) {
    const out = new Set();
    const collect = (node) => {
        if (!Array.isArray(node.content)) {
            return;
        }
        node.content.forEach((child) => {
            if (child.type === 'text') {
                child.text.split(/\s+/).forEach((word) => {
                    if (TOKEN.test(word)) {
                        out.add(word);
                    }
                });
            } else {
                collect(child);
            }
        });
    };
    collect(json);

    return out;
}

const idsIn = (json) => json.content.map((block) => block.attrs.id);
const unique = (ids) => new Set(ids).size === ids.length;

/** Answer the poll a tab has in the air, firing its timer first when it has none. */
async function pollOnce(tab) {
    if (tab.polls.length === 0) {
        if (tab.timer === null) {
            return;
        }
        await tab.fireTimer();
    }
    if (tab.polls.length > 0) {
        await tab.poll();
    }
}

/** Every save of a tab reaches the server and is answered, oldest first. */
async function landAll(tab) {
    let landed = 0;
    while (tab.saves.length > 0) {
        landed += 1;
        assert.ok(landed <= 50, `${tab.name} keeps sending saves and never comes to rest`);
        if (tab.saves[0].result === undefined) {
            tab.saveArrives();
        }
        await tab.saveReturns();
    }
}

const quiet = (tab) =>
    !tab.view.unsaved &&
    tab.view.saving === 0 &&
    tab.view.merging === null &&
    !tab.view.resave &&
    !tab.handle.pending &&
    tab.saves.length === 0 &&
    tab.polls.length === 0;

async function quiesce(tabs, trace) {
    for (let round = 1; round <= QUIESCE_ROUNDS; round += 1) {
        // A notice stands only for the MERGE_ATTEMPTS bound (merged saves
        // refused that many times in a row; nothing in this walk makes a
        // merge impossible): the writer chooses, as a writer would. Keep
        // mine saves the merged document as an overwrite, so the head it
        // replaces is kept and nothing is lost.
        for (const tab of tabs) {
            if (tab.view.conflict && tab.view.conflict.ready) {
                tab.host().keepMine();
                tab.notices += 1;
                trace.push(`${tab.name} keeps mine (the notice stood)`);
            }
        }
        // Each tab is settled before the next tab's save lands: landing
        // every tab's save in one fixed order every round had the last
        // tab's merged save refused by the previous tab's save round after
        // round, and reaching the bound inside the quiesce itself.
        for (const tab of tabs) {
            await tab.debounce();
            await landAll(tab);
            let settles = 0;
            while (tab.view.merging !== null || tab.saves.length > 0) {
                settles += 1;
                assert.ok(settles <= 50, `${tab.name} never settles: merging=${JSON.stringify(tab.view.merging)} saves=${tab.saves.length} polls=${tab.polls.length}`);
                await pollOnce(tab);
                await landAll(tab);
            }
        }
        for (const tab of tabs) {
            await pollOnce(tab);
        }
        if (tabs.every(quiet)) {
            trace.push(`quiesced after ${round} round(s)`);

            return;
        }
    }
    const states = tabs.map(
        (tab) =>
            `${tab.name}: unsaved=${tab.view.unsaved} saving=${tab.view.saving} merging=${JSON.stringify(tab.view.merging)} resave=${tab.view.resave} pending=${tab.handle.pending} saves=${tab.saves.length} polls=${tab.polls.length} conflict=${JSON.stringify(tab.view.conflict)}`
    );
    throw new Error(`did not quiesce in ${QUIESCE_ROUNDS} rounds:\n${states.join('\n')}`);
}

/** Says which walk failed and how to run it again, whatever the failure was. */
async function walk(seed, tabCount) {
    const trace = [];
    try {
        return await walkSteps(seed, tabCount, trace);
    } catch (error) {
        if (error && error.walkContext) {
            throw error;
        }
        const wrapped = new Error(
            `${error && error.message ? error.message : error} (seed ${seed}, ${tabCount} tabs; ${replayWith(seed)})\n${trace.slice(-TRACE_TAIL).join('\n')}`,
            { cause: error }
        );
        wrapped.walkContext = true;

        throw wrapped;
    }
}

async function walkSteps(seed, tabCount, trace) {
    const random = mulberry32(seed);
    const pick = (list) => list[Math.floor(random() * list.length)];
    const w = world();
    const tabs = [];
    for (let index = 0; index < tabCount; index += 1) {
        const tab = w.tab(String.fromCharCode(65 + index));
        // Every park the tab writes is logged: parking never overwrites, so none may be gone at the end (PARK-KEPT).
        tab.parkLog = [];
        const realPark = tab.draftStore.parkStaleDraft;
        tab.draftStore.parkStaleDraft = async (uuid, json, baseVersion) => {
            await realPark(uuid, json, baseVersion);
            tab.parkLog.push(json);
        };
        await tab.open();
        tabs.push(tab);
    }
    for (const tab of tabs) {
        await tab.poll();
    }
    for (const tab of tabs) {
        await tab.cycle();
    }

    const { server } = w;
    let judged = 0;
    const judgeStoredSaves = () => {
        while (judged < server.trace.length) {
            const row = server.trace[judged];
            judged += 1;
            const silent = ledger.stored(row, sender);
            if (silent.length > 0 && !row.overwrite) {
                droppedWithoutSaying.push(`version ${row.version} (sent by ${sender}) dropped ${silent.join(', ')} from the head without saying it overwrites`);
            }
        }
    };
    const realStore = server.save.bind(server);
    server.save = (json, base, overwrite = false) => {
        const result = realStore(json, base, overwrite);
        judgeStoredSaves();

        return result;
    };
    tabs.forEach((tab) => {
        const realArrive = tab.saveArrives;
        tab.saveArrives = () => {
            sender = tab.name;

            return realArrive();
        };
    });

    tabs.forEach((tab) => {
        const realSend = tab.env.save;
        tab.env.save = (json, baseVersion, overwrite) => {
            if (tab.view.merging) {
                savingFaults.push(`HELD: ${tab.name} sent a save while its merge fetch was out`);
            }
            if (tab.view.known && baseVersion < tab.view.known.version) {
                savingFaults.push(`STALEBASE: ${tab.name} sent a save on v${baseVersion} while it knows v${tab.view.known.version}`);
            }

            return realSend(json, baseVersion, overwrite);
        };
    });
    const checkBases = (label) => {
        tabs.forEach((tab) => {
            if (tab.lastBase !== undefined && tab.view.baseVersion < tab.lastBase) {
                savingFaults.push(`BACK: ${tab.name} base went back from ${tab.lastBase} to ${tab.view.baseVersion} (after: ${label})`);
            }
            tab.lastBase = tab.view.baseVersion;
        });
    };

    // Every merge that could not be done, with its inputs: there should be
    // none (invariant 4), and when there is one the ids say which case it was.
    tabs.forEach((tab) => {
        tab.notices = 0;
        tab.nullMerges = [];
        const real = tab.handle.merge;
        tab.handle.merge = (base, theirs, options) => {
            const result = real(base, theirs, options);
            if (result === null) {
                const ids = (json) => json.content.map((block) => block.attrs.id);
                tab.nullMerges.push({
                    base: ids(base),
                    mine: ids((options && options.mine) || tab.handle.editor.getJSON()),
                    theirs: ids(theirs),
                });
            }

            return result;
        };
    });

    /** token -> {author, block}: who typed what where. */
    const typed = new Map();
    /** Tokens that were in the deleter's own copy of a block when they deleted it, and whose deletion has been stored. */
    const ledger = deletionLedger(tokensIn, (name) => {
        const tab = tabs.find((candidate) => candidate.name === name);

        return Math.max(tab.view.baseVersion, tab.view.known ? tab.view.known.version : 0);
    });
    let sender = 'unknown';
    /** Invariant 6, judged as each save is stored, against the set as it stood then. */
    const droppedWithoutSaying = [];
    /** HELD, STALEBASE and BACK, found while the walk ran. */
    const savingFaults = [];
    let tokens = 0;
    let inserted = 0;

    const actions = [
        [
            30,
            () => {
                const tab = pick(tabs);
                const [id, text] = pick(tab.blocks());
                tokens += 1;
                const token = `t${tokens}`;
                typed.set(token, { author: tab.name, block: id });
                tab.typeIn(id, text ? `${text} ${token}` : token);
                trace.push(`${tab.name} types ${token} into ${id}`);
            },
        ],
        [
            8,
            () => {
                const tab = pick(tabs);
                const [afterId] = pick(tab.blocks());
                inserted += 1;
                tokens += 1;
                const id = `n${inserted}`;
                const token = `t${tokens}`;
                typed.set(token, { author: tab.name, block: id });
                tab.insertAfter(afterId, id, token);
                trace.push(`${tab.name} inserts ${id} (${token}) after ${afterId}`);
            },
        ],
        [
            6,
            () => {
                const tab = pick(tabs);
                const blocks = tab.blocks();
                if (blocks.length < 2) {
                    return;
                }
                const [id] = pick(blocks);
                ledger.deleted(tab.name, tokensIn({ content: [tab.block(id)] }));
                tab.deleteBlock(id);
                trace.push(`${tab.name} deletes ${id}`);
            },
        ],
        [
            4,
            () => {
                const tab = pick(tabs);
                const [id] = pick(tab.blocks());
                const align = pick(['left', 'center', 'right', null]);
                tab.setAttr(id, 'align', align);
                trace.push(`${tab.name} aligns ${id} ${align}`);
            },
        ],
        [
            4,
            () => {
                const tab = pick(tabs);
                const blocks = tab.blocks();
                if (blocks.length < 2) {
                    return;
                }
                const [id] = pick(blocks);
                const others = blocks.filter(([other]) => other !== id);
                const afterId = random() < 0.2 ? null : pick(others)[0];
                tab.moveBlock(id, afterId);
                trace.push(`${tab.name} moves ${id} after ${afterId}`);
            },
        ],
        [
            12,
            async () => {
                const tab = pick(tabs);
                await tab.debounce();
                trace.push(`${tab.name} debounce`);
            },
        ],
        [
            10,
            () => {
                const tab = pick(tabs);
                if (tab.saves.some((save) => save.result === undefined)) {
                    tab.saveArrives();
                    trace.push(`${tab.name} save arrives (server v${w.server.version})`);
                }
            },
        ],
        [
            10,
            async () => {
                const tab = pick(tabs);
                if (tab.saves.length > 0 && tab.saves[0].result !== undefined) {
                    await tab.saveReturns();
                    trace.push(`${tab.name} save returns`);
                }
            },
        ],
        [
            10,
            async () => {
                const tab = pick(tabs);
                await pollOnce(tab);
                trace.push(`${tab.name} polls (server v${w.server.version})`);
            },
        ],
        [
            4,
            () => {
                w.clock.now += 1500;
                trace.push('clock +1.5 s');
            },
        ],
        [
            1,
            () => {
                const tab = pick(tabs);
                if (tab.view.lost.length > 0) {
                    tab.host().putBackAllLost();
                    trace.push(`${tab.name} puts them all back`);
                }
            },
        ],
        [
            1,
            () => {
                const tab = pick(tabs);
                if (tab.view.lost.length > 0) {
                    tab.host().dismissLost();
                    trace.push(`${tab.name} dismisses all`);
                }
            },
        ],
    ];
    const total = actions.reduce((sum, [weight]) => sum + weight, 0);
    const draw = () => {
        let roll = random() * total;
        for (const [weight, action] of actions) {
            roll -= weight;
            if (roll < 0) {
                return action;
            }
        }

        return actions[0][1];
    };

    for (let step = 0; step < STEPS; step += 1) {
        await draw()();
        checkBases(trace[trace.length - 1] ?? 'start');
    }
    await quiesce(tabs, trace);
    checkBases('quiesce');
    judgeStoredSaves();

    return { w, tabs, typed, ledger, droppedWithoutSaying, savingFaults, trace };
}

for (const seed of SEEDS) {
    for (const tabCount of [2, 3]) {
        test(`seed ${seed}, ${tabCount} tabs: every tab converges on the server and no token is silently lost`, async () => {
            const { w, tabs, typed, ledger, droppedWithoutSaying, savingFaults, trace } = await walk(seed, tabCount);
            const { server } = w;
            const because = (what) => `${what} (seed ${seed}, ${tabCount} tabs; ${replayWith(seed)})\n${trace.slice(-TRACE_TAIL).join('\n')}`;

            // 1. Convergence.
            tabs.forEach((tab) => {
                assert.deepEqual(tab.handle.editor.getJSON(), server.json, because(`${tab.name} differs from the server`));
            });

            // 2. No silent loss.
            const kept = new Set(tokensIn(server.json));
            tabs.forEach((tab) => {
                tab.view.lost.forEach((entry) => tokensIn({ content: [entry.json] }).forEach((token) => kept.add(token)));
                // Every park the tab ever wrote (the latest, and the earlier ones kept under stale-<uuid>@<ms>).
                tab.parks().forEach((row) => tokensIn(JSON.parse(row.json)).forEach((token) => kept.add(token)));
                const park = tab.drafts.get(LOST);
                if (park) {
                    // What is offered, and what Dismiss all moved aside: both are in the browser.
                    [...park.entries, ...park.dismissed].forEach((entry) => tokensIn({ content: [entry.json] }).forEach((token) => kept.add(token)));
                }
            });
            server.kept.forEach((row) => tokensIn(row.json).forEach((token) => kept.add(token)));
            const silentlyLost = [...typed.keys()].filter((token) => !kept.has(token) && !ledger.excuses(token));
            assert.deepEqual(
                silentlyLost,
                [],
                because(`tokens lost without a trace: ${silentlyLost.map((token) => `${token} by ${typed.get(token).author} in ${typed.get(token).block}`).join(', ')}`)
            );

            // 3. Unique ids.
            assert.ok(unique(idsIn(server.json)), because(`the server holds a duplicate id: ${idsIn(server.json).join(', ')}`));
            tabs.forEach((tab) => {
                assert.ok(unique(idsIn(tab.handle.editor.getJSON())), because(`${tab.name} holds a duplicate id`));
            });

            // 4. No merge was impossible, and no notice stands once everything
            //    has landed (a notice during the walk is the MERGE_ATTEMPTS
            //    bound, answered with Keep mine in the quiesce). 5. Nothing owed.
            tabs.forEach((tab) => {
                assert.deepEqual(tab.nullMerges, [], because(`${tab.name}: a merge was impossible: ${JSON.stringify(tab.nullMerges)}`));
                assert.equal(tab.view.conflict, null, because(`${tab.name} shows the conflict notice`));
                assert.equal(tab.view.overwriteOwed, false, because(`${tab.name} still owes an overwrite`));
            });

            // 6. A head that lost a token nobody knowingly deleted was kept
            //    (judged as each save was stored, against who had deleted what by then).
            assert.deepEqual(droppedWithoutSaying, [], because(`a save dropped what nobody deleted: ${droppedWithoutSaying.join('; ')}`));

            // PARK-KEPT: a park is never dropped by a later park.
            tabs.forEach((tab) => {
                const held = tab.parks().map((row) => row.json);
                tab.parkLog.forEach((json) => {
                    const at = held.indexOf(json);
                    assert.notEqual(at, -1, because(`PARK-KEPT: ${tab.name} lost a park it wrote (${json.slice(0, 80)})`));
                    held.splice(at, 1);
                });
            });

            // HELD, STALEBASE, BACK: the saving itself.
            assert.deepEqual(savingFaults, [], because(`the saving broke its own rules: ${savingFaults.slice(0, 5).join('; ')}`));

            // And the walk did something.
            assert.ok(typed.size > 0, because('nothing was typed'));
            assert.ok(blocksOf(server.json).length >= 1);
        });
    }
}

// The ledger of knowing deletions (support/syncWorld.js) that invariant 6 and
// the walk of failures judge by. It is the walks' own measuring stick, so it is
// measured here: a deletion excuses its own person, and only until the token
// has come back after they saw the deletion stored.
test('deletionLedger: a deletion excuses its own person and ends once the token is back and was seen', () => {
    const doc = (...words) => ({ content: [{ type: 'paragraph', content: words.map((text) => ({ type: 'text', text })) }] });
    const row = (version, from, to) => ({ version, from, to });
    let seen = { A: 0, B: 0 };
    const ledger = deletionLedger(tokensIn, (person) => seen[person]);

    // A deletes t1 (in A's copy of the block); A's save that stores it is excused, B's drop of it would not be.
    ledger.deleted('A', ['t1']);
    assert.deepEqual(ledger.stored(row(2, doc('t1', 't2'), doc('t2')), 'B'), ['t1'], 'another person dropping it is not excused');
    ledger.stored(row(3, doc('t2'), doc('t1', 't2')), 'B'); // it is back
    assert.deepEqual(ledger.stored(row(4, doc('t1', 't2'), doc('t2')), 'A'), [], 'A deleted it, and has not seen anything since');

    // A has now seen version 4 (the deletion stored) and the token comes back: a later drop is silent, A's too.
    seen = { A: 4, B: 4 };
    ledger.stored(row(5, doc('t2'), doc('t1', 't2')), 'B');
    assert.deepEqual(ledger.stored(row(6, doc('t1', 't2'), doc('t2')), 'A'), ['t1'], 'it came back after the deletion was stored and seen');
    assert.equal(ledger.excuses('t1'), false);

    // Two people delete it while it is there; the first one's stored deletion does not excuse the second one's.
    ledger.stored(row(7, doc('t2'), doc('t3', 't2')), 'B');
    ledger.deleted('A', ['t3']);
    ledger.deleted('B', ['t3']);
    assert.deepEqual(ledger.stored(row(8, doc('t3', 't2'), doc('t2')), 'B'), []);
    ledger.stored(row(9, doc('t2'), doc('t3', 't2')), 'A');
    assert.deepEqual(ledger.stored(row(10, doc('t3', 't2'), doc('t2')), 'A'), [], 'A\'s own deletion was never stored, so it still stands');
});
