import assert from 'node:assert/strict';
import test from 'node:test';

import { SAVE_EXPIRY_MS } from '../../resources/js/editor/sync/host.js';
import { BASE, LOST, STALE, UUID, blocksOf, deletionLedger, para, world } from './support/syncWorld.js';

/**
 * The random walk with the failures of section 11 switched on. Same fake world
 * as sync.merge.walk.test.js, but the steps also include: a save whose answer
 * is lost (and may come late), a save request that fails, a poll that fails,
 * the clock jumping past the 15 s expiries, an IME composition, an impossible
 * merge for a stretch, a restore or import by somebody else, an accepted
 * suggestion, a tab that dies and reopens over its own draft store, an
 * individual Put it back, and a browser that keeps no drafts.
 *
 * Checked at every save request and after every step:
 *   A. "Saved" is never said while the editor holds a token no stored version
 *      ever had;
 *   HELD       no save request leaves a tab while its merge fetch is out;
 *   STALEBASE  no save states a base lower than the version the tab knows;
 *   BACK       a tab's base version never decreases;
 *   PARK-KEPT  a park is never dropped by a later park: every stale- park ever
 *              written is still in its browser's store (the latest on the
 *              plain key, the earlier ones under stale-<uuid>@<ms> keys);
 *   EXP        after a poll or a failed poll, no merge fetch is still out 15 s
 *              after it began (the expiry has fired);
 * Checked once everything has landed:
 *   B. an accepted save that dropped a token nobody knowingly deleted said it
 *      overwrites (a deletion excuses the drops of the person who made it; a
 *      token that comes back after the deletion was stored is not excused
 *      again once it is on that person's page, see deletionLedger in
 *      support/syncWorld.js);
 *   C. no document is sent twice on one base, unless the first request failed
 *      or was given up on (15 s), or an accepted suggestion retired that very
 *      request (and only a send made after the suggestion repeats it), or the
 *      first was refused and the editor changed (and changed back) after it left;
 *   D. every tab converges on the server's document, and a fresh token typed
 *      on each tab is saved;
 *   E. no token is lost without a trace (a version, the lost row, a set-aside
 *      text, a draft, or a park);
 *   F. no merge returned null with the merge not made impossible, and no
 *      notice or merge is left standing;
 *   G. no overwrite is still owed;
 *   H. no duplicate block id.
 *
 * Parking never overwrites (Task 12b): parkStaleDraft() keeps the park it
 * would replace under a second key, so every park ever written is a place
 * where its words are kept, and no excuse is made for a replaced park (none
 * is replaced). What IS excused is unchanged: the writer's own deletions
 * (deletionLedger); the unsaved typing a writer knowingly replaces by
 * ACCEPTING Put it back for a set-aside text (a no changes nothing, so a
 * declined Put it back excuses nothing and a loss there fails E); and a
 * browser that keeps no drafts.
 *
 * What it does NOT cover: characters inside one block, two tabs of one
 * browser sharing a draft, and the server's own failures.
 *
 * Replay: ADV_SEED=<n> [ADV_TABS=<2|3>] node --test tests/js/sync.merge.walk.failures.test.js
 * Narrow: ADV_FEATURES=lose,fail,die,replace,suggest,compose,mergeFails,putback,pollfail,expire (any subset).
 * Wider: ADV_SEEDS=500 ADV_STEPS=400.
 */
const STEPS = Number(process.env.ADV_STEPS ?? 250);
const SEEDS = process.env.ADV_SEED
    ? [Number(process.env.ADV_SEED)]
    : Array.from({ length: Number(process.env.ADV_SEEDS ?? 100) }, (_, index) => index + 1);
const TAB_COUNTS = process.env.ADV_TABS ? [Number(process.env.ADV_TABS)] : [2, 3];
const FEATURES = new Set(
    (process.env.ADV_FEATURES ?? 'lose,fail,die,replace,suggest,compose,mergeFails,putback,pollfail,expire').split(',')
);
const on = (name, weight) => (FEATURES.has(name) ? weight : 0);
const TOKEN = /^t\d+$/;
const nextTurn = () => new Promise((resolve) => setImmediate(resolve));

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

