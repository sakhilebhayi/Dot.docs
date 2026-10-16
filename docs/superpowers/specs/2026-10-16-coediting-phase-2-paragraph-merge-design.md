# Co-editing Phase 2: Paragraph-Level Merge — Design

> Produced 2026-10-16 from a design round (three independent designs, three judges, one synthesis), then approved by the owner. Builds on Phase 1 (`docs/superpowers/plans/2026-10-02-coediting-phase-1-safe-saves-and-follow.md`, live since 2026-10-16) and on the roadmap (`docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md`).

## Owner decisions (final)

- **D1** Same top-level block changed by both within the merge window: the later save loses that block only; the server's version stays; the loser's version is offered back.
- **D2** One person deletes a block the other is typing in: the deletion wins; the typed words are offered back to the typer.
- **Q1** The person whose saved paragraph a merge deleted is NOT told in Phase 2 (their version is kept in the history). Phase 3's exact steps make this detectable without a heuristic.
- **Q2** Versions kept by a merge reuse the label "Before <name> kept their version" in Phase 2.
- **Q3** Wording as proposed in sections 2 and 6.2: "elsewhere" throughout; buttons **Put it back**, **Put them all back**, **Dismiss all**.
- **Q4** Whole-document replacements (restore, import, accepted suggestion) made while somebody types are MERGED like any other remote document, not turned into the conflict notice.

---

