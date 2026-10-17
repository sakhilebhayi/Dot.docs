import assert from 'node:assert/strict';

import { Schema } from 'prosemirror-model';

import { createSyncEngine } from '../../../resources/js/editor/sync/engine.js';
import { createSyncHost, unloadSaveBody } from '../../../resources/js/editor/sync/host.js';
import { mergeDocuments } from '../../../resources/js/editor/sync/merge.js';
import { keepKeyFor, unionLostRows } from '../../../resources/js/offline.js';

/**
 * The fake world the sync host is tested in (sync.host.test.js) and walked
 * through at random (sync.merge.walk.test.js): the page's half of the sync
 * state machine (sync/host.js), driven together with the REAL polling
 * engine (sync/engine.js) and the REAL merge (sync/merge.js) against fakes
 * for everything outside those three:
 *
 *   - a fake server that keeps a document, a version and the versions it was
 *     told to keep, and answers saves as Editor::saveContent() does
 *     ({ok, conflict, version}) and polls as the sync endpoint does;
 *   - a fake editor handle that holds a BLOCK-SHAPED document (top-level
 *     blocks with ids, as the editor's are), follows the bundle's own rules
 *     for `pending` (the debounce is armed or a flush is owed) and for
 *     applyRemote() (it refuses over unsaved typing unless forced), merges
 *     with the real mergeDocuments() over the mini schema below, and puts a
 *     lost block back as insertBlock() does;
 *   - a fake draft store (the live draft, the stale- parks, the base- row and
 *     the lost- park), an injected clock and a captured timer. As offline.js
 *     does, parking never overwrites: the park that was there is kept under
 *     `stale-<uuid>@<ms>` (keepKeyFor) and the plain key holds the latest;
 *   - saves and polls that are answered BY HAND, so "a save in the air" is a
 *     state a test can hold for as long as it needs;
 *   - `leave()`, the page going away: the bundle's pagehide flush, which
 *     goes by beacon to the fake server's autosave endpoint.
 *
 * What stands in for the Blade component is `view`, a plain object with the
 * component's state fields, and `open()` / `startSync()`, which make the
 * same calls the component's init() makes. Every call builds its host from
 * the view afresh, exactly as the component's one-line delegates do.
 *
 * Not named *.test.js: `npm test` globs that pattern and this file holds no
 * cases of its own.
 */

/** Let every pending promise callback run. */
export const settle = () => new Promise((resolve) => setImmediate(resolve));
export const clone = (value) => JSON.parse(JSON.stringify(value));

export const UUID = 'doc-uuid';
export const STALE = `stale-${UUID}`;
export const LOST = `lost-${UUID}`;
export const BASE = `base-${UUID}`;

/**
 * The schema the fake editor's documents are parsed with when they are
 * merged. Small on purpose: paragraphs with an id and an alignment, an
 * isolating container (like a table or a figure), text. What it serialises
 * is exactly what para()/box()/docOf() build, so a test can compare a
 * JSON string the host holds with `JSON.stringify(doc('x'))`.
 */
export const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        text: { group: 'inline' },
    },
});

