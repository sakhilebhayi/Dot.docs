/**
 * Phase 3 evidence: a seeded randomized convergence trial of prosemirror-collab
 * (the npm package 1.3.1, or the guarded copy in collab-copy.mjs) driving 2 or 3
 * headless TipTap editors built from the project's real extension stack, through
 * a fake authority with latency, message loss and message duplication.
 *
 * Run from a SCRATCH copy, never from the repo: copy package.json and
 * package-lock.json, `npm ci`, `npm install prosemirror-collab@1.3.1`, copy
 * resources/js/editor/ to resources/js/editor/ and tests/js/moduleLoader.js plus
 * this folder's .mjs files to tests/js/, then `sh run-trial.sh` (the matrix) or e.g.
 *   COLLAB=copy SEEDS=200 CLIENTS=3 VARIANT=naive node tests/js/collab.trial.mjs
 *
 * Environment:
 *   COLLAB=package|copy   the npm package (default) or the guarded copy
 *   VARIANT=naive|filtered  naive: the id plugins as the project has them;
 *                         filtered: UniqueID and BlockIdRepair ignore transactions
 *                         from the authority (the roadmap's "Traps" advice)
 *   PROFILE=typing        a typing-heavy mix instead of the adversarial default
 *   SKIP_TRAILING=1       received transactions are tagged skipTrailingNode
 *   SEEDS, OPS, CLIENTS, DEBUG, FINAL_DOCS (write converged documents for the PHP check)
 *
 * Left out of the stack (they need a browser): figure/image, pagination, slash
 * menu, placeholder. Output: one JSON line per configuration, then the failures.
 * Nothing here is product code.
 */

// The resolve hook the project's extensions need (extensionless imports).
import { loadEditorModule } from './moduleLoader.js';

import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import UniqueID from '@tiptap/extension-unique-id';
import { Plugin, PluginKey, TextSelection } from 'prosemirror-state';
import { Step } from 'prosemirror-transform';
import * as pkgCollab from 'prosemirror-collab';
import * as copyCollab from './collab-copy.mjs';
const COLLAB = process.env.COLLAB === 'copy' ? copyCollab : pkgCollab;
const { collab, receiveTransaction, sendableSteps, getVersion } = COLLAB;