Written 2026-10-16 from the design round in this folder (three designs, three judgements) and from the code on `main` as it is today. Base design: **client-merge** (two judges' winner; the third judge's conditional winner, hybrid, wins only with client-merge's loop grafted onto it). Every fatal flaw any judge showed is repaired below with a named sequence, or stated as a limit. Owner decisions D1 and D2 are followed literally.

Everything an engineer needs to turn this into tasks is here: names, fields, sequences, texts, tests. Plain-English parts are marked **(owner)**.

---

## 1. Goal (owner)

Two people typing in different paragraphs of one document both keep typing and see each other's paragraphs appear 1–3 seconds behind, with nobody's words ever silently lost; the "changed elsewhere, choose one" notice appears only when a merge cannot be done.

---

## 2. What each person sees (owner)

A is the person whose save landed first (or who is reading). B is typing, and is the later saver or the tab that learns of A's save while still holding unsaved typing. "Elsewhere" is used in every sentence because one account in two tabs co-edits and the other tab may be your own.

| Situation | What A sees | What B sees |
|---|---|---|
| **Different paragraphs, both keep typing** | B's paragraph appears 1.5–3 s after B pauses; A's caret does not move; the save word cycles Editing → Saving → Saved. | Same, mirrored. No notice. (Undo reaches back only to the last incoming change: a stated limit until Phase 3.) |
| **Same paragraph, same moment (D1)** | Nothing. A's text won. After B presses Put it back, B's version appears as a new paragraph under A's, 1.5–3 s later. | B's paragraph changes to A's text under B's eyes (B's caret moves to the end of A's change inside that paragraph). The **lost row** appears: header *"Not everything you typed could be kept. Each item below stays here until you put it back or dismiss it; a copy is kept in this browser for 7 days."* and one item: *"This paragraph was changed elsewhere at the same moment. The other version is on the page; this is yours."* followed by the first 80 characters of B's text in quotes and a **Put it back** button. Footer buttons: **Put them all back**, **Dismiss all**. If both keep hammering the same paragraph, B gets one more item per round; nothing is ever withdrawn. |
| **A deletes the paragraph B is typing in (D2, first direction)** | Nothing. | The paragraph disappears from under B's caret (the caret lands where it was). Lost row item: *"This paragraph was removed elsewhere while you were typing in it."* + excerpt + Put it back, which puts it back **where it was, with its original id** (comments and cross-references on it come back). |
| **B deletes a paragraph A edited and saved a moment ago (D2, second direction)** | A's paragraph vanishes when A's tab follows B's save (clean) or merges it (typing). No notice: A's version is kept in the history as *"Before <B> kept their version"*. (Telling A is open question 1.) | Nothing: B wanted it gone. B's merged save carries the overwrite flag so the server keeps A's version first. |
| **A saves while B is mid-word (poll arrives inside B's debounce)** | — | B's page merges without a refused save; B's own save goes out on the new base. No notice. |
| **B's save is refused, the fetch never answers (15 s)** | — | After 15 s the Phase 1 conflict notice appears: *"Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one."* with **Keep mine** / **Load theirs**, as today. |
| **Three merged saves refused in a row** | — | The same conflict notice, with the merged document as "mine". |
| **B's save's answer is lost (connection dropped), B types on** | — | Nothing. The echo of B's own stored save is recognised (section 5.6); B's later words are kept and saved. (This closes a Phase 1 stated limit.) |
| **A reload during the lost row** | — | On reopen the items come back in the lost row, each prefixed *"From your last visit: "*, unless they were dismissed. |
| **Stale draft on reopen (tab died with unsaved text, document moved on)** | — | One question: *"An unsaved draft from your last visit was found. Add its changes to the document as it is now? Changes saved by others since then are kept, and a paragraph the draft deleted that was changed since stays. Anything that could not be kept is offered back."* Yes → the draft's changes appear, losses in the lost row (items say *"after your draft was written"*), the text is saved. A draft whose text is already in the document is cleared without a question. No → parked and offered as today. No base row (older build, swept) → today's behaviour. |
| **Accepted AI suggestion while B has unsaved typing** | Clean tabs follow. | B's typing is merged with the suggestion: a paragraph the suggestion rewrote wins (D1), B's version in the lost row (*"This paragraph was rewritten by the accepted suggestion. The suggestion's version is on the page; this is yours."*); B's new paragraphs survive and are saved. If the merge cannot be done, B's text is set aside first (row 3, as after Load theirs) and the suggestion applied. Phase 1 replaced it silently. |
| **Restore / import by somebody else while B types** | Clean tabs follow (one apply, undo emptied, no notice: as today). | Merged like any other remote document: blocks B typed in that the restore/import replaced are offered back; B's new blocks survive, anchored after their nearest surviving predecessor or, when every predecessor is gone, at the end. |
| **Attribute-only change, lists, tables, columns, callouts, figures** | — | An attribute change (align, heading level/numbered, a task ticked, callout tone) is a change of that whole block under D1/D2. A list, table, columns block, callout or figure is one block: two people editing different cells of one table collide under D1 and the loser's whole table is offered back. |
| **Block moved by one person only** | Honoured. | Honoured. Both moved: the server's order wins silently (no text involved). |

The status strip gains no new word. The conflict row's sentence is amended as quoted above (it now appears only when a merge is impossible).

---

## 3. Architecture

Angle: minimal server change. The merge, the per-block apply and the loop live in the browser, in the modules Phase 1 built. The server keeps whole-document compare-and-set; **no endpoint, no column, no dependency, no engine change, PROTOCOL stays 1.**

| Component | File | What it does |
|---|---|---|
| **Merge** (pure decision) | `resources/js/editor/sync/merge.js` (new) | `mergeDocuments(schema, {base, mine, theirs, echo}, {deletionsWin})`, `levelEmpty()`, `levelDerived()`, `isEmptyBlock()`, `excerpt()`, `blockWord()`, `appendLost()`, `lostEntry()`. Imports nothing; `node --test` runs it with `prosemirror-model`'s `Schema`. |
| **Apply** (transaction) | `resources/js/editor/sync/apply.js` (modify) | `remoteTransaction()` step 4 becomes the per-block builder `blockSteps(current, next, ReplaceStep)` (exported), probed before use, with today's one-range and whole-document applies as fallbacks. `clearHistoryTransaction()` unchanged. |
| **Narrow** | `resources/js/editor/sync/narrow.js` (modify) | `diffRange()` unchanged; new `insertionPoint(doc, anchors)` for put-back positions. |
| **Host** (state machine) | `resources/js/editor/sync/host.js` (modify) | New: `beginMerge()`, `mergeOrConflict()`, `expireMerging()`, `markConfirmed()`, `offerLost()`, `putBackLost()`, `putBackAllLost()`, `dismissLost()`, `sentenceFor()`. Amended: `persist()`, `saveAnswered()`, `saveInTheAir()`, `syncState()`, `resendIfOwed()`, `applyFromSync()`, `adoptVersion()`, `enterConflict()`, `restoreDraft()`, `applySuggestion()`, `engineHost()`. Still no state of its own, still nothing from outside `sync/`. |
| **Engine** | `resources/js/editor/sync/engine.js` | **No change.** `saved()`, `refetch()`, `pending`, `retry()`, `takeRemote()` reused as they are. |
| **Bundle** | `resources/js/editor/index.js` (modify) | Handle gains `merge(base, theirs, options)`, `insertBlock(json, {anchors, keepId})`, `get composing()`. `applyRemote()` unchanged except its docblock (`force` also covers a merged document). |
| **Drafts** | `resources/js/offline.js` (modify) | `base-<uuid>` row (`saveBase/loadBase`), `lost-<uuid>` row (`parkLost/loadLost/markLostDismissed`), `parkedRowIsExpired()` generalising `staleDraftIsExpired()` over `['stale-', 'lost-', 'base-']`. |
| **Page** | `resources/views/livewire/documents/editor.blade.php` (modify) | Six new `x-data` fields, three one-line delegates, the lost row (static markup, five item slots, under the existing `wire:ignore` bar), the amended conflict sentence. `syncHost()`'s env object keeps its shape. |
| **CSS** | `resources/css/shell.css` (modify) | `.doc-notice-list`, `.doc-notice-item`, `.doc-notice-excerpt`. |
| **Server** | `app/Livewire/Documents/VersionHistory.php` | Only the dead `version-restored` dispatch is removed. No logic change anywhere (section 8). |

### 3.1 New view (`x-data`) fields, declared in the Blade, written only by host.js

```
merging: null,        // {version, since} while a newer document is being fetched for a merge
mergeAttempts: 0,     // merges after a refused save, in a row; reset by an accepted save, by enterConflict(), by applyFromSync()
known: null,          // {version, json}: the last server document this tab held, as the editor serialises it. NEVER nulled
sentSnapshot: null,   // the JSON string of the save now in the air
unanswered: null,     // the JSON string of the last save given up on or whose request failed; null once anything is confirmed
lost: [],             // lost-block entries (section 6), oldest first
```

`confirmed`, `setAside`, `setAsideFrom`, `setAsideBase`, `overwriteOwed` keep their Phase 1 meaning exactly. `known` exists because `confirmed` is nulled on purpose (a lost save answer, a failed request, Keep mine) while the last agreed document is still the right merge base.

---

## 4. The merge (`mergeDocuments`)

```
mergeDocuments(schema, {base, mine, theirs, echo = null}, {deletionsWin = true} = {}) -> MergeResult | null
MergeResult = {
  merged:     object,   // the merged document, Node.toJSON() of this editor's schema
  theirs:     string,   // theirs as this editor serialises it (plus the carried trailing paragraph, 4.4) -> the new `confirmed`
  changed:    boolean,  // merged differs from theirs: something of mine must be saved
  lost:       [{id, type, reason: 'both'|'deleted', json, anchors: string[], text}],
  overwrites: boolean   // the merge drops content that exists only in theirs (case c2): the save must carry `overwrite`
}
```

### 4.1 Levelling (never raw JSON `==`)

Each input is deep-copied twice:

- **compare copy**: `levelEmpty()` (`[]` → `{}` for `sectionBreak.attrs.setup` and `doc.attrs.vars`), then `levelDerived()` (`toc.attrs.entries = []`, `crossRef.attrs.label = null`), then `schema.nodeFromJSON()`. Equality is `Node.eq()` on these: `align: null` versus missing is levelled by attribute defaults, mark order by the schema.
- **output copy**: `levelEmpty()` only, then `nodeFromJSON()`. Output blocks come from here so stamped entries/labels are not blanked on screen.

The derived table is the two-entry `DERIVED` of `derived.js`, duplicated into merge.js (so `sync/` imports nothing from outside); `tests/js/derived.test.js` asserts the two lists are identical.

Any parse throw, a top-level block without an id, or two top-level blocks sharing an id in one document → `null` (merge impossible → today's notice).

Each document becomes `[{id, cmp: Node, out: Node}]` plus a `Map(id → entry)`.

### 4.2 The echo rule (base per block)

When `echo` is given (the document of a save this tab sent whose answer was lost, section 5.6), the base is replaced **per block** wherever theirs agrees with the echo: for an id, if theirs and echo both lack it, or both have it and `T.cmp.eq(E.cmp)`, then that block's base is theirs' version (`B' := T`, or absent). Otherwise `B' := B`. Theirs' change to such a block is this tab's own stored save, not somebody else's; treating it as base makes mine's later typing "mine changed, theirs unchanged" (kept) instead of D1 (offered back).

### 4.3 Per-block decision (ids = union of the three id sets; `B` is `B'` when an echo applies)

Let `eqMB = M.cmp.eq(B.cmp)`, `eqTB`, `eqMT`.

| Case | Condition | Output | Lost / flags |
|---|---|---|---|
| a1 unchanged | inB, inM, inT, eqMB, eqTB | T.out | |
| a2 theirs changed | inB, inM, inT, eqMB, !eqTB | T.out | |
| a3 mine changed | inB, inM, inT, !eqMB, eqTB | **M.out** | changed |
| a4 same change | inB, inM, inT, !eqMB, !eqTB, eqMT | T.out | |
| **a5 both changed (D1)** | inB, inM, inT, !eqMB, !eqTB, !eqMT | **T.out** | lost `{reason:'both', json: M.out.toJSON(), anchors: [id]}` |
| b1 they deleted, I did not touch | inB, inM, !inT, eqMB | nothing | |
| **b2 they deleted, I typed (D2)** | inB, inM, !inT, !eqMB | nothing | lost `{reason:'deleted', json: M.out.toJSON(), anchors: up to 5 preceding ids of M, nearest first}` — **unless** `isEmptyBlock(M.cmp)` (paragraph/heading with `content.size === 0`: no text AND no inline nodes), then dropped silently |
| c1 I deleted, they did not touch | inB, !inM, inT, eqTB | nothing | |
| **c2 I deleted, they edited (D2 mirror)** | inB, !inM, inT, !eqTB | `deletionsWin` ? nothing : **T.out** | `deletionsWin` → `overwrites = true` (their edit is in a server version this save replaces; the save carries `overwrite` so `DocumentStore::save()` keeps it, label "Before <name> kept their version"). `!deletionsWin` (the draft merge, section 7) → their edit stands, nothing lost, no flag |
| d mine inserted | !inB, inM, !inT | **M.out**, anchored (4.5) | changed |
| e theirs inserted | !inB, !inM, inT | T.out | |
| f both inserted one id (cross-document paste) | !inB, inM, inT | eqMT → T.out; else as a5 | |
| g both deleted | inB, !inM, !inT | nothing | |

`text` = `node.textContent` cut to 80 characters plus `…` when cut; `[<blockWord>]` when it has no text. The D2 emptiness test is `content.size === 0`, never `textContent === ''`: a paragraph holding only a cross-reference or a variable chip is offered back.

Examples (each document as a block list `[id: text]`):

- **a3/a2 (different paragraphs)**: base `[p1: Hello, p2: World]`, mine `[p1: Hello B, p2: World]`, theirs `[p1: Hello, p2: World A]` → merged `[p1: Hello B, p2: World A]`, changed, nothing lost.
- **a5 (D1)**: base `[p1: Hello]`, mine `[p1: Hello B]`, theirs `[p1: Hello A]` → merged `[p1: Hello A]`, lost `{p1, both, "Hello B", anchors [p1]}`, changed false.
- **b2 (D2)**: base `[p1: x, p2: y, p3: z]`, mine `[p1: x, p2: y B, p3: z]`, theirs `[p1: x, p3: z]` → merged `[p1: x, p3: z]`, lost `{p2, deleted, "y B", anchors [p1]}`.
- **c2**: base `[p1: x, p2: y]`, mine `[p1: x]`, theirs `[p1: x, p2: y A]` → merged `[p1: x]`, overwrites true, nothing lost (A's text goes to the history).
- **d after a deleted predecessor**: base `[p1, p2, p3]`, mine `[p1, p2, n1, p3]`, theirs `[p1, p3]` → n1 anchors to p2 (gone) then p1 → merged `[p1, n1, p3]`.
- **echo**: base (known v1) `[p1: ""]`, echo (lost save) `[p1: X]`, mine `[p1: XY]`, theirs (v2) `[p1: X]` → B' = theirs for p1 → a3 → merged `[p1: XY]`, changed, nothing lost.
- **f**: both pasted block q7 with different text → theirs stays, mine's q7 lost `both`; with identical text → one copy.

### 4.4 Trailing paragraph

TipTap's TrailingNode gives this editor an empty paragraph after a document that does not end in one; it is this editor's own and is saved only with the writer's next edit. Let `L` be mine's last block when it is a paragraph with `content.size === 0` and is not in theirs:

- theirs does not end in a paragraph → `L` is appended to **both** `merged` and `theirsOut` (whether or not it is in base), and does not count towards `changed`. So `confirmed` after a follow-in-disguise equals the editor's own serialisation and `settleIfBackAtConfirmed()` stays reachable (Judge 1's stuck-Editing sequence).
- theirs ends in an empty paragraph → `L` is dropped silently (two tabs' own trailing paragraphs; nothing lost).

### 4.5 Order (the spine) and anchors

`orderChanged(X, Y)` = the common ids of X and Y read in X order differ from their Y order.

- Spine = **theirs**, unless `orderChanged(M, B) && !orderChanged(T, B)` (only I reordered): then spine = **mine**, and theirs-only insertions anchor after their nearest preceding theirs block that is in the output. Both reordered → theirs' order, nothing recorded (no text involved).
- Emission: insertions anchored to START first; then each spine block that is emitted, followed by the insertions anchored to it in their own document's order.
- Anchor resolution for an inserted block walks backwards in its own document: a block that is in the output → anchor; a block that is itself an insertion → same anchor, placed after it; a block that will not be in the output → skipped; nothing left → START when the block had no predecessors, **END** when it had (a typist's new paragraph lands after an imported document, not before its title).

### 4.6 Document attributes and the result

`doc.attrs` (`schema`, `style`, `vars`) per key: mine ≠ base → mine; else theirs. `{}` and `[]` are equal.

`merged = schema.topNodeType.create(attrs, Fragment.from(outputNodes)); merged.check()`; a throw → `null`. `changed = !merged.content.eq(theirsOut.content) || attrs differ`. `theirs = JSON.stringify(theirsOut.toJSON())`, `merged = merged.toJSON()`.

The merge generates no ids: every output block keeps its source id, so the merged save passes "Duplicate block id" by construction (case f emits one block).

---

## 5. The refused-save loop and the follow path

### 5.1 Actors

`persist()`, `saveAnswered()`, `beginMerge()`, `mergeOrConflict()`, `expireMerging()`, `applyFromSync()`, `engineHost().onConflict`, the unchanged engine. Constants: `MERGE_ATTEMPTS = 3`, `MERGE_FETCH_EXPIRY_MS = SAVE_EXPIRY_MS` (15 s).

### 5.2 Follow (clean tab) — unchanged path, per-block apply

```
poll → engine.deliver() → host.state() 'clean' → host.applyRemote(remote) → applyFromSync(remote)
  → handle.applyRemote(json) → remoteTransaction(): blockSteps (4a) | one range (4b) | whole document (4c)
  → markConfirmed(JSON.stringify(editor.getJSON()), remote.version); merging = null; mergeAttempts = 0; (rest as today)
```

### 5.3 A poll brings a newer version while the tab has unsaved typing

```
B types (unsaved, debounce armed)        A's save lands: v2
B poll → engine: remote = v2 → deliver() → state() 'dirty' → host.onConflict(v2) → mergeOrConflict(v2)
  merge(base = confirmed|known, mine = editor, theirs = v2) → applyRemote(merged, {force}) per block
  baseVersion = 2; confirmed = theirs(+tp); unsaved = changed; engine.saved(2) (remote dropped)
  changed → resave = true; persist(editor.getJSON())  → stored as v3
A poll → v3 → A clean: follows; A typing: merges (its own typing untouched)
```
No refused save, no fetch, no notice.

### 5.4 A save is refused as stale

In `saveAnswered(result, snapshot, overwrite)`, the branch `result.conflict && result.version > view.baseVersion` becomes:

```
view.resave = true;                                   // the text is still owed
if (view.conflict)                        enterConflict(result.version);   // the writer is choosing; no merge behind their back
else if (view.mergeAttempts >= MERGE_ATTEMPTS) enterConflict(result.version);
else { view.mergeAttempts += 1; beginMerge(result.version); }
```

```
beginMerge(version):
  engine = env.engine(); if (!engine) { enterConflict(version); return; }
  waiting = engine.pending
  if (waiting && waiting.version >= version) { mergeOrConflict(waiting); return; }   // already downloaded
  view.merging = { version, since: env.now() }
  engine.refetch(view.baseVersion)      // not poke(): the engine may have SEEN that version already
```

Sequence:

```
B debounce → persist(B1, base 1) ──────────────→ server: v2 exists → {conflict, version 2}
B saveAnswered → resave; mergeAttempts 1; beginMerge(2) → merging={2}; refetch(1)
   (B keeps typing; debounce fires; persist(): merging set → resave = true, nothing sent)
B refetch answers {changed, json v2} → deliver() → 'dirty' → onConflict → mergeOrConflict(v2)
   → merged applied per block; merging = null; base 2; persist(merged) → stored v3 → mergeAttempts = 0
```

### 5.5 `mergeOrConflict(remote)`

```
1. handle = env.handle(); if (!handle || handle.autosaves === false) return;             // fail-closed: nothing
2. if (view.conflict) { enterConflict(remote.version); return; }                           // notice stands: update it only
3. if (saveInTheAir()) return;                                                             // engine retries after the answer
4. base = view.confirmed ?? (view.known && view.known.version === view.baseVersion ? view.known.json : null)
   if (base === null) { view.merging = null; enterConflict(remote.version); return; }     // after Keep mine: no base for this baseVersion
5. result = handle.merge(JSON.parse(base), remote.json, { echo: view.unanswered ? JSON.parse(view.unanswered) : null })
   if (!result) { view.merging = null; enterConflict(remote.version); return; }
6. if (!handle.applyRemote(result.merged, { force: true })) { view.merging = null; enterConflict(remote.version); return; }
7. wasUnsaved = view.unsaved
   view.merging = null; view.baseVersion = remote.version; view.conflict = null;
   markConfirmed(result.changed ? result.theirs : JSON.stringify(handle.editor.getJSON()), remote.version);
   view.overwriteOwed = view.overwriteOwed || result.overwrites;       // OR, never assign (Judges 2 and 3)
   view.unsaved = result.changed;
   if (result.lost.length) offerLost(result.lost, 'merge', remote.version);
   env.engine()?.saved(remote.version);
   if (remote.outline) env.showOutline(remote.outline); if (remote.css) env.showCss(remote.css);
   view.tick++;
8. drafts: changed → saveDraft(uuid, JSON.stringify(handle.editor.getJSON()), view.overwriteOwed ? view.baseVersion - 1 : view.baseVersion)
           else   → clearDraft(uuid)
9. changed → { view.resave = true; persist(handle.editor.getJSON()); }
   else    → if (wasUnsaved) env.report('good', 'Saved')
```

Why step 7's OR: Judge 2/3's sequence — B presses Load theirs (confirmed = v5), then Put it back (`overwriteOwed = true`, debounce armed); within 1.2 s B's poll brings v6 → merge → `overwrites` is false → an assignment would clear the owed overwrite and the merged save would replace a head in no version row. With OR the save carries `overwrite: true` and v6 is kept. Second sequence: B deletes p5 unsaved, A edits p5 and saves v2, B's merge (c2) sets the flag, the merged save is refused because A saved v3 meanwhile, the second merge sees p5 as c1 → an assignment would clear the flag; OR keeps it until an accepted save carried it (existing rule).

`markConfirmed(json, version)`: `view.confirmed = json; view.known = {version, json}; view.unanswered = null; env.drafts()?.saveBase(view.docUuid, json, version)`. Called from `opened()` (version = `baseVersion`, json = editor JSON as today), `saveAnswered()` on `ok` (snapshot, `result.version`), `applyFromSync()`, `applySuggestion()`, `mergeOrConflict()`. Setting `confirmed = null` stays a direct assignment (`saveInTheAir()` expiry, `persist()`'s `onFailed`, `keepMine()`); `known` is never nulled. `adoptVersion()`, when it moves the base after this page's own style change, also sets `view.known.version = version` and rewrites the base row (a restyle changes only stamps, which the levelling absorbs) — Judge 3's `known.version` drift.

### 5.6 The echo of a lost answer

- `persist()` sets `view.sentSnapshot = snapshot` at send.
- `saveInTheAir()` expiry and `persist()`'s `onFailed` set `view.unanswered = view.sentSnapshot` beside `confirmed = null`.
- `markConfirmed()` clears `unanswered`.
- Merges pass `echo = unanswered` (4.2).

Sequence (the Phase 1 stated limit): A types X, save S1 leaves (base v1), stored v2, answer lost; A types Y; 15 s later `saveInTheAir()`: `confirmed = null`, `unanswered = "[p1: X]"`, `resave`; `resendIfOwed()` sends XY on v1 → refused (v2) → `beginMerge(2)` → fetch → `mergeOrConflict`: base = `known` (v1), echo matches theirs on p1 → a3 → merged `[p1: XY]` → saved as v3. No notice, nothing offered back. If somebody else had edited p1 after the echo, theirs ≠ echo → ordinary D1 (true conflict, offered back).

### 5.7 While the fetch is out, and when it never answers

- `persist()`: after the `saveInTheAir()` check and before the conflict check: `if (view.merging && !force) { view.resave = true; return; }`. Nothing is sent on a base known to be stale (Judge 3's churn).
- `syncState()` returns `'dirty'` (unsaved) — unchanged.
- `resendIfOwed()` calls `expireMerging()` first: `if (view.merging && env.now() - view.merging.since >= MERGE_FETCH_EXPIRY_MS) { v = view.merging.version; view.merging = null; enterConflict(v); }`. The engine's own back-off keeps polling; the owed save goes out after the writer's choice.
- `enterConflict()` clears `merging` and resets `mergeAttempts = 0`. `applyFromSync()` clears both too (a tab that undid everything while the fetch was out follows instead).

### 5.8 The merged save answers

- **ok**: existing path; `mergeAttempts = 0`; `markConfirmed(snapshot, version)`. Convergence: A saves v2; B merges and saves v3; A polls, follows or merges (its own block untouched), saves v4 only if it typed meanwhile.
- **conflict again**: 5.4 again; the third merge's refusal (`mergeAttempts >= 3`) → `enterConflict()` with the merged document as "mine" (Keep mine saves it as an overwrite; Load theirs sets it aside: Phase 1 paths unchanged).
- **rejected** (`ok:false, conflict:false`): "Not saved" with the draft kept; nothing further automatic (as today).

### 5.9 Guards that stay or are added

- `syncState()` returns `'busy'` while `handle.composing` (`editor.view.composing`): the engine never applies under an IME composition; the next poll or `retry()` delivers.
- `keepMine()`, `loadTheirs()`, `putBack()`, `discardSetAside()`: unchanged.
- Fail-closed (`handle.autosaves === false`): `mergeOrConflict()`, `putBackLost()`, `putBackAllLost()`, the draft merge and the suggestion merge do nothing; `dismissLost()` works (it touches neither editor nor draft, like `discardSetAside()`).

### 5.10 Applying a merged (or followed) document without destroying the caret

`remoteTransaction(state, json, ReplaceStep)` keeps steps 1 (level `[]`/`{}`), 2 (carry the trailing paragraph), 3 (attribute steps for stamped toc/crossRef) and 5 (doc attrs). Step 4:

```
4a. edits = blockSteps(tr.doc, next, ReplaceStep)                  // null: cannot be done per block
    probe = tr.doc; for e of edits (from DESC): r = e.step.apply(probe)
       if r.failed && e.whole: r = e.whole.apply(probe)             // a narrowed step that fails is retried whole
       if r.failed: edits = null; break
       probe = r.doc
    if edits && !probe.content.eq(next.content): edits = null
    if edits: edits.forEach(e => tr.step(e.used))
4b. else today's single diffRange ReplaceStep, verified by eq()
4c. else tr.replaceWith(0, size, next.content); a throw → return null
```

`blockSteps(current, next, ReplaceStep)`:
1. Top-level children of both as `{id, node, from, to}` (`from` = offset in `doc.content`, `to = from + nodeSize`). Any block without an id, or a shared id within one document → `null`.
2. Common ids in a different relative order (a move) → `null` (4b handles it).
3. Edits in CURRENT coordinates:
   - **delete** (current block absent from next): `[from, to] ← next.slice(0, 0)`.
   - **replace** (same id, `!a.eq(b)`): if `a.isTextblock && b.isTextblock && a.sameMarkup(b)`: `r = diffRange(a, b)` → narrowed `[from+1+r.from, from+1+r.toA] ← b.slice(r.from, r.toB)`, with `whole` kept as the fallback; otherwise (lists, tables, columns, callouts, figures, atoms, attribute changes) the whole block `[from, to] ← next.slice(bFrom, bTo)`, a closed slice at top-level boundaries that never needs the fitter.
   - **insert** (next block absent from current): at the position after the previous next block's current counterpart (or 0) `← next.slice(bFrom, bTo)`; consecutive insertions at one point are one slice.
4. **Coalesce** a deletion ending where an insertion starts (and the reverse) into one replace, so `[A] → [B]` is one step and the document never passes through an empty `doc` (`doc: block+` would fail the step).
5. Sort by `from` descending; return `[{step, whole|null, used}]`.

Why the caret survives: `Transaction.addStep` maps the selection through every step; a caret in a block no step touches maps straight through; a caret inside a narrowed replace moves only when it sits inside the changed characters; a caret inside a block replaced whole (a D1 loser's own paragraph, or a list) lands at the end of the replacement. Page-break decorations outside the edited ranges survive as today. The transaction still carries `addToHistory: false` and `preventUpdate`, `clearHistoryTransaction()` is dispatched after it when `docChanged`, `skipTrailingNode` is not set, `applyRemote()` reads `editor.getJSON()` after the dispatch into `lastSaved`, `isContentValid()` runs first, `null` leaves the screen alone.

---

## 6. Lost blocks: the offer

### 6.1 Entries

```
{ key: `${id}:${version}:${n}`, id, type, reason: 'both'|'deleted', source: 'merge'|'draft'|'suggestion'|'parked',
  json: <block JSON, this editor's serialisation>, anchors: string[], version, sentence, excerpt }
```

`offerLost(lost, source, version)` in host.js: builds entries (`sentence = sentenceFor(reason, source, blockWord(type))`, `excerpt = text`), `view.lost = appendLost(view.lost, entries)` — **append, never replace**; the only entry skipped is an exact duplicate (same `id`, identical `json` string). Then `drafts.parkLost(uuid, view.lost, view.baseVersion)` writes the WHOLE list to `lost-<uuid>` (own key: never the `stale-<uuid>` slot Load theirs uses — Judge 1/2's reload sequence), and `env.info()` one line.

Why append: both judges' D1 ping-pong — p5 "Hello B1" lost to "Hello A1", B types on into A's version, A saves again, B loses again: with per-id replacement "Hello B1" would be in no entry, no park, no draft and no version. With append it is the first item and the first element of the parked list.

### 6.2 Sentences (exact)

`blockWord(type)`: paragraph, heading, list, task list, quote, code block, rule, image, figure, table, contents list, page break, section break, callout, columns; else block.

| reason × source | Sentence |
|---|---|
| both, merge | This {word} was changed elsewhere at the same moment. The other version is on the page; this is yours. |
| deleted, merge | This {word} was removed elsewhere while you were typing in it. |
| both, draft | This {word} was changed in the document after your draft was written. The document's version is on the page; this is the draft's. |
| deleted, draft | This {word} was removed from the document after your draft was written. |
| both, suggestion | This {word} was rewritten by the accepted suggestion. The suggestion's version is on the page; this is yours. |
| deleted, suggestion | The accepted suggestion removed this {word} while you were typing in it. |
| any, parked | From your last visit: + the stored sentence |

### 6.3 The lost row (row 2 of `.doc-notices`, static markup under the existing `wire:ignore`)

Decision: **five static item slots** switched with `hidden`, not `x-for`. The wiring test can pin every element, and no Alpine pattern new to this bar is introduced (the Phase 1 bar faults were only visible in a browser). Row order and `x-bind:hidden` values: `!conflict`, `!lost.length`, `!setAside`, `!syncNotice`. `LOST_ROWS = 5` is a constant in host.js, mirrored by the markup.

```
<div class="doc-notice" hidden x-bind:hidden="!lost.length">
  <p class="doc-notice-text"><span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
    <span>Not everything you typed could be kept. Each item below stays here until you put it back or dismiss it; a copy is kept in this browser for 7 days.</span></p>
  <ul class="doc-notice-list">
    <!-- repeated for i = 0..4 -->
    <li class="doc-notice-item" hidden x-bind:hidden="!lost[i]">
      <span x-text="lost[i] ? lost[i].sentence : ''"></span>
      <q class="doc-notice-excerpt" x-text="lost[i] ? lost[i].excerpt : ''"></q>
      <button type="button" class="tool tool-mono" @click="putBackLost(i)" aria-describedby="doc-notice-put-back-lost-does"
              title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
    </li>
    <li class="doc-notice-item" hidden x-bind:hidden="lost.length <= 5">And <span x-text="lost.length - 5"></span> more. Put them all back restores every item, these included.</li>
  </ul>
  <span class="doc-notice-actions">
    <button type="button" class="tool tool-mono" @click="putBackAllLost()" aria-describedby="doc-notice-put-back-lost-does">Put them all back</button>
    <button type="button" class="tool tool-mono" @click="dismissLost()" aria-describedby="doc-notice-dismiss-lost-does"
            title="Remove these items from this page. The text is in no saved version; it is kept only in this browser, for 7 days, where this browser keeps drafts.">Dismiss all</button>
    <span id="doc-notice-put-back-lost-does" hidden>Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.</span>
    <span id="doc-notice-dismiss-lost-does" hidden>Remove these items from this page. The text is in no saved version; it is kept only in this browser, for 7 days, where this browser keeps drafts.</span>
  </span>
</div>
```

Single quotes only inside attribute values; nothing server-rendered; the existing `noticePressed()` listener covers the new buttons. `shell.css` gains `.doc-notice-list` (list-style none, margin 0, padding 0, flex column, gap `var(--s1)`), `.doc-notice-item` (flex, wrap, gap `var(--s2)`, align center), `.doc-notice-excerpt` (`color: var(--ink-soft)`, `overflow-wrap: anywhere`).

### 6.4 Put it back

`putBackLost(index)`: gate (`handle && handle.autosaves !== false && view.lost[index]`); `entry = view.lost[index]`; `handle.insertBlock(entry.json, { anchors: entry.anchors, keepId: entry.reason === 'deleted' })`; true → remove the entry, rewrite the `lost-` row with the rest (clear it when empty); false → `view.syncNotice = 'Your text could not be put back.'` and the entry stays. `putBackAllLost()` walks the list oldest first and stops at the first failure.

`handle.insertBlock(json, {anchors, keepId})` in index.js (~25 lines): `pos = insertionPoint(editor.state.doc, anchors)` (narrow.js, pure: the position after the first top-level child whose `attrs.id` is in `anchors`, in anchor order; else `doc.content.size` when `anchors.length`, else 0); copy the block; set `attrs.id = null` unless `keepId` and the id is absent from the document (nested ids that are taken are nulled too; UniqueID assigns fresh ones, BlockIdRepair covers anything missed); `schema.nodeFromJSON(block).check()`; `editor.chain().insertContentAt(pos, block, {updateSelection: false, errorOnInvalidContent: true}).setTextSelection(pos + nodeSize - 1 /* or pos + nodeSize for an atom */).focus(undefined, {scrollIntoView: true}).run()` inside try/catch. An ordinary writer's edit: undoable, fires `update`, autosaved on the current base, **no overwrite flag** (it replaces nothing). A `both` block goes below the winner (the owner's "offered back", both versions one under the other); a `deleted` block goes back where it was with its own id.

### 6.5 Dismiss all

`dismissLost()`: `env.confirm(DISMISS_LOST_QUESTION)` — "Dismiss this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days." Always asked (every entry is text the merge established is on the page nowhere); a no changes nothing. Yes → `view.lost = []`; `drafts.markLostDismissed(uuid)` stamps `dismissedAt` on the `lost-` row (kept for its 7 days, not re-offered). Not fail-closed-gated.

### 6.6 Re-offer after a reload

`restoreDraft()` (after the draft logic, before returning): `row = await drafts.loadLost(uuid)`; when `row && !row.dismissedAt && row.entries.length` → `view.lost = row.entries.map(e => ({...e, source: 'parked', sentence: 'From your last visit: ' + e.sentence}))`. Two tabs of one browser share the row (keyed per document, as drafts are): known, stated.

---

## 7. Drafts

- **Live draft** (`<uuid>`): unchanged rules (version-keyed recency, stamped one below while `overwriteOwed`, cleared only when a save settled).
- **`base-<uuid>`**: `{docUuid, json: confirmed, baseVersion, savedAt}`, written by `markConfirmed()` only (open, accepted save, applied/merged document, adopted restyle), never per keystroke. `loadBase(uuid) → {json, baseVersion} | null`.
- **`lost-<uuid>`**: `{docUuid, entries, baseVersion, parkedAt, dismissedAt?}`.
- **Sweep**: `parkedRowIsExpired(row, now, ttl)` expires `stale-`, `lost-` and `base-` rows older than `STALE_TTL_MS` (by `parkedAt`, else `savedAt`); `staleDraftIsExpired` stays as an alias; a live draft is never swept. A swept base only means the draft falls back to today's set-aside path.
- `window.offlineDraft` exposes `saveBase`, `loadBase`, `parkLost`, `loadLost`, `markLostDismissed`.

### 7.1 `restoreDraft()` when `draft.baseVersion < view.documentVersion`

```
base = await drafts.loadBase(uuid)
if (base && base.baseVersion === draft.baseVersion):
  result = handle.merge(JSON.parse(base.json), editor.getJSON(), { mine: parsed, deletionsWin: false })
      // theirs = what the page holds now; mine = the draft
  if (result):
    if (!result.changed && !result.lost.length) { drafts.clearDraft(uuid); return }      // the beacon stored it, or it is all in the document
    if (!env.confirm(DRAFT_MERGE_QUESTION)) → today's park + whole-document set-aside ('draft'); return
    await drafts.parkStaleDraft(uuid, draft.json, draft.baseVersion); drafts.clearDraft(uuid)   // pre-merge copy recoverable, as today
    if (handle.applyRemote(result.merged, { force: true })):
       view.unsaved = result.changed;                        // confirmed stays: opened() set it to the page document = theirs
       if (result.lost.length) offerLost(result.lost, 'draft', view.baseVersion)
       if (result.changed) { drafts.saveDraft(uuid, JSON.stringify(editor.getJSON()), view.baseVersion); view.resave = true }
           // sent by the first tick once startSync() has run (restoreDraft() runs before it)
       env.info(...); return
// fall through: today's park + set-aside, unchanged
```

`DRAFT_MERGE_QUESTION` is the text in section 2. `deletionsWin: false`: a draft's "theirs" can be days and many versions newer than "mine"; D2 was decided for live co-typing ("while the other types inside it"), so a draft's deletion of a block somebody has since changed does not win and overwrites nothing (Judge 1's Monday-to-Friday sequence); the question says so. A draft written by a Phase 1 page (no base row), an owed-overwrite draft (stamped one below, never matching its base row) and a merge that returns null all follow today's path.

---

## 8. Restore, import, suggestions; server changes

**Checked in the code:** `VersionHistory::restore()` runs on `/documents/{uuid}/history`, a separate page, and its `version-restored` dispatch has no listener anywhere (dead). `DocumentImportController::store()` redirects the importing tab to the editor page: a navigation after a form POST, not an editor reload; the importing tab's unsaved typing goes by the pagehide beacon first (refused as stale if the import lands first, then merged on reopen through 7.1). `Editor::acceptSuggestion()` dispatches `suggestion-accepted` and the page applies it through `applyRemote(force)`. No editor is reloaded anywhere, so nothing is removed there.

- **Accepted suggestion** (`applySuggestion(content, version)`): when `view.unsaved` and a base is known (`confirmed ?? known` at `baseVersion`): `result = handle.merge(base, content)`; applied as in 5.5 steps 6–9 with `source: 'suggestion'`, `baseVersion = version`, `engine.saved(version)`, `aiError = ''`, `refreshOutline()`. When no base is known or the merge/apply fails: park the editor's text as `stale-`, set it aside (`setAside`, `setAsideFrom = 'conflict'`, `setAsideBase = version`), then today's forced apply. Nothing of the accepter's is dropped without an offer (Phase 1 replaced it silently). Other open tabs receive it through the poll: a clean tab follows, a typing tab merges.
- **Restore**: open tabs follow or merge through the poll; no change but the dead dispatch removed.
- **Import**: unchanged. The `fetch` + `expectsJson()` variant is not built (decision 12).

**Server changes: none to logic.** `DocumentStore::save()`, `Editor::saveContent()`, both controllers, `PresenceService`, routes, migrations: untouched. The merged save is an ordinary `saveContent(merged, remote.version, overwrites)`; `overwrites` reuses the existing `$overwrite → keepReplacedAs` path (`EditorSaveBaseVersionTest::test_an_overwrite_first_keeps_the_replaced_text_in_the_version_history` covers base = current; the plan confirms it). The only touch under `app/` is deleting the dead `$this->dispatch('version-restored', ...)` line in `VersionHistory.php`. Deliberately not done: `saveContent()` returning the stored JSON (would close the normalise-drift false D1, section 14; a contract change for Phase 5); taking `contentJson` out of the Livewire snapshot (Phase 5); the import JSON branch.

---

## 9. Data each component holds and where it comes from

| Holder | Data | Source |
|---|---|---|
| Alpine view | `baseVersion`, `confirmed`, `known`, `sentSnapshot`, `unanswered`, `merging`, `mergeAttempts`, `lost`, Phase 1 fields | written by host.js only; `documentVersion`/`docUuid` from the render |
| Engine | `seen`, `remote` (pending document + outline + css), `refused` | the sync endpoint |
| Bundle handle | editor state, `dirty`, `saveTimer`, `lastSaved`, `autosave` | TipTap; `applyRemote()` and `insertBlock()` bookkeeping |
| Merge module | nothing | pure |
| IndexedDB | `<uuid>` (live draft), `stale-<uuid>`, `base-<uuid>`, `lost-<uuid>` | `edited()`, `parkStaleDraft()`, `markConfirmed()`, `offerLost()` |
| Server | `documents.version/content_json`, `document_versions` | `DocumentStore::save()` as today |

---

## 10. Request sizes and counts per tab

- Polling: unchanged (1.5 s with company, 10 s alone, none hidden; a quiet poll never loads content).
- A poll-triggered merge costs nothing extra: the document, outline and css were already in the "behind" response; it brings the tab's save forward by up to 1.2 s (at most one save per incoming version).
- A refusal-triggered merge costs one extra "behind" response (the same body `refetch()` fetches for today's conflict) plus the merged save (a Livewire request carrying the document in the argument and the snapshot both ways, as today). Bounded: at most `MERGE_ATTEMPTS = 3` merges after refusals in a row, and a fetch unanswered for 15 s ends the loop in the notice.
- `engine.saved(remote.version)` after a merge stops the consumed document being re-downloaded on every poll.
- IndexedDB: one `base-` write per confirmed version (not per keystroke); one `lost-` write per merge that lost something and per put-back/dismiss.
- Server load per save unchanged; two typists now save about every 1.2 s each plus one fetch and one save per refusal. Phase 5 measures it on the host (unmeasured, as Phase 1 states).

---

## 11. Failure handling

| Failure | Outcome |
|---|---|
| Theirs unparsable by this schema, duplicate/missing top-level id, `check()` fails on the merged document | `mergeDocuments` → null → today's conflict notice (amended sentence); Keep mine / Load theirs unchanged |
| `applyRemote()` returns false (content invalid, fail-closed) | notice (or nothing, fail-closed) |
| Per-block steps fail or do not reproduce the target | one-range apply, then whole-document apply, then null |
| Fetch for a merge never answers | 15 s → notice; owed save sent after the choice |
| Save refused three times after merges | notice with the merged document as mine |
| Merged save rejected by the schema (`ok:false, conflict:false`) | "Not saved", draft kept, nothing automatic |
| Save answer lost / request fails | `confirmed = null`, `unanswered` set; the next merge uses `known` + echo; if `known.version !== baseVersion` (after Keep mine) → notice |
| Put it back fails (`insertContentAt` throws) | entry stays, `syncNotice = 'Your text could not be put back.'` |
| Browser cannot keep IndexedDB | no base row → stale draft set aside as today; no lost- park → Dismiss loses the copy (the question still says "in this browser", as Phase 1's does) |
| A host callback throws inside `deliver()` | swallowed by the engine as today; `mergeOrConflict()` wraps `handle.merge`/`applyRemote` in try/catch and falls to the notice |
| Tab closed inside the debounce in a browser without drafts | Phase 1 limit, unchanged |

---

## 12. Tests (write first; names are the specification)

### `tests/js/sync.merge.test.js` (new; `prosemirror-model` Schema with doc/paragraph(id, align)/heading/toc/crossRef/variable/sectionBreak/box(isolating)/text)
Iterates `tests/fixtures/merge/*.json` (`{name, base, mine, theirs, echo?, options?, expected: {blocks, changed, lost: [{id, reason, anchors}], overwrites}}`), one file per row of 4.3 and per ordering case, plus hand-written cases: stamps (toc.entries, crossRef.label) and `[]` setups count as unchanged; `align: null` vs missing is one block; b2 skips an empty paragraph but offers a variable-only paragraph; c2 with `deletionsWin: false` keeps theirs and sets no flag; d anchors after a deleted predecessor, chained insertions keep order, START vs END; spine (only I / only they / both reordered); trailing paragraph (appended to merged AND theirs when theirs ends in a table; dropped when theirs ends in an empty paragraph; never counts as changed); doc attrs per key; echo (block equal to echo → mine wins; block absent from both → mine inserted; theirs ≠ echo → D1); null outcomes; `theirs` equals `nodeFromJSON(theirs).toJSON()`; `merged` round-trips. One fixture pair is REAL: `editor-form.json` captured from a TipTap `Editor` in `sync.merge.editor.test.js` and `stored-form.json` produced by a PHPUnit test (`MergeFixtureTest`: `DocumentStore::save()` then `json()` of that input); the merge of the two must be no change and no loss (canonicalisation gaps surface here).

### `tests/js/sync.apply.test.js` (extend) and `tests/js/sync.narrow.test.js` (extend)
"two separate changes are two steps and a caret between them stays put"; "a change inside a textblock is narrowed inside the block"; "a changed list is replaced whole"; "an inserted, a deleted and a changed block are three steps"; "[A] to [B] is one coalesced step, never an empty document"; "a move falls back to the one-range apply"; "a narrowed step that fails is retried whole"; "a probe that does not reproduce the target falls back"; "stamps are levelled by attribute steps before the block steps"; every existing case still passes. `insertionPoint`: first anchor present, later anchor, none with anchors → end, none without → start.

### `tests/js/sync.merge.editor.test.js` (new; real TipTap `Editor` with StarterKit, BlockId, BlockIdRepair, DocAttrs, as `sync.history.test.js`)
Merge + remote dispatch keeps ids unique, empties the history once, keeps the caret in the typist's paragraph, carries the trailing paragraph; `insertBlock` gives a fresh id for `both`, keeps the id for `deleted`, emits `update`; captures `editor-form.json`.

### `tests/js/sync.host.test.js` (extend)
Harness: the fake document becomes block-shaped (`doc(text)` = one paragraph `p1`; `type()` edits `p1`; new `typeIn(id, text)`, `insertAfter(id, newId, text)`, `deleteBlock(id)`, `moveBlock(id, afterId)`); the fake `handle.merge` calls the REAL `mergeDocuments` over a mini schema; `handle.insertBlock`, `handle.composing`; fake drafts gain `saveBase/loadBase/parkLost/loadLost/markLostDismissed`; `makeView()` gains the six fields; a `mergeFails` tab option makes `handle.merge` return null so every Phase 1 notice test (`conflicted()`) still exercises `enterConflict()`/`keepMine()`/`loadTheirs()`. The harness moves to `tests/js/support/syncWorld.js` so the walk (below) shares it. New cases:
- "both type in different paragraphs: the refused save is merged and saved on the new base, both tabs converge, nothing is lost, no notice"; "a newer version arriving while typing is merged, not refused".
- D1: "theirs stays, mine is listed; Put it back inserts it below with a fresh id and the autosave saves it without an overwrite"; "two losses of one paragraph in a row keep both offered versions and both parked copies" (the ping-pong).
- D2: "the paragraph goes, my words are listed; Put it back restores it where it was with its original id".
- c2: "the merged save says it overwrites and the server keeps the replaced version"; "Load theirs, Put it back, a merge inside the debounce: the merged save still overwrites and the head is kept"; "c2 then c1 across two merges: the overwrite stays owed until a save carried it".
- "three merged saves refused in a row end in the notice with the merged text as mine"; "a merge fetch that never answers degrades to the notice after fifteen seconds; the owed save is sent after the choice"; "while the fetch is out the debounce does not send on the stale base; the text is sent once, merged".
- "a merge that is impossible shows the amended notice; Keep mine and Load theirs work as before"; "after Keep mine no merge is attempted"; "while the notice stands a newer version updates the notice and is not merged behind the writer's back".
- Echo: "a save whose answer is lost is recognised: later typing is kept, no notice" (replaces the Phase 1 stated-limit test); "a save whose request fails while offline is merged on return"; "somebody else's edit after the echo is a true D1".
- "the consumed document is not re-merged on the next poll; the undo history is emptied once"; "a merge with nothing of mine surviving is a follow: Saved said once, draft cleared, no save sent, settle still reachable for a document ending in a table"; "a merge with nothing unsaved while the fetch is out follows and clears merging".
- Lost row: "entries are appended, exact duplicates skipped, the park holds the whole list"; "Dismiss all asks, keeps the park, marks it dismissed"; "a reload during the row re-offers the entries prefixed From your last visit; a dismissed park is not re-offered"; "Put them all back stops at the first failure and says so".
- IME: "a composition makes the tab busy; the engine delivers afterwards".
- `adoptVersion` after a restyle keeps `known.version` in step: the next remote version is merged.
- Drafts: "a stale draft with a base row is merged after a confirm and saved; its losses are listed with draft sentences"; "a draft already in the document is cleared without a question"; "a draft's deletion of a block changed since does not win and owes no overwrite"; "a declined merge parks and sets aside as today"; "no base row → today's path"; "an owed-overwrite draft is never merged".
- Suggestion: "over unsaved typing: unrelated blocks survive and are saved, the rewritten block is listed"; "when the merge is impossible the typing is set aside first".
- Fail-closed: the new paths do nothing; `dismissLost` works. "each call reads and writes the state object it was built from" extended to the new fields.

### `tests/js/sync.merge.walk.test.js` (new: random-walk convergence harness)
Seeded PRNG (`mulberry32`; seeds 1–50 by default, `WALK_SEED` to replay one, the seed printed on failure). Two or three tabs on the shared block-shaped world. 200 steps per run, each drawn from: `typeIn(tab, id)` with a unique token `t<n>` (recorded in `typed` with its author and block), `insertAfter`, `deleteBlock` (recorded in `ownDeleted` for that author), `setAttr`, `moveBlock`, `debounce(tab)`, `saveArrives/saveReturns(tab)`, `poll(tab)`, `clock += 1.5 s`, rarely `putBackAllLost(tab)` or `dismissLost(tab)`. Quiesce: repeat {every debounce, land every save, every poll} until no tab is `unsaved`, `saving`, `merging` or `resave`, bounded at 50 rounds (else fail). Invariants: (1) every tab's block list equals the server's; (2) every token is in the server document, or in some tab's `lost[].json`, or in that tab's `lost-` park, or in `server.history`, or its block was deleted by its own author; (3) ids unique in every document; (4) no conflict notice on any tab; (5) `overwriteOwed` false everywhere; (6) `server.history` gained a row for every head the trace replaced that had no row.

### `tests/js/offline.test.js` (extend)
`base-` and `lost-` rows expire after the TTL; live drafts never; `staleDraftIsExpired` keeps its answers.

### PHPUnit
- `EditorSyncWiringTest`: four rows in order `['!conflict', '!lost.length', '!setAside', '!syncNotice']`; the amended conflict sentence; the lost row's header, five item slots with `x-bind:hidden="!lost[i]"`, the overflow line, handlers `['keepMine()', 'loadTheirs()', 'putBackLost(0)' … 'putBackLost(4)', 'putBackAllLost()', 'dismissLost()', 'putBack()', 'discardSetAside()']` each once, titles and `aria-describedby`; six new `x-data` fields declared; still no `x-show`/`x-cloak` in the bar; nothing server-rendered in it; the attribute still one attribute (no double quote).
- `MergeFixtureTest` (new): produces/asserts `tests/fixtures/merge/stored-form.json` from `editor-form.json`.
- `VersionHistoryTest`: restore still works with the dispatch gone.
- Rules: `.ai/rules/editor.md` (the applyRemote section: per-block steps, the merge, what `force` covers, `confirmed` after a merge, `known`/`unanswered`, the lost row and its append rule, base rows and the draft merge, `MERGE_ATTEMPTS`, the fields list), `.ai/rules/livewire.md` (the fourth row, static slots, the fail-closed list gains `mergeOrConflict()`, `putBackLost()`, `putBackAllLost()`; `dismissLost()` ungated like `discardSetAside()`), roadmap Phase 2 paragraph (D1/D2 recorded; "both versions one under the other" becomes "on request, through Put it back").

### Browser pass (owner, two accounts, before merging)
Different paragraphs (poll-first and refusal-first with one tab throttled); D1 with Put it back; D2 both directions; echo (kill the connection mid-save); stale draft (tab killed mid-sentence); accepted suggestion while typing; restore from the history page while the other tab types; the lost row across a Livewire re-render; `storage/logs/laravel.log` "database is locked" count before and after.

---

## 13. Limits of Phase 2 (owner)

- Characters inside one block are not merged: when two people change the same paragraph, the later saver loses that paragraph on every round and types into the other's version; every lost version is offered back.
- Lists, tables, columns, callouts and figures are one block each; the loser's whole table is offered back.
- The undo history ends at every incoming change; while somebody else types steadily, undo reaches back only a few seconds. Phase 3.
- A caret inside the one block the other person changed moves to the end of their change; a D1 loser's caret moves to the end of the winner's paragraph.
- Blocks both people moved: the server's order wins silently.
- Case c2 (my deletion beats a block the other person edited and saved): they are not told; their text is in the history under "Before <name> kept their version", which misdescribes a merge.
- A tab that pressed Keep mine has no base for its new base version and cannot merge until a save is accepted (today's notice meanwhile).
- Normalise drift: `confirmed` after an accepted save is what the editor sent, not what `DocumentSchema::normalise()` stored (control characters stripped, an invalid `align` dropped). A block the server altered and the writer edits again reads as D1 and the new typing is offered back, not kept. Rare; closing it needs `saveContent()` to return the stored JSON (Phase 5).
- A block whose id the server re-stamps (invalid or duplicate id) or that TipTap re-ids on a block conversion (`toggleHeading` runs `setNode`, which may stamp a fresh id — to be pinned by one test in the plan) looks like delete + insert: the typist's words are offered back although nobody deleted anything.
- Two tabs of one browser share the live draft, the base row and the lost park (keyed per document).
- A tab closed inside the debounce in a browser that cannot keep drafts loses its last words (Phase 1 limit).
- A merge during a restore/import/suggestion splices the typist's new blocks at the end when every anchor is gone.
- The Livewire save still carries the document in the snapshot both ways (Phase 5).
- Drafts from before Phase 2 (no base row) are set aside as today.

---

## 14. What Phase 3 replaces

The step log (`prosemirror-collab`) replaces `mergeDocuments`, `merging`, `mergeAttempts`, `unanswered`/`sentSnapshot`, the echo rule, the `base-` row and the draft merge (drafts become unsent steps), and the undo-history clearing. It **keeps** the per-block apply (checkpoint and epoch loads), the lost row with its append rule and old-id reinsertion (the "last unsent seconds as recoverable text" of a new epoch), `known`, the `lost-` park, and the fail-closed and notice-bar patterns. The `off / follow / steps` switch's `follow` mode is this phase's behaviour. Nothing on the server has to be unwound.

---

## 15. Decisions taken in this spec

1. **Base design: client-merge.** Zero server change, engine untouched, the most rigorous loop (hold while merging, bounded attempts, fetch expiry, `pending` before `refetch`, no merge behind a standing notice, IME gate).
2. **`overwriteOwed` is ORed, never assigned** (hybrid's rule). Repairs client-merge's two loss sequences.
3. **Emptiness is `content.size === 0`**, never `textContent` (server-assisted's rule). Repairs the variable-only paragraph loss.
4. **Lost blocks are parked under `lost-<uuid>`**, appended, the whole list rewritten; never in `stale-` (server-assisted's slot). Repairs the Load-theirs-park overwrite.
5. **Offers are never withdrawn**: append per merge, skip only exact duplicates; no per-id coalescing. Repairs the D1 ping-pong loss in hybrid and server-assisted.
6. **Per-block entries** with reason sentence, visible excerpt, per-block Put it back; `deleted` blocks return to their old position with their original id; `both` blocks go below the winner with a fresh id (hybrid's UX).
7. **Five static item slots instead of `x-for`** inside the `wire:ignore` bar: pinnable by PHPUnit, no new Alpine pattern; `x-for` is the Phase 3 cleanup if lists grow.
8. **`known` (never nulled) as the merge base when `confirmed` is null**, excluding the Keep-mine case by `known.version === baseVersion` (hybrid), plus **the echo rule** with `unanswered` (generalised from server-assisted's `equalsTheirs` and Judge 2's suggestion). Turns a lost save answer, a failed offline save and the Phase 1 "notice against yourself" into merges, without hybrid's false D1 and with the honest D1 when somebody else did edit.
9. **`confirmed` after a merge is theirs-as-serialised plus the carried trailing paragraph when something of mine survived, and the editor's own serialisation when nothing did** (server-assisted's `settled`, Judge 1's fix). Keeps `settleIfBackAtConfirmed()` reachable.
10. **The stale-draft merge asks first, does not apply c2** (their edit stands), and clears a draft already in the document without a question (Judge 1's rule; D2's "while" does not describe a days-old draft).
11. **An accepted suggestion over unsaved typing is merged**; when it cannot be, the typing is set aside first (hybrid), never replaced silently.
12. **Import's redirect stays; the `expectsJson()` branch is not built.** It is a form navigation, not an editor reload; the beacon and the draft merge protect the typing; Phase 2 keeps zero server logic change.
13. **No `isReplacement` threshold**: a restore, import or suggestion is merged like any remote document (nothing is lost; insertions fall to the end when every anchor is gone). A guessed threshold would reintroduce the notice on a heuristic. The owner may overturn this (a notice instead of a merge for whole-document replacements) at the cost of ~20 lines.
14. **`recent`/`vanishedBlocks` (telling the c2 victim) is not built** — a heuristic that also fires on deliberate deletes; open question 1.
15. **Narrowing inside a block only for textblocks**; a narrowed step that fails is retried whole before the per-block path is abandoned (server-assisted/Judge 2).
16. **`MERGE_ATTEMPTS = 3`, `MERGE_FETCH_EXPIRY_MS = 15 s`, `LOST_ROWS = 5`, `anchors` capped at 5** — starting values; `FAST_MS` and these are the knobs to measure on production.
17. **`base-` rows expire with the 7-day sweep** (bounded storage; an open tab rewrites its row on every accepted save).
18. **Dismiss all always asks** (every entry is on the page nowhere) and stamps the park `dismissedAt` instead of deleting it (the button's 7-day promise holds); dismissed parks are not re-offered.
19. **The dead `version-restored` dispatch is removed** in this phase's docs pass.
20. **Shared fixtures** drive the merge tests, with one real editor-form/stored-form pair produced by the node and PHPUnit suites together; a random-walk convergence harness with a no-silent-loss invariant runs under `node --test`.

---

## 16. Questions put to the owner, and the answers (2026-10-16)

1. **Telling the person whose saved paragraph a merge deleted (case c2).** Today their text is in the history only. Hybrid's `recent` + `vanishedBlocks()` would show them *"This paragraph, which you wrote a moment ago, was removed by a change saved elsewhere"* with Put it back — but the same notice appears when a colleague deliberately deletes a paragraph written under two minutes ago, because the two cannot be told apart from that tab. Build it in Phase 2 (about 60 lines, one more notice), or leave it to Phase 3's exact steps? Recommended: leave it.
2. **The history label for a version kept by a c2 merge.** Reusing "Before <name> kept their version" needs no server change but misdescribes a merge. A truer label ("Before <name>'s change was merged") needs a second flag through `saveContent()`, the beacon body and the autosave controller. Recommended: reuse the label in Phase 2; the history page shows no labels today anyway.
3. **Wording.** The sentences in sections 2 and 6.2 are proposals. In particular: "elsewhere" (honest for one account in two tabs) versus "by someone else" when presence shows another person; and whether "Put it back" / "Put them all back" / "Dismiss all" are the words wanted.
4. **Whole-document replacements while somebody types** (decision 13): merge them (designed), or show the conflict notice and let the typist choose?

**Answers:** 1 — leave it to Phase 3 (recommended option taken). 2 — reuse the label in Phase 2. 3 — wording accepted as proposed. 4 — merge them (decision 13 stands).