/** A paragraph, in Node.toJSON() order (type, attrs, content). */
export const para = (id, text = '') => ({
    type: 'paragraph',
    attrs: { id, align: null },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
export const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
export const docOf = (...blocks) => ({ type: 'doc', content: blocks });
/** The one-paragraph document every Phase 1 case is written against: block p1 holding `text`. */
export const doc = (text) => docOf(para('p1', text));

export const blockText = (block) =>
    block && Array.isArray(block.content) ? block.content.map((node) => node.text ?? '').join('') : '';
/** The text of block p1, or '' when it is gone. */
export const textOf = (json) => blockText(json.content.find((block) => block.attrs.id === 'p1'));
/** Every top-level block as [id, text]. */
export const blocksOf = (json) => json.content.map((block) => [block.attrs.id, blockText(block)]);

export class FakeServer {
    constructor(text) {
        this.version = 1;
        this.json = doc(text);
        /** Versions kept in the history before being replaced on purpose, as [{version, text}]. */
        this.history = [];
        /** The same, with the whole document, for the walk. */
        this.kept = [];
        /** Every accepted save: what it replaced, what it stored, and whether it said it overwrites. */
        this.trace = [];
        this.present = new Map();
        /** The next save is rejected, as DocumentSchema rejects a bad document. */
        this.rejectNext = false;
        /** Fresh ids the fake insertBlock() hands out: one counter per world, so two tabs never put a block back under one id. */
        this.fresh = 0;
    }

    /** The text of block p1 as the server holds it. */
    text() {
        return textOf(this.json);
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
            this.history.push({ version: this.version, text: this.text() });
            this.kept.push({ version: this.version, json: clone(this.json) });
        }

        this.trace.push({ version: this.version + 1, from: clone(this.json), to: clone(json), overwrite: !!overwrite });
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

    /**
     * A save that states no base: a style change, an accepted suggestion, a
     * restore, an import. `history` (what the Phase 1 tests read) is NOT
     * written, as Phase 1's fake wrote nothing there: a restyle cuts no
     * version. `kept` and `trace` record it for the walk.
     *
     * `keeps` says whether the head it replaces is kept as a version, as the
     * real store does for a restore, an import and an accepted suggestion
     * (true, the default) and does NOT for a restyle (false:
     * DocumentStore::restyle() keeps nothing). A restyle changes no text, so
     * the walk must not count its replaced head as a place a token is kept.
     */
    replace(json, { keeps = true } = {}) {
        this.trace.push({ version: this.version + 1, from: clone(this.json), to: clone(json), overwrite: keeps });
        if (keeps) {
            this.kept.push({ version: this.version, json: clone(this.json) });
        }
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
export const makeView = (version) => ({
    saving: 0,
    savingSince: 0,
    saveSeq: 0,
    expiredSaveSeq: 0,
    unsaved: false,
    resave: false,
    confirmed: null,
    overwriteOwed: false,
    overwriteFloor: null,
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
    // Phase 2
    merging: null,
    mergeAttempts: 0,
    known: null,
    sentSnapshot: null,
    unanswered: null,
    lost: [],
    putBackSeq: 0,
});

export function world(text = 'start') {
    const server = new FakeServer(text);
    const clock = { now: 1_000_000 };

    return { server, clock, tab: (name, options) => makeTab({ server, clock }, name, options) };
}

/**
 * One open editor tab.
 *
 * @param {{server: FakeServer, clock: {now: number}}} world
 * @param {string} name
 * @param {{failClosed?: boolean, drafts?: Map, draftStore?: boolean, confirmAnswer?: boolean, mergeFails?: boolean}} options
 */
export function makeTab({ server, clock }, name, options = {}) {
    const t = {
        name,
        view: { ...makeView(server.version), tabId: name },
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
        mergeCalls: 0,
        insertCalls: [],
        /** This editor cannot open what arrives. */
        refuses: false,
        setContentThrows: false,
        /** The merge is impossible (handle.merge() returns null): the Phase 1 notice path. */
        mergeFails: options.mergeFails ?? false,
        insertBlockFails: false,
        /** The park write of the lost row fails (parkLost() resolves false), or throws. */
        parkLostFails: false,
        parkLostThrows: false,
        /** An IME composition is in progress. */
        composing: false,
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
            t.beacons.push({ body, text: textOf(body.content), answer: server.autosave(body) });

            return true;
        }

        // The bundle's onChange, which the page wires to persist().
        t.host().persist(json);

        return true;
    };

    const findBlock = (id) => content.content.find((block) => block.attrs.id === id);
    const indexOf = (id) => content.content.findIndex((block) => block.attrs.id === id);
    const setText = (block, text) => {
        if (text) {
            block.content = [{ type: 'text', text }];
        } else {
            delete block.content;
        }
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
        get composing() {
            return t.composing;
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
            // As the editor's UniqueID does: a block that arrives without an id gets one (the merge's stand-in paragraph).
            content.content.forEach((block) => {
                if (block.attrs?.id === null || block.attrs?.id === undefined) {
                    server.fresh += 1;
                    block.attrs = { ...(block.attrs ?? {}), id: `fresh${server.fresh}` };
                }
            });
            saveTimer = null;
            dirty = false;
            lastSaved = JSON.stringify(content);

            return true;
        },
        /** The REAL merge, over the mini schema, with the editor's document as mine unless one is handed in. */
        merge(base, theirs, { echo = null, mine = null, deletionsWin = true } = {}) {
            t.mergeCalls += 1;
            if (!autosave || t.mergeFails) {
                return null;
            }

            return mergeDocuments(schema, { base, mine: mine ?? clone(content), theirs, echo }, { deletionsWin });
        },
        /** What insertBlock() does: after the first anchor on the page, else the end, else the start; a fresh id unless kept and free. */
        insertBlock(json, { anchors = [], keepId = false } = {}) {
            t.insertCalls.push({ json: clone(json), anchors: [...anchors], keepId });
            if (!json || !autosave || t.insertBlockFails) {
                return false;
            }
            const block = clone(json);
            const taken = new Set(content.content.map((candidate) => candidate.attrs.id));
            if (!(keepId && !taken.has(block.attrs.id))) {
                server.fresh += 1;
                block.attrs.id = `fresh${server.fresh}`;
            }
            let at = anchors.length > 0 ? content.content.length : 0;
            for (const anchor of anchors) {
                const index = indexOf(anchor);
                if (index !== -1) {
                    at = index + 1;
                    break;
                }
            }
            content.content.splice(at, 0, block);
            emitUpdate();

            return true;
        },
    };

    // The lost rows of a document in this browser's store: the plain `lost-<uuid>` and every `lost-<uuid>@<tab>`.
    const lostKeyOf = (uuid, tabId) => `lost-${uuid}${tabId ? `@${tabId}` : ''}`;
    const lostRowsOf = (uuid) =>
        [...t.drafts.entries()]
            .filter(([key]) => key === `lost-${uuid}` || key.startsWith(`lost-${uuid}@`))
            .map(([key, row]) => ({ ...row, docUuid: key }));

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
                      // As offline.js does: the earlier park is kept under a second key, the plain key holds the latest.
                      const plain = `stale-${uuid}`;
                      const earlier = t.drafts.get(plain);
                      if (earlier) {
                          t.drafts.set(keepKeyFor(uuid, earlier, [...t.drafts.keys()], clock.now), earlier);
                      }
                      t.drafts.set(plain, { json, baseVersion, parkedAt: clock.now });
                  },
                  saveBase: async (uuid, json, baseVersion) => {
                      t.draftCalls.push('saveBase');
                      t.drafts.set(`base-${uuid}`, { json, baseVersion });
                  },
                  loadBase: async (uuid) => {
                      t.draftCalls.push('loadBase');

                      return t.drafts.get(`base-${uuid}`) ?? null;
                  },
                  parkLost: async (uuid, entries, baseVersion, tabId = '') => {
                      t.draftCalls.push('parkLost');
                      // As offline.js does: false means nothing was stored (and nothing is thrown); the tests can make it so, or make it throw.
                      if (t.parkLostThrows) {
                          throw new Error('the store refused the write');
                      }
                      if (t.parkLostFails) {
                          return false;
                      }
                      // As offline.js does: this tab's OWN row, `entries` rewritten, `dismissed` carried over, the row's stamp goes.
                      const key = lostKeyOf(uuid, tabId);
                      const held = t.drafts.get(key);
                      t.drafts.set(key, {
                          docUuid: key,
                          entries: clone(entries),
                          dismissed: held ? clone(held.dismissed ?? []) : [],
                          baseVersion,
                          parkedAt: clock.now,
                          dismissedAt: null,
                      });

                      return true;
                  },
                  loadLost: async (uuid) => {
                      t.draftCalls.push('loadLost');

                      return clone(unionLostRows(lostRowsOf(uuid)));
                  },
                  adoptLost: async (uuid, tabId = '') => {
                      t.draftCalls.push('adoptLost');
                      const rows = lostRowsOf(uuid);
                      const union = unionLostRows(rows);
                      if (union === null) {
                          return null;
                      }
                      const own = lostKeyOf(uuid, tabId);
                      rows.forEach((row) => {
                          if (row.docUuid !== own) {
                              t.drafts.delete(row.docUuid);
                          }
                      });
                      t.drafts.set(own, {
                          docUuid: own,
                          entries: clone(union.entries),
                          dismissed: clone(union.dismissed),
                          baseVersion: union.baseVersion,
                          parkedAt: union.parkedAt || clock.now,
                          dismissedAt: union.dismissedAt,
                      });

                      return clone(union);
                  },
                  markLostDismissed: async (uuid, tabId = '') => {
                      t.draftCalls.push('markLostDismissed');
                      const row = t.drafts.get(lostKeyOf(uuid, tabId));
                      if (row) {
                          row.dismissed = [...(row.dismissed ?? []), ...row.entries];
                          row.entries = [];
                          row.dismissedAt = clock.now;
                      }
                  },
              };

    // ---- everything the page hands the module
    t.env = {
        handle: () => (t.mounted ? t.handle : null),
        engine: () => t.engine,
        save: (json, baseVersion, overwrite) =>
            new Promise((resolve, reject) => {
                const save = { json: clone(json), text: textOf(json), base: baseVersion, overwrite, resolve, reject, result: undefined };
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
    /** Type into block `id`: its text becomes `text`. */
    t.typeIn = (id, text) => {
        const block = findBlock(id);
        assert.ok(block, `${name} has no block ${id} to type in`);
        setText(block, text);
        emitUpdate();
    };
    /** Type into p1, the one block every Phase 1 case has. */
    t.type = (text) => t.typeIn('p1', text);
    /** A new paragraph after `afterId` (null: at the top). */
    t.insertAfter = (afterId, newId, text) => {
        const at = afterId === null ? 0 : indexOf(afterId) + 1;
        assert.ok(at > 0 || afterId === null, `${name} has no block ${afterId} to insert after`);
        content.content.splice(at, 0, para(newId, text));
        emitUpdate();
    };
    /** Delete block `id`. Never the last one: the editor's `doc` is block+. */
    t.deleteBlock = (id) => {
        assert.ok(content.content.length > 1, `${name} cannot delete its last block`);
        assert.notEqual(indexOf(id), -1, `${name} has no block ${id} to delete`);
        content.content = content.content.filter((block) => block.attrs.id !== id);
        emitUpdate();
    };
    /** Move block `id` to directly after `afterId` (null: to the top). */
    t.moveBlock = (id, afterId) => {
        const block = findBlock(id);
        assert.ok(block, `${name} has no block ${id} to move`);
        content.content = content.content.filter((candidate) => candidate.attrs.id !== id);
        const at = afterId === null ? 0 : indexOf(afterId) + 1;
        content.content.splice(at, 0, block);
        emitUpdate();
    };
    /** An attribute change: a whole-block change under D1/D2. */
    t.setAttr = (id, attribute, value) => {
        const block = findBlock(id);
        assert.ok(block, `${name} has no block ${id}`);
        block.attrs[attribute] = value;
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
    /** The text of p1. */
    t.text = () => textOf(content);
    t.blocks = () => blocksOf(content);
    t.block = (id) => clone(findBlock(id) ?? null);
    t.word = () => t.reports.at(-1)?.word;
    t.words = () => t.reports.map((report) => report.word);
    t.draft = () => (t.drafts.has(UUID) ? textOf(JSON.parse(t.drafts.get(UUID).json)) : null);
    t.stale = () => (t.drafts.has(STALE) ? textOf(JSON.parse(t.drafts.get(STALE).json)) : null);
    /** Every park the store holds for the document: the kept ones (oldest first), then the latest. */
    t.parks = () => [
        ...[...t.drafts.entries()].filter(([key]) => key.startsWith(`${STALE}@`)).sort((a, b) => a[1].parkedAt - b[1].parkedAt).map(([, row]) => row),
        ...(t.drafts.has(STALE) ? [t.drafts.get(STALE)] : []),
    ];
    t.lostTexts = () => t.view.lost.map((entry) => entry.excerpt);
    t.lostPark = () => t.drafts.get(lostKeyOf(UUID, t.view.tabId)) ?? t.drafts.get(LOST) ?? null;

    return t;
}

/** Two people with the same document open, each already told the other is here. */
export async function pair(options = {}) {
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
 *
 * B's merge is IMPOSSIBLE here (`mergeFails`): from Phase 2 on a newer
 * version arriving over unsaved typing is merged, and the notice is raised
 * only when it cannot be. Every Phase 1 case written against the notice
 * keeps exercising enterConflict(), keepMine() and loadTheirs() through
 * this.
 */
export async function conflicted(options = {}) {
    const w = await pair({ ...options, B: { mergeFails: true, ...(options.B ?? {}) } });
    const { A, B } = w;
    A.type('A1');
    B.type('B1');
    await A.debounce();
    await A.land();
    await B.cycle();
    await B.debounce();

    return w;
}

/**
 * Who knowingly deleted what, for the random walks (both of them use it).
 *
 * "Knowingly deleted" means every token that was in the deleter's own copy of
 * the block at the moment they deleted it: deleted(person, tokens). That
 * excuses the drops of that same person's saves (stored(), told who sent the
 * save), and only theirs: another person's deletion is another event, and a
 * save of somebody else that drops the token is not excused by it. The
 * excuse ends with the token coming back AND the person seeing it: once the
 * person's deletion has been stored (their save dropped the token from the
 * head), it ends when that person's page holds the token again (`onPage()`,
 * which the walks call after every step and before judging every stored
 * version), and from then on a later drop of it by anybody is silent again.
 * A person who has NOT seen the token come back (the answer was lost, the
 * tab died, their draft still holds the deletion, or their merge applied
 * their own pending deletion again over a version that had it) still means
 * it: the same deletion, sent again, stays excused.
 *
 * `seenBy` is the older measure, kept for the direct test: the excuse also
 * ends when the token reappears on the server after the person's page has
 * taken in the version that stored the deletion. The walks do not pass it.
 * A base version is not what the person saw: a merge can move the base past
 * the stored deletion while applying that same person's deletion again (case
 * c1), so the token never showed on their page, and the next save of the same
 * deletion was reported as a silent drop.
 *
 * @param {(json: object) => Set<string>} tokensIn the walk's own token reader
 * @param {(person: *) => number} [seenBy] the newest server version that person's page has taken in
 */
export function deletionLedger(tokensIn, seenBy = () => -Infinity) {
    /** token -> person -> the version that stored their deletion, or null while none has */
    const owed = new Map();
    /** tokens whose latest removal from the head was somebody's knowing deletion */
    const gone = new Set();

    return {
        deleted(person, tokens) {
            tokens.forEach((token) => {
                if (!owed.has(token)) {
                    owed.set(token, new Map());
                }
                owed.get(token).set(person, null);
            });
        },
        /**
         * An accepted save (or a restore) replaced `row.from` with `row.to`.
         *
         * @returns {string[]} the tokens it dropped from the head that its sender had not deleted
         */
        stored(row, person) {
            const before = tokensIn(row.from);
            const after = tokensIn(row.to);
            const silent = [];
            [...before]
                .filter((token) => !after.has(token))
                .forEach((token) => {
                    const people = owed.get(token);
                    if (people && people.has(person)) {
                        people.set(person, row.version);
                        gone.add(token);
                    } else {
                        gone.delete(token);
                        silent.push(token);
                    }
                });
            [...after]
                .filter((token) => !before.has(token))
                .forEach((token) => {
                    gone.delete(token);
                    owed.get(token)?.forEach((storedAt, who, people) => {
                        if (storedAt !== null && seenBy(who) >= storedAt) {
                            people.delete(who);
                        }
                    });
                });

            return silent;
        },
        /**
         * The person's page holds these tokens now: a stored deletion of
         * theirs that has come back is seen, and no longer excused. A
         * deletion not stored yet is left alone: it is still on its way.
         *
         * @param {*} person
         * @param {Iterable<string>} tokens
         */
        onPage(person, tokens) {
            for (const token of tokens) {
                const people = owed.get(token);
                if (people && people.has(person) && people.get(person) !== null) {
                    people.delete(person);
                }
            }
        },
        /** Whether a token that is nowhere to be found went with somebody's deletion. */
        excuses(token) {
            return gone.has(token) || (owed.get(token)?.size ?? 0) > 0;
        },
    };
}