// ───────────────────────────────────────── deterministic randomness (ids too)
function mulberry32(a) {
    return function () {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
let idRand = mulberry32(1);
// blockId.js's base62() reads crypto.getRandomValues: make it seeded.
Object.defineProperty(globalThis, 'crypto', {
    value: { getRandomValues: (bytes) => { for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(idRand() * 256); return bytes; } },
    configurable: true,
});

const { BLOCK_TYPES, BlockIdRepair, blockIdRepairPlugin, base62 } = await loadEditorModule('extensions/blockId.js');
const { uniqueIdsTransaction } = await loadEditorModule('uniqueIds.js');
const { HeadingNumbered } = await loadEditorModule('extensions/headingNumbered.js');
const { Align } = await loadEditorModule('extensions/textAlign.js');
const { DocTextStyle } = await loadEditorModule('extensions/textStyle.js');
const { Toc } = await loadEditorModule('extensions/toc.js');
const { CrossRef } = await loadEditorModule('extensions/crossRef.js');
const { PageBreak } = await loadEditorModule('extensions/pageBreak.js');
const { SectionBreak } = await loadEditorModule('extensions/sectionBreak.js');
const { Callout } = await loadEditorModule('extensions/callout.js');
const { Column, Columns } = await loadEditorModule('extensions/columns.js');
const { Variable } = await loadEditorModule('extensions/variable.js');
const { DocAttrs } = await loadEditorModule('extensions/docAttrs.js');

const fromAuthority = (tr) => tr.getMeta('rebased') !== undefined;

function extensionsFor(variant, version, clientID) {
    const CollabExt = Extension.create({
        name: 'collabTrial',
        addProseMirrorPlugins: () => [collab({ version, clientID })],
    });
    let ids;
    if (variant === 'filtered') {
        ids = [
            Extension.create({
                name: 'blockIdRepair',
                addProseMirrorPlugins: () => [
                    new Plugin({
                        key: new PluginKey('dotdocBlockIdRepairFiltered'),
                        appendTransaction: (trs, _o, s) =>
                            trs.some(fromAuthority) ? null : uniqueIdsTransaction(trs, s, { types: BLOCK_TYPES, generateId: () => base62(8) }),
                    }),
                ],
            }),
            UniqueID.configure({ attributeName: 'id', types: BLOCK_TYPES, generateID: () => base62(8), filterTransaction: (tr) => !fromAuthority(tr) }),
        ];
    } else {
        ids = [BlockIdRepair, UniqueID.configure({ attributeName: 'id', types: BLOCK_TYPES, generateID: () => base62(8) })];
    }

    return [
        StarterKit.configure({ heading: false, link: { openOnClick: false, autolink: true } }),
        HeadingNumbered.configure({ levels: [1, 2, 3, 4, 5, 6] }),
        Highlight,
        Subscript,
        Superscript,
        Align.configure({ types: ['heading', 'paragraph'] }),
        DocTextStyle,
        Table.configure({ resizable: false }),
        TableRow,
        TableHeader,
        TableCell,
        TaskList,
        TaskItem.configure({ nested: true }),
        Toc,
        CrossRef,
        PageBreak,
        SectionBreak,
        Callout,
        Columns,
        Column,
        Variable.configure({ vars: {} }),
        DocAttrs,
        ...ids,
        CollabExt,
    ];
}

function makeEditor(variant, content, version, clientID) {
    const editor = new Editor({ element: null, extensions: extensionsFor(variant, version, clientID), content });
    // An editor built with element:null has no plugins in its state (roadmap trap).
    editor.view.updateState(editor.state.reconfigure({ plugins: editor.extensionManager.plugins }));
    return editor;
}

// ───────────────────────────────────────── the document every run starts from
const p = (id, text) => ({ type: 'paragraph', attrs: { id }, content: text ? [{ type: 'text', text }] : [] });
const INITIAL = {
    type: 'doc',
    attrs: { schema: 1, style: 'report', vars: {} },
    content: [
        { type: 'heading', attrs: { id: 'head0001', level: 1 }, content: [{ type: 'text', text: 'Quarterly report' }] },
        { type: 'toc', attrs: { id: 'toc00001', depth: 3, entries: [] } },
        p('para0001', 'The first paragraph holds some words.'),
        p('para0002', 'A second paragraph, where two people will type together.'),
        { type: 'bulletList', attrs: { id: 'list0001' }, content: [
            { type: 'listItem', attrs: { id: 'item0001' }, content: [p('para0003', 'first item')] },
            { type: 'listItem', attrs: { id: 'item0002' }, content: [p('para0004', 'second item')] },
        ] },
        { type: 'table', attrs: { id: 'tabl0001' }, content: [
            { type: 'tableRow', attrs: { id: 'trow0001' }, content: [
                { type: 'tableHeader', attrs: { id: 'thea0001' }, content: [p('para0005', 'H1')] },
                { type: 'tableHeader', attrs: { id: 'thea0002' }, content: [p('para0006', 'H2')] },
            ] },
            { type: 'tableRow', attrs: { id: 'trow0002' }, content: [
                { type: 'tableCell', attrs: { id: 'tcel0001' }, content: [p('para0007', 'a')] },
                { type: 'tableCell', attrs: { id: 'tcel0002' }, content: [p('para0008', 'b')] },
            ] },
        ] },
        { type: 'callout', attrs: { id: 'call0001', tone: 'note' }, content: [p('para0009', 'Inside a callout')] },
        p('para0010', 'See section '),
        p('para0011', 'Last words.'),
    ],
};

// ───────────────────────────────────────── the fake authority
class Authority {
    constructor(schema) {
        this.schema = schema;
        this.steps = []; // JSON, as stored
        this.clientIDs = [];
        this.batches = 0;
        this.rejected = 0;
        this.duplicateAccepted = 0;
        this.seenBatchKeys = new Set();
    }
    receive({ version, steps, clientID, key }) {
        if (version !== this.steps.length) {
            this.rejected += 1;
            return { ok: false, version: this.steps.length };
        }
        if (this.seenBatchKeys.has(key)) this.duplicateAccepted += 1; // would mean a duplicate was appended twice
        this.seenBatchKeys.add(key);
        for (const s of steps) {
            this.steps.push(s);
            this.clientIDs.push(clientID);
        }
        this.batches += 1;
        return { ok: true, version: this.steps.length };
    }
    since(version) {
        return { since: version, steps: this.steps.slice(version), clientIDs: this.clientIDs.slice(version), head: this.steps.length };
    }
}

// ───────────────────────────────────────── random edits
function textblocks(doc) {
    const out = [];
    doc.descendants((node, pos) => {
        if (node.isTextblock) out.push({ node, pos });
        return true;
    });
    return out;
}

let tokenSeq = 0;
function randomEdit(editor, rand, stats) {
    const doc = editor.state.doc;
    const tbs = textblocks(doc);
    if (!tbs.length) return 'none';
    const tb = tbs[Math.floor(rand() * tbs.length)];
    const start = tb.pos + 1;
    const end = tb.pos + 1 + tb.node.content.size;
    const at = start + Math.floor(rand() * (tb.node.content.size + 1));
    let r = rand();
    const ch = editor.chain().setTextSelection(at);
    if (process.env.PROFILE === 'typing') {
        // Typing-heavy mix: 75% typing, 8% deletes, 5% marks, 5% Enter, 2% Backspace-join,
        // 2% new blocks, 1% heading, 1% list, 1% undo.
        const q = r;
        r = q < 0.75 ? 0.1 : q < 0.83 ? 0.4 : q < 0.88 ? 0.5 : q < 0.93 ? 0.58 : q < 0.95 ? 0.65 : q < 0.97 ? 0.7 : q < 0.98 ? 0.76 : q < 0.99 ? 0.8 : 0.95;
    }
    try {
        if (r < 0.34) {
            const token = ` t${++tokenSeq}`;
            ch.insertContent({ type: 'text', text: token }).run();
            stats.typedChars = (stats.typedChars || 0) + token.length;
            return 'type';
        }
        if (r < 0.46) {
            if (end - start < 2) return 'none';
            const a = start + Math.floor(rand() * (end - start - 1));
            const b = Math.min(end, a + 1 + Math.floor(rand() * 6));
            editor.chain().setTextSelection({ from: a, to: b }).deleteSelection().run();
            return 'delete';
        }
        if (r < 0.54) {
            if (end - start < 2) return 'none';
            const a = start + Math.floor(rand() * (end - start - 1));
            const b = Math.min(end, a + 1 + Math.floor(rand() * 8));
            const mark = ['toggleBold', 'toggleItalic', 'toggleUnderline', 'toggleHighlight'][Math.floor(rand() * 4)];
            editor.chain().setTextSelection({ from: a, to: b })[mark]().run();
            return 'mark';
        }
        if (r < 0.62) {
            ch.splitBlock().run();
            return 'split';
        }
        if (r < 0.68) {
            editor.chain().setTextSelection(start).joinBackward().run();
            return 'join';
        }
        if (r < 0.74) {
            const kinds = [
                () => ({ type: 'paragraph', content: [{ type: 'text', text: `new t${++tokenSeq}` }] }),
                () => ({ type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: `head t${++tokenSeq}` }] }),
                () => ({ type: 'pageBreak' }),
                () => ({ type: 'horizontalRule' }),
                () => ({ type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: `li t${++tokenSeq}` }] }] }] }),
                () => ({ type: 'table', content: [0, 1].map(() => ({ type: 'tableRow', content: [0, 1].map(() => ({ type: 'tableCell', content: [{ type: 'paragraph' }] })) })) }),
                () => ({ type: 'columns', attrs: { count: 2 }, content: [0, 1].map(() => ({ type: 'column', content: [{ type: 'paragraph', content: [{ type: 'text', text: `col t${++tokenSeq}` }] }] })) }),
                () => ({ type: 'callout', attrs: { tone: 'warning' }, content: [{ type: 'paragraph', content: [{ type: 'text', text: `co t${++tokenSeq}` }] }] }),
            ];
            // After a random top-level block.
            const tops = [];
            doc.forEach((n, off) => tops.push(off + n.nodeSize));
            const pos = tops[Math.floor(rand() * tops.length)];
            editor.chain().insertContentAt(pos, kinds[Math.floor(rand() * kinds.length)]()).run();
            return 'insertBlock';
        }
        if (r < 0.78) {
            ch.toggleHeading({ level: 2 }).run();
            return 'heading';
        }
        if (r < 0.81) {
            ch.toggleBulletList().run();
            return 'list';
        }
        if (r < 0.84) {
            const ok = ch.addRowAfter().run() || editor.chain().setTextSelection(at).addColumnAfter().run();
            return ok ? 'table' : 'none';
        }
        if (r < 0.86) {
            const ok = ch.deleteRow().run();
            return ok ? 'tableDelete' : 'none';
        }
        if (r < 0.89) {
            const inline = rand() < 0.5
                ? { type: 'crossRef', attrs: { targetId: 'head0001', kind: 'heading', label: null } }
                : { type: 'variable', attrs: { key: 'client' } };
            ch.insertContent(inline).run();
            return 'inline';
        }
        if (r < 0.91) {
            ch.setTextAlign(['center', 'right', 'left'][Math.floor(rand() * 3)]).run();
            return 'align';
        }
        if (r < 0.94) {
            // Delete a whole top-level block (not the last one).
            if (doc.childCount < 3) return 'none';
            const idx = Math.floor(rand() * doc.childCount);
            let off = 0;
            for (let i = 0; i < idx; i++) off += doc.child(i).nodeSize;
            editor.view.dispatch(editor.state.tr.delete(off, off + doc.child(idx).nodeSize));
            return 'deleteBlock';
        }
        if (r < 0.97) {
            editor.commands.undo();
            return 'undo';
        }
        editor.commands.redo();
        return 'redo';
    } catch (e) {
        stats.editThrows += 1;
        return 'threw';
    }
}