/** The tokens (t1, t2, ...) the walk typed that a document holds. */
function tokensIn(json) {
    const found = new Set();
    const collect = (node) => {
        if (!node || !Array.isArray(node.content)) {
            return;
        }
        node.content.forEach((child) => {
            if (child.type === 'text') {
                child.text.split(/\s+/).forEach((word) => {
                    if (TOKEN.test(word)) {
                        found.add(word);
                    }
                });
            } else {
                collect(child);
            }
        });
    };
    collect(json);

    return found;
}

const tokensOfString = (serialised) => (serialised ? tokensIn(JSON.parse(serialised)) : new Set());
const tokensOfEntry = (entry) => tokensIn({ content: [entry.json] });
const idsIn = (json) => json.content.map((block) => block.attrs.id);
const unique = (ids) => new Set(ids).size === ids.length;

async function run(seed, tabCount) {
    const random = mulberry32(seed);
    const pick = (list) => list[Math.floor(random() * list.length)];
    const w = world();
    const { server, clock } = w;
    const slots = []; // one browser per person: a reopened tab takes over its slot's draft store
    const allTabs = [];
    const trace = [];
    const violations = [];
    const typed = new Map(); // token -> who typed it, and where
    // Tokens in a block somebody deleted on purpose, and whose save dropped
    // them; excused until that person's page holds the token again (onPage).
    const ledger = deletionLedger(tokensIn);
    const pagesSeen = () => slots.forEach((tab) => ledger.onPage(tab.slot, tokensIn(tab.handle.editor.getJSON())));
    let actorSlot = -1;
    const everStored = new Set(); // tokens any stored version has ever held
    const excused = new Set(); // tokens the writer, or a browser without drafts, knowingly let go
    const lateAnswers = []; // lost answers that may still arrive
    const parkLog = []; // per slot (one browser, one draft store): every park its browsers ever wrote
    let tokens = 0;
    let inserted = 0;
    let generation = 0;
    let events = 0; // orders the sends and the answers of saves, for invariant C
    let actor = 'unknown';
    const actors = new Map();
    tokensIn(server.json).forEach((token) => everStored.add(token));

    // B, judged as each version is stored: who sent it decides what was knowingly deleted.
    let judged = 0;
    const judgeStoredVersions = () => {
        pagesSeen();
        while (judged < server.trace.length) {
            const row = server.trace[judged];
            judged += 1;
            const silent = ledger.stored(row, actorSlot);
            if (silent.length > 0 && !row.overwrite) {
                violations.push(`B: version ${row.version} dropped ${silent.join(',')} from the head without saying it overwrites (by ${JSON.stringify(actors.get(row.version))})`);
            }
        }
    };
    const realSave = server.save.bind(server);
    server.save = (json, base, overwrite = false) => {
        const result = realSave(json, base, overwrite);
        if (result.ok) {
            actors.set(result.version, { actor, base, overwrite });
        }
        judgeStoredVersions();

        return result;
    };
    const realReplace = server.replace.bind(server);
    server.replace = (json, options) => {
        const version = realReplace(json, options);
        actorSlot = -1; // a restore, an import, an accepted suggestion: nobody's deletion
        judgeStoredVersions();

        return version;
    };
    // Who is sending: the slot is the person (a reopened tab is the same person's browser).
    const sends = (tab, what) => {
        actor = `${tab.name}.${what}`;
        actorSlot = tab.slot;
    };

    const track = (tab) => {
        const realArrive = tab.saveArrives;
        tab.saveArrives = () => {
            sends(tab, 'saveArrives');

            return realArrive();
        };
        const realLeave = tab.leave;
        tab.leave = () => {
            sends(tab, 'beacon');

            return realLeave();
        };
        // When each save was sent and answered, for invariant C.
        const realSend = tab.env.save;
        tab.env.save = (json, baseVersion, overwrite) => {
            if (tab.view.merging) {
                violations.push(`HELD: ${tab.name} sent a save while its merge fetch was out`);
            }
            if (tab.view.known && baseVersion < tab.view.known.version) {
                violations.push(`STALEBASE: ${tab.name} sent a save on v${baseVersion} while it knows v${tab.view.known.version}`);
            }
            const promise = realSend(json, baseVersion, overwrite);
            const record = tab.sent[tab.sent.length - 1];
            record.at = clock.now;
            record.sendSeq = ++events;
            record.key = `${baseVersion}|${overwrite}|${JSON.stringify(json)}`;
            record.answered = false;
            record.edits = tab.edits;
            promise.then(
                (result) => {
                    record.answered = 'ok';
                    record.refused = result?.ok === false;
                    record.answerSeq = ++events;
                },
                () => {
                    record.answered = 'failed';
                    record.answerSeq = ++events;
                }
            );

            return promise;
        };
        // How many times the writer changed the editor, for invariant C: a byte-identical document can be sent
        // again after a refusal when the editor changed and changed back in between.
        tab.edits = 0;
        const realHost = tab.host;
        tab.host = () => {
            const host = realHost();
            const edited = host.edited;
            host.edited = (...args) => {
                tab.edits += 1;

                return edited(...args);
            };

            return host;
        };
        tab.nullMerges = 0;
        const realMerge = tab.handle.merge;
        tab.handle.merge = (base, theirs, options) => {
            const result = realMerge(base, theirs, options);
            if (result === null && !tab.mergeFails) {
                tab.nullMerges += 1;
            }

            return result;
        };
        allTabs.push(tab);
    };

    const openSlot = async (index, options = {}) => {
        const drafts = slots[index] ? slots[index].drafts : new Map();
        parkLog[index] ??= [];
        generation += 1;
        const tab = w.tab(`${String.fromCharCode(65 + index)}${generation}`, { drafts, ...options });
        tab.slot = index;
        tab.drafts = drafts;
        tab.noDrafts = options.draftStore === false;
        track(tab);
        if (tab.draftStore) {
            // PARK-KEPT: log every park written; parking never overwrites, so none may go (parksKept, below).
            const realPark = tab.draftStore.parkStaleDraft;
            tab.draftStore.parkStaleDraft = async (uuid, json, baseVersion) => {
                await realPark(uuid, json, baseVersion);
                parkLog[index].push({ json, by: tab.name, at: events });
                parksKept(`${tab.name} parked`);
            };
        }
        slots[index] = tab;
        await tab.open();

        return tab;
    };

    for (let index = 0; index < tabCount; index += 1) {
        await openSlot(index, index === tabCount - 1 && random() < 0.15 ? { draftStore: false } : {});
    }
    for (const tab of slots) {
        await tab.poll();
    }
    for (const tab of slots) {
        await tab.cycle();
    }

    const editorTokens = (tab) => tokensIn(tab.handle.editor.getJSON());
    const excuseUnstored = (tab) => {
        editorTokens(tab).forEach((token) => {
            if (!everStored.has(token)) {
                excused.add(token);
            }
        });
    };
    // Put it back over unsaved typing replaces that typing, knowingly, once the writer says yes. The words are
    // excused ONLY when the offer was taken (it is gone from the page afterwards): a no changes nothing, and
    // anything lost on that path is a real loss that must fail E.
    const putBackExcusing = (tab) => {
        const unsaved = tab.view.unsaved ? [...editorTokens(tab)].filter((token) => !everStored.has(token)) : [];
        tab.host().putBack();
        if (!tab.view.setAside) {
            unsaved.forEach((token) => excused.add(token));
        }
    };

    // PARK-KEPT: every park ever written is still in the store of its browser (as many rows as were written with that text).
    const reportedParks = new Set(); // one report per lost park, not one per step after it
    const parksKept = (label) => {
        parkLog.forEach((written, index) => {
            if (!slots[index]) {
                return;
            }
            const held = new Map();
            slots[index].parks().forEach((row) => held.set(row.json, (held.get(row.json) ?? 0) + 1));
            const wanted = new Map();
            written.forEach((park) => wanted.set(park.json, (wanted.get(park.json) ?? 0) + 1));
            for (const [json, count] of wanted) {
                if ((held.get(json) ?? 0) < count && !reportedParks.has(`${index}|${json}`)) {
                    reportedParks.add(`${index}|${json}`);
                    violations.push(
                        `PARK-KEPT: browser ${index} lost a park holding ${[...tokensOfString(json)].join(',')} (${count} written, ${held.get(json) ?? 0} held; after: ${label})`
                    );
                }
            }
        });
    };

    // EXP: a poll or a failed poll is when a merge fetch that began 15 s ago must be given up on.
    const checkExpiry = (tab) => {
        if (tab.view.merging && clock.now - tab.view.merging.since >= SAVE_EXPIRY_MS) {
            violations.push(`EXP: ${tab.name} still merging ${clock.now - tab.view.merging.since} ms after the fetch began`);
        }
    };

    // A. checked after every step
    const afterStep = (label) => {
        parksKept(label);
        pagesSeen();
        server.trace.forEach((row) => tokensIn(row.to).forEach((token) => everStored.add(token)));
        server.kept.forEach((row) => tokensIn(row.json).forEach((token) => everStored.add(token)));
        for (const tab of slots) {
            if (tab.lastBase !== undefined && tab.view.baseVersion < tab.lastBase) {
                violations.push(`BACK: ${tab.name} base went back from ${tab.lastBase} to ${tab.view.baseVersion} (after: ${label})`);
            }
            tab.lastBase = tab.view.baseVersion;
        }
        for (const tab of slots) {
            const untouched = tab.sent.length === 0 && tab.reports.length === 1;
            if (tab.word() === 'Saved' && !untouched) {
                const stray = [...editorTokens(tab)].filter((token) => !everStored.has(token));
                if (stray.length > 0) {
                    violations.push(`A: ${tab.name} says Saved while the editor holds ${stray.join(',')} that no stored version ever had (after: ${label})`);
                }
            }
        }
    };

    const type = () => {
        const tab = pick(slots);
        const [id, text] = pick(tab.blocks());
        tokens += 1;
        const token = `t${tokens}`;
        typed.set(token, { author: tab.name, block: id });
        tab.typeIn(id, text ? `${text} ${token}` : token);
        trace.push(`${tab.name} types ${token} into ${id}`);
    };

    // The writer's answer to a notice, mid-walk and in the quiesce.
    const answerNotice = async (tab, chooseKeep) => {
        if (chooseKeep) {
            tab.host().keepMine();
            trace.push(`${tab.name} keeps mine`);

            return;
        }
        // The writer confirmed replacing the text on offer. With a draft store it stays parked (parking never
        // overwrites); a browser that keeps no drafts has no park, so there the confirmed replacement is the
        // 'browser-without-drafts' excuse, as on the other paths below.
        if (tab.noDrafts && tab.view.setAside) {
            tokensOfString(tab.view.setAside).forEach((token) => excused.add(token));
        }
        await tab.host().loadTheirs();
        trace.push(`${tab.name} loads theirs`);
        if (tab.view.setAside && random() < 0.5) {
            putBackExcusing(tab);
            trace.push(`${tab.name} puts it back (setAside)`);
        }
    };

    const actions = [
        [24, type],
        [
            6,
            () => {
                const tab = pick(slots);
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
            5,
            () => {
                const tab = pick(slots);
                const blocks = tab.blocks();
                if (blocks.length < 2) {
                    return;
                }
                const [id] = pick(blocks);
                ledger.deleted(tab.slot, tokensIn({ content: [tab.block(id)] }));
                tab.deleteBlock(id);
                trace.push(`${tab.name} deletes ${id}`);
            },
        ],
        [
            3,
            () => {
                const tab = pick(slots);
                const [id] = pick(tab.blocks());
                const align = pick(['left', 'center', null]);
                tab.setAttr(id, 'align', align);
                trace.push(`${tab.name} aligns ${id} ${align}`);
            },
        ],
        [
            3,
            () => {
                const tab = pick(slots);
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
            10,
            async () => {
                const tab = pick(slots);
                await tab.debounce();
                trace.push(`${tab.name} debounce`);
            },
        ],
        [
            9,
            () => {
                const tab = pick(slots);
                if (tab.saves.some((save) => save.result === undefined)) {
                    tab.saveArrives();
                    trace.push(`${tab.name} save arrives (v${server.version})`);
                }
            },
        ],
        [
            9,
            async () => {
                const tab = pick(slots);
                if (tab.saves.length > 0 && tab.saves[0].result !== undefined) {
                    await tab.saveReturns();
                    trace.push(`${tab.name} save returns`);
                }
            },
        ],
        // The answer is lost (maybe after the server stored the save); it may come late.
        [
            on('lose', 3),
            () => {
                const tab = pick(slots);
                if (tab.saves.length === 0) {
                    return;
                }
                const record = tab.saveNeverAnswers();
                if (record.result === undefined && random() < 0.5) {
                    sends(tab, 'lostAnswerStored');
                    record.result = server.save(record.json, record.base, record.overwrite);
                }
                lateAnswers.push({ tab, record });
                trace.push(`${tab.name} loses a save's answer (stored=${record.result !== undefined && record.result.ok})`);
            },
        ],
        [
            on('lose', 2),
            async () => {
                if (lateAnswers.length === 0) {
                    return;
                }
                const { tab, record } = lateAnswers.splice(Math.floor(random() * lateAnswers.length), 1)[0];
                if (record.result === undefined) {
                    sends(tab, 'late');
                    record.result = server.save(record.json, record.base, record.overwrite);
                }
                record.resolve(record.result);
                await nextTurn();
                trace.push(`${tab.name} a lost save's answer arrives late`);
            },
        ],
        // The request itself fails, stored or not.
        [
            on('fail', 2),
            async () => {
                const tab = pick(slots);
                if (tab.saves.length === 0) {
                    return;
                }
                const record = tab.saves.shift();
                if (record.result === undefined && random() < 0.5) {
                    sends(tab, 'failedButStored');
                    server.save(record.json, record.base, record.overwrite);
                }
                record.reject(new Error('network'));
                await nextTurn();
                trace.push(`${tab.name} save request fails`);
            },
        ],
        [
            8,
            async () => {
                const tab = pick(slots);
                if (tab.polls.length === 0 && tab.timer) {
                    await tab.fireTimer();
                }
                if (tab.polls.length > 0) {
                    await tab.poll();
                    trace.push(`${tab.name} polls (v${server.version})`);
                    checkExpiry(tab);
                }
            },
        ],
        [
            on('pollfail', 2),
            async () => {
                const tab = pick(slots);
                if (tab.polls.length === 0 && tab.timer) {
                    await tab.fireTimer();
                }
                if (tab.polls.length > 0) {
                    await (random() < 0.5 ? tab.pollAnswers(500, null) : tab.pollFails());
                    trace.push(`${tab.name} poll fails`);
                    checkExpiry(tab);
                }
            },
        ],
        [
            4,
            () => {
                clock.now += 1500;
                trace.push('clock +1.5 s');
            },
        ],
        [
            on('expire', 2),
            () => {
                clock.now += SAVE_EXPIRY_MS + 100;
                trace.push('clock +15 s');
            },
        ],
        [
            on('compose', 2),
            () => {
                const tab = pick(slots);
                tab.composing = !tab.composing;
                trace.push(`${tab.name} composing=${tab.composing}`);
            },
        ],
        [
            on('mergeFails', 2),
            () => {
                const tab = pick(slots);
                tab.mergeFails = !tab.mergeFails;
                trace.push(`${tab.name} mergeFails=${tab.mergeFails}`);
            },
        ],
        // A restore or an import by somebody else: a whole different document, stored with no base.
        [
            on('replace', 2),
            () => {
                const rows = server.kept.length > 0 ? server.kept : [{ json: server.json }];
                const old = JSON.parse(JSON.stringify(random() < 0.5 ? pick(rows).json : server.json));
                const edited = JSON.parse(JSON.stringify(old));
                if (random() < 0.5 && edited.content.length > 1) {
                    edited.content.shift();
                }
                tokens += 1;
                inserted += 1;
                const token = `t${tokens}`;
                edited.content.push(para(`n${inserted}`, token));
                typed.set(token, { author: 'import', block: `n${inserted}` });
                server.replace(edited); // what it drops from the head is in server.kept
                trace.push(`somebody restores/imports (v${server.version})`);
            },
        ],
        // An accepted suggestion on one tab.
        [
            on('suggest', 2),
            () => {
                const tab = pick(slots);
                const content = JSON.parse(JSON.stringify(server.json));
                tokens += 1;
                const token = `t${tokens}`;
                typed.set(token, { author: 'suggestion', block: content.content[0].attrs.id });
                content.content[0].content = [{ type: 'text', text: `suggested ${token}` }];
                const version = server.replace(content);
                // applySuggestion retires any save this tab has in the air; note which one and when, for invariant C.
                if (tab.view.saving > 0) {
                    const inTheAir = tab.sent.filter((record) => record.answered === false);
                    const suggestionSeq = ++events;
                    (inTheAir.length > 0 ? inTheAir : tab.sent.slice(-1)).forEach((record) => {
                        tab.retired ??= [];
                        tab.retired.push({ record, suggestionSeq });
                    });
                }
                tab.host().applySuggestion(content, version);
                trace.push(`${tab.name} accepts a suggestion (v${version})`);
            },
        ],
        [
            on('putback', 2),
            () => {
                const tab = pick(slots);
                if (tab.view.lost.length > 0) {
                    // Now and then the editor cannot take the block (insertBlock() returns false): the entry must stay.
                    tab.insertBlockFails = random() < 0.3;
                    tab.host().putBackLost(Math.floor(random() * tab.view.lost.length));
                    trace.push(`${tab.name} puts one back (insertBlock fails=${tab.insertBlockFails})`);
                    tab.insertBlockFails = false;
                }
            },
        ],
        [
            1,
            () => {
                const tab = pick(slots);
                if (tab.view.lost.length > 0) {
                    tab.host().putBackAllLost();
                    trace.push(`${tab.name} puts them all back`);
                }
            },
        ],
        [
            1,
            async () => {
                const tab = pick(slots);
                if (tab.view.lost.length === 0) {
                    return;
                }
                if (tab.noDrafts) {
                    tab.view.lost.forEach((entry) => tokensOfEntry(entry).forEach((token) => excused.add(token)));
                }
                await tab.host().dismissLost();
                trace.push(`${tab.name} dismisses all`);
            },
        ],
        // The writer answers a notice, or puts back a text set aside.
        [
            3,
            async () => {
                const tab = pick(slots);
                if (tab.view.conflict) {
                    await answerNotice(tab, random() < 0.5);
                } else if (tab.view.setAside && random() < 0.3) {
                    putBackExcusing(tab);
                    trace.push(`${tab.name} puts back set-aside`);
                }
            },
        ],
        // The tab dies (pagehide beacon) and the browser opens the document again.
        [
            on('die', 2),
            async () => {
                const tab = pick(slots);
                const arrive = random() < 0.5;
                if (tab.noDrafts) {
                    excuseUnstored(tab);
                    tab.view.lost.forEach((entry) => tokensOfEntry(entry).forEach((token) => excused.add(token)));
                    tokensOfString(tab.view.setAside).forEach((token) => excused.add(token));
                }
                tab.leave();
                if (arrive) {
                    while (tab.saves.some((save) => save.result === undefined)) {
                        tab.saveArrives();
                    }
                }
                // What the dead tab's draft still deletes relative to the row it was typed on is still the person's
                // deletion, even if a restore has since brought those tokens back and shown them to them (seed 42519):
                // the reopened tab's draft merge applies it again, by design (decision: a draft's deletion of a block
                // that looks unchanged applies; the replaced version is in the history either way).
                const draftRow = tab.drafts.get(UUID);
                const baseRow = tab.drafts.get(BASE);
                if (draftRow && baseRow && draftRow.baseVersion === baseRow.baseVersion) {
                    const kept = tokensOfString(draftRow.json);
                    const deletedByDraft = [...tokensOfString(baseRow.json)].filter((token) => !kept.has(token));
                    ledger.deleted(tab.slot, new Set(deletedByDraft));
                }
                // The old page is gone: nothing answers it any more.
                tab.saves.length = 0;
                tab.polls.length = 0;
                tab.engine?.stop();
                tab.mounted = false;
                tab.dead = true;
                const confirmAnswer = random() < 0.7;
                await openSlot(tab.slot, { confirmAnswer });
                trace.push(`${tab.name} dies and ${slots[tab.slot].name} reopens (confirm=${confirmAnswer})`);
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
        afterStep(trace[trace.length - 1] ?? 'start');
    }

    // ── Quiesce with a healthy network: the clock past every expiry, composition and impossible merges off,
    //    late answers delivered, notices answered, every tab given the chance to save and poll.
    clock.now += SAVE_EXPIRY_MS + 100;
    for (const tab of slots) {
        tab.composing = false;
        tab.mergeFails = false;
    }
    for (const { tab, record } of lateAnswers.splice(0)) {
        if (record.result === undefined) {
            sends(tab, 'quiesceLate');
            record.result = server.save(record.json, record.base, record.overwrite);
        }
        if (!tab.dead) {
            record.resolve(record.result);
            await nextTurn();
        }
    }
    for (let round = 1; round <= 80; round += 1) {
        for (const tab of slots) {
            if (tab.view.conflict) {
                await answerNotice(tab, random() < 0.5);
            }
        }
        clock.now += 1000;
        for (const tab of slots) {
            await tab.debounce();
            let guard = 0;
            while ((tab.saves.length > 0 || tab.view.merging) && guard++ < 40) {
                while (tab.saves.length > 0) {
                    if (tab.saves[0].result === undefined) {
                        tab.saveArrives();
                    }
                    await tab.saveReturns();
                }
                if (tab.view.merging) {
                    if (tab.polls.length === 0 && tab.timer) {
                        await tab.fireTimer();
                    }
                    if (tab.polls.length > 0) {
                        await tab.poll();
                    }
                }
            }
        }
        for (const tab of slots) {
            if (tab.polls.length === 0 && tab.timer) {
                await tab.fireTimer();
            }
            if (tab.polls.length > 0) {
                await tab.poll();
            }
        }
        const quiet = slots.every(
            (tab) =>
                !tab.view.unsaved &&
                tab.view.saving === 0 &&
                !tab.view.merging &&
                !tab.view.resave &&
                !tab.handle.pending &&
                tab.saves.length === 0 &&
                !tab.view.conflict
        );
        if (quiet) {
            trace.push(`quiesced after ${round} rounds`);
            break;
        }
        if (round === 80) {
            violations.push(
                `D: did not quiesce: ${slots
                    .map(
                        (tab) =>
                            `${tab.name}: unsaved=${tab.view.unsaved} saving=${tab.view.saving} merging=${JSON.stringify(tab.view.merging)} resave=${tab.view.resave} saves=${tab.saves.length} conflict=${JSON.stringify(tab.view.conflict)} word=${tab.word()}`
                    )
                    .join(' | ')}`
            );
        }
    }
    afterStep('quiesce');

    // ── D. convergence, then a fresh token typed on each tab must be saved.
    const converged = () => {
        slots.forEach((tab) => {
            if (JSON.stringify(tab.handle.editor.getJSON()) !== JSON.stringify(server.json)) {
                violations.push(
                    `D: ${tab.name} differs from the server: tab=${JSON.stringify(blocksOf(tab.handle.editor.getJSON()))} server=${JSON.stringify(blocksOf(server.json))} unsaved=${tab.view.unsaved} base=${tab.view.baseVersion}/${server.version} word=${tab.word()}`
                );
            }
        });
    };
    converged();
    if (!violations.some((violation) => violation.startsWith('D:'))) {
        for (const tab of slots) {
            tokens += 1;
            const token = `t${tokens}`;
            const [id, text] = tab.blocks()[0];
            typed.set(token, { author: tab.name, block: id });
            tab.typeIn(id, text ? `${text} ${token}` : token);
            await tab.debounce();
            for (let attempt = 0; attempt < 6; attempt += 1) {
                while (tab.saves.length > 0) {
                    if (tab.saves[0].result === undefined) {
                        tab.saveArrives();
                    }
                    await tab.saveReturns();
                }
                for (const other of slots) {
                    if (other.polls.length === 0 && other.timer) {
                        await other.fireTimer();
                    }
                    if (other.polls.length > 0) {
                        await other.poll();
                    }
                }
                await tab.debounce();
            }
            if (!tokensIn(server.json).has(token)) {
                violations.push(
                    `D: ${tab.name} could not save a fresh token (${token}) after the walk; unsaved=${tab.view.unsaved} merging=${JSON.stringify(tab.view.merging)} conflict=${JSON.stringify(tab.view.conflict)} saving=${tab.view.saving} resave=${tab.view.resave} word=${tab.word()}`
                );
            }
        }
        converged();
    }

    // ── E. no token is lost without a trace.
    const kept = new Set(tokensIn(server.json));
    server.kept.forEach((row) => tokensIn(row.json).forEach((token) => kept.add(token)));
    excused.forEach((token) => kept.add(token));
    slots.forEach((tab) => {
        tab.view.lost.forEach((entry) => tokensOfEntry(entry).forEach((token) => kept.add(token)));
        tokensOfString(tab.view.setAside).forEach((token) => kept.add(token));
        for (const [key, row] of tab.drafts.entries()) {
            if (key === UUID || key === STALE || key.startsWith(`${STALE}@`)) {
                // The live draft, the latest park, and every earlier park (kept under stale-<uuid>@<ms>).
                tokensOfString(row.json).forEach((token) => kept.add(token));
            } else if (key === LOST) {
                [...(row.entries ?? []), ...(row.dismissed ?? [])].forEach((entry) => tokensOfEntry(entry).forEach((token) => kept.add(token)));
            }
        }
    });
    const silently = [...typed.keys()].filter((token) => !kept.has(token) && !ledger.excuses(token));
    if (silently.length > 0) {
        violations.push(`E: tokens lost without a trace: ${silently.map((token) => `${token} by ${typed.get(token).author} in ${typed.get(token).block}`).join(', ')}`);
    }

    // ── PARK-KEPT, once more at the end.
    parksKept('the end');

    // ── B. a save that dropped content said it overwrites: judged as each version was stored (judgeStoredVersions).
    judgeStoredVersions();

    // ── C. no document is sent twice on one base, unless the first request failed or was given up on.
    allTabs.forEach((tab) => {
        const seen = new Map();
        tab.sent.forEach((record) => {
            if (!record.key) {
                return;
            }
            const earlier = seen.get(record.key);
            if (earlier) {
                const waited = record.at - earlier.at;
                const unansweredThen = !earlier.answered || earlier.answerSeq > record.sendSeq;
                // A failed request, or a save given up on after 15 s (it may answer late), may be sent again.
                let legitimate = (earlier.answered === 'failed' && earlier.answerSeq < record.sendSeq) || (unansweredThen && waited >= SAVE_EXPIRY_MS);
                // The one narrow allowance: an accepted suggestion retired THIS earlier request (applySuggestion zeroes
                // the count, by design). The suggestion cannot always be merged at once, so the tab keeps its typing
                // owed on the same, now stale, base and the next poll resends it; the server refuses it (it is already
                // past that base) and the normal conflict path runs. A wasted round trip, nothing lost. It excuses a
                // send made AFTER the suggestion that retired the earlier one, and only a repeat of that very request:
                // a third send repeats the second, which no suggestion retired.
                // Another: the earlier send was REFUSED (an answer, not a lost one) and the writer changed the editor
                // after it left, then changed it back (an undo, a composition's undone words): the same bytes, a new send.
                if (!legitimate && earlier.refused === true && record.edits > earlier.edits) {
                    legitimate = true;
                }
                if (!legitimate && tab.retired?.some((entry) => entry.record === earlier && record.sendSeq > entry.suggestionSeq)) {
                    legitimate = true;
                }
                if (!legitimate) {
                    violations.push(`C: ${tab.name} sent the same document on base ${record.base} twice (earlier answered=${earlier.answered}, waited ${waited} ms)`);
                }
            }
            seen.set(record.key, record);
        });
    });

    // ── F, G, H.
    allTabs.forEach((tab) => {
        if (tab.nullMerges > 0) {
            violations.push(`F: ${tab.name} had ${tab.nullMerges} impossible merge(s) with mergeFails off`);
        }
    });
    slots.forEach((tab) => {
        if (tab.view.conflict) {
            violations.push(`F: ${tab.name} still shows the notice ${JSON.stringify(tab.view.conflict)}`);
        }
        if (tab.view.merging) {
            violations.push(`F: ${tab.name} is still merging ${JSON.stringify(tab.view.merging)}`);
        }
        if (tab.view.overwriteOwed) {
            violations.push(`G: ${tab.name} still owes an overwrite after everything landed`);
        }
        if (!unique(idsIn(tab.handle.editor.getJSON()))) {
            violations.push(`H: ${tab.name} holds a duplicate id`);
        }
    });
    if (!unique(idsIn(server.json))) {
        violations.push('H: the server holds a duplicate id');
    }

    if (process.env.ADV_TRACE) {
        console.log(trace.join('\n'));
    }

    return { violations, trace };
}

for (const seed of SEEDS) {
    for (const tabCount of TAB_COUNTS) {
        test(`failure walk, seed ${seed}, ${tabCount} tabs`, async () => {
            const { violations, trace } = await run(seed, tabCount);

            assert.deepEqual(violations, [], `seed ${seed}, ${tabCount} tabs: replay with ADV_SEED=${seed} ADV_TABS=${tabCount}\n${trace.slice(-40).join('\n')}`);
        });
    }
}
