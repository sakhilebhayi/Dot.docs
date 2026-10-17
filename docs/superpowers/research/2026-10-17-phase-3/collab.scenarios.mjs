/**
 * Phase 3 evidence: hand-written two-person scenarios on the REAL TipTap
 * stack with prosemirror-collab (the package, and the guarded copy), through
 * an in-memory authority. Each scenario prints PASS/FAIL with what it saw.
 *   node tests/js/collab.scenarios.mjs            (package)
 *   COLLAB=copy node tests/js/collab.scenarios.mjs
 */
import { loadEditorModule } from './moduleLoader.js';
import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Step } from 'prosemirror-transform';
import * as pkgCollab from 'prosemirror-collab';
import * as copyCollab from './collab-copy.mjs';

const C = process.env.COLLAB === 'copy' ? copyCollab : pkgCollab;
const { BlockId, BlockIdRepair } = await loadEditorModule('extensions/blockId.js');
const { HeadingNumbered } = await loadEditorModule('extensions/headingNumbered.js');
const { Align } = await loadEditorModule('extensions/textAlign.js');
const { DocAttrs } = await loadEditorModule('extensions/docAttrs.js');

function make(content, clientID) {
    const editor = new Editor({
        element: null,
        content,
        extensions: [
            StarterKit.configure({ heading: false }),
            HeadingNumbered.configure({ levels: [1, 2, 3] }),
            Align.configure({ types: ['heading', 'paragraph'] }),
            DocAttrs,
            BlockIdRepair,
            BlockId,
            Extension.create({ name: 'collabS', addProseMirrorPlugins: () => [C.collab({ version: 0, clientID })] }),
        ],
    });
    editor.view.updateState(editor.state.reconfigure({ plugins: editor.extensionManager.plugins }));
    return editor;
}
const log = { steps: [], ids: [] };
function send(ed) {
    const s = C.sendableSteps(ed.state);
    if (!s) return 'nothing';
    if (s.version !== log.steps.length) return 'rejected';
    for (const st of s.steps) { log.steps.push(st.toJSON()); log.ids.push(s.clientID); }
    return 'accepted';
}
function pull(ed) {
    const v = C.getVersion(ed.state);
    const steps = log.steps.slice(v).map((j) => Step.fromJSON(ed.schema, j));
    if (!steps.length) return;
    ed.view.dispatch(C.receiveTransaction(ed.state, steps, log.ids.slice(v)));
}
function sync(...eds) { for (let i = 0; i < 4; i++) for (const e of eds) { send(e); pull(e); } }
const text = (ed) => ed.state.doc.textBetween(0, ed.state.doc.content.size, '|');
const results = [];
function check(name, ok, detail) { results.push({ name, ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} :: ${detail}`); }
const para = (id, t) => ({ type: 'paragraph', attrs: { id }, content: t ? [{ type: 'text', text: t }] : [] });
const reset = () => { log.steps = []; log.ids = []; };

// S1: the same sentence, at the same moment. Both keep their characters; carets stay put.
{
    reset();
    const doc = { type: 'doc', content: [para('pppp0001', 'We will meet on Monday.')] };
    const A = make(doc, 1), B = make(doc, 2);
    A.chain().setTextSelection(1 + 'We will meet'.length).insertContent({ type: 'text', text: ' everyone' }).run();
    B.chain().setTextSelection(1 + 'We will meet on Monday'.length).insertContent({ type: 'text', text: ' at nine' }).run();
    const aCaret = A.state.selection.head, bCaretBefore = B.state.selection.head;
    send(A); const bSend = send(B); // B was based on version 0: refused
    pull(B); send(B); pull(A); pull(B);
    const ok = text(A) === text(B) && text(A) === 'We will meet everyone on Monday at nine.';
    check('S1 same sentence: both keep their words', ok, `A="${text(A)}" B="${text(B)}" (B's first send ${bSend})`);
    check('S1 caret: A stays after its own word', A.state.selection.head === aCaret, `A head ${aCaret} -> ${A.state.selection.head}`);
    check('S1 caret: B moves with its own text', B.state.selection.head === bCaretBefore + ' everyone'.length, `B head ${bCaretBefore} -> ${B.state.selection.head}`);

    // S2: undo undoes only your own typing.
    A.commands.undo();
    sync(A, B);
    check('S2 undo: A undoes only A', text(A) === 'We will meet on Monday at nine.' && text(B) === text(A), `A="${text(A)}" B="${text(B)}"`);
    B.commands.undo();
    sync(A, B);
    check('S2 undo: B undoes only B', text(A) === 'We will meet on Monday.' && text(B) === text(A), `A="${text(A)}" B="${text(B)}"`);
    A.destroy(); B.destroy();
}

// S3: the Phase 1 history bug (editor.md "A remote change empties the undo history"):
// A presses Enter at the start of "Hello"; B types "Above" into the new empty paragraph
// and "Well, " in front of "Hello"; A undoes. With exact steps B's words must survive.
{
    reset();
    const doc = { type: 'doc', content: [para('pppp0001', 'Hello')] };
    const A = make(doc, 1), B = make(doc, 2);
    A.chain().setTextSelection(1).splitBlock().run();
    sync(A, B);
    B.chain().setTextSelection(1).insertContent({ type: 'text', text: 'Above' }).run();
    const helloAt = B.state.doc.child(0).nodeSize + 1;
    B.chain().setTextSelection(helloAt).insertContent({ type: 'text', text: 'Well, ' }).run();
    sync(A, B);
    A.commands.undo();
    sync(A, B);
    const t = text(A);
    check('S3 undo across somebody else\'s typing keeps their words', t.includes('Above') && t.includes('Well, Hello') && text(B) === t, `after A's undo: "${t}"`);
    A.destroy(); B.destroy();
}

// S4: B types inside a sentence A deletes at the same moment (D2 inside a paragraph).
{
    reset();
    const doc = { type: 'doc', content: [para('pppp0001', 'Keep this. Remove this middle part. Keep that.')] };
    const A = make(doc, 1), B = make(doc, 2);
    const from = 1 + 'Keep this. '.length, to = from + 'Remove this middle part. '.length;
    A.chain().setTextSelection({ from, to }).deleteSelection().run();
    B.chain().setTextSelection(from + 'Remove this'.length).insertContent({ type: 'text', text: ' XYZZY' }).run();
    send(A);
    if (C.rebaseReport) C.rebaseReport.dropped = [];
    pull(B); send(B); pull(A); pull(B);
    const dropped = C.rebaseReport ? C.rebaseReport.dropped.map((s) => s.slice ? s.slice.content.textBetween(0, s.slice.content.size) : '').join('') : '(package: not reported)';
    check('S4 typing inside a range deleted elsewhere: deletion wins, both converge', text(A) === text(B) && !text(A).includes('XYZZY'), `doc="${text(A)}"`);
    check('S4 the dropped words are reported (copy only)', process.env.COLLAB !== 'copy' || dropped.includes('XYZZY'), `dropped text reported: "${dropped}"`);
    A.destroy(); B.destroy();
}

// S5: B's caret is in a paragraph A splits; B keeps typing where it was.
{
    reset();
    const doc = { type: 'doc', content: [para('pppp0001', 'First half second half')] };
    const A = make(doc, 1), B = make(doc, 2);
    B.chain().setTextSelection(1 + 'First half second'.length).run();
    A.chain().setTextSelection(1 + 'First half'.length).splitBlock().run();
    sync(A, B);
    B.chain().insertContent({ type: 'text', text: ' (B)' }).run();
    sync(A, B);
    check('S5 a split elsewhere does not move B\'s caret off its word', text(A) === text(B) && text(A).includes('second (B) half'), `doc="${text(A)}"`);
    const ids = []; A.state.doc.descendants((n) => { if (n.attrs.id) ids.push(n.attrs.id); });
    check('S5 the split gives the new block its own id on every side', new Set(ids).size === ids.length && ids.length === 2, `ids=${ids.join(',')}`);
    A.destroy(); B.destroy();
}

console.log(JSON.stringify({ collab: process.env.COLLAB === 'copy' ? 'copy' : 'package', passed: results.filter((r) => r.ok).length, total: results.length }));