// ───────────────────────────────────────── one run
function idsOf(doc) {
    const ids = [];
    doc.descendants((n) => {
        if (n.attrs && typeof n.attrs.id === 'string') ids.push(n.attrs.id);
    });
    return ids;
}

function trailingEmpties(doc) {
    let n = 0;
    for (let i = doc.childCount - 1; i >= 0; i--) {
        const c = doc.child(i);
        if (c.type.name === 'paragraph' && c.content.size === 0) n++;
        else break;
    }
    return n;
}

function run({ seed, clients: nClients, variant, ops = 300, loss = 0.1, dup = 0.05, maxLatency = 5 }) {
    const rand = mulberry32(seed);
    idRand = mulberry32(seed * 7919 + 13);
    const stats = {
        seed, editThrows: 0, receiveThrows: 0, droppedOnRebase: 0, droppedTextChars: 0, localEchoSteps: 0,
        resyncs: 0, sendBatches: 0, dropKinds: {}, dropEvents: 0, echoKinds: {}, stepBytes: [], batchBytes: [], batchSteps: [], opCounts: {},
    };
    const clients = [];
    for (let i = 0; i < nClients; i++) {
        const editor = makeEditor(variant, INITIAL, 0, 1000 + i);
        clients.push({ id: 1000 + i, editor, sendInFlight: false, sendKeySeq: 0 });
    }
    const schema = clients[0].editor.schema;
    const initialDoc = schema.nodeFromJSON(INITIAL);
    const auth = new Authority(schema);
    if (process.env.DEBUG) console.error('initial eq', clients.map((c) => JSON.stringify(c.editor.state.doc.toJSON()) === JSON.stringify(initialDoc.toJSON())));
    let now = 0;
    const queue = []; // {at, fn}
    let lossless = false;
    const net = (fn, lossy = true) => {
        if (lossless) lossy = false;
        if (lossy && rand() < loss) return; // lost
        queue.push({ at: now + 1 + Math.floor(rand() * maxLatency), fn });
        if (lossy && rand() < dup) queue.push({ at: now + 1 + Math.floor(rand() * maxLatency), fn });
    };

    function applyIncoming(c, resp) {
        const st = c.editor.state;
        const v = getVersion(st);
        if (resp.since > v) return; // a gap: ignore, the next poll asks again
        const skip = v - resp.since;
        if (skip >= resp.steps.length) return; // nothing new
        const steps = resp.steps.slice(skip).map((j) => Step.fromJSON(c.editor.schema, j));
        const ids = resp.clientIDs.slice(skip);
        const before = sendableSteps(st);
        const beforeCount = before ? before.steps.length : 0;
        let ours = 0;
        while (ours < ids.length && ids[ours] === c.id) ours++;
        try {
            const tr = receiveTransaction(st, steps, ids);
            const collabPlugin = st.plugins.find((pl) => pl.spec.historyPreserveItems);
            const cs = tr.getMeta(collabPlugin);
            const expected = Math.max(0, beforeCount - ours);
            const rebasedCount = cs ? cs.unconfirmed.length : expected;
            if (rebasedCount < expected && before) try {
                // Re-run prosemirror-collab's rebaseSteps() algorithm on a scratch transform to see WHY each step went.
                // The unconfirmed steps and their inverses, as the plugin keeps them.
                const tr2 = st.tr;
                const cst = collabPlugin.getState(st);
                const reb = cst.unconfirmed.slice(ours);
                for (let i = reb.length - 1; i >= 0; i--) tr2.step(reb[i].inverted);
                for (const rs of steps) tr2.step(rs);
                for (let i = 0, mapFrom = reb.length; i < reb.length; i++) {
                    const step = reb[i].step;
                    const mapped = step.map(tr2.mapping.slice(mapFrom));
                    mapFrom--;
                    const kind = step.toJSON().stepType + (step.slice && step.slice.content.size ? (step.slice.content.textBetween(0, step.slice.content.size, '', ' ').length ? '+text' : '+nodes') : '');
                    if (!mapped) { stats.dropKinds[`unmappable:${kind}`] = (stats.dropKinds[`unmappable:${kind}`] || 0) + 1; continue; }
                    const r2 = tr2.maybeStep(mapped);
                    if (r2.failed) { stats.dropKinds[`failed:${kind}`] = (stats.dropKinds[`failed:${kind}`] || 0) + 1; continue; }
                    tr2.mapping.setMirror(mapFrom, tr2.steps.length - 1);
                }
            } catch (e) { stats.analysisThrows = (stats.analysisThrows || 0) + 1; }
            if (rebasedCount < expected) {
                stats.droppedOnRebase += expected - rebasedCount;
                const textOf = (arr) => arr.reduce((n, s) => n + (s.slice ? s.slice.content.textBetween(0, s.slice.content.size, '', ' ').length : 0), 0);
                const keptSteps = cs.unconfirmed.map((u) => u.step);
                stats.droppedTextChars += Math.max(0, textOf(before.steps.slice(ours)) - textOf(keptSteps));
                stats.dropEvents = (stats.dropEvents || 0) + 1;
            }
            if (process.env.SKIP_TRAILING) tr.setMeta('skipTrailingNode', true);
            c.editor.view.dispatch(tr);
            const after = sendableSteps(c.editor.state);
            const afterCount = after ? after.steps.length : 0;
            if (after && afterCount > rebasedCount) {
                for (const es of after.steps.slice(rebasedCount)) {
                    const j = es.toJSON();
                    let k = j.stepType;
                    if (j.stepType === 'attr') k += ':' + j.attr;
                    if (j.stepType === 'replace' && j.slice && j.slice.content && j.slice.content.length === 1 && j.slice.content[0].type === 'paragraph' && !j.slice.content[0].content && j.from === c.editor.state.doc.content.size - 2) k += ':trailingParagraph';
                    else if (j.stepType === 'replace') k += ':' + (j.slice && j.slice.content ? j.slice.content.map((n) => n.type).join('+') : 'delete');
                    stats.echoKinds[k] = (stats.echoKinds[k] || 0) + 1;
                }
            }
            // Plugins that append to the receive dispatch add local steps.
            if (afterCount > rebasedCount) stats.localEchoSteps += afterCount - rebasedCount;
        } catch (e) {
            stats.receiveThrows += 1; if (process.env.DEBUG) console.error("receive threw", e.stack.split("\n").slice(0,9).join(" | "), "v", v, "since", resp.since, "n", resp.steps.length);
            // Resync: rebuild from the authority's document; local unconfirmed steps are lost.
            stats.resyncs += 1;
            const doc = replay(auth.steps.length);
            c.editor.destroy();
            c.editor = makeEditor(variant, doc.toJSON(), auth.steps.length, c.id);
        }
    }

    function replay(upTo) {
        let doc = initialDoc;
        for (let i = 0; i < upTo; i++) {
            const res = Step.fromJSON(schema, auth.steps[i]).apply(doc);
            if (res.failed) throw new Error(`authority replay failed at ${i}: ${res.failed}`);
            doc = res.doc;
        }
        return doc;
    }

    function send(c) {
        if (c.sendInFlight) return;
        const s = sendableSteps(c.editor.state);
        if (!s) return;
        const steps = s.steps.map((x) => x.toJSON());
        for (const j of steps) stats.stepBytes.push(JSON.stringify(j).length);
        const body = { version: s.version, steps, clientID: s.clientID, key: `${c.id}:${++c.sendKeySeq}` };
        stats.batchBytes.push(JSON.stringify(body).length);
        stats.batchSteps.push(steps.length);
        stats.sendBatches += 1;
        c.sendInFlight = true;
        const sentAt = now;
        c.sendSentAt = sentAt;
        // request may be lost or duplicated
        net(() => {
            const answer = auth.receive(JSON.parse(JSON.stringify(body)));
            net(() => {
                if (c.sendSentAt === sentAt) c.sendInFlight = false;
                poll(c);
            });
        });
    }

    function poll(c) {
        const v = getVersion(c.editor.state);
        net(() => {
            const resp = JSON.parse(JSON.stringify(auth.since(v)));
            net(() => applyIncoming(c, resp));
        });
    }

    function tickNetwork() {
        queue.sort((a, b) => a.at - b.at);
        while (queue.length && queue[0].at <= now) queue.shift().fn();
        // A send whose answer never came is given up on after 15 ticks.
        for (const c of clients) if (c.sendInFlight && now - c.sendSentAt > 15) c.sendInFlight = false;
    }

    for (let i = 0; i < ops; i++) {
        now++;
        const c = clients[Math.floor(rand() * clients.length)];
        const r = rand();
        if (r < 0.55) {
            const op = randomEdit(c.editor, rand, stats);
            stats.opCounts[op] = (stats.opCounts[op] || 0) + 1;
        } else if (r < 0.75) send(c);
        else poll(c);
        tickNetwork();
    }

    lossless = true;
    // Quiesce: no loss, keep syncing until every client is at the head with nothing unsent.
    let rounds = 0;
    const settled = () => queue.length === 0 && clients.every((c) => !sendableSteps(c.editor.state) && getVersion(c.editor.state) === auth.steps.length);
    while (!settled() && rounds < 500) {
        rounds++;
        for (const c of clients) {
            now++;
            if (!c.sendInFlight) send(c);
            const v = getVersion(c.editor.state);
            queue.push({ at: now + 1, fn: () => { const resp = auth.since(v); queue.push({ at: now + 1, fn: () => applyIncoming(c, JSON.parse(JSON.stringify(resp))) }); } });
        }
        for (let k = 0; k < 6; k++) { now++; tickNetwork(); for (const c of clients) if (c.sendInFlight && now - c.sendSentAt > 3) c.sendInFlight = false; }
    }
    // loss off during quiesce: re-run sends without loss
    const result = { ...stats, rounds, settled: settled(), steps: auth.steps.length, rejected: auth.rejected, duplicateAccepted: auth.duplicateAccepted };
    let authDoc = null;
    try {
        authDoc = replay(auth.steps.length);
        result.replayOk = true;
    } catch (e) {
        result.replayOk = false;
        result.replayError = String(e.message).slice(0, 200);
    }
    if (process.env.DEBUG && authDoc) for (const c of clients) { const a = JSON.stringify(c.editor.state.doc.toJSON()); const b = JSON.stringify(authDoc.toJSON()); let i = 0; while (i < a.length && a[i] === b[i]) i++; if (a === b) continue; console.error('diff client', c.id, 'v', getVersion(c.editor.state), 'auth', auth.steps.length, '\n C:', a.slice(Math.max(0,i-80), i+120), '\n A:', b.slice(Math.max(0,i-80), i+120)); }
    const authJson = authDoc && JSON.stringify(authDoc.toJSON());
    result.converged = authDoc !== null && clients.every((c) => JSON.stringify(c.editor.state.doc.toJSON()) === authJson);
    result.clientsAgree = clients.every((c) => JSON.stringify(c.editor.state.doc.toJSON()) === JSON.stringify(clients[0].editor.state.doc.toJSON()));
    result.idsUnique = clients.every((c) => { const ids = idsOf(c.editor.state.doc); return new Set(ids).size === ids.length; });
    result.idsMissing = clients.some((c) => { let miss = false; c.editor.state.doc.descendants((n) => { if (BLOCK_TYPES.includes(n.type.name) && !n.attrs.id) miss = true; }); return miss; });
    result.checkOk = clients.every((c) => { try { c.editor.state.doc.check(); return true; } catch (_) { return false; } });
    result.trailingEmpties = Math.max(...clients.map((c) => trailingEmpties(c.editor.state.doc)));
    result.docBytes = JSON.stringify(clients[0].editor.state.doc.toJSON()).length;
    result.finalDoc = authDoc ? authDoc.toJSON() : null;
    // Late joiner: loads a "checkpoint" at the midpoint with server stamps (toc.entries,
    // crossRef.label) written into it, then applies the rest of the log.
    if (authDoc) {
        const mid = Math.floor(auth.steps.length / 2);
        const cp = JSON.parse(JSON.stringify(replay(mid).toJSON()));
        const stamp = (n) => {
            if (n.type === 'toc') n.attrs.entries = [{ id: 'head0001', level: 1, text: 'Quarterly report', number: '1' }];
            if (n.type === 'crossRef') n.attrs.label = '1';
            (n.content || []).forEach(stamp);
        };
        stamp(cp);
        let doc = schema.nodeFromJSON(cp);
        let ok = true;
        for (let i = mid; i < auth.steps.length; i++) {
            const res = Step.fromJSON(schema, auth.steps[i]).apply(doc);
            if (res.failed) { ok = false; break; }
            doc = res.doc;
        }
        const unstamp = (j) => { if (j.type === 'toc') j.attrs.entries = []; if (j.type === 'crossRef') j.attrs.label = null; (j.content || []).forEach(unstamp); return j; };
        result.lateJoinerApplies = ok;
        result.lateJoinerEqualModuloStamps = ok && JSON.stringify(unstamp(doc.toJSON())) === JSON.stringify(unstamp(authDoc.toJSON()));
    }
    for (const c of clients) c.editor.destroy();
    return result;
}

// ───────────────────────────────────────── main
const pct = (arr, q) => { if (!arr.length) return 0; const s = [...arr].sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(q * s.length))]; };
const SEEDS = Number(process.env.SEEDS || 100);
const OPS = Number(process.env.OPS || 300);
const variants = process.env.VARIANT ? [process.env.VARIANT] : ['naive', 'filtered'];
const clientCounts = process.env.CLIENTS ? [Number(process.env.CLIENTS)] : [2, 3];
const summary = [];
const failures = [];
const finalDocs = [];
for (const variant of variants) {
    for (const n of clientCounts) {
        const agg = { variant, clients: n, seeds: SEEDS, ops: OPS, converged: 0, clientsAgree: 0, settled: 0, replayOk: 0, idsUnique: 0, idsMissing: 0, checkOk: 0,
            lateJoinerApplies: 0, lateJoinerEqualModuloStamps: 0, receiveThrows: 0, resyncs: 0, droppedOnRebase: 0, localEchoSteps: 0, editThrows: 0,
            maxTrailingEmpties: 0, runsWithExtraTrailing: 0, droppedTextChars: 0, dropEvents: 0, dropKinds: {}, echoKinds: {}, typedChars: 0, steps: 0, rejected: 0, duplicateAccepted: 0, sendBatches: 0, stepBytes: [], batchBytes: [], batchSteps: [], docBytesMax: 0, opCounts: {} };
        for (let seed = 1; seed <= SEEDS; seed++) {
            const r = run({ seed, clients: n, variant, ops: OPS });
            for (const k of ['converged', 'clientsAgree', 'settled', 'replayOk', 'idsUnique', 'idsMissing', 'checkOk', 'lateJoinerApplies', 'lateJoinerEqualModuloStamps']) if (r[k]) agg[k]++;
            for (const [k, v] of Object.entries(r.dropKinds)) agg.dropKinds[k] = (agg.dropKinds[k] || 0) + v;
            for (const [k, v] of Object.entries(r.echoKinds)) agg.echoKinds[k] = (agg.echoKinds[k] || 0) + v;
            agg.typedChars += r.typedChars || 0;
            for (const k of ['droppedTextChars', 'dropEvents', 'receiveThrows', 'resyncs', 'droppedOnRebase', 'localEchoSteps', 'editThrows', 'steps', 'rejected', 'duplicateAccepted', 'sendBatches']) agg[k] += r[k];
            agg.maxTrailingEmpties = Math.max(agg.maxTrailingEmpties, r.trailingEmpties);
            if (r.trailingEmpties > 1) agg.runsWithExtraTrailing++;
            agg.stepBytes.push(...r.stepBytes); agg.batchBytes.push(...r.batchBytes); agg.batchSteps.push(...r.batchSteps);
            agg.docBytesMax = Math.max(agg.docBytesMax, r.docBytes);
            for (const [k, v] of Object.entries(r.opCounts)) agg.opCounts[k] = (agg.opCounts[k] || 0) + v;
            if (!r.converged || !r.idsUnique || !r.checkOk || !r.settled) failures.push({ variant, clients: n, seed, converged: r.converged, clientsAgree: r.clientsAgree, idsUnique: r.idsUnique, checkOk: r.checkOk, settled: r.settled, replayError: r.replayError });
            if (n === 3 && seed <= 100 && r.finalDoc) finalDocs.push({ seed, doc: r.finalDoc });
        }
        const out = { ...agg,
            stepBytes: { n: agg.stepBytes.length, p50: pct(agg.stepBytes, 0.5), p95: pct(agg.stepBytes, 0.95), max: agg.stepBytes.reduce((m, x) => (x > m ? x : m), 0) },
            batchBytes: { n: agg.batchBytes.length, p50: pct(agg.batchBytes, 0.5), p95: pct(agg.batchBytes, 0.95), max: agg.batchBytes.reduce((m, x) => (x > m ? x : m), 0) },
            batchSteps: { p50: pct(agg.batchSteps, 0.5), p95: pct(agg.batchSteps, 0.95), max: agg.batchSteps.reduce((m, x) => (x > m ? x : m), 0) },
        };
        summary.push(out);
        out.collab = process.env.COLLAB === 'copy' ? 'copy' : 'package';
        out.profile = process.env.PROFILE || 'adversarial';
        out.skipTrailing = !!process.env.SKIP_TRAILING;
        if (process.env.COLLAB === 'copy') out.copyInvertThrowsCaught = copyCollab.rebaseReport.invertThrows;
        copyCollab.rebaseReport.invertThrows = 0;
        console.log(JSON.stringify(out));
    }
}
console.log(JSON.stringify({ failures: failures.slice(0, 40), failureCount: failures.length }));
if (process.env.FINAL_DOCS) {
    const { writeFileSync } = await import('node:fs');
    writeFileSync(process.env.FINAL_DOCS, JSON.stringify(finalDocs));
}
