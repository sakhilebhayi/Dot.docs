# Co-editing Phase 2: Paragraph-Level Merge — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two people typing in different paragraphs of one document both keep typing and see each other's paragraphs appear 1–3 seconds behind, with nobody's words ever silently lost; the "changed elsewhere, choose one" notice appears only when a merge cannot be done.

**Architecture:** The merge, the per-block apply and the refused-save loop live in the browser, in the modules Phase 1 built under `resources/js/editor/sync/`. A newer document that arrives while a tab holds unsaved typing is merged block by block (top-level blocks matched by the ids the schema already assigns) against the last server document the tab held, applied as per-block steps that leave the caret alone, and saved on the new base. A block both people changed (D1) or one person deleted while the other typed in it (D2) is offered back in a new row of the notice bar. The server keeps whole-document compare-and-set: no endpoint, no column, no dependency, no engine change, `PROTOCOL` stays 1.

**Tech Stack:** Laravel 13 / PHP 8.5, Livewire 3, PHPUnit, TipTap 3 on ProseMirror, Alpine (via Livewire), `node --test` (Node 24) for JavaScript, SQLite in test and production.

**Spec:** `docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md` (the requirement; section numbers below refer to it). **Roadmap:** `docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md`. **Phase 1:** `docs/superpowers/plans/2026-10-02-coediting-phase-1-safe-saves-and-follow.md` (its "Amendments made during execution" are the traps a dry run found there; the equivalents here are listed under Global Constraints).

## Global Constraints

- Before editing any file, open `.ai/rules/index.md` and read every rule file whose globs match it. For this plan that is `editor.md` (`resources/js/editor/**`), `views.md` (`resources/views/**`, `resources/css/**`, `resources/js/**`), `livewire.md` (`resources/views/livewire/**`) and `app.md` (`app/**`). Run `grep -rin 'merge\|lost\|notice bar' .ai/rules` as well.
- Every change is tested first: write the failing test, watch it fail with the message the step names, then implement.
- Never contact the production server. Never start the project's dev server (`npm run dev`, `composer run dev`, the `dotdoc` launch configuration); the browser verification in Task 14 is the owner's, on their machine.
- Never compare editor JSON with server JSON using `==` or `JSON.stringify`. Every comparison in the merge is `Node.eq()` on nodes parsed from a levelled copy (section 4.1).
- Every path that writes into the editor or reads a draft checks `handle.autosaves === false` first (the fail-closed rule in `.ai/rules/livewire.md`). The new paths that write are `mergeOrConflict()`, `beginMerge()` (it fetches a document only to write it back, and its 15 s expiry would otherwise raise "Not saved" on a read-only page), `putBackLost()`, `putBackAllLost()`, the draft merge and the suggestion merge; `dismissLost()` is deliberately ungated, like `discardSetAside()`.
- No new npm or composer dependency. `prosemirror-model`, `prosemirror-state`, `prosemirror-transform` and `prosemirror-history` are installed (TipTap depends on them) and tests import them; the editor bundle imports the same code through `@tiptap/pm/...`.
- JavaScript under `resources/js/editor/sync/` is dependency-free so `node --test` can load it: no npm package and nothing from outside `sync/`. One file there may import another, with the `.js` extension written out (`./merge.js`), because `node --test` does not resolve an extensionless import. `host.js` gains its first import (`./merge.js`): that is inside `sync/` and allowed; `.ai/rules/editor.md` says "it has no import at all" today and Task 14 amends it.
- The `x-data` attribute of `resources/views/livewire/documents/editor.blade.php` is ONE double-quoted HTML attribute: no double-quote character anywhere inside it, code or comment. Every `js` block this plan adds to it uses single quotes only. `EditorSyncWiringTest` and `ContextualToolbarTest` fail when the attribute is cut short; the awk line in Task 9 Step 6 lists any offending line.
- **Dry-run amendments (2026-10-17).** Three verifiers executed this plan in scratch worktrees; every defect they found is folded into the steps below, and the choices made where their fixes differed are recorded under "Owner decisions this plan assumes" (the bullets marked *dry run*). The ones that change the spec are: `MERGE_ATTEMPTS` 3 → 5; two deletions that leave no block merge to one empty paragraph instead of the notice (spec 4.6, 11, 13); `beginMerge()` drops an older pending document (spec 5.4); `mergeAttempts` is also reset by a merge that leaves nothing of mine to save (spec 3.1); the lost row's re-offer runs BEFORE the draft logic (spec 6.6); the `lost-` row keeps a `dismissed` list (spec 6.5, 7, decision 18); `beginMerge()` joins the fail-closed list (spec 5.9).
- **Second dry run (2026-10-17).** The plan as amended above was executed again by three verifiers in scratch worktrees, and every correction below was then run, test first, in a fourth. The defects they found are folded into the steps and marked *dry run 2* under "Owner decisions this plan assumes": an owed overwrite is dropped when a merge leaves nothing of mine to save (Tasks 7, 11); a late OK answer of a save given up on ends the merge for its version (Task 7); `enterConflict()` drops an engine document older than the version the notice names (Task 7); `putBackSeq`, so a Put it back pressed after a flagged save left keeps its own overwrite owed (Tasks 6 to 8); Load theirs asks before it replaces a text that is already set aside (Task 8); a draft merge that leaves text to save says Editing (Task 10); an accepted suggestion gives up the save in the air and does not replace a standing set-aside text (Task 11); more than `MAX_BLOCK_STEPS` block steps take the one-range apply (Task 3); the lost list is bounded in height (Task 9); the walk can be run wider, and a second walk switches the failures of section 11 on (Task 12). The spec gains a section 17 recording the changes (Task 14 Step 3b).
- Nothing in the notice bar (`.doc-notices`, `wire:ignore`) may be rendered by the server: no Blade condition, no echoed value, no `wire:` directive. Rows and items are switched with `hidden` + `x-bind:hidden`, never `x-show`.
- PHP: run `vendor/bin/pint --dirty --format agent` after changes; `vendor/bin/phpstan analyse --memory-limit=1G` must report no errors and the baseline must not gain entries.
- Expected test counts are the dry runs': 416 JavaScript tests before Task 1, 847 after Task 13 (`node --test tests/js/*.test.js`; 100 of them are the random walk of Task 12 and 200 the failure walk of Task 12 Step 2b); PHPUnit 534 passed, 4 skipped before the plan and 537 passed, 4 skipped (3309 assertions) after it. A count that differs by one after a task is a case not written, not a case to delete.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Work on the branch `coediting-phase-2` (its first commit is the spec). Do not merge: `main` requires a review the owner cannot give themselves, and merging deploys to production.
- Tasks 1, 2 and 4 to 6 change nothing a browser can see. From Task 3 every document a clean tab follows is applied block by block (the caret in a block no step touches stays put; the undo mapping of the old one-range apply is gone, Task 3 Step 5b); from Task 7 on the page merges instead of raising the notice, and from Task 7 to Task 9 the page carries the new state fields but no lost row yet: a D1 or D2 loss between those tasks is recorded (`view.lost`, the `lost-` park) but not shown. The branch is browser-testable only after Task 9; the offline draft protects typing throughout.

## Owner decisions this plan assumes

The spec records D1, D2 and Q1–Q4 as decided. Where the spec left a choice, this plan decides as follows (each is also recorded in the task's Interfaces block):

- **The merge lives in one module, `sync/merge.js`, written in one task (Task 1), with the per-row fixtures in the next (Task 2).** The spec names the fixtures as the main test; the hand-written cases in Task 1 exist so the module is written test-first before 21 fixture files are typed.
- **`DERIVED` is exported from `resources/js/editor/derived.js`** (today a module-level `const`) so `tests/js/derived.test.js` can assert the two lists are identical, as section 4.1 asks. Nothing else about `derived.js` changes.
- **Put it back builds its own transaction (`sync/insert.js`, `insertBlockTransaction()`) instead of `editor.chain().insertContentAt()`.** Section 6.4 sketches the chain form; a transaction built in a dependency-free module can be dispatched in the real TipTap editor under `node --test` (`sync.merge.editor.test.js`), which the chain form cannot (nothing under `tests/js` can load `index.js`). The transaction is an ordinary writer's edit: undoable, `update` fires, the autosave runs, no overwrite flag. Fresh ids are generated by the bundle's `base62(8)` (handed in) rather than left `null` for UniqueID, so the id a lost block gets is decided before it goes in.
- **The caret after Put it back is `Selection.near(doc.resolve(pos + nodeSize - 1), -1)`.** Section 6.4 says `setTextSelection(pos + nodeSize - 1)`; for a list or a table that position is not inside inline content and `TextSelection` warns. `Selection.near` gives the same caret for a paragraph and a valid one for every other block.
- **"Put them all back" has a title and a description of its own** (`doc-notice-put-back-all-lost-does`), because `EditorSyncWiringTest` pins that every button in the bar has a `title` equal to the element it is described by; section 6.3's markup gave that button no title.
- **The lost-row item's `key` is `${id}:${version}:${n}` where `n` is the item's index in the merge's own lost list.** The key is informational (the row uses five static slots, no `x-for`).
- **The random-walk invariant "deleted by its own author" (section 12) is read as: every token that was in the deleter's copy of the block at the moment they deleted it is knowingly gone.** Tokens typed into that block by somebody else after the deleter's copy are not, and must be kept (in the history, through `overwrite`). Invariant 6 is checked from a trace the fake server keeps of every accepted save.
- **The real fixture pair (`editor-form.json` / `stored-form.json`) is produced by the two test suites when the file is missing or `MERGE_FIXTURE_WRITE=1` is set, and asserted against on every later run.** The editor that captures `editor-form.json` registers every project extension that loads under node through `tests/js/moduleLoader.js`; the figure and image nodes are left out (their `src` validity rules belong to a browser upload). The merge of the pair runs in `sync.merge.editor.test.js` (the real schema), not in the mini-schema fixture iterator.
- **The apply test "a change inside an isolating node plus a change after it is one step" becomes two steps.** With per-block steps a changed box and a deleted paragraph are two edits; the test is amended to say so and still asserts the target is reproduced exactly. Every other existing case passes unchanged.
- **The amended conflict sentence is** "Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one." (section 2).
- **`MERGE_ATTEMPTS = 5` (the spec's decision 16 said 3), `MERGE_FETCH_EXPIRY_MS = SAVE_EXPIRY_MS` (15 s), `LOST_ROWS = 5`, `ANCHOR_LIMIT = 5`, `EXCERPT_LENGTH = 80`** are exported constants of `host.js` / `merge.js` (section 15, decision 16). *Dry run:* at 3 the random walk (Task 12) ended a tab in the notice in 11 of 100 runs after every other fix below, and in 2 of 100 with the quiesce settling each refused tab before the next tab's save lands; with three writers in three different paragraphs whose saves always land in the same order, the slowest writer reached the bound after three rounds with none of their text stored, although every merge had been possible. At 5 the walk raised no notice in 100 runs under either quiesce. The notice is for a merge that cannot be done (section 1); a bound that raises it when every merge succeeded is the wrong bound, and 5 is the smallest value the dry run measured clean. Still a starting value to measure on production ("Before this goes live", item 4); the host tests are written against the constant, not the digit, so the owner can turn it back to 3 by changing one line and one test name.
- *Dry run:* **two deletions that leave no block are a merge, not an impossible one.** When each side deleted what the other kept, the output has no block and `doc: block+` fails `check()`; spec 4.6 and section 11 made that `null` and the notice, for two plain deletions nothing was lost in. `mergeDocuments()` emits one empty paragraph under the id of mine's last block (every id of the union is gone from the output, so none is duplicated), `changed` true, nothing lost, no overwrite. Spec 4.6, 11 (row 1) and 13 need the sentence.
- *Dry run:* **`beginMerge()` drops a pending document OLDER than the refused version** (`engine.takeRemote()`) before it refetches. Left in place, the `retry()` that follows every answered save hands it to `mergeOrConflict()`, which merges it, ends `merging` and sends the merged document on a base known to be stale: a refusal for nothing and an attempt gone (seen in the walk as a tab reaching the bound although every merge was possible). Spec 5.4's `beginMerge` sketch gains the line. The other repair measured (a guard in `mergeOrConflict()` that ignores a document older than `merging.version`) works as well; the drop is where the decision is made and leaves the spec's `mergeOrConflict()` steps as they are.
- *Dry run:* **`beginMerge()` is fail-closed-gated** like every other merge path (spec 5.9 lists `mergeOrConflict()` only; the Global Constraints list it).
- *Dry run:* **`mergeAttempts` is also reset by a merge that leaves nothing of mine to save** (`result.changed` false: a follow in disguise, the echo case). Otherwise the count stays at 1 for good after such a merge and the next loop has one attempt fewer. Spec 3.1's list of resets gains it.
- *Dry run:* **the re-offer of the lost park runs BEFORE the draft logic in `restoreDraft()`** (spec 6.6 says after). `offerLost()` parks the WHOLE list: run after the draft merge it read the park the merge had just rewritten and offered the fresh losses back as "From your last visit", and an earlier visit's park was overwritten before it was read. Run first, the draft merge's losses are appended after the re-offered ones, with their own source.
- *Dry run:* **the `lost-` row keeps a `dismissed` list beside `entries`.** Dismiss all moves the entries there (`markLostDismissed()`), `parkLost()` carries the list over on every rewrite, `loadLost()` returns it; `entries` holds only what is offered. Without it the next loss after Dismiss all rewrote the row from the emptied view list and the dismissed text, which the button promised "kept only in this browser, for 7 days", was in no version anywhere (the walk's invariant 2 caught it in 10 of 100 runs). Spec 6.5, 7 and decision 18 describe the row-level stamp only; the stamp stays and the list is added. The other repair measured (a `dismissedAt` on each entry, kept inside `entries`) works as well; a second list keeps `entries` meaning "offered" everywhere it is read.
- *Dry run:* **`mergeDocuments()` compares `doc.attrs` with `JSON.stringify`**, so two `vars` objects with the same keys in a different order read as "mine changed": mine's `vars` win and `changed` is true (one extra save), nothing is lost. Left as it is; listed under "What Phase 2 does NOT do".
- *Dry run 2:* **an owed overwrite is ORed only while something of mine is left to save.** A merge that leaves nothing of mine to save (`result.changed` false: a follow in disguise) sends nothing, so no save is left to carry the flag, and it is cleared: `view.overwriteOwed = result.changed ? view.overwriteOwed || result.overwrites : false`, in `mergeOrConflict()` and in `mergeSuggestion()`. Left owed, the next unrelated save carried `overwrite: true` (a spurious "Before <name> kept their version" row) and `edited()` stamped the drafts one version below the base, so a later draft merge found no matching base row and fell back to the set-aside. The walk's invariant 5 caught it in 39 of 24,000 runs (seeds 1 to 12,000, two and three tabs); with the one line, 0 of 80,000 (seeds 1 to 40,000). Spec 5.5 (the "Why step 7's OR" sentence) and Task 14's rule text say so.
- *Dry run 2:* **the late OK answer of a save given up on ends a merge for that version or an older one** (`saveAnswered()`, after `markConfirmed()`): the fetch the merge started would bring the very version the answer names, which `engine.saved()` marks as seen, so it would never be delivered and `merging` would hold every save until the 15 s expiry raised a notice nobody caused. The merge is over and the text is owed (`merging = null`, `resave = true`).
- *Dry run 2:* **`enterConflict()` drops an engine document OLDER than the version the notice names** (`engine.takeRemote()`), then refetches. A Keep mine save refused by a still newer version left the older document pending and the notice `ready`; Load theirs then showed a version the head had moved past, and Put it back ran as if the document had not moved. Phase 2 reaches this far more often, because every bound, impossible merge and fetch expiry routes through `enterConflict()`.
- *Dry run 2:* **`putBackSeq`, a seventh `x-data` field.** Put it back pressed while a save that CARRIED the overwrite is in the air (a c2 merge, or Keep mine) would have that save's OK answer write off the overwrite the put-back text still owes: the put-back save then went out on the stored head with `overwrite: false`, replacing it with no version kept. `putBack()` stamps `view.putBackSeq = view.saveSeq`; `persist()` passes `overwrite && view.putBackSeq < seq` to `saveAnswered()`, so only a save sent AFTER the put-back can settle it. Blocking Put it back while a save is in the air is not an option (Phase 1's test requires it to work). The field must be declared in the Blade, or `undefined < n` is false and the overwrite would be owed for ever.
- *Dry run 2:* **an accepted suggestion first gives up the save in the air**, before the merge decides its base and before `markConfirmed()`: left to expire later inside `persist()`, the older save nulled `confirmed` again and installed its older document as the echo of the NEXT merge, so a later change of somebody else that equalled it (a restore of that version) read as this tab's own and was dropped without the overwrite flag; and an OK answer of that save moved the base BACK. **And it does not replace a text that is already set aside**: when the suggestion cannot be merged and a set-aside offer stands, `aiError` says to put back or discard that text first (the other tabs still receive the suggestion through the poll); `setAsideTyping()` would have overwritten the offer and its `stale-` park, and the earlier text would have been in no version, no park and no offer.
- *Dry run 2:* **Load theirs asks before it replaces a text that is already set aside** (`LOAD_THEIRS_REPLACES_QUESTION`, asked before the engine's document is taken, so a no changes nothing). The `stale-` park holds one text; three Phase 2 paths leave an offer standing (a declined draft merge, the suggestion fallback, Load theirs itself), and the lost row promises that nothing offered is withdrawn.
- *Dry run 2:* **a draft merge that leaves text to save reports Editing.** `opened()` said Saved a moment before; the merged text is unsaved until the first poll tick sends it, and the top bar (`shell:save-state`) does not hide Saved the way the in-page strip does.
- *Dry run 2:* **`MAX_BLOCK_STEPS = 500`** (`apply.js`): a restore or an import that shares few ids with a large document would produce one block step per block, each applied twice (probe and transaction) and mapped through every plugin; in the real editor 4,000 blocks cost 1.9 s against 0.5 s for Phase 1's single range. Beyond 500 steps the one-range apply is used. `blockSteps()` itself does not cap; `applyBlockSteps()` does.
- *Dry run 2:* **`excerpt()` cuts by code point,** so a character outside the BMP that straddles the 80th is kept whole or left out whole; the notice never shows a lone surrogate.
- *Dry run 2:* **the stray empty paragraph is intended** (spec 4.4). When mine ends in an empty paragraph the editor added after the last confirmation (TrailingNode, e.g. after a table mine inserted) and theirs ends in a NON-empty paragraph, the empty one is an ordinary insertion and lands between the blocks. An empty paragraph cannot be told from one the writer typed on purpose, nothing is lost, and either writer can delete it; the alternative (treat it as the editor's own whenever theirs ends in any paragraph) would drop a deliberate blank line. Task 1's test says so; Task 14 Step 3b records it in the spec.
- *Dry run 2:* **the second walk (`sync.merge.walk.failures.test.js`) is expected green only with all of the repairs above.** On the plan code before them it fails on seeds 23 and 100 within its first hundred, and on 146, 189, 247, 333, 386, 420, 440, 442 and 453 within its first five hundred. Task 12's walk reaches none of the failures of section 11; the second walk does, and its docblock says which it does not cover.

## What Phase 2 does NOT do

Section 13 of the spec is the list; what an engineer executing this plan must not try to fix along the way:

- Characters inside one block are not merged: the later saver of a block both changed loses that block on every round and types into the other's version; every lost version is offered back (D1).
- Lists, tables, columns, callouts and figures are one block each; the loser's whole table is offered back.
- The undo history ends at every incoming change (Phase 1, unchanged). A D1 loser's caret moves to the end of the winner's paragraph.
- Blocks both people moved: the server's order wins silently.
- The person whose saved paragraph a c2 merge deleted is not told (Q1); their text is in the history under "Before <name> kept their version" (Q2).
- A tab that pressed Keep mine has no base for its new base version and cannot merge until a save is accepted (today's notice meanwhile).
- Normalise drift (section 13): a block the server altered on save (control characters, an invalid `align`) reads as D1 when the writer edits it again. Closing it needs `saveContent()` to return the stored JSON (Phase 5).
- A block whose id the server re-stamps, or that TipTap re-ids on a block conversion, looks like delete + insert. Task 4 pins which conversions do: `toggleHeading` and `toggleCodeBlock` keep the id; wrapping a paragraph in a bullet list, ordered list, task list or quote gives the wrapper a new top-level id (the paragraph id moves inside it). So a saved edit to a paragraph that somebody else then wraps is dropped from the page (case c2: kept in the history, nobody told), and a typist in a paragraph somebody else wraps is told it was removed. Closing it needs the merge to treat a deleted top-level id that reappears nested inside one of mine's inserted blocks as a D1 between the two blocks instead of c2/b2: an owner decision, not built.
- Two tabs of one browser share the live draft, the base row and the lost park.
- The `expectsJson()` import branch, the `recent`/`vanishedBlocks` notice and an `isReplacement` threshold are not built (decisions 12–14).
- `saveContent()` does not return the stored JSON; `contentJson` stays in the Livewire snapshot (Phase 5).
- Document attributes are compared as JSON strings (section 4.6): `vars` with the same keys in a different order count as changed by mine, which costs one save and loses nothing (dry run).
- When both sides changed the same document attribute (`vars`, `style`), the later saver's value wins silently and nothing is offered back (spec 4.6); blocks do the opposite (D1: theirs stays, mine is offered back). In practice `vars` and `style` change through server actions that bump the version, so mine rarely differs from the base. If the owner wants D1 symmetry, set `result.overwrites = true` in that branch so the replaced head is kept in the history.
- A stray empty paragraph can be saved between two blocks: mine ends in the empty paragraph the editor added after the last confirmation, and theirs ends in a non-empty paragraph (see Owner decisions, the dry run 2 bullet on spec 4.4). Nothing is lost.
- A document-sized change (a restore, an import) that shares few ids with a large document is applied as Phase 1's single range, not block by block, beyond `MAX_BLOCK_STEPS` = 500 steps (Task 3): the caret in a block no step touches is not kept in that case.

## File Structure

| File | Responsibility |
|---|---|
| `resources/js/editor/sync/merge.js` (create) | `mergeDocuments()`, `levelEmpty()`, `levelDerived()`, `isEmptyBlock()`, `excerpt()`, `blockWord()`, `lostEntry()`, `appendLost()`, `DERIVED`. Pure; imports nothing. |
| `resources/js/editor/derived.js` (modify) | `DERIVED` becomes an export (one word). |
| `tests/fixtures/merge/*.json` (create) | One fixture per row of section 4.3 and per ordering case; `editor-form.json` and `stored-form.json`, the real pair. |
| `resources/js/editor/sync/apply.js` (modify) | `remoteTransaction()` step 4 becomes the per-block builder `blockSteps()` (exported), probed before use and not tried beyond `MAX_BLOCK_STEPS` (500, exported), with today's one-range and whole-document applies as fallbacks. |
| `resources/js/editor/sync/narrow.js` (modify) | New `insertionPoint(doc, anchors)`. |
| `resources/js/editor/sync/insert.js` (create) | `insertBlockTransaction()`: the transaction Put it back dispatches. |
| `resources/js/editor/index.js` (modify) | Handle gains `merge()`, `insertBlock()`, `get composing()`; `window.offlineDraft` gains five functions. |
| `resources/js/offline.js` (modify) | `base-<uuid>` and `lost-<uuid>` rows; `parkedRowIsExpired()`. |
| `resources/js/editor/sync/host.js` (modify) | The loop (`beginMerge()`, `mergeOrConflict()`, `expireMerging()`, `markConfirmed()`), the offer (`offerLost()`, `putBackLost()`, `putBackAllLost()`, `dismissLost()`, `sentenceFor()`, `reofferLost()`), the draft merge (`mergeDraft()`), the suggestion merge (`mergeSuggestion()`, `setAsideTyping()`), `writeBase()`; amended `persist()`, `saveAnswered()`, `saveInTheAir()`, `syncState()`, `resendIfOwed()`, `applyFromSync()`, `adoptVersion()`, `enterConflict()`, `loadTheirs()`, `putBack()`, `restoreDraft()`, `applySuggestion()`; new export `LOAD_THEIRS_REPLACES_QUESTION`. |
| `tests/js/support/syncWorld.js` (create) | The block-shaped fake world the host tests and the walk share. |
| `tests/js/sync.host.test.js` (modify) | Moves onto the shared harness; the Phase 2 cases. |
| `tests/js/sync.merge.test.js`, `sync.merge.editor.test.js`, `sync.merge.walk.test.js`, `sync.merge.walk.failures.test.js` (create) | The merge, the real editor, the random walk, the random walk with the failures of section 11 switched on. |
| `tests/js/sync.apply.test.js`, `sync.narrow.test.js`, `sync.history.test.js`, `uniqueIds.test.js`, `offline.test.js`, `derived.test.js` (modify) | Extended; the two historical replays (`sync.history`, `uniqueIds`) are pinned to the one-range apply. |
| `resources/views/livewire/documents/editor.blade.php` (modify) | Seven `x-data` fields, three delegates, the lost row, the amended conflict sentence. |
| `resources/css/shell.css` (modify) | `.doc-notice-list` (bounded in height), `.doc-notice-item`, `.doc-notice-excerpt`. |
| `scripts/design/contrast-dom.mjs` (modify) | `.doc-notice-excerpt` joins `INKS`. |
| `tests/Feature/Documents/EditorSyncWiringTest.php` (modify) | Pins the fields, the fourth row, the slots, the sentence. |
| `tests/Feature/Documents/MergeFixtureTest.php` (create) | Produces/asserts `stored-form.json`. |
| `app/Livewire/Documents/VersionHistory.php` (modify) | The dead `version-restored` dispatch goes. |
| `tests/Feature/Documents/VersionHistoryTest.php` (modify) | Pins that the dispatch is gone. |
| `.ai/rules/editor.md`, `.ai/rules/livewire.md`, `.ai/rules/views.md`, `.ai/rules/app.md`, `docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md`, `docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md` (modify) | The standing rules, the roadmap paragraph, and the spec's section 17 (the amendments made while planning). |

---

## Task 1: The merge module, `sync/merge.js`

**Files:**
- Create: `resources/js/editor/sync/merge.js`
- Test: `tests/js/sync.merge.test.js` (create)

**Interfaces:**
- Produces: `mergeDocuments(schema, {base, mine, theirs, echo = null}, {deletionsWin = true} = {}) -> MergeResult | null`, `MergeResult = {merged: object, theirs: string, changed: boolean, lost: [{id, type, reason: 'both'|'deleted', json, anchors: string[], text}], overwrites: boolean}` exactly as section 4 defines it. `base`, `mine`, `theirs`, `echo` are Dot.Doc JSON documents (objects); `schema` is a ProseMirror `Schema` (the editor's, or a test's).
- Produces: `levelEmpty(json)`, `levelDerived(json)` (deep copies, section 4.1), `isEmptyBlock(node)` (paragraph or heading with `content.size === 0`), `excerpt(node)` (`textContent` cut to `EXCERPT_LENGTH` code points plus `…`, or `[<blockWord>]` when there is no text), `blockWord(type)` (section 6.2's words), `lostEntry(loss, {source, version, n, sentence})` (section 6.1's entry), `appendLost(list, entries)` (append; skip only an exact duplicate, same `id` and identical `JSON.stringify(json)`), and the constants `DERIVED`, `EXCERPT_LENGTH = 80`, `ANCHOR_LIMIT = 5`.
- Decisions: an insertion's anchors are `[]` for START and a non-empty list for everything else (`insertionPoint()` in Task 3 reads `[]` as "the start" and a list with no present id as "the end", section 4.5); a lost `both` block's anchors are `[id]`; a lost `deleted` block's anchors are up to five preceding ids of mine, nearest first. The order comparison for the spine uses the ORIGINAL base (the echo rule is about content). Imports nothing: `schema.topNodeType.create(attrs, nodes)` builds the merged document, so `Fragment` is not needed.
- Decisions (dry run): `lost[].json` is plain JSON (a `JSON.parse(JSON.stringify())` copy), never `Node.toJSON()`'s own object, whose `attrs` prosemirror-model builds with `Object.create(null)`: `node:assert/strict`'s `deepEqual` tells a null-prototype object from a plain one, and the entry also goes into IndexedDB and Alpine. When the output has no block at all (each side deleted what the other kept) the result is one empty paragraph under the id of mine's last block, `changed` true, nothing lost: two deletions are a merge, not the notice (see Owner decisions).

- [ ] **Step 1: Write the failing tests**

Create `tests/js/sync.merge.test.js`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema } from 'prosemirror-model';

import {
    DERIVED,
    appendLost,
    blockWord,
    excerpt,
    isEmptyBlock,
    levelDerived,
    levelEmpty,
    lostEntry,
    mergeDocuments,
} from '../../resources/js/editor/sync/merge.js';

/**
 * The paragraph-level merge (sync/merge.js), row by row of the spec's
 * decision table (section 4.3) and rule by rule of its levelling (4.1),
 * echo (4.2), trailing paragraph (4.4), order (4.5) and attributes (4.6).
 *
 * A stand-in for the editor's schema: the node types the merge treats
 * specially, declared the way the editor declares them. A contents list and
 * a cross-reference the server stamps, a section break whose `setup` PHP
 * may send as `[]`, an `isolating` container (like table cells, figures and
 * columns), a variable chip (an inline atom with no text), the
 * document-level attrs.
 */
const schema = new Schema({
    nodes: {
        doc: { content: 'block+', attrs: { schema: { default: 1 }, style: { default: 'report' }, vars: { default: {} } } },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        heading: { group: 'block', content: 'inline*', attrs: { id: { default: null }, level: { default: 1 } } },
        toc: { group: 'block', atom: true, attrs: { id: { default: null }, entries: { default: [] } } },
        sectionBreak: { group: 'block', atom: true, attrs: { id: { default: null }, setup: { default: {} } } },
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        crossRef: {
            group: 'inline',
            inline: true,
            atom: true,
            attrs: { kind: { default: 'heading' }, targetId: { default: null }, label: { default: null } },
        },
        variable: { group: 'inline', inline: true, atom: true, attrs: { key: { default: '' } } },
        text: { group: 'inline' },
    },
});

/** A paragraph. Strings become text; anything else is an inline node. */
const para = (id, ...inline) => ({
    type: 'paragraph',
    attrs: { id },
    ...(inline.length
        ? { content: inline.map((part) => (typeof part === 'string' ? { type: 'text', text: part } : part)) }
        : {}),
});
const heading = (id, text, level = 1) => ({ type: 'heading', attrs: { id, level }, content: [{ type: 'text', text }] });
const toc = (id, entries = []) => ({ type: 'toc', attrs: { id, entries } });
const sectionBreak = (id, setup = {}) => ({ type: 'sectionBreak', attrs: { id, setup } });
const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
const ref = (targetId, label = null) => ({ type: 'crossRef', attrs: { kind: 'heading', targetId, label } });
const variable = (key) => ({ type: 'variable', attrs: { key } });
const docOf = (blocks, attrs) => ({ type: 'doc', ...(attrs ? { attrs } : {}), content: blocks });

const merge = (base, mine, theirs, extra = {}, options = undefined) =>
    mergeDocuments(schema, { base, mine, theirs, ...extra }, options);
const textOf = (block) => (block.content ?? []).map((node) => node.text ?? '').join('');
/** Every top-level block as [id, text]. */
const blocksOf = (json) => json.content.map((block) => [block.attrs.id, textOf(block)]);
const idsOf = (json) => json.content.map((block) => block.attrs.id);
const losses = (result) => result.lost.map(({ id, reason, anchors }) => ({ id, reason, anchors }));

// ───────────────────────────────────────────────────────── the decision table (4.3)

test('a3 and a2: changes in different paragraphs are both kept, mine counts as changed, nothing is lost', () => {
    const result = merge(
        docOf([para('p1', 'Hello'), para('p2', 'World')]),
        docOf([para('p1', 'Hello B'), para('p2', 'World')]),
        docOf([para('p1', 'Hello'), para('p2', 'World A')])
    );

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'Hello B'],
        ['p2', 'World A'],
    ]);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('a1 and a4: an unchanged block and the same change on both sides are theirs, and nothing counts as changed', () => {
    const base = docOf([para('p1', 'Hello'), para('p2', 'World')]);
    const same = docOf([para('p1', 'Hello'), para('p2', 'World!')]);

    const result = merge(base, same, same);

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'Hello'],
        ['p2', 'World!'],
    ]);
    assert.equal(result.changed, false);
    assert.deepEqual(result.lost, []);
});

test('a5 (D1): theirs stays, mine is offered back anchored to the paragraph, and nothing counts as changed', () => {
    const result = merge(docOf([para('p1', 'Hello')]), docOf([para('p1', 'Hello B')]), docOf([para('p1', 'Hello A')]));

    assert.deepEqual(blocksOf(result.merged), [['p1', 'Hello A']]);
    assert.equal(result.changed, false);
    assert.deepEqual(losses(result), [{ id: 'p1', reason: 'both', anchors: ['p1'] }]);
    assert.equal(result.lost[0].type, 'paragraph');
    assert.equal(result.lost[0].text, 'Hello B');
    assert.deepEqual(result.lost[0].json, {
        type: 'paragraph',
        attrs: { id: 'p1', align: null },
        content: [{ type: 'text', text: 'Hello B' }],
    });
});

test('b2 (D2): a paragraph they deleted while I typed in it is offered back with its preceding ids, nearest first', () => {
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y'), para('p3', 'z')]),
        docOf([para('p1', 'x'), para('p2', 'y B'), para('p3', 'z')]),
        docOf([para('p1', 'x'), para('p3', 'z')])
    );

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'x'],
        ['p3', 'z'],
    ]);
    assert.equal(result.changed, false);
    assert.deepEqual(losses(result), [{ id: 'p2', reason: 'deleted', anchors: ['p1'] }]);
    assert.equal(result.lost[0].text, 'y B');
});

test('b2 remembers at most five preceding ids', () => {
    const ids = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7'];
    const base = docOf([...ids.map((id) => para(id, id)), para('p8', 'y')]);
    const mine = docOf([...ids.map((id) => para(id, id)), para('p8', 'y B')]);
    const theirs = docOf(ids.map((id) => para(id, id)));

    assert.deepEqual(losses(merge(base, mine, theirs)), [{ id: 'p8', reason: 'deleted', anchors: ['a7', 'a6', 'a5', 'a4', 'a3'] }]);
});

test('b2 drops an empty paragraph silently but offers a paragraph holding only a variable chip', () => {
    const base = docOf([para('p1', 'x'), para('p2', 'y'), para('p3', 'z')]);
    const theirs = docOf([para('p1', 'x')]);

    const emptied = merge(base, docOf([para('p1', 'x'), para('p2'), para('p3', 'z')]), theirs);
    assert.deepEqual(losses(emptied), []);
    assert.deepEqual(idsOf(emptied.merged), ['p1']);

    const chip = merge(base, docOf([para('p1', 'x'), para('p2', variable('client')), para('p3', 'z')]), theirs);
    assert.deepEqual(losses(chip), [{ id: 'p2', reason: 'deleted', anchors: ['p1'] }]);
    assert.equal(chip.lost[0].text, '[paragraph]');
});

test('b1, c1 and g: a deletion nobody else touched is honoured, nothing is lost, nothing overwrites', () => {
    // They deleted p2 (untouched by me); I deleted p3 (untouched by them); both deleted p4.
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y'), para('p3', 'z'), para('p4', 'w')]),
        docOf([para('p1', 'x'), para('p2', 'y')]),
        docOf([para('p1', 'x'), para('p3', 'z')])
    );

    assert.deepEqual(blocksOf(result.merged), [['p1', 'x']]);
    assert.equal(result.changed, true, 'my deletion of p3 has to be saved');
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('c2: my deletion of a paragraph they edited wins, and the save must carry overwrite', () => {
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y')]),
        docOf([para('p1', 'x')]),
        docOf([para('p1', 'x'), para('p2', 'y A')])
    );

    assert.deepEqual(blocksOf(result.merged), [['p1', 'x']]);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, true);
});

test('c2 with deletionsWin false keeps their edit, loses nothing and sets no flag', () => {
    const result = merge(
        docOf([para('p1', 'x'), para('p2', 'y')]),
        docOf([para('p1', 'x')]),
        docOf([para('p1', 'x'), para('p2', 'y A')]),
        {},
        { deletionsWin: false }
    );

    assert.deepEqual(blocksOf(result.merged), [
        ['p1', 'x'],
        ['p2', 'y A'],
    ]);
    assert.equal(result.changed, false);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('d: my new paragraph is kept and anchored after its nearest surviving predecessor', () => {
    const result = merge(
        docOf([para('p1', '1'), para('p2', '2'), para('p3', '3')]),
        docOf([para('p1', '1'), para('p2', '2'), para('n1', 'new'), para('p3', '3')]),
        docOf([para('p1', '1'), para('p3', '3')])
    );

    assert.deepEqual(idsOf(result.merged), ['p1', 'n1', 'p3']);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
});

test('d: chained insertions keep their order, a block with no predecessor goes first, and one whose predecessors are all gone goes last', () => {
    const chained = merge(
        docOf([para('p1', '1'), para('p2', '2')]),
        docOf([para('n0', 'top'), para('p1', '1'), para('n1', 'a'), para('n2', 'b'), para('p2', '2')]),
        docOf([para('p1', '1'), para('p2', '2 A')])
    );
    assert.deepEqual(idsOf(chained.merged), ['n0', 'p1', 'n1', 'n2', 'p2']);
    assert.equal(textOf(chained.merged.content[4]), '2 A');

    // Every predecessor of the typist's new paragraph was replaced by an
    // import: it lands at the end, not before the imported title.
    const orphan = merge(
        docOf([para('p1', 'old'), para('p2', 'old too')]),
        docOf([para('p1', 'old'), para('p2', 'old too'), para('n1', 'typed')]),
        docOf([heading('h1', 'Imported title'), para('q1', 'Imported body')])
    );
    assert.deepEqual(idsOf(orphan.merged), ['h1', 'q1', 'n1']);
    assert.equal(orphan.changed, true);
    assert.deepEqual(orphan.lost, []);
});

test('e and f: their new block is taken; a block both pasted is one copy when equal and a D1 when not', () => {
    const base = docOf([para('p1', '1')]);

    const taken = merge(base, docOf([para('p1', '1')]), docOf([para('p1', '1'), para('q1', 'theirs')]));
    assert.deepEqual(idsOf(taken.merged), ['p1', 'q1']);
    assert.equal(taken.changed, false);

    const equal = merge(base, docOf([para('p1', '1'), para('q7', 'pasted')]), docOf([para('p1', '1'), para('q7', 'pasted')]));
    assert.deepEqual(blocksOf(equal.merged), [
        ['p1', '1'],
        ['q7', 'pasted'],
    ]);
    assert.equal(equal.changed, false);
    assert.deepEqual(equal.lost, []);

    const differing = merge(base, docOf([para('p1', '1'), para('q7', 'mine')]), docOf([para('p1', '1'), para('q7', 'theirs')]));
    assert.deepEqual(blocksOf(differing.merged), [
        ['p1', '1'],
        ['q7', 'theirs'],
    ]);
    assert.deepEqual(losses(differing), [{ id: 'q7', reason: 'both', anchors: ['q7'] }]);
});

// ───────────────────────────────────────────────────────── levelling (4.1), trailing paragraph (4.4), attrs (4.6), echo (4.2), order (4.5)

test('levelling: stamps, [] setups, [] vars and align null count as unchanged, and the output keeps the stamps', () => {
    const base = docOf([toc('t1', []), para('p1', 'See ', ref('h1', null)), sectionBreak('s1', {}), para('p2', 'end')], { vars: {} });
    const mine = docOf(
        [
            toc('t1', []),
            { type: 'paragraph', attrs: { id: 'p1', align: null }, content: [{ type: 'text', text: 'See ' }, ref('h1', null)] },
            sectionBreak('s1', {}),
            para('p2', 'end B'),
        ],
        { vars: {} }
    );
    // As the server stores it: stamped entries and label, [] for the empties, no align.
    const theirs = docOf(
        [
            toc('t1', [{ id: 'h1', text: 'Intro' }]),
            { type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: 'See ' }, ref('h1', '1 Intro')] },
            sectionBreak('s1', []),
            para('p2', 'end'),
        ],
        { vars: [] }
    );

    const result = merge(base, mine, theirs);

    assert.deepEqual(idsOf(result.merged), ['t1', 'p1', 's1', 'p2']);
    assert.deepEqual(result.lost, []);
    assert.equal(result.changed, true, 'only p2 is mine');
    assert.deepEqual(result.merged.content[0].attrs.entries, [{ id: 'h1', text: 'Intro' }], 'the stamped entries stay on screen');
    assert.equal(result.merged.content[1].content[1].attrs.label, '1 Intro');
    assert.deepEqual(result.merged.content[2].attrs.setup, {});
    assert.deepEqual(result.merged.attrs.vars, {});
    assert.equal(textOf(result.merged.content[3]), 'end B');
});

test('levelling: a paragraph B typed in is kept when A\'s save only re-stamped the cross-reference label inside it', () => {
    const base = docOf([para('p1', 'See ', ref('h1', null), ' for details')]);
    const mine = docOf([para('p1', 'See ', ref('h1', null), ' for details, and my addition')]);
    // As the server stores it after A renumbered the headings: the same paragraph, a new label.
    const theirs = docOf([para('p1', 'See ', ref('h1', 'Section 3'), ' for details')]);

    const result = merge(base, mine, theirs);

    assert.deepEqual(result.lost, [], 'a re-stamped label is not a change by A, so there is no D1');
    assert.equal(result.changed, true);
    assert.equal(textOf(result.merged.content[0]), 'See  for details, and my addition');
});

test('trailing paragraph: appended to merged AND theirs when theirs ends in a box; dropped when theirs ends in an empty paragraph; never counted as changed', () => {
    const base = docOf([para('p1', 'one'), box('b1', para('q1', 'in'))]);
    // This editor added its own empty paragraph after the box; the server's document does not have it.
    const mine = docOf([para('p1', 'one'), box('b1', para('q1', 'in')), para('t1')]);

    const carried = merge(base, mine, docOf([para('p1', 'one A'), box('b1', para('q1', 'in'))]));
    assert.deepEqual(idsOf(carried.merged), ['p1', 'b1', 't1']);
    assert.equal(carried.changed, false, 'the carried paragraph is in theirs too, so nothing of mine has to be saved');
    assert.deepEqual(idsOf(JSON.parse(carried.theirs)), ['p1', 'b1', 't1']);
    assert.deepEqual(carried.lost, []);

    // Theirs ends in an empty paragraph of its own (the other tab's trailing paragraph): mine is dropped.
    const dropped = merge(base, mine, docOf([para('p1', 'one A'), box('b1', para('q1', 'in')), para('t2')]));
    assert.deepEqual(idsOf(dropped.merged), ['p1', 'b1', 't2']);
    assert.equal(dropped.changed, false);
    assert.deepEqual(dropped.lost, []);

    // Theirs ends in a paragraph with text: the empty paragraph is an ordinary insertion of mine, anchored after the box.
    // A stray blank paragraph between the two is the cost, and it is intended (spec 4.4, as amended in its section 17): an
    // empty paragraph cannot be told from one the writer typed on purpose, nothing is lost, and either writer can delete it.
    const ordinary = merge(base, mine, docOf([para('p1', 'one A'), box('b1', para('q1', 'in')), para('t3', 'typed')]));
    assert.deepEqual(idsOf(ordinary.merged), ['p1', 'b1', 't1', 't3']);
    assert.equal(ordinary.changed, true);
});

test('doc attrs: per key, mine when mine changed it, else theirs; {} and [] are one empty value', () => {
    const blocks = [para('p1', 'x')];

    const result = merge(
        docOf(blocks, { style: 'report', vars: {} }),
        docOf(blocks, { style: 'legal', vars: {} }),
        docOf(blocks, { style: 'report', vars: { client: 'Acme' } })
    );
    assert.equal(result.merged.attrs.style, 'legal');
    assert.deepEqual(result.merged.attrs.vars, { client: 'Acme' });
    assert.equal(result.changed, true);

    const empties = merge(docOf(blocks, { vars: {} }), docOf(blocks, { vars: {} }), docOf(blocks, { vars: [] }));
    assert.equal(empties.changed, false);
    assert.deepEqual(empties.merged.attrs.vars, {});
});

test('echo: a block equal to the echo makes mine win, a block absent from both is my insertion, and theirs unlike the echo is a true D1', () => {
    const known = docOf([para('p1')]);
    const echo = docOf([para('p1', 'X')]);
    const mine = docOf([para('p1', 'XY'), para('n1', 'new since')]);

    const own = merge(known, mine, docOf([para('p1', 'X')]), { echo });
    assert.deepEqual(blocksOf(own.merged), [
        ['p1', 'XY'],
        ['n1', 'new since'],
    ]);
    assert.equal(own.changed, true);
    assert.deepEqual(own.lost, []);

    const edited = merge(known, mine, docOf([para('p1', 'X by somebody else')]), { echo });
    assert.deepEqual(blocksOf(edited.merged), [
        ['p1', 'X by somebody else'],
        ['n1', 'new since'],
    ]);
    assert.deepEqual(losses(edited), [{ id: 'p1', reason: 'both', anchors: ['p1'] }]);
});

test('spine: only I reordered keeps my order; only they reordered keeps theirs; both reordered is theirs, and nothing is lost', () => {
    const base = docOf([para('p1', '1'), para('p2', '2'), para('p3', '3')]);
    const mineOrder = docOf([para('p3', '3'), para('p1', '1'), para('p2', '2')]);
    const theirsOrder = docOf([para('p2', '2'), para('p3', '3'), para('p1', '1')]);

    const onlyMe = merge(base, mineOrder, docOf([para('p1', '1'), para('p2', '2 A'), para('p3', '3')]));
    assert.deepEqual(blocksOf(onlyMe.merged), [
        ['p3', '3'],
        ['p1', '1'],
        ['p2', '2 A'],
    ]);
    assert.equal(onlyMe.changed, true);

    const onlyThem = merge(base, docOf([para('p1', '1 B'), para('p2', '2'), para('p3', '3')]), theirsOrder);
    assert.deepEqual(blocksOf(onlyThem.merged), [
        ['p2', '2'],
        ['p3', '3'],
        ['p1', '1 B'],
    ]);

    const both = merge(base, mineOrder, theirsOrder);
    assert.deepEqual(idsOf(both.merged), ['p2', 'p3', 'p1']);
    assert.equal(both.changed, false);
    assert.deepEqual(both.lost, []);

    // Their new block, when my order is the spine, follows its nearest preceding block of theirs that is on the page.
    const theirInsert = merge(base, mineOrder, docOf([para('p1', '1'), para('q1', 'theirs'), para('p2', '2'), para('p3', '3')]));
    assert.deepEqual(idsOf(theirInsert.merged), ['p3', 'p1', 'q1', 'p2']);
});

// ───────────────────────────────────────────────────────── the result, and the impossible

test('theirs is theirs as this schema serialises it, and merged round-trips through the schema', () => {
    const theirs = docOf(
        [{ type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: 'x' }] }, sectionBreak('s1', [])],
        { vars: [] }
    );

    const result = merge(docOf([para('p1', 'x')]), docOf([para('p1', 'x')]), theirs);

    assert.equal(result.theirs, JSON.stringify(schema.nodeFromJSON(levelEmpty(theirs)).toJSON()));
    assert.match(result.theirs, /"align":null/);
    assert.deepEqual(schema.nodeFromJSON(result.merged).toJSON(), result.merged);
    assert.equal(result.changed, false);
});

test('impossible: an unknown node, a missing top-level id, a shared id, a bad echo and an empty document all give null', () => {
    const base = docOf([para('p1', 'x'), para('p2', 'y')]);

    assert.equal(merge(base, base, docOf([{ type: 'marquee' }])), null);
    assert.equal(merge(base, base, docOf([para(null, 'no id')])), null);
    assert.equal(merge(base, base, docOf([para('p1', 'x'), para('p1', 'again')])), null);
    assert.equal(merge(base, base, base, { echo: docOf([{ type: 'marquee' }]) }), null);
    assert.equal(merge(base, docOf([]), base), null);
    // Two deletions that leave nothing are NOT impossible: the next case.
});

test('both deleted what the other kept: the result is one empty paragraph under an id of mine, not an impossible merge', () => {
    const result = merge(docOf([para('p1', 'x'), para('p2', 'y')]), docOf([para('p1', 'x')]), docOf([para('p2', 'y')]));

    assert.notEqual(result, null);
    assert.deepEqual(blocksOf(result.merged), [['p1', '']]);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);
    assert.equal(result.overwrites, false);
});

test('the text of a lost block is cut to 80 characters, and a block without text is named by its kind', () => {
    const long = 'a'.repeat(100);
    const result = merge(
        docOf([para('p1', 'x'), sectionBreak('s1', {})]),
        docOf([para('p1', long), sectionBreak('s1', { orientation: 'landscape' })]),
        docOf([para('p1', 'y'), sectionBreak('s1', { size: 'A5' })])
    );

    assert.deepEqual(
        result.lost.map((loss) => [loss.id, loss.type, loss.text]),
        [
            ['p1', 'paragraph', `${'a'.repeat(80)}…`],
            ['s1', 'sectionBreak', '[section break]'],
        ]
    );
    assert.equal(excerpt(schema.nodeFromJSON(para('p1', 'short'))), 'short');
    assert.equal(excerpt(schema.nodeFromJSON(para('p1', 'a'.repeat(80)))), 'a'.repeat(80), 'exactly 80 is not cut');
    // A character outside the BMP that straddles the cut is kept whole or left out whole, never halved.
    const straddling = excerpt(schema.nodeFromJSON(para('p1', `${'a'.repeat(79)}\u{1F600}tail`)));
    assert.equal(straddling, `${'a'.repeat(79)}\u{1F600}…`);
    assert.ok(straddling.isWellFormed(), 'no lone surrogate in the notice');
});

test('blockWord names every block type the editor has, and anything else is a block', () => {
    assert.deepEqual(
        [
            'paragraph', 'heading', 'bulletList', 'orderedList', 'taskList', 'blockquote', 'codeBlock', 'horizontalRule',
            'image', 'figure', 'table', 'toc', 'pageBreak', 'sectionBreak', 'callout', 'columns', 'doc',
        ].map(blockWord),
        [
            'paragraph', 'heading', 'list', 'list', 'task list', 'quote', 'code block', 'rule',
            'image', 'figure', 'table', 'contents list', 'page break', 'section break', 'callout', 'columns', 'block',
        ]
    );
});

test('isEmptyBlock is content.size, never textContent: a paragraph holding only a variable chip is not empty', () => {
    assert.equal(isEmptyBlock(schema.nodeFromJSON(para('p1'))), true);
    assert.equal(isEmptyBlock(schema.nodeFromJSON({ type: 'heading', attrs: { id: 'h1' } })), true);
    assert.equal(isEmptyBlock(schema.nodeFromJSON(para('p1', variable('client')))), false);
    assert.equal(isEmptyBlock(schema.nodeFromJSON(toc('t1'))), false, 'only a paragraph or a heading can be empty padding');
});

test('levelEmpty and levelDerived copy, never mutate, and level only what they are for', () => {
    const json = docOf([toc('t1', [{ id: 'h1' }]), para('p1', ref('h1', 'Label')), sectionBreak('s1', [])], { vars: [] });

    const empty = levelEmpty(json);
    assert.deepEqual(empty.attrs.vars, {});
    assert.deepEqual(empty.content[2].attrs.setup, {});
    assert.deepEqual(empty.content[0].attrs.entries, [{ id: 'h1' }], 'stamps are left alone');

    const derived = levelDerived(json);
    assert.deepEqual(derived.content[0].attrs.entries, []);
    assert.equal(derived.content[1].content[0].attrs.label, null);
    assert.deepEqual(derived.attrs.vars, [], 'empties are left alone');

    assert.deepEqual(json.attrs.vars, []);
    assert.deepEqual(json.content[0].attrs.entries, [{ id: 'h1' }]);
    assert.equal(json.content[1].content[0].attrs.label, 'Label');
    assert.deepEqual(DERIVED, { toc: ['entries'], crossRef: ['label'] });
});

test('appendLost appends and never replaces; the only entry skipped is an exact duplicate', () => {
    const loss = (text) => ({ id: 'p5', type: 'paragraph', reason: 'both', json: para('p5', text), anchors: ['p5'], text });
    const first = lostEntry(loss('Hello B1'), { source: 'merge', version: 2, n: 0, sentence: 'S' });
    const second = lostEntry(loss('Hello B2'), { source: 'merge', version: 3, n: 0, sentence: 'S' });
    const again = lostEntry(loss('Hello B1'), { source: 'merge', version: 4, n: 0, sentence: 'S' });

    const list = appendLost(appendLost([], [first]), [second, again]);

    assert.deepEqual(
        list.map((entry) => [entry.key, entry.excerpt]),
        [
            ['p5:2:0', 'Hello B1'],
            ['p5:3:0', 'Hello B2'],
        ]
    );
    assert.deepEqual(first, {
        key: 'p5:2:0',
        id: 'p5',
        type: 'paragraph',
        reason: 'both',
        source: 'merge',
        json: para('p5', 'Hello B1'),
        anchors: ['p5'],
        version: 2,
        sentence: 'S',
        excerpt: 'Hello B1',
    });
    assert.deepEqual(appendLost([], []), []);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/sync.merge.test.js`
Expected: FAIL before any case runs, with `Error [ERR_MODULE_NOT_FOUND]: Cannot find module '.../resources/js/editor/sync/merge.js'`.

- [ ] **Step 3: Create `merge.js`**

Create `resources/js/editor/sync/merge.js`:

```js
/**
 * The paragraph-level merge: three documents in, one document out.
 *
 * `base` is the last server document this tab held (the document of the
 * version its typing is based on), `mine` is what the editor holds now and
 * `theirs` is the newer server document that arrived. Every top-level block
 * is matched by the id the schema already gives it, and decided on its own
 * (section 4.3 of the Phase 2 spec):
 *
 *   - a block only one side changed takes that side's version;
 *   - a block both sides changed takes THEIRS, and mine is offered back
 *     (owner decision D1: the later save loses that block only);
 *   - a block they deleted while I typed in it goes, and my words are
 *     offered back with the ids that preceded it, so Put it back can return
 *     it to where it was (D2);
 *   - a block I deleted that they edited goes too, and the result says so
 *     (`overwrites`), so the save that carries it keeps their version in
 *     the history first. A draft merge turns that rule off (`deletionsWin`
 *     false): a days-old draft's deletion does not beat a change made since.
 *
 * Nothing is compared as JSON. Each input is parsed twice through the
 * schema: a COMPARE copy with the server-stamped attributes blanked
 * (`toc.entries`, `crossRef.label`) and `[]` levelled to `{}` (a section
 * break's `setup`, the document's `vars`), compared with `Node.eq()`, so
 * `align: null` against a missing `align` and a re-stamped contents list
 * are not changes; and an OUTPUT copy with the stamps kept, which is what
 * goes on screen. The merged document is built from output nodes and keeps
 * every block's own id, so it passes the server's "Duplicate block id"
 * check by construction.
 *
 * `echo` is the document of a save this tab sent whose answer was lost
 * (section 5.6). Wherever theirs agrees with it, that block's base is
 * theirs: the change is this tab's own stored save, not somebody else's,
 * so typing on top of it is "mine changed" and kept, not a D1.
 *
 * The order of the result is theirs (the spine), unless only I moved
 * blocks, in which case it is mine. A block one side inserted is anchored
 * after the nearest preceding block of its own document that is on the
 * page; with no predecessor at all it goes first, and with every
 * predecessor gone it goes last (a typist's new paragraph lands after an
 * imported document, not before its title).
 *
 * TipTap's TrailingNode gives this editor an empty paragraph after a
 * document that does not end in one; that paragraph is this editor's own.
 * When mine ends in such a paragraph that theirs lacks, it is carried into
 * BOTH the merged document and `theirs`, so a merge in which nothing of mine
 * survived still leaves `confirmed` equal to what the editor serialises.
 *
 * Dependency-free: `tests/js/sync.merge.test.js` runs it under `node --test`
 * with prosemirror-model's Schema. It imports nothing, not even derived.js:
 * the DERIVED table is duplicated here and `tests/js/derived.test.js`
 * asserts the two are identical.
 */

/** The attributes the server stamps into a stored document (derived.js has the same table). */
export const DERIVED = {
    toc: ['entries'],
    crossRef: ['label'],
};

/** What a blanked stamp reads as. */
const DERIVED_BLANK = { entries: [], label: null };

/** How many characters of a lost block the notice shows. */
export const EXCERPT_LENGTH = 80;

/** How many preceding ids a lost block remembers, nearest first. */
export const ANCHOR_LIMIT = 5;

/** The word the notice uses for each block type (section 6.2). */
const WORDS = {
    paragraph: 'paragraph',
    heading: 'heading',
    bulletList: 'list',
    orderedList: 'list',
    taskList: 'task list',
    blockquote: 'quote',
    codeBlock: 'code block',
    horizontalRule: 'rule',
    image: 'image',
    figure: 'figure',
    table: 'table',
    toc: 'contents list',
    pageBreak: 'page break',
    sectionBreak: 'section break',
    callout: 'callout',
    columns: 'columns',
};

/**
 * @param {string} type a node type name
 * @returns {string}
 */
export function blockWord(type) {
    return WORDS[type] ?? 'block';
}

const isEmptyValue = (value) => value !== null && typeof value === 'object' && Object.keys(value).length === 0;
const copy = (json) => JSON.parse(JSON.stringify(json));

/**
 * `[]` to `{}` where PHP sends an empty list for the editor's empty object:
 * a section break's `setup` and the document's `vars`. A deep copy.
 *
 * @param {object} json a Dot.Doc document
 * @returns {object}
 */
export function levelEmpty(json) {
    const out = copy(json);
    if (out && out.attrs && isEmptyValue(out.attrs.vars)) {
        out.attrs.vars = {};
    }
    const walk = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        if (node.type === 'sectionBreak' && node.attrs && isEmptyValue(node.attrs.setup)) {
            node.attrs.setup = {};
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(walk);
        }
    };
    walk(out);

    return out;
}

/**
 * Blank the server-stamped attributes (DERIVED), so a document the server
 * re-stamped compares equal to the editor's copy. A deep copy.
 *
 * @param {object} json a Dot.Doc document
 * @returns {object}
 */
export function levelDerived(json) {
    const out = copy(json);
    const walk = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        const derived = DERIVED[node.type];
        if (derived && node.attrs) {
            derived.forEach((key) => {
                node.attrs[key] = DERIVED_BLANK[key];
            });
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(walk);
        }
    };
    walk(out);

    return out;
}

/**
 * Whether a block is empty padding: a paragraph or heading with nothing in
 * it. `content.size`, never `textContent`: a paragraph holding only a
 * cross-reference or a variable chip has no text and is not empty.
 *
 * @param {import('prosemirror-model').Node} node
 * @returns {boolean}
 */
export function isEmptyBlock(node) {
    return (node.type.name === 'paragraph' || node.type.name === 'heading') && node.content.size === 0;
}

/**
 * What the notice shows of a lost block.
 *
 * @param {import('prosemirror-model').Node} node
 * @returns {string}
 */
export function excerpt(node) {
    const text = node.textContent;
    if (text === '') {
        return `[${blockWord(node.type.name)}]`;
    }

    // By code point, never by UTF-16 unit: a cut inside a surrogate pair leaves a broken character in the notice.
    const characters = Array.from(text);

    return characters.length > EXCERPT_LENGTH ? `${characters.slice(0, EXCERPT_LENGTH).join('')}…` : text;
}

/**
 * One entry of the lost row (section 6.1), from one loss of a merge.
 *
 * @param {{id: string, type: string, reason: 'both'|'deleted', json: object, anchors: string[], text: string}} loss
 * @param {{source: 'merge'|'draft'|'suggestion'|'parked', version: number, n: number, sentence: string}} context
 */
export function lostEntry(loss, { source, version, n, sentence }) {
    return {
        key: `${loss.id}:${version}:${n}`,
        id: loss.id,
        type: loss.type,
        reason: loss.reason,
        source,
        json: loss.json,
        anchors: loss.anchors,
        version,
        sentence,
        excerpt: loss.text,
    };
}

/**
 * The lost list with `entries` appended. Append, never replace: a paragraph
 * lost twice in a row (both versions of a D1 ping-pong) is two entries. The
 * only entry skipped is an exact duplicate, the same id with identical JSON.
 *
 * @param {object[]} list
 * @param {object[]} entries
 * @returns {object[]} a new array
 */
export function appendLost(list, entries) {
    const out = [...list];
    entries.forEach((entry) => {
        const json = JSON.stringify(entry.json);
        if (out.some((held) => held.id === entry.id && JSON.stringify(held.json) === json)) {
            return;
        }
        out.push(entry);
    });

    return out;
}

/**
 * One input, parsed twice: `doc` is the output copy, `blocks` its top-level
 * children with their compare twins. Null when a top-level block has no id
 * or two share one; a throw from the schema propagates to the caller.
 */
function read(schema, json) {
    const out = schema.nodeFromJSON(levelEmpty(json));
    out.check();
    const cmp = schema.nodeFromJSON(levelDerived(levelEmpty(json)));
    const blocks = [];
    const byId = new Map();
    for (let index = 0; index < out.childCount; index += 1) {
        const id = out.child(index).attrs.id;
        if (id === null || id === undefined || byId.has(id)) {
            return null;
        }
        const block = { id, cmp: cmp.child(index), out: out.child(index), index };
        blocks.push(block);
        byId.set(id, block);
    }

    return { doc: out, blocks, byId };
}

/** Whether the blocks common to x and y are in a different order in x than in y. */
function orderChanged(x, y) {
    const xs = x.blocks.filter((block) => y.byId.has(block.id)).map((block) => block.id);
    const ys = y.blocks.filter((block) => x.byId.has(block.id)).map((block) => block.id);

    return xs.length !== ys.length || xs.some((id, index) => id !== ys[index]);
}

const START = Symbol('start');
const END = Symbol('end');

/**
 * @param {import('prosemirror-model').Schema} schema
 * @param {{base: object, mine: object, theirs: object, echo?: object|null}} documents
 * @param {{deletionsWin?: boolean}} options
 * @returns {{merged: object, theirs: string, changed: boolean, lost: object[], overwrites: boolean}|null}
 *          null when the merge cannot be done (the caller shows the conflict notice)
 */
export function mergeDocuments(schema, { base, mine, theirs, echo = null }, { deletionsWin = true } = {}) {
    let B;
    let M;
    let T;
    let E = null;
    try {
        B = read(schema, base);
        M = read(schema, mine);
        T = read(schema, theirs);
        if (echo !== null && echo !== undefined) {
            E = read(schema, echo);
        }
    } catch (_) {
        return null;
    }
    if (B === null || M === null || T === null || (echo !== null && echo !== undefined && E === null)) {
        return null;
    }

    // 4.2 The base of a block is theirs wherever theirs agrees with the echo.
    const baseFor = (id) => {
        if (E !== null) {
            const t = T.byId.get(id);
            const e = E.byId.get(id);
            if ((!t && !e) || (t && e && t.cmp.eq(e.cmp))) {
                return t ?? null;
            }
        }

        return B.byId.get(id) ?? null;
    };

    // 4.4 This editor's own trailing paragraph.
    const skip = new Set();
    let theirsOut = T.doc;
    let carried = null;
    const mineLast = M.blocks[M.blocks.length - 1];
    const theirsLast = T.blocks[T.blocks.length - 1];
    if (
        mineLast &&
        mineLast.cmp.type.name === 'paragraph' &&
        mineLast.cmp.content.size === 0 &&
        !T.byId.has(mineLast.id) &&
        theirsLast
    ) {
        if (theirsLast.cmp.type.name !== 'paragraph') {
            carried = mineLast;
            skip.add(mineLast.id);
            theirsOut = T.doc.copy(T.doc.content.addToEnd(mineLast.out));
        } else if (theirsLast.cmp.content.size === 0) {
            skip.add(mineLast.id);
        }
    }

    // 4.3 One decision per block, over the union of the three id sets.
    const ids = [];
    const seen = new Set();
    [B, M, T].forEach((document) =>
        document.blocks.forEach((block) => {
            if (!seen.has(block.id) && !skip.has(block.id)) {
                seen.add(block.id);
                ids.push(block.id);
            }
        })
    );

    const emit = new Map();
    const lost = [];
    let overwrites = false;
    const eq = (x, y) => x.cmp.eq(y.cmp);
    const precedingIds = (block) =>
        M.blocks
            .slice(Math.max(0, block.index - ANCHOR_LIMIT), block.index)
            .map((candidate) => candidate.id)
            .reverse();
    const lose = (block, reason, anchors) =>
        lost.push({
            id: block.id,
            type: block.out.type.name,
            reason,
            // A plain copy: Node.toJSON() builds attrs with Object.create(null),
            // and the entry goes into IndexedDB, Alpine and strict deepEqual.
            json: copy(block.out.toJSON()),
            anchors,
            text: excerpt(block.out),
        });

    ids.forEach((id) => {
        const b = baseFor(id);
        const m = M.byId.get(id) ?? null;
        const t = T.byId.get(id) ?? null;

        if (b && m && t) {
            if (eq(m, b)) {
                emit.set(id, t.out); // a1 unchanged, a2 theirs changed
            } else if (eq(t, b)) {
                emit.set(id, m.out); // a3 mine changed
            } else if (eq(m, t)) {
                emit.set(id, t.out); // a4 the same change
            } else {
                emit.set(id, t.out); // a5 both changed: D1
                lose(m, 'both', [id]);
            }

            return;
        }

        if (b && m && !t) {
            // b1 they deleted, I did not touch it; b2 they deleted while I typed: D2.
            if (!eq(m, b) && !isEmptyBlock(m.cmp)) {
                lose(m, 'deleted', precedingIds(m));
            }

            return;
        }

        if (b && !m && t) {
            // c1 I deleted, they did not touch it; c2 I deleted, they edited.
            if (eq(t, b)) {
                return;
            }
            if (deletionsWin) {
                overwrites = true;
            } else {
                emit.set(id, t.out);
            }

            return;
        }

        if (!b && m && !t) {
            emit.set(id, m.out); // d mine inserted

            return;
        }

        if (!b && !m && t) {
            emit.set(id, t.out); // e theirs inserted

            return;
        }

        if (!b && m && t) {
            emit.set(id, t.out); // f both inserted one id
            if (!eq(m, t)) {
                lose(m, 'both', [id]);
            }
        }
        // g both deleted: nothing.
    });

    // 4.5 The spine and the anchors.
    const isOut = (id) => emit.has(id);
    const spine = orderChanged(M, B) && !orderChanged(T, B) ? M : T;
    const other = spine === M ? T : M;
    const spineIds = new Set(spine.blocks.map((block) => block.id));
    const anchorOf = new Map();
    const groups = new Map();
    other.blocks.forEach((block, index) => {
        if (spineIds.has(block.id) || !isOut(block.id)) {
            return;
        }
        let anchor = index === 0 ? START : END;
        for (let before = index - 1; before >= 0; before -= 1) {
            const previous = other.blocks[before];
            if (!isOut(previous.id)) {
                continue;
            }
            anchor = spineIds.has(previous.id) ? previous.id : anchorOf.get(previous.id);
            break;
        }
        anchorOf.set(block.id, anchor);
        if (!groups.has(anchor)) {
            groups.set(anchor, []);
        }
        groups.get(anchor).push(block.id);
    });

    const output = [];
    const push = (id) => output.push(emit.get(id));
    (groups.get(START) ?? []).forEach(push);
    spine.blocks.forEach((block) => {
        if (!isOut(block.id)) {
            return;
        }
        push(block.id);
        (groups.get(block.id) ?? []).forEach(push);
    });
    (groups.get(END) ?? []).forEach(push);
    if (carried !== null) {
        output.push(carried.out);
    }

    // Nothing left: each side deleted what the other kept. The document is
    // `block+`, so the result is one empty paragraph, under the id of mine's
    // last block (every id of the union is gone from the output, so none is
    // duplicated). Without it the merge was impossible and two deletions,
    // in which nothing was lost, ended in the notice.
    if (output.length === 0 && schema.nodes.paragraph && M.blocks.length > 0) {
        output.push(schema.nodes.paragraph.create({ id: M.blocks[M.blocks.length - 1].id }));
    }

    // 4.6 Document attributes, per key: mine where mine changed it, else theirs.
    const same = (x, y) => JSON.stringify(x ?? null) === JSON.stringify(y ?? null);
    const keys = [...new Set([...Object.keys(T.doc.attrs), ...Object.keys(M.doc.attrs)])];
    const attrs = {};
    keys.forEach((key) => {
        attrs[key] = same(M.doc.attrs[key], B.doc.attrs[key]) ? T.doc.attrs[key] : M.doc.attrs[key];
    });
    const attrsDiffer = keys.some((key) => !same(attrs[key], T.doc.attrs[key]));

    let merged;
    try {
        merged = schema.topNodeType.create(attrs, output);
        merged.check();
    } catch (_) {
        return null;
    }

    return {
        merged: merged.toJSON(),
        theirs: JSON.stringify(theirsOut.toJSON()),
        changed: !merged.content.eq(theirsOut.content) || attrsDiffer,
        lost,
        overwrites,
    };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/sync.merge.test.js`
Expected: PASS, 26 tests, 0 failures. If "a5 (D1)" fails with `attrs: [Object: null prototype]` in the diff, `lose()` is handing out `Node.toJSON()` itself instead of `copy()` of it. If "d: chained insertions ..." fails on the orphan case with `n1` before `h1`, the END rule is not reached: check that `anchor` starts as `END` for every block that has a predecessor and as `START` only for `index === 0`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/editor/sync/merge.js tests/js/sync.merge.test.js
git commit -m "feat(editor): the paragraph-level merge, sync/merge.js

mergeDocuments() merges three Dot.Doc documents block by block, matched
by block id: a block only one side changed takes that side, a block both
changed takes theirs and offers mine back (D1), a block they deleted
while I typed in it goes and my words are offered back with their place
(D2), and a block I deleted that they edited goes with the save marked as
an overwrite. Every comparison is Node.eq() on a levelled copy (stamps
blanked, [] levelled to {}), the result keeps every block's own id, the
order is theirs unless only I moved blocks, insertions are anchored
after their nearest surviving predecessor, and this editor's trailing
paragraph is carried into both the merged document and theirs. An echo
(a save whose answer was lost) makes the tab's own stored change its
base. Pure and dependency-free; tested under node --test.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 2: The merge fixtures, and `DERIVED` shared with `derived.js`

**Files:**
- Modify: `resources/js/editor/derived.js` (one word: `DERIVED` becomes an export)
- Modify: `tests/js/derived.test.js` (one import, one test)
- Create: `tests/fixtures/merge/*.json` (21 files)
- Modify: `tests/js/sync.merge.test.js` (the fixture iterator)

**Interfaces:**
- Consumes: `mergeDocuments()` and `DERIVED` from Task 1.
- Produces: `tests/fixtures/merge/<case>.json`, each `{name, base, mine, theirs, echo?, options?, expected: {blocks: [[id, text]...], changed, lost: [{id, reason, anchors}], overwrites}}`, one per row of section 4.3 and per ordering case of 4.5. `editor-form.json` and `stored-form.json` (Tasks 4 and 13) live in the same directory and are skipped by the iterator.
- Produces: `DERIVED` exported from `resources/js/editor/derived.js`.

- [ ] **Step 1: Write the failing test for the shared table**

In `tests/js/derived.test.js`, replace

```js
import { documentsDiffer, stripDerived } from '../../resources/js/editor/derived.js';
```

with

```js
import { DERIVED, documentsDiffer, stripDerived } from '../../resources/js/editor/derived.js';
import { DERIVED as MERGE_DERIVED } from '../../resources/js/editor/sync/merge.js';
```

and append at the end of the file:

```js

test('the merge module levels exactly the attributes this module strips: the two tables are one', () => {
    // sync/merge.js imports nothing from outside sync/, so it carries its
    // own copy of the table. This is what keeps the copy honest.
    assert.deepEqual(MERGE_DERIVED, DERIVED);
    assert.deepEqual(DERIVED, { toc: ['entries'], crossRef: ['label'] });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/derived.test.js`
Expected: FAIL before any case runs, with `SyntaxError: The requested module '../../resources/js/editor/derived.js' does not provide an export named 'DERIVED'`.

- [ ] **Step 3: Export the table**

In `resources/js/editor/derived.js`, replace

```js
 * Dependency-free: `tests/js` runs it under `node --test`.
 */
const DERIVED = {
    toc: ['entries'],
    crossRef: ['label'],
};
```

with

```js
 * Dependency-free: `tests/js` runs it under `node --test`.
 *
 * Exported because sync/merge.js, which imports nothing from outside sync/,
 * carries a copy of this table, and tests/js/derived.test.js asserts the two
 * are identical.
 */
export const DERIVED = {
    toc: ['entries'],
    crossRef: ['label'],
};
```

Run: `node --test tests/js/derived.test.js`
Expected: PASS, 8 tests, 0 failures.

- [ ] **Step 4: Create the fixtures**

Create the directory `tests/fixtures/merge/` and the 21 files below, each with exactly this content. (A paragraph is `{"type":"paragraph","attrs":{"id":"<id>"},"content":[{"type":"text","text":"<text>"}]}`; an empty paragraph has no `content`.)

`tests/fixtures/merge/a1-unchanged.json`:

```json
{
  "name": "a1: a block nobody changed is theirs, and nothing counts as changed",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"World"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"World"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"World"}]}]},
  "expected": {"blocks": [["p1","Hello"],["p2","World"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/a2-theirs-changed.json`:

```json
{
  "name": "a2: a block only they changed takes their version, and nothing counts as changed",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello A"}]}]},
  "expected": {"blocks": [["p1","Hello A"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/a3-mine-changed.json`:

```json
{
  "name": "a3: a block only I changed keeps my version and has to be saved",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello B"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]}]},
  "expected": {"blocks": [["p1","Hello B"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/a4-same-change.json`:

```json
{
  "name": "a4: the same change on both sides is theirs, and nothing counts as changed",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello!"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello!"}]}]},
  "expected": {"blocks": [["p1","Hello!"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/a5-both-changed.json`:

```json
{
  "name": "a5 (D1): a block both changed is theirs, and mine is offered back anchored to it",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello B"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"Hello A"}]}]},
  "expected": {"blocks": [["p1","Hello A"]], "changed": false, "lost": [{"id":"p1","reason":"both","anchors":["p1"]}], "overwrites": false}
}
```

`tests/fixtures/merge/b1-they-deleted-untouched.json`:

```json
{
  "name": "b1: a block they deleted that I did not touch goes, and nothing counts as changed",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]}]},
  "expected": {"blocks": [["p1","x"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/b2-they-deleted-i-typed.json`:

```json
{
  "name": "b2 (D2): a block they deleted while I typed in it goes, and my words are offered back with their place",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"z"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y B"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"z"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"z"}]}]},
  "expected": {"blocks": [["p1","x"],["p3","z"]], "changed": false, "lost": [{"id":"p2","reason":"deleted","anchors":["p1"]}], "overwrites": false}
}
```

`tests/fixtures/merge/c1-i-deleted-untouched.json`:

```json
{
  "name": "c1: a block I deleted that they did not touch goes, my deletion has to be saved, nothing overwrites",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "expected": {"blocks": [["p1","x"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/c2-i-deleted-they-edited.json`:

```json
{
  "name": "c2: a block I deleted that they edited goes, and the save must carry overwrite",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y A"}]}]},
  "expected": {"blocks": [["p1","x"]], "changed": true, "lost": [], "overwrites": true}
}
```

`tests/fixtures/merge/c2-draft-deletions-do-not-win.json`:

```json
{
  "name": "c2 with deletionsWin false (the draft merge): their edit stands, nothing is lost, no flag",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y A"}]}]},
  "options": {"deletionsWin": false},
  "expected": {"blocks": [["p1","x"],["p2","y A"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/d-mine-inserted.json`:

```json
{
  "name": "d: a block I inserted is kept after its predecessor and has to be saved",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"n1"},"content":[{"type":"text","text":"new"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "expected": {"blocks": [["p1","1"],["n1","new"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/d-after-deleted-predecessor.json`:

```json
{
  "name": "d: a block I inserted after a predecessor they deleted anchors to the nearest surviving one",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"n1"},"content":[{"type":"text","text":"new"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "expected": {"blocks": [["p1","1"],["n1","new"],["p3","3"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/e-theirs-inserted.json`:

```json
{
  "name": "e: a block they inserted is taken, and nothing counts as changed",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"q1"},"content":[{"type":"text","text":"theirs"}]}]},
  "expected": {"blocks": [["p1","1"],["q1","theirs"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/f-both-inserted-equal.json`:

```json
{
  "name": "f: a block both pasted, identical, is one copy",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"q7"},"content":[{"type":"text","text":"pasted"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"q7"},"content":[{"type":"text","text":"pasted"}]}]},
  "expected": {"blocks": [["p1","1"],["q7","pasted"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/f-both-inserted-different.json`:

```json
{
  "name": "f: a block both pasted with different text is theirs, and mine is offered back",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"q7"},"content":[{"type":"text","text":"mine"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"q7"},"content":[{"type":"text","text":"theirs"}]}]},
  "expected": {"blocks": [["p1","1"],["q7","theirs"]], "changed": false, "lost": [{"id":"q7","reason":"both","anchors":["q7"]}], "overwrites": false}
}
```

`tests/fixtures/merge/g-both-deleted.json`:

```json
{
  "name": "g: a block both deleted goes, and nothing counts as changed",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"y"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"x"}]}]},
  "expected": {"blocks": [["p1","x"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/order-only-i-reordered.json`:

```json
{
  "name": "order: only I moved blocks, so my order is the spine and their edit lands in it",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]},
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2 A"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "expected": {"blocks": [["p3","3"],["p1","1"],["p2","2 A"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/order-only-they-reordered.json`:

```json
{
  "name": "order: only they moved blocks, so their order is the spine and my edit lands in it",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1 B"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]},
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "expected": {"blocks": [["p2","2"],["p3","3"],["p1","1 B"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/order-both-reordered.json`:

```json
{
  "name": "order: both moved blocks, so their order wins silently and nothing is lost",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]},
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]},
    {"type":"paragraph","attrs":{"id":"p3"},"content":[{"type":"text","text":"3"}]},
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]}]},
  "expected": {"blocks": [["p2","2"],["p3","3"],["p1","1"]], "changed": false, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/order-chained-insertions.json`:

```json
{
  "name": "order: chained insertions keep their order, and one with no predecessor goes first",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"n0"},"content":[{"type":"text","text":"top"}]},
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"n1"},"content":[{"type":"text","text":"a"}]},
    {"type":"paragraph","attrs":{"id":"n2"},"content":[{"type":"text","text":"b"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"1"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"2 A"}]}]},
  "expected": {"blocks": [["n0","top"],["p1","1"],["n1","a"],["n2","b"],["p2","2 A"]], "changed": true, "lost": [], "overwrites": false}
}
```

`tests/fixtures/merge/order-start-and-end.json`:

```json
{
  "name": "order: a typist's new paragraph whose predecessors an import replaced lands at the end, after the imported title",
  "base": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"old"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"old too"}]}]},
  "mine": {"type":"doc","content":[
    {"type":"paragraph","attrs":{"id":"p1"},"content":[{"type":"text","text":"old"}]},
    {"type":"paragraph","attrs":{"id":"p2"},"content":[{"type":"text","text":"old too"}]},
    {"type":"paragraph","attrs":{"id":"n1"},"content":[{"type":"text","text":"typed"}]}]},
  "theirs": {"type":"doc","content":[
    {"type":"heading","attrs":{"id":"h1","level":1},"content":[{"type":"text","text":"Imported title"}]},
    {"type":"paragraph","attrs":{"id":"q1"},"content":[{"type":"text","text":"Imported body"}]}]},
  "expected": {"blocks": [["h1","Imported title"],["q1","Imported body"],["n1","typed"]], "changed": true, "lost": [], "overwrites": false}
}
```

- [ ] **Step 5: Iterate the fixtures from the merge test**

In `tests/js/sync.merge.test.js`, replace

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema } from 'prosemirror-model';
```

with

```js
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

import { Schema } from 'prosemirror-model';
```

and append at the end of the file:

```js

// ───────────────────────────────────────────────────────── the fixtures: one per row of 4.3 and per ordering case

const FIXTURES = new URL('../fixtures/merge/', import.meta.url);
/** The real pair is captured by sync.merge.editor.test.js and MergeFixtureTest, and merged over the editor's own schema there. */
const REAL_PAIR = ['editor-form.json', 'stored-form.json'];
const fixtures = readdirSync(FIXTURES)
    .filter((name) => name.endsWith('.json') && !REAL_PAIR.includes(name))
    .sort();

test('there is a fixture for every row of the decision table and every ordering case', () => {
    assert.deepEqual(fixtures, [
        'a1-unchanged.json',
        'a2-theirs-changed.json',
        'a3-mine-changed.json',
        'a4-same-change.json',
        'a5-both-changed.json',
        'b1-they-deleted-untouched.json',
        'b2-they-deleted-i-typed.json',
        'c1-i-deleted-untouched.json',
        'c2-draft-deletions-do-not-win.json',
        'c2-i-deleted-they-edited.json',
        'd-after-deleted-predecessor.json',
        'd-mine-inserted.json',
        'e-theirs-inserted.json',
        'f-both-inserted-different.json',
        'f-both-inserted-equal.json',
        'g-both-deleted.json',
        'order-both-reordered.json',
        'order-chained-insertions.json',
        'order-only-i-reordered.json',
        'order-only-they-reordered.json',
        'order-start-and-end.json',
    ]);
});

for (const name of fixtures) {
    const fixture = JSON.parse(readFileSync(new URL(name, FIXTURES), 'utf8'));

    test(`${name}: ${fixture.name}`, () => {
        const result = mergeDocuments(
            schema,
            { base: fixture.base, mine: fixture.mine, theirs: fixture.theirs, echo: fixture.echo ?? null },
            fixture.options ?? {}
        );

        assert.notEqual(result, null, 'the merge is possible');
        assert.deepEqual(blocksOf(result.merged), fixture.expected.blocks);
        assert.equal(result.changed, fixture.expected.changed);
        assert.deepEqual(losses(result), fixture.expected.lost);
        assert.equal(result.overwrites, fixture.expected.overwrites);
    });
}
```

Run: `node --test tests/js/sync.merge.test.js`
Expected: PASS, 48 tests (26 from Task 1, the listing test, 21 fixtures), 0 failures.

Run: `npm test`
Expected: every JavaScript test passes: 465 tests (442 after Task 1, plus the listing test, 21 fixtures and the `derived.test.js` case).

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor/derived.js tests/js/derived.test.js tests/fixtures/merge tests/js/sync.merge.test.js
git commit -m "test(editor): one merge fixture per decision-table row and ordering case

tests/fixtures/merge/*.json drive sync.merge.test.js: one file per row of
the spec's decision table and per ordering case, each stating the three
documents and the expected blocks, changed flag, losses and overwrite
flag. derived.js exports DERIVED so derived.test.js can assert that the
merge module's copy of the table is identical.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 3: Per-block apply (`blockSteps`) and `insertionPoint`

**Files:**
- Modify: `resources/js/editor/sync/apply.js` (step 4 of `remoteTransaction()`; new exported `blockSteps()`)
- Modify: `resources/js/editor/sync/narrow.js` (new `insertionPoint()`)
- Modify: `tests/js/sync.apply.test.js`, `tests/js/sync.narrow.test.js`
- Modify: `tests/js/sync.history.test.js`, `tests/js/uniqueIds.test.js` (Step 5b)

**Interfaces:**
- Produces: `blockSteps(current, next, ReplaceStep) -> [{step, whole, used}] | null` from `apply.js` (section 5.10): edits in CURRENT coordinates, sorted by `from` descending (ties: the wider step first, so an insertion at a block boundary comes after the replace of the block that starts there); `whole` is the whole-block fallback of a narrowed textblock replace, else `null`; `used` is filled by the caller. `null` when a block has no id, two share one, or the common blocks are in a different order (a move).
- Produces: `remoteTransaction()` step 4 as section 5.10 describes: 4a the block steps, probed on a copy and applied only when every step applies (a narrowed step that fails is retried whole) AND the probe reproduces the target; 4b today's one-range `ReplaceStep`; 4c `tr.replaceWith()`; a throw there gives `null`. Steps 1, 2, 3 and 5 are unchanged.
- Produces: `MAX_BLOCK_STEPS = 500` exported from `apply.js` (dry run 2): when `blockSteps()` gives more edits than that, `applyBlockSteps()` returns false without probing, so a document-sized change (a restore or an import that shares few ids with a large document) takes the one-range apply of Phase 1. In the real editor 4,000 whole-block steps cost 1.9 s of dispatch against 0.5 s for the single range. `blockSteps()` itself does not cap.
- Produces: `insertionPoint(doc, anchors) -> number` from `narrow.js`: the position after the first top-level child whose `attrs.id` is in `anchors`, in anchor order; else `doc.content.size` when `anchors` is non-empty, else `0`.
- Decision: the existing apply test for a changed box and a deleted paragraph now expects two steps (see Owner decisions).

- [ ] **Step 1: Write the failing apply tests**

In `tests/js/sync.apply.test.js`, replace

```js
import { Schema, Slice } from 'prosemirror-model';
import { EditorState } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { remoteTransaction } from '../../resources/js/editor/sync/apply.js';
```

with

```js
import { Schema, Slice } from 'prosemirror-model';
import { EditorState, TextSelection } from 'prosemirror-state';
import { ReplaceStep, StepResult } from 'prosemirror-transform';

import { MAX_BLOCK_STEPS, blockSteps, remoteTransaction } from '../../resources/js/editor/sync/apply.js';
```

Replace the whole test

```js
test('a change inside an isolating node plus a change after it is one step that reproduces the target', () => {
    // tr.replace() would fit this slice by nesting a second copy of the box
    // inside the first, without throwing. A ReplaceStep applies it as is.
    const held = doc([box('b1', para('p1', 'In the box')), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([box('b1', para('p1', 'In the box, edited')), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.steps[0].from > 1, 'the step is the narrowed range, not the whole document');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});
```

with

```js
test('a change inside an isolating node plus a deletion after it are two block steps that reproduce the target', () => {
    // The box is not a textblock, so it is replaced whole: a closed slice at
    // a top-level boundary, which never needs the fitter. The deleted
    // paragraph is a second step. tr.replace() would have fitted ONE open
    // slice by nesting a second copy of the box inside the first.
    const held = doc([box('b1', para('p1', 'In the box')), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([box('b1', para('p1', 'In the box, edited')), para('p2', 'Two')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace', 'replace']);
    assert.equal(tr.steps[0].from, startOf(state.doc, 'p3'), 'the deletion first: steps run from the end of the document backwards');
    assert.equal(tr.steps[1].from, 0);
    assert.equal(tr.steps[1].to, state.doc.child(0).nodeSize, 'the box, whole');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});
```

Then append at the end of the file:

```js

// ───────────────────────────────────────────────────────── per-block steps (Phase 2)

test('two separate changes are two steps, and a caret between them stays put', () => {
    const held = doc([para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third')]);
    const arriving = doc([para('p1', 'First, longer'), para('p2', 'Second'), para('p3', 'Third, longer')]);
    const state = stateOf(held);
    // The caret after "Sec" in the middle paragraph.
    const caret = startOf(state.doc, 'p2') + 1 + 3;
    const typing = state.apply(state.tr.setSelection(TextSelection.create(state.doc, caret)));

    const tr = remoteTransaction(typing, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace', 'replace']);
    assert.ok(tr.steps[0].from > tr.steps[1].to, 'applied from the end of the document backwards');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
    assert.equal(tr.doc.textBetween(tr.selection.from - 3, tr.selection.from), 'Sec');
    assert.equal(tr.doc.textBetween(tr.selection.from, tr.selection.from + 3), 'ond');
});

test('a change inside a textblock is narrowed to the characters that changed', () => {
    const held = doc([para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third')]);
    const arriving = doc([para('p1', 'First'), para('p2', 'Second, edited'), para('p3', 'Third')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    const p2 = startOf(state.doc, 'p2');
    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, p2 + 1 + 'Second'.length);
    assert.equal(tr.steps[0].to, p2 + 1 + 'Second'.length);
    assert.equal(tr.steps[0].slice.content.textBetween(0, tr.steps[0].slice.content.size), ', edited');
});

test('a changed block that is not a textblock is replaced whole', () => {
    const held = doc([box('b1', para('p1', 'In the box')), para('p2', 'Two')]);
    const arriving = doc([box('b1', para('p1', 'In the box, edited')), para('p2', 'Two')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.child(0).nodeSize);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('an inserted, a deleted and a changed block are three steps', () => {
    const held = doc([para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([para('p1', 'One!'), para('p3', 'Three'), para('p4', 'Four')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace', 'replace', 'replace']);
    assert.equal(tr.steps[0].from, state.doc.content.size, 'the insertion comes first: it is furthest down');
    assert.equal(tr.steps[1].from, startOf(state.doc, 'p2'));
    assert.equal(tr.steps[1].to, startOf(state.doc, 'p3'), 'the deletion, whole');
    assert.ok(tr.steps[2].from > 0 && tr.steps[2].to < startOf(state.doc, 'p2'), 'the change in p1, narrowed');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('[A] to [B] is one coalesced step, never an empty document', () => {
    const held = doc([para('p1', 'A')]);
    const arriving = doc([para('p2', 'B')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.content.size);
    assert.equal(tr.steps[0].slice.content.childCount, 1);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));

    // A block replaced in the middle: the deletion and the insertion at one point are one step too.
    const replaced = doc([para('p1', 'One'), para('n1', 'New'), para('p3', 'Three')]);
    const middle = remoteTransaction(stateOf(doc([para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three')])), replaced, ReplaceStep);
    assert.deepEqual(stepTypes(middle), ['replace']);
    assert.ok(middle.doc.eq(schema.nodeFromJSON(replaced)));
});

test('a move falls back to the one-range apply', () => {
    const held = doc([para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three')]);
    const arriving = doc([para('p2', 'Two'), para('p1', 'One'), para('p3', 'Three')]);
    const state = stateOf(held);

    assert.equal(blockSteps(state.doc, schema.nodeFromJSON(arriving), ReplaceStep), null);

    const tr = remoteTransaction(state, arriving, ReplaceStep);
    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    // findDiffEnd stops exactly where the moved pair ends, which is where p3 starts.
    assert.equal(tr.steps[0].to, startOf(state.doc, 'p3'), 'one range over the two moved blocks, not the whole document');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a narrowed step that fails is retried whole', () => {
    // Stands in for a narrowed slice the document refuses: a step that
    // starts inside a block fails, and the same change as a whole-block
    // step (which starts at a top-level boundary) applies.
    class FailsNarrow extends ReplaceStep {
        apply(docNode) {
            if (docNode.resolve(this.from).depth > 0) {
                return StepResult.fail('a narrowed step this document refuses');
            }

            return super.apply(docNode);
        }
    }
    const held = doc([para('p1', 'First'), para('p2', 'Second')]);
    const arriving = doc([para('p1', 'First, edited'), para('p2', 'Second')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, FailsNarrow);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.child(0).nodeSize, 'the whole first paragraph');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('blockSteps gives null for a block without an id or two blocks sharing one, and nothing for identical documents', () => {
    const okay = schema.nodeFromJSON(doc([para('p1', 'One')]));

    assert.equal(blockSteps(schema.nodeFromJSON(doc([para(null, 'One')])), okay, ReplaceStep), null);
    assert.equal(blockSteps(okay, schema.nodeFromJSON(doc([para('p1', 'One'), para('p1', 'Again')])), ReplaceStep), null);
    assert.deepEqual(blockSteps(okay, okay, ReplaceStep), []);
});

test('more than MAX_BLOCK_STEPS block steps fall back to the one-range apply: a document-sized change is one step', () => {
    const paragraphs = (count, text) => Array.from({ length: count }, (_, index) => para(`p${index}`, `${text} ${index}`));

    // At the bound: every paragraph changed, one step each.
    const atBound = remoteTransaction(stateOf(doc(paragraphs(MAX_BLOCK_STEPS, 'old'))), doc(paragraphs(MAX_BLOCK_STEPS, 'new')), ReplaceStep);
    assert.equal(atBound.steps.length, MAX_BLOCK_STEPS);
    assert.ok(atBound.doc.eq(schema.nodeFromJSON(doc(paragraphs(MAX_BLOCK_STEPS, 'new')))));

    // One more: the whole range, as Phase 1 applied it.
    const held = stateOf(doc(paragraphs(MAX_BLOCK_STEPS + 1, 'old')));
    const arriving = doc(paragraphs(MAX_BLOCK_STEPS + 1, 'new'));
    assert.equal(blockSteps(held.doc, schema.nodeFromJSON(arriving), ReplaceStep).length, MAX_BLOCK_STEPS + 1, 'blockSteps itself does not cap');

    const beyond = remoteTransaction(held, arriving, ReplaceStep);
    assert.deepEqual(stepTypes(beyond), ['replace']);
    assert.ok(beyond.doc.eq(schema.nodeFromJSON(arriving)));
});
```

(The existing test "a step that applies but does not reproduce the target falls back too" is the spec's "a probe that does not reproduce the target falls back": its `Wrong` step makes the probe differ from the target, so no block step is applied and the one-range apply, then the whole-document apply, run as before. Its expected step list `['attr', 'replace', 'replace']` is unchanged.)

- [ ] **Step 2: Write the failing narrow tests**

In `tests/js/sync.narrow.test.js`, replace

```js
import { diffRange } from '../../resources/js/editor/sync/narrow.js';
```

with

```js
import { diffRange, insertionPoint } from '../../resources/js/editor/sync/narrow.js';
```

and append at the end of the file:

```js

// ───────────────────────────────────────────────────────── where a lost block goes back (Phase 2)

test('insertionPoint: the first anchor that is on the page wins, and the block goes after it', () => {
    const page = doc(para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three'));

    assert.equal(insertionPoint(page, ['p2', 'p1']), page.child(0).nodeSize + page.child(1).nodeSize);
    assert.equal(insertionPoint(page, ['p1']), page.child(0).nodeSize);
});

test('insertionPoint: a later anchor is used when the first is gone', () => {
    const page = doc(para('p1', 'One'), para('p3', 'Three'));

    assert.equal(insertionPoint(page, ['p2', 'p1']), page.child(0).nodeSize);
    assert.equal(insertionPoint(page, ['p9', 'p3']), page.content.size);
});

test('insertionPoint: anchors that are all gone put the block at the end', () => {
    const page = doc(para('p1', 'One'), para('p2', 'Two'));

    assert.equal(insertionPoint(page, ['p7', 'p8']), page.content.size);
});

test('insertionPoint: no anchors at all put the block at the start', () => {
    const page = doc(para('p1', 'One'), para('p2', 'Two'));

    assert.equal(insertionPoint(page, []), 0);
});
```

- [ ] **Step 3: Run both test files to verify they fail**

Run: `node --test tests/js/sync.apply.test.js tests/js/sync.narrow.test.js`
Expected: both files FAIL before any case runs: `SyntaxError: The requested module '../../resources/js/editor/sync/apply.js' does not provide an export named 'blockSteps'` and `SyntaxError: The requested module '../../resources/js/editor/sync/narrow.js' does not provide an export named 'insertionPoint'`.

- [ ] **Step 4: Add `insertionPoint()` to `narrow.js`**

Append at the end of `resources/js/editor/sync/narrow.js`:

```js

/**
 * Where a lost block goes back on the page (Put it back, Phase 2).
 *
 * `anchors` are block ids, nearest first: for a block that was changed on
 * both sides (D1) the id of the block that won, so mine goes in directly
 * below it; for a block that was deleted while I typed in it (D2) up to
 * five ids that preceded it, so it goes back where it was even if its
 * nearest neighbour has gone too. The first anchor that is on the page
 * decides; with anchors but none of them on the page the block goes to
 * the END (its neighbourhood is gone: after everything, never before the
 * title); with no anchors at all (it had no predecessor) it goes to the
 * START.
 *
 * Pure: it only reads the document it is given.
 *
 * @param {import('prosemirror-model').Node} doc the document the editor holds
 * @param {string[]} anchors block ids, nearest first
 * @returns {number} a position between two top-level blocks
 */
export function insertionPoint(doc, anchors) {
    for (const anchor of anchors) {
        let found = null;
        doc.forEach((child, offset) => {
            if (found === null && child.attrs.id === anchor) {
                found = offset + child.nodeSize;
            }
        });
        if (found !== null) {
            return found;
        }
    }

    return anchors.length > 0 ? doc.content.size : 0;
}
```

- [ ] **Step 5: Add `blockSteps()` and the per-block step 4 to `apply.js`**

In `resources/js/editor/sync/apply.js`, replace the docblock item

```js
 *   4. The one range that still differs (see ./narrow.js) is replaced with a
 *      ReplaceStep, NOT tr.replace(): tr.replace() runs ProseMirror's fitter,
 *      which re-shapes an open slice around isolating nodes (table cells,
 *      figures, columns) and hands back a DIFFERENT document without
 *      throwing. The result is checked against the target; if the step
 *      throws or the result differs, everything is replaced instead:
 *      correct, just not gentle.
```

with

```js
 *   4. What still differs is changed BLOCK BY BLOCK (blockSteps(), below):
 *      a block absent from the arriving document is deleted, a changed
 *      textblock is replaced inside the block only (the characters that
 *      differ), any other changed block (a list, a table, columns, a
 *      callout, a figure, an atom, an attribute change) is replaced whole,
 *      and an inserted block is put in after its predecessor. A deletion
 *      and an insertion at one point are one replace, so the document
 *      never passes through an empty `doc`. The steps are probed on a copy
 *      first: a narrowed step that fails is retried whole, and if any step
 *      fails, the result is not the target, or there are more than
 *      MAX_BLOCK_STEPS steps (a restore or an import that shares few ids with
 *      the document: every step is applied twice and mapped through every
 *      plugin, which costs more than the one range), the one range that differs
 *      (see ./narrow.js) is replaced instead, as a ReplaceStep, NOT
 *      tr.replace(): tr.replace() runs ProseMirror's fitter, which
 *      re-shapes an open slice around isolating nodes and hands back a
 *      DIFFERENT document without throwing. If that too throws or differs,
 *      everything is replaced: correct, just not gentle. The caret survives
 *      because Transaction.addStep maps the selection through every step: a
 *      caret in a block no step touches maps straight through, a caret
 *      inside a narrowed replace moves only when it sits inside the changed
 *      characters, and a caret in a block replaced whole lands at the end
 *      of the replacement.
```

Then replace

```js
    // 4. Replace the one range that still differs.
    const range = diffRange(tr.doc, next);

    if (range !== null) {
        try {
            tr.step(new ReplaceStep(range.from, range.toA, next.slice(range.from, range.toB)));
            if (!tr.doc.content.eq(next.content)) {
                throw new Error('The narrowed replace did not reproduce the document');
            }
        } catch (_) {
            // The narrowed slice did not fit (a structural change the open
            // slice cannot express). Sized from tr.doc, not state.doc: the
            // transaction may already hold a step.
            try {
                tr.replaceWith(0, tr.doc.content.size, next.content);
            } catch (_error) {
                return null;
            }
        }
    }
```

with

```js
    // 4. Change what still differs: block by block (4a); failing that, the
    //    one range that differs (4b); failing that, everything (4c).
    //    applyBlockSteps() adds steps only when every one of them applies
    //    and the probe reproduces the target, so on false tr.doc is as it
    //    was and `range` still holds.
    const range = diffRange(tr.doc, next);

    if (range !== null && !applyBlockSteps(tr, next, ReplaceStep)) {
        try {
            tr.step(new ReplaceStep(range.from, range.toA, next.slice(range.from, range.toB)));
            if (!tr.doc.content.eq(next.content)) {
                throw new Error('The narrowed replace did not reproduce the document');
            }
        } catch (_) {
            // The narrowed slice did not fit (a structural change the open
            // slice cannot express). Sized from tr.doc, not state.doc: the
            // transaction may already hold a step.
            try {
                tr.replaceWith(0, tr.doc.content.size, next.content);
            } catch (_error) {
                return null;
            }
        }
    }
```

Then, directly after the closing brace of `remoteTransaction()` (the line `}` that follows `    return tr;`) and before the comment block that begins `/**` above `const HISTORY_KEY`, insert:

```js

/**
 * More block steps than this are not worth taking: a document-sized change
 * (a restore, an import) is applied as the one range of Phase 1 instead.
 */
export const MAX_BLOCK_STEPS = 500;

/**
 * The edits that turn `current` into `next`, one top-level block at a time,
 * in CURRENT coordinates, sorted so they apply from the end of the document
 * backwards (so no step shifts the positions of the steps still to come).
 *
 *   1. The top-level children of both, with their ids. A block without an
 *      id, or two blocks sharing one in one document: null (the caller
 *      falls back to the one-range apply).
 *   2. The common blocks in a different relative order (a move): null.
 *   3. In current coordinates: a block absent from `next` is deleted; a
 *      block whose content differs is replaced inside the block when both
 *      versions are textblocks with the same markup (the characters that
 *      differ, found by diffRange(); the whole block is kept as `whole`, the
 *      fallback should the narrowed step fail), and whole otherwise; a block
 *      absent from `current` is inserted after the current counterpart of
 *      the nearest preceding `next` block that has one, consecutive
 *      insertions at one point as one slice.
 *   4. A deletion that starts where an insertion starts, and that insertion,
 *      are one replace: `[A] -> [B]` is one step and the document never
 *      passes through an empty `doc`.
 *   5. Sorted by `from` descending; when two edits start at one position
 *      the wider goes first (the replace of the block that starts there,
 *      then the insertion in front of it).
 *
 * Every slice is cut from `next` at top-level boundaries (closed), or from
 * inside one textblock (inline content): neither needs the fitter.
 *
 * @param {import('prosemirror-model').Node} current the document the editor holds
 * @param {import('prosemirror-model').Node} next    the document it should hold
 * @param {typeof import('prosemirror-transform').ReplaceStep} ReplaceStep
 * @returns {{step: object, whole: object|null, used: object|null}[]|null}
 */
export function blockSteps(current, next, ReplaceStep) {
    const list = (docNode) => {
        const blocks = [];
        const seen = new Set();
        let ok = true;
        docNode.forEach((node, offset) => {
            const id = node.attrs.id;
            if (id === null || id === undefined || seen.has(id)) {
                ok = false;
            }
            seen.add(id);
            blocks.push({ id, node, from: offset, to: offset + node.nodeSize });
        });

        return ok ? blocks : null;
    };
    const a = list(current);
    const b = list(next);
    if (a === null || b === null) {
        return null;
    }
    const aById = new Map(a.map((block) => [block.id, block]));
    const bById = new Map(b.map((block) => [block.id, block]));

    // 2. A move is not a per-block edit.
    const commonA = a.filter((block) => bById.has(block.id)).map((block) => block.id);
    const commonB = b.filter((block) => aById.has(block.id)).map((block) => block.id);
    if (commonA.some((id, index) => id !== commonB[index])) {
        return null;
    }

    // 3. Deletions and replacements, in current coordinates.
    const edits = [];
    a.forEach((block) => {
        const other = bById.get(block.id);
        if (!other) {
            edits.push({ kind: 'delete', from: block.from, to: block.to, slice: next.slice(0, 0), whole: null });

            return;
        }
        if (block.node.eq(other.node)) {
            return;
        }
        const whole = { from: block.from, to: block.to, slice: next.slice(other.from, other.to) };
        if (block.node.isTextblock && other.node.isTextblock && block.node.sameMarkup(other.node)) {
            const range = diffRange(block.node, other.node);
            if (range !== null) {
                edits.push({
                    kind: 'replace',
                    from: block.from + 1 + range.from,
                    to: block.from + 1 + range.toA,
                    slice: other.node.slice(range.from, range.toB),
                    whole,
                });

                return;
            }
        }
        edits.push({ kind: 'replace', from: whole.from, to: whole.to, slice: whole.slice, whole: null });
    });

    // Insertions: after the current counterpart of the nearest preceding
    // `next` block that has one; a run of them at one point is one slice.
    let at = 0;
    let run = null;
    b.forEach((block) => {
        const counterpart = aById.get(block.id);
        if (counterpart) {
            at = counterpart.to;
            run = null;

            return;
        }
        if (run === null) {
            run = { kind: 'insert', from: at, to: at, first: block.from, last: block.to, slice: null, whole: null };
            edits.push(run);
        }
        run.last = block.to;
    });
    edits.forEach((edit) => {
        if (edit.kind === 'insert') {
            edit.slice = next.slice(edit.first, edit.last);
        }
    });

    // 4. Coalesce.
    edits
        .filter((edit) => edit.kind === 'insert')
        .forEach((insert) => {
            const deletion = edits.find((edit) => edit.kind === 'delete' && edit.from === insert.from);
            if (deletion) {
                deletion.kind = 'replace';
                deletion.slice = insert.slice;
                edits.splice(edits.indexOf(insert), 1);
            }
        });

    // 5. From the end backwards; the wider edit first at one position.
    edits.sort((x, y) => y.from - x.from || y.to - x.to);

    return edits.map((edit) => ({
        step: new ReplaceStep(edit.from, edit.to, edit.slice),
        whole: edit.whole ? new ReplaceStep(edit.whole.from, edit.whole.to, edit.whole.slice) : null,
        used: null,
    }));
}

/**
 * Step 4a of remoteTransaction(): the block steps, probed on a copy of the
 * document before any of them is added to the transaction. A narrowed step
 * that fails is retried whole; a step that still fails, or a probe that
 * does not reproduce the target, leaves the transaction untouched, and so
 * does a list of more than MAX_BLOCK_STEPS edits, which is not probed at all.
 *
 * @returns {boolean} whether the steps were added
 */
function applyBlockSteps(tr, next, ReplaceStep) {
    let edits = null;
    try {
        edits = blockSteps(tr.doc, next, ReplaceStep);
    } catch (_) {
        return false;
    }
    if (edits === null || edits.length > MAX_BLOCK_STEPS) {
        return false;
    }

    let probe = tr.doc;
    for (const edit of edits) {
        let result = edit.step.apply(probe);
        edit.used = edit.step;
        if (result.failed && edit.whole !== null) {
            result = edit.whole.apply(probe);
            edit.used = edit.whole;
        }
        if (result.failed) {
            return false;
        }
        probe = result.doc;
    }
    if (!probe.content.eq(next.content)) {
        return false;
    }

    edits.forEach((edit) => tr.step(edit.used));

    return true;
}
```

- [ ] **Step 5b: Keep the two historical replays on the apply they were seen with**

Two existing tests replay, on purpose, what happened in a browser when a newer document was applied as ONE range (Phase 1) and the undo history was kept across it: `tests/js/sync.history.test.js` ("the danger") and `tests/js/uniqueIds.test.js` ("the defect"). Their assertions describe the one-range mapping; with per-block steps that exact undo maps differently (it joins the two paragraphs instead of deleting the text), so the replays are pinned to the one-range step they were seen with, and the per-block path is covered by the TipTap cases that stay on `remoteTransaction()`.

In `tests/js/sync.history.test.js`, replace

```js
import { clearHistoryTransaction, remoteTransaction } from '../../resources/js/editor/sync/apply.js';
```

with

```js
import { clearHistoryTransaction, remoteTransaction } from '../../resources/js/editor/sync/apply.js';
import { diffRange } from '../../resources/js/editor/sync/narrow.js';
```

Replace

```js
/** The first of applyRemote()'s two dispatches: the newer document, outside the undo history. */
const remote = (state, json) =>
    remoteTransaction(state, json, ReplaceStep).setMeta('addToHistory', false).setMeta('preventUpdate', true);
```

with

```js
/**
 * The first of applyRemote()'s two dispatches, as Phase 1 built it: the
 * newer document as ONE replaced range, outside the undo history. Phase 2
 * applies per block (remoteTransaction(), which the TipTap cases below
 * dispatch); the danger shown with this one-range step is the reason the
 * history is emptied after either kind of apply, because a stored step
 * mapped through a coarse replacement lands on the text that arrived.
 */
const remote = (state, json) => {
    // The state's own schema: the TipTap cases dispatch this into an editor
    // whose schema is not the bare one above.
    const theirs = state.schema.nodeFromJSON(json);
    const range = diffRange(state.doc, theirs);

    return state.tr
        .step(new ReplaceStep(range.from, range.toA, theirs.slice(range.from, range.toB)))
        .setMeta('addToHistory', false)
        .setMeta('preventUpdate', true);
};
```

Replace

```js
function dispatchAsApplyRemoteDoes(editor, json, { clearHistory = true } = {}) {
    const tr = remoteTransaction(editor.state, json, ReplaceStep);
```

with

```js
function dispatchAsApplyRemoteDoes(editor, json, { clearHistory = true, oneRange = false } = {}) {
    const tr = oneRange ? remote(editor.state, json) : remoteTransaction(editor.state, json, ReplaceStep);
```

Replace

```js
test('in TipTap own editor, with the history kept, undo deletes the text that arrived', () => {
    const { editor } = tiptapAfterTheirChange({ clearHistory: false });
```

with

```js
test('in TipTap own editor, with the history kept, undo deletes the text that arrived', () => {
    // The Phase 1 one-range apply, kept as the demonstration. Per-block
    // steps change what this particular undo does (it joins the two
    // paragraphs they typed instead of deleting the text), not whether the
    // history must go.
    const { editor } = tiptapAfterTheirChange({ clearHistory: false, oneRange: true });
```

Replace

```js
    assert.deepEqual(dispatches.slice(before.dispatches), [
        [1, [1, 1]],
        [0, []],
    ]);
```

with

```js
    // Two block steps of its own (the edited paragraph, narrowed, and the
    // rule inserted), then what the plugins appended; the clearing
    // transaction has no steps and nothing is appended to it.
    assert.deepEqual(dispatches.slice(before.dispatches), [
        [2, [1, 1]],
        [0, []],
    ]);
```

In `tests/js/uniqueIds.test.js`, replace

```js
import { remoteTransaction } from '../../resources/js/editor/sync/apply.js';
```

with

```js
import { diffRange } from '../../resources/js/editor/sync/narrow.js';
```

and replace

```js
    world.apply(remoteTransaction(state, alphas, ReplaceStep).setMeta('addToHistory', false).setMeta('preventUpdate', true));
```

with

```js
    // As ONE replaced range, the way applyRemote() applied a newer document
    // when this was seen (Phase 1). Phase 2 applies per block and empties
    // the history after the change, so the editor no longer takes this
    // path; the repair has to hold for whatever a transaction does.
    const theirs = node(alphas);
    const range = diffRange(state.doc, theirs);
    world.apply(
        state.tr
            .step(new ReplaceStep(range.from, range.toA, theirs.slice(range.from, range.toB)))
            .setMeta('addToHistory', false)
            .setMeta('preventUpdate', true)
    );
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `node --test tests/js/sync.apply.test.js tests/js/sync.narrow.test.js tests/js/sync.history.test.js tests/js/uniqueIds.test.js`
Expected: PASS. `sync.apply.test.js` 25 tests (16 before, one amended, 9 new), `sync.narrow.test.js` 13 tests (9 + 4), `sync.history.test.js` 12 tests and `uniqueIds.test.js` 19 tests, as before. If "in TipTap own editor, the two dispatches of applyRemote() leave nothing to undo and fire no update" fails, the per-block steps did not reproduce `theirs` in the real editor: compare `blocksOf(editor.state.doc)` with the expected three paragraphs before reading anything else.

Run: `npm test`
Expected: every JavaScript test passes (478 tests: 465 after Task 2, plus 9 apply cases and 4 narrow cases).

- [ ] **Step 7: Commit**

```bash
git add resources/js/editor/sync/apply.js resources/js/editor/sync/narrow.js tests/js/sync.apply.test.js tests/js/sync.narrow.test.js tests/js/sync.history.test.js tests/js/uniqueIds.test.js
git commit -m "feat(editor): apply a newer document block by block

remoteTransaction() changes what differs one top-level block at a time:
a changed textblock inside the block only, any other changed block
whole, deletions and insertions as their own steps, a deletion and an
insertion at one point as one replace. The steps are probed on a copy
first; a narrowed step that fails is retried whole, and if the probe
does not reproduce the target the one-range apply and then the
whole-document apply run as before, as they do for more than
MAX_BLOCK_STEPS (500) steps. A caret in a block no step touches
stays exactly where it was. insertionPoint() in narrow.js is where a
lost block goes back: after the first of its anchors that is on the
page, else the end, or the start when it had no predecessor.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 4: The editor handle learns `merge()`, `insertBlock()` and `composing`

**Files:**
- Create: `resources/js/editor/sync/insert.js`
- Modify: `resources/js/editor/index.js` (imports; the handle; `applyRemote()`'s docblock)
- Test: `tests/js/sync.merge.editor.test.js` (create; the real TipTap `Editor`)

**Interfaces:**
- Produces: `insertBlockTransaction(state, json, {anchors = [], keepId = false, generateId, Selection}) -> Transaction` from `sync/insert.js`: a deep copy of the block with every `id` replaced by `generateId()` (never one the document already uses), except, when `keepId`, an id the document does not hold, which stays (nested blocks included); `schema.nodeFromJSON(block).check()`; inserted at `insertionPoint(state.doc, anchors)`; the selection set to `Selection.near(doc.resolve(pos + nodeSize - 1), -1)` (after the block for an atom) and scrolled into view. A throw means the block cannot go in. Not dispatched; the caller decides.
- Produces, on the handle in `index.js`: `merge(base, theirs, {echo = null, mine = null, deletionsWin = true} = {}) -> MergeResult|null` (null also when fail-closed); `insertBlock(json, {anchors, keepId}) -> boolean` (dispatches the transaction as an ordinary writer's edit: undoable, `update` fires, the autosave runs, no overwrite flag; focuses the editor; false when fail-closed or when the transaction throws); `get composing()` (`editor.view.composing`).
- Produces: `tests/fixtures/merge/editor-form.json`, captured from the real editor (written when missing or when `MERGE_FIXTURE_WRITE=1`; asserted otherwise).
- Decision: the test editor registers the project's own extensions through `tests/js/moduleLoader.js` (see Step 1). If one of them cannot load under node, it is left out of the editor AND of the fixture document, and the commit message says which.

- [ ] **Step 1: Write the failing editor test**

Create `tests/js/sync.merge.editor.test.js`:

```js
import assert from 'node:assert/strict';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

// First: registers the resolve hook the project's extensions need (they
// import their neighbours without an extension, as Vite resolves them).
import { loadEditorModule } from './moduleLoader.js';

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import { Selection } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { clearHistoryTransaction, remoteTransaction } from '../../resources/js/editor/sync/apply.js';
import { insertBlockTransaction } from '../../resources/js/editor/sync/insert.js';
import { mergeDocuments } from '../../resources/js/editor/sync/merge.js';

/**
 * The merge and Put it back in TipTap's own editor, with the project's own
 * extension stack: the id plugins, numbered headings, alignment, the
 * contents list and cross-references, section and page breaks, callouts,
 * columns, variables, tables and task lists. The figure and image nodes are
 * left out: their `src` rules belong to a browser upload. No DOM is needed:
 * an Editor built with `element: null` is not mounted, and
 * `editor.view.dispatch()` runs TipTap's own `dispatchTransaction`, the
 * code that decides whether `update` fires.
 *
 * index.js cannot be loaded here (it needs a browser), so the two things
 * it does with these modules are repeated: the two dispatches of
 * applyRemote() (as sync.history.test.js does) and the dispatch of
 * insertBlock().
 */
const { BlockId, BlockIdRepair, base62 } = await loadEditorModule('extensions/blockId.js');
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

const FIXTURE = fileURLToPath(new URL('../fixtures/merge/editor-form.json', import.meta.url));

/** An editor as index.js builds it, less what needs a browser. */
function realEditor(content) {
    const editor = new Editor({
        element: null,
        extensions: [
            StarterKit.configure({ heading: false, link: { openOnClick: false, autolink: true } }),
            HeadingNumbered.configure({ levels: [1, 2, 3, 4, 5, 6] }),
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
            BlockIdRepair,
            BlockId,
        ],
        content,
    });
    editor.view.updateState(editor.state.reconfigure({ plugins: editor.extensionManager.plugins }));

    const updates = [];
    editor.on('update', ({ transaction }) => updates.push(transaction));

    return { editor, updates };
}

const para = (id, text, attrs = {}) => ({
    type: 'paragraph',
    attrs: { id, ...attrs },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
const heading = (id, text, level = 1) => ({ type: 'heading', attrs: { id, level }, content: [{ type: 'text', text }] });
const rule = (id) => ({ type: 'horizontalRule', attrs: { id } });
const docOf = (...content) => ({ type: 'doc', content });

/** Every top-level block as [id, text]. */
const blocksOf = (docNode) => {
    const blocks = [];
    docNode.forEach((child) => blocks.push([child.attrs.id, child.textContent]));

    return blocks;
};
const idsOf = (docNode) => {
    const ids = [];
    docNode.descendants((node) => {
        if (node.attrs && node.attrs.id !== null && node.attrs.id !== undefined) {
            ids.push(node.attrs.id);
        }
    });

    return ids;
};
const allDifferent = (ids) => new Set(ids).size === ids.length;
const VALID_ID = /^[0-9A-Za-z]{8}$/;

/** What applyRemote() dispatches: the merged document, then the transaction that empties the history. */
function dispatchAsApplyRemoteDoes(editor, json) {
    const tr = remoteTransaction(editor.state, json, ReplaceStep);
    assert.notEqual(tr, null);
    if (!tr.docChanged) {
        return;
    }
    tr.setMeta('addToHistory', false);
    tr.setMeta('preventUpdate', true);
    editor.view.dispatch(tr);
    editor.view.dispatch(clearHistoryTransaction(editor.state));
}

/** What insertBlock() dispatches. */
function dispatchAsInsertBlockDoes(editor, json, options) {
    editor.view.dispatch(
        insertBlockTransaction(editor.state, json, { ...options, generateId: () => base62(8), Selection })
    );
}

// ───────────────────────────────────────────────────────── a merge in the real editor

test('a merge applied as block steps keeps the typist caret, keeps ids unique, carries the trailing paragraph and empties the history once', () => {
    const { editor, updates } = realEditor(docOf(heading('headXX01', 'Title'), para('paraXX01', 'Hello'), para('paraXX02', 'World')));
    const base = editor.getJSON();

    // The typist works at the end of the last paragraph. A JSON text node,
    // not a string: insertContent() parses a string as HTML through
    // window.DOMParser, which node does not have.
    editor.chain().setTextSelection(editor.state.doc.content.size - 1).insertContent({ type: 'text', text: ' B' }).run();
    assert.deepEqual(blocksOf(editor.state.doc), [
        ['headXX01', 'Title'],
        ['paraXX01', 'Hello'],
        ['paraXX02', 'World B'],
    ]);
    assert.equal(updates.length, 1);
    assert.equal(editor.can().undo(), true);

    // Somebody else edited the second paragraph and ended the document with a rule.
    const theirs = docOf(heading('headXX01', 'Title'), para('paraXX01', 'Hello A'), para('paraXX02', 'World'), rule('ruleXX01'));
    const result = mergeDocuments(editor.schema, { base, mine: editor.getJSON(), theirs });
    assert.notEqual(result, null);
    assert.equal(result.changed, true);
    assert.deepEqual(result.lost, []);

    dispatchAsApplyRemoteDoes(editor, result.merged);

    // Their paragraph, my paragraph, their rule, and the editor's own trailing paragraph after it.
    const blocks = blocksOf(editor.state.doc);
    assert.deepEqual(blocks.slice(0, 4).map(([, text]) => text), ['Title', 'Hello A', 'World B', '']);
    assert.equal(editor.state.doc.childCount, 5);
    assert.equal(editor.state.doc.lastChild.type.name, 'paragraph');
    assert.equal(editor.state.doc.lastChild.content.size, 0);
    assert.ok(allDifferent(idsOf(editor.state.doc)), `ids unique, got ${idsOf(editor.state.doc).join(', ')}`);
    // The caret stayed at the end of the typist's paragraph.
    assert.equal(editor.state.selection.$from.parent.textContent, 'World B');
    assert.equal(editor.state.selection.$from.parentOffset, 'World B'.length);
    // Nothing to undo, no update fired, so the autosave was not armed.
    assert.equal(editor.can().undo(), false);
    assert.equal(editor.can().redo(), false);
    assert.equal(updates.length, 1);

    // The merged document was stored. A follow in disguise: merging what the
    // editor holds now against the stored document is no change and no loss,
    // and `theirs` carries the trailing paragraph, so it equals the editor's
    // own serialisation (settleIfBackAtConfirmed() stays reachable).
    const again = mergeDocuments(editor.schema, { base: JSON.parse(result.theirs), mine: editor.getJSON(), theirs: result.merged });
    assert.notEqual(again, null);
    assert.equal(again.changed, false);
    assert.deepEqual(again.lost, []);
    assert.equal(again.theirs, JSON.stringify(editor.getJSON()));

    editor.destroy();
});

test('a D1 loser caret lands at the end of the winner paragraph', () => {
    const { editor } = realEditor(docOf(para('paraXX01', 'Hello'), para('paraXX02', 'World')));
    const base = editor.getJSON();
    editor.chain().setTextSelection(6).insertContent({ type: 'text', text: ' B' }).run(); // a JSON text node: no DOM under node
    assert.equal(editor.state.doc.firstChild.textContent, 'Hello B');

    const result = mergeDocuments(editor.schema, {
        base,
        mine: editor.getJSON(),
        theirs: docOf(para('paraXX01', 'Hello A, at length'), para('paraXX02', 'World')),
    });
    assert.deepEqual(result.lost.map((loss) => [loss.id, loss.reason, loss.text]), [['paraXX01', 'both', 'Hello B']]);

    dispatchAsApplyRemoteDoes(editor, result.merged);

    assert.equal(editor.state.doc.firstChild.textContent, 'Hello A, at length');
    assert.equal(editor.state.selection.$from.parent.textContent, 'Hello A, at length');
    assert.equal(editor.state.selection.$from.parentOffset, 'Hello A, at length'.length);

    editor.destroy();
});

// ───────────────────────────────────────────────────────── Put it back in the real editor

test('insertBlock: a both block gets a fresh id below the winner, a deleted block keeps its id where it was, and both fire update', () => {
    const { editor, updates } = realEditor(docOf(para('paraXX01', 'One'), para('paraXX03', 'Three')));

    // D2: the deleted paragraph returns with its own id, after its predecessor.
    dispatchAsInsertBlockDoes(editor, para('paraXX02', 'Two, typed'), { anchors: ['paraXX01'], keepId: true });
    assert.deepEqual(blocksOf(editor.state.doc), [
        ['paraXX01', 'One'],
        ['paraXX02', 'Two, typed'],
        ['paraXX03', 'Three'],
    ]);
    assert.equal(updates.length, 1, 'an ordinary edit: update fires and the autosave runs');
    assert.equal(editor.can().undo(), true, 'and it is undoable');
    assert.equal(editor.state.selection.$from.parent.textContent, 'Two, typed');
    assert.equal(editor.state.selection.$from.parentOffset, 'Two, typed'.length, 'the caret is at the end of the block put back');

    // D1: my version of a paragraph goes below the winner, with a fresh id.
    dispatchAsInsertBlockDoes(editor, para('paraXX01', 'One B'), { anchors: ['paraXX01'], keepId: false });
    const ids = idsOf(editor.state.doc);
    assert.deepEqual(blocksOf(editor.state.doc).map(([, text]) => text), ['One', 'One B', 'Two, typed', 'Three']);
    assert.notEqual(ids[1], 'paraXX01');
    assert.match(ids[1], VALID_ID);
    assert.ok(allDifferent(ids));
    assert.equal(updates.length, 2);

    // keepId with an id the page holds again: a fresh one all the same.
    dispatchAsInsertBlockDoes(editor, para('paraXX03', 'Three again'), { anchors: ['paraXX03'], keepId: true });
    const after = idsOf(editor.state.doc);
    assert.equal(after.filter((id) => id === 'paraXX03').length, 1);
    assert.ok(allDifferent(after));

    editor.destroy();
});

test('insertBlock: nested ids are fresh too, anchors all gone put the block at the end, and no anchors put it at the start', () => {
    const { editor } = realEditor(docOf(para('paraXX01', 'One')));
    const list = {
        type: 'bulletList',
        attrs: { id: 'listXX01' },
        content: [
            { type: 'listItem', attrs: { id: 'itemXX01' }, content: [para('ipara001', 'Item')] },
        ],
    };

    dispatchAsInsertBlockDoes(editor, list, { anchors: ['goneXX01', 'goneXX02'], keepId: false });
    // At the end of the document; StarterKit's TrailingNode appends the editor's own empty paragraph after a list.
    const types = [];
    editor.state.doc.forEach((child) => types.push(child.type.name));
    assert.deepEqual(types, ['paragraph', 'bulletList', 'paragraph']);
    const ids = idsOf(editor.state.doc);
    assert.ok(ids.every((id) => VALID_ID.test(id)), ids.join(', '));
    assert.ok(!ids.includes('listXX01') && !ids.includes('itemXX01') && !ids.includes('ipara001'));
    assert.ok(allDifferent(ids));

    dispatchAsInsertBlockDoes(editor, para('topXXX01', 'Top'), { anchors: [], keepId: true });
    assert.equal(editor.state.doc.firstChild.attrs.id, 'topXXX01');
    assert.equal(editor.state.doc.firstChild.textContent, 'Top');

    editor.destroy();
});

test('insertBlock refuses a block this schema cannot hold, and leaves the document alone', () => {
    const { editor, updates } = realEditor(docOf(para('paraXX01', 'One')));

    assert.throws(() =>
        insertBlockTransaction(editor.state, { type: 'marquee', attrs: { id: 'marqXX01' } }, {
            anchors: ['paraXX01'],
            keepId: true,
            generateId: () => base62(8),
            Selection,
        })
    );
    assert.deepEqual(blocksOf(editor.state.doc), [['paraXX01', 'One']]);
    assert.equal(updates.length, 0);

    editor.destroy();
});

// ───────────────────────────────────────────────────────── which block conversions keep the id, and which give a new one

test('toggleHeading and toggleCodeBlock keep the block id, so a merge sees one changed block and not a deletion plus an insertion', () => {
    // Section 13 of the spec names this as a limit to pin. If a row ever
    // fails, TipTap re-ids the block on that conversion: move the row to
    // the wrapping test below, keep both tests, and the limit stands as
    // written.
    for (const [command, args, type] of [
        ['toggleHeading', [{ level: 2 }], 'heading'],
        ['toggleCodeBlock', [], 'codeBlock'],
    ]) {
        const { editor } = realEditor(docOf(para('paraXX01', 'Becomes converted'), para('paraXX02', 'Stays')));

        assert.equal(editor.chain().setTextSelection(2)[command](...args).run(), true, command);

        assert.equal(editor.state.doc.firstChild.type.name, type, command);
        assert.equal(editor.state.doc.firstChild.attrs.id, 'paraXX01', command);
        assert.deepEqual(idsOf(editor.state.doc), ['paraXX01', 'paraXX02'], command);

        editor.destroy();
    }
});

test('wrapping conversions give the block a NEW top-level id, the paragraph id moves inside it: a merge sees a deletion plus an insertion (a stated limit)', () => {
    for (const [command, type] of [
        ['toggleBulletList', 'bulletList'],
        ['toggleOrderedList', 'orderedList'],
        ['toggleTaskList', 'taskList'],
        ['toggleBlockquote', 'blockquote'],
    ]) {
        const { editor } = realEditor(docOf(para('paraXX01', 'Becomes wrapped'), para('paraXX02', 'Stays')));

        assert.equal(editor.chain().setTextSelection(2)[command]().run(), true, command);
        assert.equal(editor.state.doc.firstChild.type.name, type, command);
        assert.notEqual(editor.state.doc.firstChild.attrs.id, 'paraXX01', `${command} re-ids the top-level block`);
        assert.ok(idsOf(editor.state.doc).includes('paraXX01'), `${command}: the paragraph id is still there, nested`);

        editor.destroy();
    }
});

// ───────────────────────────────────────────────────────── the real fixture: this editor's serialisation

/** One of everything the editor stack above can hold, with the ids the server accepts. */
const FIXTURE_DOC = {
    type: 'doc',
    attrs: { schema: 1, style: 'report', vars: {} },
    content: [
        { type: 'heading', attrs: { id: 'fxHead01', level: 1, numbered: true }, content: [{ type: 'text', text: 'Introduction' }] },
        {
            type: 'paragraph',
            attrs: { id: 'fxPara01' },
            content: [
                { type: 'text', text: 'See ' },
                { type: 'crossRef', attrs: { kind: 'heading', targetId: 'fxHead01', label: null } },
                { type: 'text', text: ' and ' },
                { type: 'variable', attrs: { key: 'client' } },
                { type: 'text', text: ', ' },
                { type: 'text', marks: [{ type: 'bold' }], text: 'in bold' },
                { type: 'text', text: '.' },
            ],
        },
        { type: 'toc', attrs: { id: 'fxTocc01', depth: 3, entries: [] } },
        {
            type: 'bulletList',
            attrs: { id: 'fxList01' },
            content: [
                { type: 'listItem', attrs: { id: 'fxItem01' }, content: [para('fxIpar01', 'First')] },
                { type: 'listItem', attrs: { id: 'fxItem02' }, content: [para('fxIpar02', 'Second')] },
            ],
        },
        {
            type: 'orderedList',
            attrs: { id: 'fxOlst01' },
            content: [{ type: 'listItem', attrs: { id: 'fxOitm01' }, content: [para('fxOpar01', 'Step one')] }],
        },
        {
            type: 'taskList',
            attrs: { id: 'fxTask01' },
            content: [{ type: 'taskItem', attrs: { id: 'fxTitm01', checked: true }, content: [para('fxTpar01', 'Done')] }],
        },
        { type: 'blockquote', attrs: { id: 'fxQuot01' }, content: [para('fxQpar01', 'Quoted')] },
        { type: 'codeBlock', attrs: { id: 'fxCode01' }, content: [{ type: 'text', text: 'const x = 1;' }] },
        { type: 'horizontalRule', attrs: { id: 'fxRule01' } },
        {
            type: 'table',
            attrs: { id: 'fxTabl01' },
            content: [
                {
                    type: 'tableRow',
                    attrs: { id: 'fxTrow01' },
                    content: [
                        { type: 'tableHeader', attrs: { id: 'fxThdr01' }, content: [para('fxHpar01', 'Head')] },
                        { type: 'tableHeader', attrs: { id: 'fxThdr02' }, content: [para('fxHpar02', 'Head 2')] },
                    ],
                },
                {
                    type: 'tableRow',
                    attrs: { id: 'fxTrow02' },
                    content: [
                        { type: 'tableCell', attrs: { id: 'fxTcel01' }, content: [para('fxCpar01', 'Cell')] },
                        { type: 'tableCell', attrs: { id: 'fxTcel02' }, content: [para('fxCpar02', 'Cell 2')] },
                    ],
                },
            ],
        },
        { type: 'callout', attrs: { id: 'fxCall01', tone: 'note' }, content: [para('fxCapa01', 'Note this')] },
        {
            type: 'columns',
            attrs: { id: 'fxCols01', count: 2 },
            content: [
                { type: 'column', attrs: { id: 'fxColm01' }, content: [para('fxC1pa01', 'Left')] },
                { type: 'column', attrs: { id: 'fxColm02' }, content: [para('fxC2pa01', 'Right')] },
            ],
        },
        { type: 'sectionBreak', attrs: { id: 'fxSect01', setup: {} } },
        { type: 'pageBreak', attrs: { id: 'fxPage01' } },
        para('fxPara02', 'Centred end.', { align: 'center' }),
    ],
};

test('editor-form.json is what this editor serialises for the fixture document', () => {
    const { editor } = realEditor(FIXTURE_DOC);
    // A plain copy: Node.toJSON() builds attrs with Object.create(null), which
    // strict deepEqual tells apart from the parsed file.
    const captured = JSON.parse(JSON.stringify(editor.getJSON()));

    assert.equal(captured.content.length, FIXTURE_DOC.content.length, 'no block was dropped or added on the way in');
    assert.deepEqual(
        captured.content.map((block) => block.attrs.id),
        FIXTURE_DOC.content.map((block) => block.attrs.id)
    );

    if (!existsSync(FIXTURE) || process.env.MERGE_FIXTURE_WRITE) {
        writeFileSync(FIXTURE, `${JSON.stringify(captured, null, 2)}\n`);
        console.info(`[fixture] wrote ${FIXTURE}; run MergeFixtureTest to produce stored-form.json`);
    }

    assert.deepEqual(JSON.parse(readFileSync(FIXTURE, 'utf8')), captured);

    editor.destroy();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/sync.merge.editor.test.js`
Expected: FAIL before any case runs, with `Error [ERR_MODULE_NOT_FOUND]: Cannot find module '.../resources/js/editor/sync/insert.js'`. If instead the failure names one of the `loadEditorModule(...)` lines (a `ReferenceError: document is not defined`, or `window`), that extension cannot load under node: remove its `await loadEditorModule(...)` line, its entry in `realEditor()` and its block from `FIXTURE_DOC`, note it for the commit message, and run again.

- [ ] **Step 3: Create `insert.js`**

Create `resources/js/editor/sync/insert.js`:

```js
import { insertionPoint } from './narrow.js';

/** How many ids the generator is asked for before a block is left without one (UniqueID then fills it in). */
const FRESH_ID_ATTEMPTS = 8;

/**
 * The transaction that puts a lost block back on the page (the lost row's
 * Put it back, Phase 2).
 *
 * The block arrives as JSON from the lost entry, exactly as the editor
 * serialised it when the merge could not keep it. Its ids are decided
 * first: every id is replaced by a fresh one, so a block both people
 * changed (D1) goes in as a NEW block below the winner and never shares the
 * winner's id; with `keepId`, an id the document does not hold any more
 * stays, so a block somebody deleted while I typed in it (D2) returns with
 * its own id, and the comments and cross-references that pointed at it come
 * back with it. Nested blocks (a list's items, a table's cells) follow the
 * same rule. The block is checked against the schema, inserted after the
 * first of its anchors that is on the page (see insertionPoint() in
 * ./narrow.js), and the caret is put at its end so the writer can carry on
 * typing in it.
 *
 * An ordinary writer's edit: the caller dispatches it without
 * `addToHistory: false` or `preventUpdate`, so it is undoable, `update`
 * fires and the autosave sends it on the current base. It replaces
 * nothing, so no overwrite is owed.
 *
 * Dependency-free: the id generator and `Selection` are handed in (the
 * bundle passes base62() and @tiptap/pm/state's; the tests pass
 * prosemirror-state's).
 *
 * @param {import('prosemirror-state').EditorState} state
 * @param {object} json the block
 * @param {{anchors?: string[], keepId?: boolean, generateId: () => string, Selection: typeof import('prosemirror-state').Selection}} options
 * @returns {import('prosemirror-state').Transaction} not dispatched; a throw means the block cannot go in
 */
export function insertBlockTransaction(state, json, { anchors = [], keepId = false, generateId, Selection }) {
    const block = JSON.parse(JSON.stringify(json));

    const taken = new Set();
    state.doc.descendants((node) => {
        if (node.attrs && node.attrs.id !== null && node.attrs.id !== undefined) {
            taken.add(node.attrs.id);
        }
    });
    const fresh = () => {
        for (let attempt = 0; attempt < FRESH_ID_ATTEMPTS; attempt += 1) {
            const id = generateId();
            if (!taken.has(id)) {
                taken.add(id);

                return id;
            }
        }

        return null;
    };
    const reId = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        if (node.attrs && Object.prototype.hasOwnProperty.call(node.attrs, 'id')) {
            const own = node.attrs.id;
            if (keepId && own !== null && own !== undefined && !taken.has(own)) {
                taken.add(own);
            } else {
                node.attrs.id = fresh();
            }
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(reId);
        }
    };
    reId(block);

    const node = state.schema.nodeFromJSON(block);
    node.check();

    const pos = insertionPoint(state.doc, anchors);
    const tr = state.tr.insert(pos, node);
    // The end of the block's content; after the block for an atom.
    const end = Math.min(node.isAtom || node.isLeaf ? pos + node.nodeSize : pos + node.nodeSize - 1, tr.doc.content.size);

    return tr.setSelection(Selection.near(tr.doc.resolve(end), -1)).scrollIntoView();
}
```

- [ ] **Step 4: Run the test again**

Run: `node --test tests/js/sync.merge.editor.test.js`
Expected: PASS, 8 tests, 0 failures, and the file `tests/fixtures/merge/editor-form.json` now exists (the fixture test wrote it and printed `[fixture] wrote ...`). Run it once more: PASS, 8 tests, nothing written. Open the fixture: its first block is the heading `fxHead01`, its last the centred paragraph `fxPara02`, and every paragraph carries `"align": null` or its value.

If "toggleHeading and toggleCodeBlock keep the block id" fails, the message names the command: TipTap re-ids the block on that conversion. Move the command's row to the loop of the wrapping test (which then asserts a new top-level id), and say so in the commit message. If the wrapping test fails for a command (the id is kept), do the reverse. Nothing else changes: the limit is already written in section 13 of the spec and under "What Phase 2 does NOT do".

- [ ] **Step 5: Give the handle `merge()`, `insertBlock()` and `composing`**

In `resources/js/editor/index.js`:

1. Replace

```js
import { ReplaceStep } from '@tiptap/pm/transform';
```

with

```js
import { Selection } from '@tiptap/pm/state';
import { ReplaceStep } from '@tiptap/pm/transform';
```

and replace

```js
import { clearHistoryTransaction, remoteTransaction } from './sync/apply';
import { createSyncEngine } from './sync/engine';
```

with

```js
import { clearHistoryTransaction, remoteTransaction } from './sync/apply';
import { createSyncEngine } from './sync/engine';
import { insertBlockTransaction } from './sync/insert';
import { mergeDocuments } from './sync/merge';
```

2. In the handle, replace

```js
        get pending() {
            return dirty || saveTimer !== null;
        },

        /** Send any pending document immediately. */
        flush: flushSave,
```

with

```js
        get pending() {
            return dirty || saveTimer !== null;
        },

        /**
         * Whether an IME composition is in progress. The sync host reports
         * the tab busy while it is: a document applied under a composition
         * would land in the middle of a character being composed.
         */
        get composing() {
            return editor.view.composing;
        },

        /** Send any pending document immediately. */
        flush: flushSave,
```

3. In `applyRemote`'s docblock, replace

```js
         * It never runs over unsaved local typing unless `force` is given:
         * while the debounce is armed or a save is owed, what the editor
         * holds exists nowhere else. The caller decides what happens to that
         * text first (the conflict notice in the Blade bridge).
```

with

```js
         * It never runs over unsaved local typing unless `force` is given:
         * while the debounce is armed or a save is owed, what the editor
         * holds exists nowhere else. `force` is for the cases where the
         * caller has already decided what happens to that text: a MERGED
         * document (sync/host.js merged the typing into it and offers back
         * whatever could not be kept) and the writer's own choice (Load
         * theirs, an accepted suggestion).
```

4. Directly after the `applyRemote` property (its closing `},` is followed by a blank line and `destroy: () => {`), insert before `destroy: () => {`:

```js
        /**
         * Merge a newer server document with what this editor holds
         * (sync/merge.js). `base` is the last server document this tab
         * held, `theirs` the newer one; `mine` is the editor's own document
         * unless the caller hands one in (the draft merge hands in the
         * draft). `echo` is the document of a save whose answer was lost;
         * `deletionsWin` false is the draft merge's rule. Null when the
         * merge cannot be done, and when the editor is fail-closed: what a
         * read-only view holds is not the document, so it merges nothing.
         *
         * @param {object} base
         * @param {object} theirs
         * @param {{echo?: object|null, mine?: object|null, deletionsWin?: boolean}} options
         * @returns {object|null} the MergeResult of mergeDocuments()
         */
        merge: (base, theirs, { echo = null, mine = null, deletionsWin = true } = {}) => {
            if (!base || !theirs || editor.isDestroyed || !autosave) {
                return null;
            }

            try {
                return mergeDocuments(editor.schema, { base, mine: mine ?? editor.getJSON(), theirs, echo }, { deletionsWin });
            } catch (_) {
                return null;
            }
        },

        /**
         * Put a block a merge could not keep back on the page (the lost
         * row's Put it back). The transaction is built by
         * insertBlockTransaction() (sync/insert.js, which says where the
         * block goes and what happens to its ids) and dispatched as an
         * ordinary writer's edit: undoable, `update` fires, the autosave
         * sends it on the current base, and no overwrite is owed, because
         * it replaces nothing.
         *
         * @param {object} json the block, as the lost entry holds it
         * @param {{anchors?: string[], keepId?: boolean}} options
         * @returns {boolean} false when the block could not go in (nothing changed)
         */
        insertBlock: (json, { anchors = [], keepId = false } = {}) => {
            if (!json || editor.isDestroyed || !autosave) {
                return false;
            }

            try {
                const tr = insertBlockTransaction(editor.state, json, {
                    anchors,
                    keepId,
                    generateId: () => base62(8),
                    Selection,
                });
                editor.view.dispatch(tr);
            } catch (_) {
                return false;
            }

            try {
                editor.commands.focus();
            } catch (_) {
                // The block is in and the caret is set; focus is a nicety.
            }

            return true;
        },

```

- [ ] **Step 6: Build and run everything**

Run: `npm test && npm run build`
Expected: every JavaScript test passes (486 tests: 465 after Task 2, 13 from Task 3, 8 here) and the build succeeds. `grep -n "insertBlock\|merge:\|get composing" resources/js/editor/index.js` lists the three new members among its matches (the import of `insertBlockTransaction` and the docblocks match too).

- [ ] **Step 7: Commit**

```bash
git add resources/js/editor/sync/insert.js resources/js/editor/index.js tests/js/sync.merge.editor.test.js tests/fixtures/merge/editor-form.json
git commit -m "feat(editor): the handle merges, puts a lost block back, and reports composition

handle.merge() runs mergeDocuments() over the editor's own schema (null
when fail-closed); handle.insertBlock() dispatches insertBlockTransaction()
(sync/insert.js): a lost block goes back after the first of its anchors
that is on the page, with fresh ids, or with its own id when it was deleted
and the id is free, as an ordinary undoable edit that the autosave sends;
handle.composing exposes the IME state. sync.merge.editor.test.js runs the
merge and the put-back in TipTap's own editor with the project's extension
stack, pins that toggleHeading and toggleCodeBlock keep the block id and
that wrapping a paragraph in a list or a quote gives the wrapper a new
top-level id (a stated limit), and captures
tests/fixtures/merge/editor-form.json, this editor's serialisation of one
of everything, for the stored-form fixture MergeFixtureTest produces.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 5: Drafts remember their base, and the lost park

**Files:**
- Modify: `resources/js/offline.js`
- Modify: `resources/js/editor/index.js` (the `../offline` import and `window.offlineDraft`)
- Modify: `tests/js/offline.test.js`

**Interfaces:**
- Produces, in `offline.js`: `BASE_PREFIX = 'base-'`, `LOST_PREFIX = 'lost-'`, `PARKED_PREFIXES = ['stale-', 'lost-', 'base-']`; `parkedRowIsExpired(row, now, ttl)` (a row under any parked prefix older than `STALE_TTL_MS`, dated by `parkedAt`, else `savedAt`; undatable → expired; a live draft never); `staleDraftIsExpired` stays as an alias of it; `saveBase(docUuid, json, baseVersion)` / `loadBase(docUuid) -> {json, baseVersion}|null`; `parkLost(docUuid, entries, baseVersion)` (stores a plain copy as `entries`, drops the row's `dismissedAt`, and carries the row's `dismissed` list over unchanged) / `loadLost(docUuid) -> {entries, dismissed, baseVersion, parkedAt, dismissedAt}|null` / `markLostDismissed(docUuid)` (moves `entries` to the end of `dismissed`, empties `entries`, stamps `dismissedAt`). `entries` is what is offered; `dismissed` is what Dismiss all promised to keep for 7 days, and only the sweep removes it (dry run: without the second list the next loss after Dismiss all rewrote the row from the emptied view list and the dismissed text was gone from the browser).
- Produces: `window.offlineDraft` gains `saveBase`, `loadBase`, `parkLost`, `loadLost`, `markLostDismissed`.
- The IndexedDB code is not reachable from `npm test` (no IndexedDB under node); the pure rule is. The rows are exercised end to end in the browser pass (Task 14).

- [ ] **Step 1: Write the failing tests**

In `tests/js/offline.test.js`, replace

```js
import { STALE_PREFIX, STALE_TTL_MS, staleDraftIsExpired } from '../../resources/js/offline.js';
```

with

```js
import {
    BASE_PREFIX,
    LOST_PREFIX,
    PARKED_PREFIXES,
    STALE_PREFIX,
    STALE_TTL_MS,
    parkedRowIsExpired,
    staleDraftIsExpired,
} from '../../resources/js/offline.js';
```

and append at the end of the file:

```js

// ───────────────────────────────────────────────────────── the base row and the lost park (Phase 2)

test('base- and lost- rows expire after the TTL, dated by parkedAt or else savedAt, and undatable ones go', () => {
    assert.deepEqual(PARKED_PREFIXES, [STALE_PREFIX, LOST_PREFIX, BASE_PREFIX]);

    assert.equal(parkedRowIsExpired({ docUuid: BASE_PREFIX + 'abc', json: '{}', savedAt: NOW - 8 * DAY }, NOW), true);
    assert.equal(parkedRowIsExpired({ docUuid: BASE_PREFIX + 'abc', json: '{}', savedAt: NOW - 6 * DAY }, NOW), false);
    assert.equal(parkedRowIsExpired({ docUuid: LOST_PREFIX + 'abc', entries: [], parkedAt: NOW - 8 * DAY }, NOW), true);
    assert.equal(parkedRowIsExpired({ docUuid: LOST_PREFIX + 'abc', entries: [], parkedAt: NOW - DAY }, NOW), false);
    assert.equal(parkedRowIsExpired({ docUuid: LOST_PREFIX + 'abc', entries: [] }, NOW), true, 'a parked row that cannot be dated goes');
    assert.equal(parkedRowIsExpired(parked(NOW - 8 * DAY), NOW), true, 'a stale draft, as before');
});

test('the generalised rule never sweeps a live draft, however old, and leaves anything that is not a row alone', () => {
    assert.equal(parkedRowIsExpired({ docUuid: 'abc', json: '{}', savedAt: NOW - 400 * DAY }, NOW), false);
    assert.equal(parkedRowIsExpired(null, NOW), false);
    assert.equal(parkedRowIsExpired({}, NOW), false);
    assert.equal(parkedRowIsExpired({ docUuid: 42 }, NOW), false);
});

test('staleDraftIsExpired keeps its answers: it is the same rule', () => {
    for (const row of [
        parked(NOW - 8 * DAY),
        parked(NOW - 6 * DAY),
        { docUuid: 'abc', json: '{}', savedAt: NOW - 400 * DAY },
        { docUuid: STALE_PREFIX + 'abc' },
        { docUuid: BASE_PREFIX + 'abc', savedAt: NOW - 9 * DAY },
        null,
    ]) {
        assert.equal(staleDraftIsExpired(row, NOW), parkedRowIsExpired(row, NOW));
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/offline.test.js`
Expected: FAIL before any case runs, with `SyntaxError: The requested module '../../resources/js/offline.js' does not provide an export named 'BASE_PREFIX'`.

- [ ] **Step 3: Generalise the sweep and add the two rows**

In `resources/js/offline.js`, replace

```js
/**
 * A draft that cannot be restored is parked under this prefix rather than
 * deleted — the writer declined the restore, or the document has moved on to a
 * newer version — because a mis-click must not destroy the only copy of
 * somebody's offline work. Nothing in the UI reads a parked draft back yet, so
 * without a sweep they accumulate in IndexedDB for ever.
 */
export const STALE_PREFIX = 'stale-';

/** How long a parked draft is kept. Seven days, swept on app boot. */
export const STALE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Whether a stored row is a parked draft that has outlived the TTL.
 *
 * Pure, and exported so `npm test` can exercise it without IndexedDB. Rows
 * that are not parked drafts are never expired here: the live draft for a
 * document is the writer's only unsaved copy and age says nothing about it.
 *
 * @param {{docUuid?: string, parkedAt?: number, savedAt?: number}} row
 */
export function staleDraftIsExpired(row, now = Date.now(), ttl = STALE_TTL_MS) {
    if (!row || typeof row.docUuid !== 'string' || !row.docUuid.startsWith(STALE_PREFIX)) {
        return false;
    }

    // `parkedAt` is when it was parked; `savedAt` is what an older build wrote
    // at the same moment. A parked row with neither is from a build older than
    // both and cannot be dated at all, so it goes.
    const parked = Number(row.parkedAt ?? row.savedAt);
    if (!Number.isFinite(parked) || parked <= 0) {
        return true;
    }

    return now - parked > ttl;
}
```

with

```js
/**
 * A draft that cannot be restored is parked under this prefix rather than
 * deleted — the writer declined the restore, or the document has moved on to a
 * newer version — because a mis-click must not destroy the only copy of
 * somebody's offline work. The editor page offers a parked draft back (Put it
 * back in the notice bar); without a sweep they would still accumulate in
 * IndexedDB for ever.
 */
export const STALE_PREFIX = 'stale-';

/**
 * The last server document a tab held, as it serialises it, with its version
 * (saveBase()/loadBase()). Written by markConfirmed() in sync/host.js on
 * open, on an accepted save, on an applied or merged document and on an
 * adopted restyle - never per keystroke. It is what lets a draft the
 * document has moved past be MERGED on the next visit instead of only set
 * aside: the merge needs the document the draft was based on.
 */
export const BASE_PREFIX = 'base-';

/**
 * The blocks a merge could not keep, offered back in the notice bar's lost
 * row (parkLost()/loadLost()/markLostDismissed()). Its own key, never the
 * stale- slot Load theirs uses, so neither overwrites the other.
 */
export const LOST_PREFIX = 'lost-';

/** The rows the sweep may remove. A live draft (the bare uuid) is never one of them. */
export const PARKED_PREFIXES = [STALE_PREFIX, LOST_PREFIX, BASE_PREFIX];

/** How long a parked row is kept. Seven days, swept on app boot. */
export const STALE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Whether a stored row is a parked row (a stale draft, a lost park or a base
 * row) that has outlived the TTL.
 *
 * Pure, and exported so `npm test` can exercise it without IndexedDB. Rows
 * that are not parked are never expired here: the live draft for a document
 * is the writer's only unsaved copy and age says nothing about it. A swept
 * base row only means that a stale draft falls back to being set aside; a
 * swept lost park means the copy the Dismiss button promised for 7 days has
 * had its 7 days.
 *
 * @param {{docUuid?: string, parkedAt?: number, savedAt?: number}} row
 */
export function parkedRowIsExpired(row, now = Date.now(), ttl = STALE_TTL_MS) {
    if (!row || typeof row.docUuid !== 'string' || !PARKED_PREFIXES.some((prefix) => row.docUuid.startsWith(prefix))) {
        return false;
    }

    // `parkedAt` is when it was parked; `savedAt` is what an older build wrote
    // at the same moment (and what a base row carries). A parked row with
    // neither is from a build older than both and cannot be dated at all, so
    // it goes.
    const parked = Number(row.parkedAt ?? row.savedAt);
    if (!Number.isFinite(parked) || parked <= 0) {
        return true;
    }

    return now - parked > ttl;
}

/** The rule's older name; the same rule. */
export function staleDraftIsExpired(row, now = Date.now(), ttl = STALE_TTL_MS) {
    return parkedRowIsExpired(row, now, ttl);
}
```

Then replace

```js
/**
 * Delete parked drafts older than the TTL. Called once on app boot.
 *
 * @returns {Promise<number>} how many rows were removed
 */
```

with

```js
/**
 * Delete parked rows (stale drafts, lost parks, base rows) older than the
 * TTL. Called once on app boot.
 *
 * @returns {Promise<number>} how many rows were removed
 */
```

and replace

```js
                if (staleDraftIsExpired(cursor.value, now, ttl)) {
```

with

```js
                if (parkedRowIsExpired(cursor.value, now, ttl)) {
```

Then, directly after the `clearDraft()` function (its closing `}` is followed by a blank line and the docblock of `initOfflineSupport()`), insert before that docblock:

```js
/**
 * Write the base row: the server document this tab holds as confirmed, and
 * the version it is. See BASE_PREFIX.
 */
export async function saveBase(docUuid, json, baseVersion = null) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put({
                docUuid: BASE_PREFIX + docUuid,
                json,
                savedAt: Date.now(),
                baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
            });
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) { /* silent — don't break the editor */ }
}

/**
 * @returns {Promise<{json: string, baseVersion: number|null}|null>}
 */
export async function loadBase(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(BASE_PREFIX + docUuid);
            req.onsuccess = () => {
                const row = req.result;
                const baseVersion = Number(row?.baseVersion);
                resolve(
                    typeof row?.json !== 'string'
                        ? null
                        : {
                              json: row.json,
                              baseVersion: Number.isFinite(baseVersion) && row.baseVersion !== null ? baseVersion : null,
                          }
                );
            };
            req.onerror   = () => reject(req.error);
        });
    } catch (_) {
        return null;
    }
}

/**
 * Write the lost park: the WHOLE lost list of a document, rewritten on every
 * merge that lost something and on every put-back; the row's dismissal
 * stamp does not survive (a new loss is offered again), but the entries
 * Dismiss all moved aside under `dismissed` do: they stay for the row's 7
 * days, whatever is offered after them. A plain copy is stored: the page
 * hands in Alpine's reactive proxy, which IndexedDB cannot clone. See
 * LOST_PREFIX.
 */
export async function parkLost(docUuid, entries, baseVersion = null) {
    try {
        const db = await openDb();
        const plain = JSON.parse(JSON.stringify(entries));
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            // Read the row in the same transaction: `dismissed` is carried over.
            const req = store.get(LOST_PREFIX + docUuid);
            req.onsuccess = () => {
                store.put({
                    docUuid: LOST_PREFIX + docUuid,
                    entries: plain,
                    dismissed: Array.isArray(req.result?.dismissed) ? req.result.dismissed : [],
                    parkedAt: Date.now(),
                    baseVersion: Number.isFinite(baseVersion) ? baseVersion : null,
                });
            };
            req.onerror   = () => reject(req.error);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) { /* silent — don't break the editor */ }
}

/**
 * @returns {Promise<{entries: object[], dismissed: object[], baseVersion: number|null, parkedAt: number, dismissedAt: number|null}|null>}
 */
export async function loadLost(docUuid) {
    try {
        const db = await openDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const req = tx.objectStore(STORE_NAME).get(LOST_PREFIX + docUuid);
            req.onsuccess = () => {
                const row = req.result;
                const baseVersion = Number(row?.baseVersion);
                resolve(
                    !row || !Array.isArray(row.entries)
                        ? null
                        : {
                              entries: row.entries,
                              dismissed: Array.isArray(row.dismissed) ? row.dismissed : [],
                              baseVersion: Number.isFinite(baseVersion) && row.baseVersion !== null ? baseVersion : null,
                              parkedAt: Number(row.parkedAt) || 0,
                              dismissedAt: Number(row.dismissedAt) || null,
                          }
                );
            };
            req.onerror   = () => reject(req.error);
        });
    } catch (_) {
        return null;
    }
}

/**
 * Dismiss all: the park stays for its 7 days (the button says so), but it is
 * not offered again on the next visit. The entries move aside under
 * `dismissed`, which parkLost() carries over, so a later loss that rewrites
 * `entries` does not take the dismissed text out of the browser.
 */
export async function markLostDismissed(docUuid) {
    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const req = store.get(LOST_PREFIX + docUuid);
            req.onsuccess = () => {
                if (req.result) {
                    const row = req.result;
                    store.put({
                        ...row,
                        entries: [],
                        dismissed: [...(Array.isArray(row.dismissed) ? row.dismissed : []), ...(Array.isArray(row.entries) ? row.entries : [])],
                        dismissedAt: Date.now(),
                    });
                }
            };
            req.onerror   = () => reject(req.error);
            tx.oncomplete = resolve;
            tx.onerror    = () => reject(tx.error);
        });
    } catch (_) { /* silent — don't break the editor */ }
}

```

- [ ] **Step 4: Expose the five functions on `window.offlineDraft`**

In `resources/js/editor/index.js`, replace

```js
import { clearDraft, loadDraft, parkStaleDraft, purgeStaleDrafts, saveDraft } from '../offline';
```

with

```js
import {
    clearDraft,
    loadBase,
    loadDraft,
    loadLost,
    markLostDismissed,
    parkLost,
    parkStaleDraft,
    purgeStaleDrafts,
    saveBase,
    saveDraft,
} from '../offline';
```

and replace

```js
// Offline draft helpers the Blade bridge calls (kept from the pre-JSON editor).
// parkStaleDraft() is how a draft that cannot be restored is kept instead of
// deleted; purgeStaleDrafts() (also run on app boot) is what stops those
// parked drafts accumulating in IndexedDB for ever.
window.offlineDraft = { saveDraft, loadDraft, clearDraft, parkStaleDraft, purgeStaleDrafts };
```

with

```js
// Offline draft helpers the Blade bridge calls (kept from the pre-JSON editor).
// parkStaleDraft() is how a draft that cannot be restored is kept instead of
// deleted; purgeStaleDrafts() (also run on app boot) is what stops those
// parked rows accumulating in IndexedDB for ever. saveBase()/loadBase() keep
// the server document a tab's typing is based on, so a stale draft can be
// merged on the next visit; parkLost()/loadLost()/markLostDismissed() keep
// the blocks a merge could not keep, for the notice bar's lost row.
window.offlineDraft = {
    saveDraft,
    loadDraft,
    clearDraft,
    parkStaleDraft,
    purgeStaleDrafts,
    saveBase,
    loadBase,
    parkLost,
    loadLost,
    markLostDismissed,
};
```

- [ ] **Step 5: Run the tests and build**

Run: `node --test tests/js/offline.test.js`
Expected: PASS, 10 tests, 0 failures.

Run: `npm test && npm run build`
Expected: every JavaScript test passes (489 tests: 486 + 3) and the build succeeds.

- [ ] **Step 6: Commit**

```bash
git add resources/js/offline.js resources/js/editor/index.js tests/js/offline.test.js
git commit -m "feat(editor): drafts remember their base; a lost park for the notice bar

offline.js gains the base- row (the server document a tab's typing is
based on, written by the page only when a version is confirmed, so a
stale draft can be merged on the next visit instead of only set aside)
and the lost- row (the whole list of blocks a merge could not keep,
rewritten on every change; Dismiss all moves the entries to a dismissed
list the next rewrite carries over, so the text stays its 7 days). The
sweep expires stale-, lost- and base- rows alike
(parkedRowIsExpired; staleDraftIsExpired stays as its older name) and
never a live draft. window.offlineDraft exposes the five new functions.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 6: The block-shaped fake world, shared by the host tests and the walk

A refactor with no behaviour change: `host.js` is untouched, and every existing host test must pass exactly as before. The harness at the top of `tests/js/sync.host.test.js` moves to `tests/js/support/syncWorld.js`, the fake document becomes block-shaped (paragraph `p1` instead of `{type: 'doc', text}`), the fake handle learns `merge()` (the REAL `mergeDocuments()` over a mini schema), `insertBlock()` and `composing`, the fake drafts learn the five new functions, and `makeView()` gains the seven Phase 2 fields.

**Files:**
- Create: `tests/js/support/syncWorld.js`
- Modify: `tests/js/sync.host.test.js` (the harness goes; mechanical substitutions)

**Interfaces:**
- Produces, from `syncWorld.js`: `schema` (mini: `doc`, `paragraph{id, align}`, `box{id}` isolating, `text`), `para(id, text)`, `box(id, ...content)`, `docOf(...blocks)`, `doc(text)` (one paragraph `p1`), `textOf(json)` (the text of block `p1`), `blockText(block)`, `blocksOf(json)` (`[[id, text]...]`), `clone`, `settle`, `UUID`, `STALE`, `LOST`, `BASE`, `FakeServer` (`save`, `autosave`, `replace`, `sync`, `text()`, `history` rows `{version, text}` unchanged, new `kept` rows `{version, json}` and `trace` rows `{version, from, to, overwrite}` for the walk; `replace()` writes `kept` and `trace` but NOT `history`, exactly as Phase 1's `replace()` wrote nothing there: seven Phase 1 cases assert `server.history` after a restyle), `makeView(version)`, `world(text)`, `makeTab(world, name, options)`, `pair(options)`, `conflicted(options)`.
- Tab options: `failClosed`, `drafts`, `draftStore`, `confirmAnswer` (as before), `mergeFails` (the fake `handle.merge()` returns null). `conflicted()` sets `mergeFails: true` on B: every Phase 1 notice test keeps exercising `enterConflict()`, `keepMine()` and `loadTheirs()` once Task 7 merges instead.
- Tab actions: `type(text)` (= `typeIn('p1', text)`), `typeIn(id, text)`, `insertAfter(afterId, newId, text)` (`afterId` null → the start), `deleteBlock(id)` (never the last block), `moveBlock(id, afterId)`, `setAttr(id, name, value)`; readers `blocks()`, `block(id)`, `lostTexts()`, `lostPark()`; flags `composing`, `mergeFails`, `insertBlockFails`; counters `mergeCalls`, `insertCalls`.
- Save records (`t.sent`, `t.saves`) carry `text` (of `p1`) beside `json`; beacon records carry `text` beside `body`.

- [ ] **Step 1: Count the tests as they are**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: `pass 162` (the cases including the four loops; the dry run counted them), `fail 0`. Write the number down: it must not change in this task.

- [ ] **Step 2: Create the shared world**

Create `tests/js/support/syncWorld.js`:

```js
import assert from 'node:assert/strict';

import { Schema } from 'prosemirror-model';

import { createSyncEngine } from '../../../resources/js/editor/sync/engine.js';
import { createSyncHost, unloadSaveBody } from '../../../resources/js/editor/sync/host.js';
import { mergeDocuments } from '../../../resources/js/editor/sync/merge.js';

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
 *   - a fake draft store (the live draft, the stale- park, the base- row and
 *     the lost- park), an injected clock and a captured timer;
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
     */
    replace(json) {
        this.trace.push({ version: this.version + 1, from: clone(this.json), to: clone(json), overwrite: true });
        this.kept.push({ version: this.version, json: clone(this.json) });
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
        mergeCalls: 0,
        insertCalls: [],
        /** This editor cannot open what arrives. */
        refuses: false,
        setContentThrows: false,
        /** The merge is impossible (handle.merge() returns null): the Phase 1 notice path. */
        mergeFails: options.mergeFails ?? false,
        insertBlockFails: false,
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
                  saveBase: async (uuid, json, baseVersion) => {
                      t.draftCalls.push('saveBase');
                      t.drafts.set(`base-${uuid}`, { json, baseVersion });
                  },
                  loadBase: async (uuid) => {
                      t.draftCalls.push('loadBase');

                      return t.drafts.get(`base-${uuid}`) ?? null;
                  },
                  parkLost: async (uuid, entries, baseVersion) => {
                      t.draftCalls.push('parkLost');
                      // As offline.js does: `entries` is rewritten, `dismissed` is carried over, the row's stamp goes.
                      const held = t.drafts.get(`lost-${uuid}`);
                      t.drafts.set(`lost-${uuid}`, {
                          entries: clone(entries),
                          dismissed: held ? clone(held.dismissed ?? []) : [],
                          baseVersion,
                          parkedAt: clock.now,
                          dismissedAt: null,
                      });
                  },
                  loadLost: async (uuid) => {
                      t.draftCalls.push('loadLost');
                      const row = t.drafts.get(`lost-${uuid}`);

                      return row ? { ...row, entries: clone(row.entries), dismissed: clone(row.dismissed ?? []) } : null;
                  },
                  markLostDismissed: async (uuid) => {
                      t.draftCalls.push('markLostDismissed');
                      const row = t.drafts.get(`lost-${uuid}`);
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
    t.lostTexts = () => t.view.lost.map((entry) => entry.excerpt);
    t.lostPark = () => t.drafts.get(LOST) ?? null;

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
```

- [ ] **Step 3: Replace the harness at the top of the host test**

In `tests/js/sync.host.test.js`, delete everything from line 1, which is

```js
import assert from 'node:assert/strict';
```

down to and including the `}` that closes `async function conflicted(options = {}) {` (line 526 today; the next non-blank line is `// ───────────────────────────────────────────────────────── following`), and put this in its place:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DISCARD_QUESTION,
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    SAVE_EXPIRY_MS,
    createSyncHost,
    createTabId,
    unloadSaveBody,
} from '../../resources/js/editor/sync/host.js';
import { STALE, UUID, conflicted, doc, makeTab, makeView, pair, settle, world } from './support/syncWorld.js';

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
```

Check: `head -1 tests/js/sync.host.test.js` prints `import assert from 'node:assert/strict';`, and `grep -n "following$" tests/js/sync.host.test.js | head -1` prints the banner on line 26.

- [ ] **Step 4: The mechanical substitutions**

The document is block-shaped now, so the text of block p1 is read through `server.text()`, a save record's `text` and a beacon record's `text`:

```bash
perl -pi -e 's/server\.json\.text/server.text()/g; s/\.json\.text\b/.text/g; s/beacon\.body\.content\.text/beacon.text/g' tests/js/sync.host.test.js
grep -c 'json\.text' tests/js/sync.host.test.js
grep -c 'server\.text()' tests/js/sync.host.test.js
grep -c 'beacon\.text' tests/js/sync.host.test.js
```

Expected: `0`, `65`, `3` (the 64 substitutions plus the mention of `server.text()` in the header docblock Step 3 put in; `grep -c` with no match prints `0` and exits 1, which is fine). Nothing else in the file changes: `createSyncEngine` is no longer imported (only the harness used it), and `settle`, `makeView`, `makeTab`, `world`, `doc`, `UUID`, `STALE` come from the support module.

- [ ] **Step 5: Run the host tests: the same count, all green**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: the number noted in Step 1 (`pass 162`), `fail 0`. A failure here is a harness fault, not a host fault: `host.js` has not changed. The dry run's one: an extra `{version, text}` row in `server.history` after `server.replace()` in seven Phase 1 cases, which means `replace()` is writing `history` (Step 2 writes `kept` and `trace` only). The usual ones: a `doc()` comparison against a JSON string the host built (`view.confirmed`, `view.setAside`), which means `para()` does not serialise as the schema does; or a `.text` on a record that has none.

Run: `npm test`
Expected: every JavaScript test passes (489 tests, as after Task 5: no case was added or removed).

- [ ] **Step 6: Commit**

```bash
git add tests/js/support/syncWorld.js tests/js/sync.host.test.js
git commit -m "test(editor): the sync host tests run on a block-shaped fake world

The harness moves to tests/js/support/syncWorld.js so the random walk can
share it. The fake document is block-shaped (paragraph p1, as the
editor's documents are), the fake handle merges with the real
mergeDocuments() over a mini schema and puts a lost block back as
insertBlock() does, the fake drafts keep the base row and the lost park,
and the view carries the seven Phase 2 fields. conflicted() makes B's
merge impossible, so every Phase 1 notice case keeps its meaning once
the host merges. No behaviour changes: host.js is untouched and the
test count is the same.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 7: The merge loop in `host.js`

The page merges instead of raising the notice. New: `markConfirmed()`, `beginMerge()`, `mergeOrConflict()`, `expireMerging()`, `offerLost()`, `sentenceFor()`. Amended: `saveInTheAir()`, `opened()`, `persist()`, `saveAnswered()`, `resendIfOwed()`, `syncState()`, `adoptVersion()`, `applyFromSync()`, `enterConflict()`, `applySuggestion()` (its `confirmed` write only), `engineHost()`. The Blade declares the seven fields the module writes. Put it back and Dismiss all for the lost row are Task 8; the Blade row itself is Task 9.

**Files:**
- Modify: `resources/js/editor/sync/host.js`
- Modify: `tests/js/sync.host.test.js`
- Modify: `resources/views/livewire/documents/editor.blade.php` (seven `x-data` fields)
- Modify: `tests/Feature/Documents/EditorSyncWiringTest.php` (one test)

**Interfaces:**
- Consumes: `handle.merge()`, `handle.composing` (Task 4), `drafts.saveBase()`, `drafts.parkLost()` (Task 5), `appendLost()`, `lostEntry()`, `blockWord()` (Task 1).
- Produces: `MERGE_ATTEMPTS = 5` (see Owner decisions), `MERGE_FETCH_EXPIRY_MS = SAVE_EXPIRY_MS`, `LOST_ROWS = 5` exported from `host.js`; on the host object `beginMerge(version)`, `mergeOrConflict(remote)`, `expireMerging()`, `markConfirmed(json, version)`, `offerLost(lost, source, version)`, `sentenceFor(reason, source, word)`.
- Decisions (dry run): `beginMerge()` is fail-closed-gated (nothing is fetched to be written into a read-only view) and, before it refetches, drops a pending document OLDER than the refused version with `engine.takeRemote()` (left in place, the `retry()` after the answered save would hand it to `mergeOrConflict()`, which would merge it, end `merging` and send the merged document on a base known to be stale). `mergeOrConflict()` resets `mergeAttempts` when the merge leaves nothing of mine to save (`result.changed` false): the row of refusals is over.
- Decisions (dry run 2): `saveAnswered()` ends a merge for the answered version or an older one (the late OK answer of a save given up on, arriving while the fetch is out: the fetch would bring the very version `engine.saved()` has just marked as seen, so it would never be delivered and `merging` would hold every save until the 15 s expiry raised a notice nobody caused); `mergeOrConflict()` ORs the overwrite only while `result.changed` (a merge that leaves nothing of mine to save sends nothing, so no save is left to carry the flag: it is cleared); `enterConflict()` drops a pending engine document OLDER than the version the notice names (`engine.takeRemote()`) before it refetches; `persist()` passes `overwrite && view.putBackSeq < seq` to `saveAnswered()`, so a save that left before a Put it back cannot settle the overwrite that put-back text owes (Task 8 sets `putBackSeq`; this task declares it, in `makeView()` and in the Blade).
- Sequence to know when reading the Phase 1 cases Step 2 amends: on a 200 poll the engine calls `host.onMembers()` (which runs `resendIfOwed()`) BEFORE `deliver()`, so a save given up on at 15 s is sent again on its old base first, the tab is `busy` when the document is delivered and the engine holds it; the merge happens when that resend is refused, with the held document in hand (`engine.pending`). Tests that move the clock past `SAVE_EXPIRY_MS` and then `cycle()` need a `land()` before they look at the merged state.
- Produces: the view fields `merging`, `mergeAttempts`, `known`, `sentSnapshot`, `unanswered`, `lost` with exactly the meanings of section 3.1, and (dry run 2) `putBackSeq`, the `saveSeq` at the moment Put it back last replaced the text. `known` is never nulled.
- Produces: `engineHost().onConflict` calls `mergeOrConflict(remote)`.
- Decisions: `adoptVersion()` updates `known` (and rewrites the base row) only when `known` was current (`known.version === baseVersion` before the base moves), so a base moved up by Keep mine is never given the pre-Keep-mine document as its known content; and it ends a merge whose version is at or below the adopted one the way it ends a conflict, by owing the text and sending it (the restyle changed only stamps). The base row is written through one helper, `writeBase()`, which is fail-closed-gated, so a read-only page writes no row.

- [ ] **Step 1: Write the failing tests**

In `tests/js/sync.host.test.js`, replace

```js
import { STALE, UUID, conflicted, doc, makeTab, makeView, pair, settle, world } from './support/syncWorld.js';
```

with

```js
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
    world,
} from './support/syncWorld.js';
```

and replace

```js
import {
    DISCARD_QUESTION,
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    SAVE_EXPIRY_MS,
    createSyncHost,
    createTabId,
    unloadSaveBody,
} from '../../resources/js/editor/sync/host.js';
```

with

```js
import {
    DISCARD_QUESTION,
    MERGE_ATTEMPTS,
    MERGE_FETCH_EXPIRY_MS,
    PUT_BACK_QUESTION,
    PUT_BACK_UNSAVED_WARNING,
    SAVE_EXPIRY_MS,
    createSyncHost,
    createTabId,
    unloadSaveBody,
} from '../../resources/js/editor/sync/host.js';
```

Then append at the end of the file:

```js

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
```

- [ ] **Step 2: Bring the Phase 1 cases that reach a refusal onto the Phase 2 paths**

The Phase 1 cases below are written against "a refused save raises the notice". From this task on a refused save starts a merge, and the notice is for a merge that is impossible. Each case below keeps its meaning through `mergeFails` (the merge is impossible) or is amended to what the page does now. One case (item 10) is replaced by the echo tests above. Every other Phase 1 case passes unchanged, the dry run found: in particular "a late answer the tab can still use is applied, and does not count against the save that replaced it" describes what still happens (the resend goes out before the poll's document is delivered, and refused against this tab's own version 2 it is merged as a no-op) and stays as it is. Still in `tests/js/sync.host.test.js`:

1. The refusal starts a merge now, and the notice comes only once the fetched document is in hand and the merge has proved impossible (`ready: true` straight away; the "first without the document" half is item 5's expiry path). Replace
```js
test('a tab with unsaved typing does not follow: the notice is raised, first without the document, then with it', async () => {
    const { server, A, B } = await pair();
```
with
```js
test('a tab with unsaved typing whose merge is impossible does not follow: the newer document is fetched, and the notice is raised with it', async () => {
    const { server, A, B } = await pair({ B: { mergeFails: true } });
```
and, in the same test, replace
```js
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
```
with
```js
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
```
(the rest of the test is unchanged).

2. Replace
```js
test('a newer version found by the poll while this tab is typing raises the notice with the document in hand', async () => {
    const { A, B } = await pair();
```
with
```js
test('a newer version found by the poll while this tab is typing raises the notice with the document in hand', async () => {
    const { A, B } = await pair({ B: { mergeFails: true } });
```

3. Replace
```js
test('Keep mine pressed inside the debounce saves what the editor holds, once', async () => {
    const { server, A, B } = await pair();
```
with
```js
test('Keep mine pressed inside the debounce saves what the editor holds, once', async () => {
    const { server, A, B } = await pair({ B: { mergeFails: true } });
```

4. Replace
```js
test('Load theirs inside the debounce replaces the typing, which is set aside, and nothing is sent', async () => {
    const { A, B } = await pair();
```
with
```js
test('Load theirs inside the debounce replaces the typing, which is set aside, and nothing is sent', async () => {
    const { A, B } = await pair({ B: { mergeFails: true } });
```

5. Replace
```js
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
```
with
```js
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
```

6. Replace
```js
test('a save that never answers does not stop the notice: when somebody else saves, the text is kept and asked about', async () => {
    const { server, clock, A, B } = await pair();
```
with
```js
test('a save that never answers does not stop the notice: when somebody else saves, the text is kept and asked about', async () => {
    const { server, clock, A, B } = await pair({ A: { mergeFails: true } });
```

7. Replace
```js
test('a tab whose save was rejected does not follow: its text exists nowhere else', async () => {
    const { server, A, B } = await pair();
```
with
```js
test('a tab whose save was rejected does not follow: its text exists nowhere else', async () => {
    const { server, A, B } = await pair({ A: { mergeFails: true } });
```

8. Replace
```js
test('after Keep mine the editor being back at the old confirmed document settles nothing: the base no longer names it', async () => {
    const { server, A, B } = await pair();
```
with
```js
test('after Keep mine the editor being back at the old confirmed document settles nothing: the base no longer names it', async () => {
    const { server, A, B } = await pair({ B: { mergeFails: true } });
```

9. Replace
```js
test('a restored draft whose save is refused is still in the draft store', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 });
```
with
```js
test('a restored draft whose save is refused is still in the draft store', async () => {
    const w = world();
    const A = await reopened(w, { json: JSON.stringify(doc('offline work')), baseVersion: 1 }, { mergeFails: true });
```

10. Delete the whole test
```js
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
    assert.equal(server.text(), 'A1');
    assert.equal(A.text(), 'A1');
});
```
(the echo tests above replace it: the limit is closed).

11. Replace
```js
    test(`an undo after a save whose answer was lost is not written off as Saved (${who} notices first)`, async () => {
        const { server, clock, B } = await pair();
```
with
```js
    test(`an undo after a save whose answer was lost is not written off as Saved (${who} notices first)`, async () => {
        // The merge is impossible here, so the refusal ends in the notice as
        // it did in Phase 1; with a merge the echo rule recognises the save
        // (see the echo cases) and the undo is simply stored.
        const { server, clock, B } = await pair({ B: { mergeFails: true } });
```

12. Replace
```js
test('a save that expired answers late while its own conflict is showing: the text typed since is sent', async () => {
    const { server, clock, A } = await pair();
```
with
```js
test('a save that expired answers late while its own conflict is showing: the text typed since is sent', async () => {
    const { server, clock, A } = await pair({ A: { mergeFails: true } });
```

13. In "adoptVersion inside the debounce, over text the debounce will not send again: the owed save sends it", replace
```js
    const styled = server.replace(server.json);
    A.saveArrives();
    await A.saveReturns();
    assert.deepEqual(A.view.conflict, { version: 2, ready: false });

    // A typo and a backspace, then the style change's own event arrives.
```
with
```js
    const styled = server.replace(server.json);
    A.saveArrives();
    await A.saveReturns();
    // Refused as stale: Phase 2 fetches the newer document to merge, and
    // holds the debounce meanwhile; the notice is for a merge that fails.
    assert.equal(A.view.conflict, null);
    assert.deepEqual(A.view.merging, { version: 2, since: A.env.now() });

    // A typo and a backspace, then the style change's own event arrives.
```

14. In "adoptVersion: a conflict at or below the adopted version clears, and the owed save is sent", replace
```js
    // The style change is stored first; the autosave that travelled with it is refused.
    const styled = server.replace(server.json);
    A.saveArrives();
    await A.saveReturns();
    assert.deepEqual(A.view.conflict, { version: 2, ready: false });

    A.host().adoptVersion(styled);
```
with
```js
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
```

15. In "adoptVersion: a conflict above the adopted version stands", replace
```js
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
```
with
```js
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
```

16. The base row is written when the page opens, once the draft has been looked for. Replace
```js
    assert.deepEqual(A.draftCalls, ['loadDraft']);
```
with
```js
    assert.deepEqual(A.draftCalls, ['loadDraft', 'saveBase']);
```
and replace
```js
    assert.deepEqual(again.draftCalls, ['loadDraft']);
```
with
```js
    assert.deepEqual(again.draftCalls, ['loadDraft', 'saveBase']);
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: FAIL before any case runs: `SyntaxError: The requested module '../../resources/js/editor/sync/host.js' does not provide an export named 'MERGE_ATTEMPTS'`.

- [ ] **Step 4: The loop in `host.js`**

Every edit below is in `resources/js/editor/sync/host.js`.

1. The import and the constants. Replace

```js
 * No DOM, no `window`, no Livewire, no clock and no console of its own:
 * everything outside the state object arrives in `env`.
 */

/** How long a save is waited for before it is taken as never answering. */
export const SAVE_EXPIRY_MS = 15000;
```

with

```js
 * No DOM, no `window`, no Livewire, no clock and no console of its own:
 * everything outside the state object arrives in `env`. Its one import is
 * ./merge.js, inside sync/: the helpers that build the lost row's entries.
 *
 * Phase 2 (the paragraph-level merge, docs/superpowers/specs/
 * 2026-10-16-coediting-phase-2-paragraph-merge-design.md): a newer document
 * that arrives while this tab holds unsaved typing is MERGED block by block
 * (mergeOrConflict()), applied over the typing and saved on the new base;
 * a save refused as stale fetches the newer document to merge it
 * (beginMerge()); the conflict notice is for a merge that cannot be done.
 * What the merge could not keep is offered back in the notice bar's lost
 * row (offerLost(), putBackLost(), dismissLost()).
 */

import { appendLost, blockWord, lostEntry } from './merge.js';

/** How long a save is waited for before it is taken as never answering. */
export const SAVE_EXPIRY_MS = 15000;

/**
 * How many merges after refused saves are tried in a row before the
 * conflict notice (section 5.4 of the Phase 2 spec; the spec's starting
 * value was 3, raised after the random walk of the dry run showed three
 * writers reaching it with every merge possible). Reset by an accepted
 * save, by a merge that leaves nothing of mine to save, by enterConflict()
 * and by applyFromSync().
 */
export const MERGE_ATTEMPTS = 5;

/** How long the fetch for a merge is waited for before the conflict notice (section 5.7). */
export const MERGE_FETCH_EXPIRY_MS = SAVE_EXPIRY_MS;

/** How many item slots the notice bar's lost row has; the markup in editor.blade.php mirrors it. */
export const LOST_ROWS = 5;

/** The sentence of each lost-row item, by reason and source (section 6.2). The `parked` source prefixes a stored sentence instead (restoreDraft()). */
const LOST_SENTENCES = {
    'both:merge': (word) => `This ${word} was changed elsewhere at the same moment. The other version is on the page; this is yours.`,
    'deleted:merge': (word) => `This ${word} was removed elsewhere while you were typing in it.`,
    'both:draft': (word) =>
        `This ${word} was changed in the document after your draft was written. The document's version is on the page; this is the draft's.`,
    'deleted:draft': (word) => `This ${word} was removed from the document after your draft was written.`,
    'both:suggestion': (word) =>
        `This ${word} was rewritten by the accepted suggestion. The suggestion's version is on the page; this is yours.`,
    'deleted:suggestion': (word) => `The accepted suggestion removed this ${word} while you were typing in it.`,
};
```

2. The view's typedef. Replace

```js
 * @property {string} aiError
 * @property {number} tick
 */
```

with

```js
 * @property {string} aiError
 * @property {number} tick
 * @property {{version: number, since: number}|null} merging set while a
 *           newer document is being fetched for a merge (beginMerge());
 *           persist() sends nothing while it is, syncState() reports dirty,
 *           and resendIfOwed() gives it up after MERGE_FETCH_EXPIRY_MS
 * @property {number} mergeAttempts merges after refused saves, in a row.
 *           Reset by an accepted save, by enterConflict() and by applyFromSync()
 * @property {{version: number, json: string}|null} known the last server
 *           document this tab held, as the editor serialises it, with its
 *           version. NEVER nulled: `confirmed` is nulled on purpose (a lost
 *           answer, a failed request, Keep mine) while the last agreed
 *           document is still the right merge base, as long as its version
 *           is the one the typing is based on
 * @property {string|null} sentSnapshot the JSON string of the save now in the air
 * @property {string|null} unanswered the JSON string of the last save given
 *           up on or whose request failed; the merge's echo (section 4.2).
 *           Null once anything is confirmed
 * @property {object[]} lost the lost row's entries (section 6.1), oldest first
 */
```

3. `saveInTheAir()`: the lost save becomes the echo. Replace

```js
        if (view.saving > 0 && env.now() - view.savingSince >= SAVE_EXPIRY_MS) {
            view.saving = 0;
            view.resave = true;
            view.confirmed = null;
            view.expiredSaveSeq = view.saveSeq;
        }
```

with

```js
        if (view.saving > 0 && env.now() - view.savingSince >= SAVE_EXPIRY_MS) {
            view.saving = 0;
            view.resave = true;
            view.confirmed = null;
            // The save may have been stored. If the next merge finds its
            // document on the server, that change is this tab's own.
            view.unanswered = view.sentSnapshot;
            view.expiredSaveSeq = view.saveSeq;
        }
```

4. `opened()`. Replace

```js
    function opened() {
        const handle = env.handle();

        view.confirmed = JSON.stringify(handle.editor.getJSON());
        env.report(
```

with

```js
    function opened() {
        const handle = env.handle();

        // Confirmed and known; the base row is written at the END of
        // restoreDraft(): a draft of an earlier version is merged against
        // the row of ITS version, which this page must read before it
        // writes its own.
        markConfirmed(JSON.stringify(handle.editor.getJSON()), view.baseVersion, { writeRow: false });
        env.report(
```

5. `persist()`: the hold while a merge is under way, and the snapshot of the save in the air. Replace

```js
        if (saveInTheAir() && !force) {
            view.resave = true;

            return Promise.resolve();
        }

        // An unresolved conflict: every save would be refused. The draft
        // keeps the text; the notice asks the question.
        if (view.conflict && !force) {
```

with

```js
        if (saveInTheAir() && !force) {
            view.resave = true;

            return Promise.resolve();
        }

        // A newer document is being fetched to merge: the base this save
        // would state is known to be stale, so nothing goes out until the
        // merge has run (it sends the merged document itself). Owed, so
        // nothing typed meanwhile is dropped.
        if (view.merging && !force) {
            view.resave = true;

            return Promise.resolve();
        }

        // An unresolved conflict: every save would be refused. The draft
        // keeps the text; the notice asks the question.
        if (view.conflict && !force) {
```

Replace

```js
        // The owed save is this one.
        view.resave = false;
        view.saving++;
```

with

```js
        // The owed save is this one.
        view.resave = false;
        // What is in the air, for the echo rule should its answer be lost.
        view.sentSnapshot = snapshot;
        view.saving++;
```

Replace

```js
                view.saving = Math.max(0, view.saving - 1);
                view.confirmed = null;
                env.report('danger', 'Not saved');
                env.engine()?.retry();
```

with

```js
                view.saving = Math.max(0, view.saving - 1);
                view.confirmed = null;
                // The server may have stored it all the same (section 5.6).
                view.unanswered = view.sentSnapshot;
                env.report('danger', 'Not saved');
                env.engine()?.retry();
```

5b. `persist()`: a Put it back pressed after a save left must not be settled by that save's answer. In the handler that runs when the save is answered, replace

```js
                    saveAnswered(result, snapshot, overwrite);
```

with

```js
                    // A Put it back pressed after this save left replaced the
                    // text it carried: its answer must not write off the
                    // overwrite that text still owes.
                    saveAnswered(result, snapshot, overwrite && view.putBackSeq < seq);
```

(`seq` is the number `persist()` gave this save, `const seq = ++view.saveSeq`; `putBackSeq` is set by `putBack()` in Task 8 Step 3 and declared in the Blade in Step 5b, and `makeView()` in the fake world carries it from Task 6. Until Task 8 sets it, it stays 0 and the expression is `overwrite`, as before.)

6. `saveAnswered()`: an accepted save is confirmed and ends the count; a refusal starts a merge. Replace

```js
        if (result && result.ok) {
            view.baseVersion = result.version;
            view.conflict = null;
            view.confirmed = snapshot;
```

with

```js
        if (result && result.ok) {
            view.baseVersion = result.version;
            view.conflict = null;
            view.mergeAttempts = 0;
            markConfirmed(snapshot, result.version);
            // A merge under way for this version or an older one is over: the
            // fetch it started would bring the very version this answer
            // names, which engine.saved() below marks as seen, so it would
            // never be delivered and `merging` would hold every save until
            // the 15 s expiry raised a notice nobody caused (a late answer of
            // a save given up on, arriving while the fetch is out).
            if (view.merging && view.merging.version <= result.version) {
                view.merging = null;
                view.resave = true;
            }
```

and replace

```js
        } else if (result && result.conflict) {
            enterConflict(result.version);
        } else {
            env.report('danger', 'Not saved');
        }
```

with

```js
        } else if (result && result.conflict) {
            // Refused as stale (section 5.4): the text is still owed. The
            // writer choosing in the notice is never merged behind their
            // back; the refusal after MERGE_ATTEMPTS merges in a row ends in
            // the notice too; else the newer document is fetched and merged.
            view.resave = true;
            if (view.conflict || view.mergeAttempts >= MERGE_ATTEMPTS) {
                enterConflict(result.version);
            } else {
                view.mergeAttempts += 1;
                beginMerge(result.version);
            }
        } else {
            env.report('danger', 'Not saved');
        }
```

7. `resendIfOwed()`: give up a fetch that never answers. Replace

```js
    function resendIfOwed() {
        const handle = env.handle();
        // Fail-closed: nothing is written back.
        if (!handle || handle.autosaves === false) {
            return;
        }

        if (saveInTheAir()) {
            return;
        }
```

with

```js
    function resendIfOwed() {
        // A fetch for a merge that has not answered in fifteen seconds ends
        // in the notice; the owed save goes out after the writer's choice.
        expireMerging();

        const handle = env.handle();
        // Fail-closed: nothing is written back.
        if (!handle || handle.autosaves === false) {
            return;
        }

        if (saveInTheAir()) {
            return;
        }
```

8. `syncState()`: busy under an IME composition. Replace

```js
        if (saveInTheAir()) {
            return 'busy';
        }

        // After the save-in-the-air check, never before it.
        settleIfBackAtConfirmed(handle);
```

with

```js
        if (saveInTheAir()) {
            return 'busy';
        }

        // Never apply a document under an IME composition: it would land in
        // the middle of a character being composed. The next poll or
        // retry() delivers.
        if (handle.composing) {
            return 'busy';
        }

        // After the save-in-the-air check, never before it.
        settleIfBackAtConfirmed(handle);
```

9. `adoptVersion()`: `known` moves with a restyle, and a merge at or below the adopted version is over. Replace

```js
        view.baseVersion = version;
        env.engine()?.saved(version);
        // An autosave that travelled with that action may have been
        // refused against the version the action itself produced.
        if (view.conflict && view.conflict.version <= version) {
            view.conflict = null;
            view.resave = true;
            resendIfOwed();
        }
    }
```

with

```js
        // A restyle changes only stamps, which the merge's levelling
        // absorbs: the last known document is still the right merge base,
        // and it is now of this version. Only when `known` was current:
        // after Keep mine the base has moved past it, and it must not be
        // given the version the editor never held. applySuggestion() loads
        // the content itself and confirms it itself.
        const knownIsCurrent = !contentLoaded && view.known !== null && view.known.version === view.baseVersion;

        view.baseVersion = version;
        env.engine()?.saved(version);

        if (knownIsCurrent) {
            view.known = { version, json: view.known.json };
            writeBase(view.known.json, version);
        }

        // An autosave that travelled with that action may have been
        // refused against the version the action itself produced: the
        // merge it started, or the notice it raised, is over, and the owed
        // text goes out on the adopted base.
        if (view.merging && view.merging.version <= version) {
            view.merging = null;
            view.mergeAttempts = 0;
            view.resave = true;
            resendIfOwed();
        }
        if (view.conflict && view.conflict.version <= version) {
            view.conflict = null;
            view.resave = true;
            resendIfOwed();
        }
    }
```

10. `applyFromSync()`: a follow ends a merge and is confirmed through `markConfirmed()`. Replace

```js
        view.baseVersion = remote.version;
        view.unsaved = false;
        view.confirmed = JSON.stringify(handle.editor.getJSON());
        // Whatever this tab had put back is no longer in the editor.
        view.overwriteOwed = false;
```

with

```js
        view.baseVersion = remote.version;
        view.unsaved = false;
        // A merge under way is over: the tab was clean after all (it undid
        // everything while the fetch was out), so it follows instead.
        view.merging = null;
        view.mergeAttempts = 0;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), remote.version);
        // Whatever this tab had put back is no longer in the editor.
        view.overwriteOwed = false;
```

11. After `applyFromSync()` (its closing `}` is followed by a blank line and the docblock that begins `     * A newer version exists and this tab has unsaved typing. Stop saving`), insert before that docblock:

```js
    /**
     * The server document this tab now holds is confirmed: `confirmed` and
     * `known` are set to it, the echo is forgotten, and the base row is
     * written so a draft of this version can be merged on a later visit.
     *
     * Called from opened() (the page document), saveAnswered() on ok (the
     * snapshot that was stored), applyFromSync() and applySuggestion() (the
     * editor's own serialisation of what was applied) and mergeOrConflict()
     * (theirs, or the editor's serialisation when nothing of mine survived).
     * `confirmed = null` stays a direct assignment where it is (a lost
     * answer, a failed request, Keep mine); `known` is never nulled.
     *
     * @param {string} json the document as a JSON string
     * @param {number} version the version it is
     * @param {{writeRow?: boolean}} options `writeRow: false` is opened()'s:
     *        the row is written once the draft check has read the old one
     */
    function markConfirmed(json, version, { writeRow = true } = {}) {
        view.confirmed = json;
        view.known = { version, json };
        view.unanswered = null;
        if (writeRow) {
            writeBase(json, version);
        }
    }

    /** The base row, never in fail-closed mode: what a read-only view holds is not the document. */
    function writeBase(json, version) {
        const handle = env.handle();
        if (!handle || handle.autosaves === false) {
            return;
        }

        env.drafts()?.saveBase(view.docUuid, json, version);
    }

    /**
     * A save was refused as stale: fetch the newer document and merge the
     * typing into it (section 5.4). When the engine already holds a document
     * at least that new, merge it at once.
     *
     * refetch(), not poke(): the engine may have SEEN that version already,
     * and an ordinary poll would be told nothing changed.
     *
     * @param {number} version the version the server is at
     */
    function beginMerge(version) {
        const handle = env.handle();
        // Fail-closed: nothing is fetched to be written into a read-only
        // view (and expireMerging() would raise "Not saved" on it 15 s on).
        if (!handle || handle.autosaves === false) {
            return;
        }

        const engine = env.engine();
        if (!engine) {
            enterConflict(version);

            return;
        }

        const waiting = engine.pending;
        if (waiting && waiting.version >= version) {
            mergeOrConflict(waiting);

            return;
        }

        // A document OLDER than the refused version (polled while the save
        // was in the air) is dropped: the fetch below replaces it. Left in
        // place, the retry() that follows every answered save would hand it
        // to mergeOrConflict(), which would merge it, end `merging` and send
        // the merged document on a base known to be stale: a refusal for
        // nothing, and one of the attempts gone.
        if (waiting) {
            engine.takeRemote();
        }

        view.merging = { version, since: env.now() };
        engine.refetch(view.baseVersion);
    }

    /**
     * A fetch for a merge that has not answered in MERGE_FETCH_EXPIRY_MS is
     * given up on: the notice stands, the engine's own back-off keeps
     * polling, and the owed save goes out after the writer's choice.
     */
    function expireMerging() {
        if (view.merging && env.now() - view.merging.since >= MERGE_FETCH_EXPIRY_MS) {
            const version = view.merging.version;
            view.merging = null;
            enterConflict(version);
        }
    }

    /**
     * A newer server document has arrived while this tab holds unsaved
     * typing: merge it, or raise the notice when that cannot be done
     * (section 5.5). The engine calls this through onConflict (a poll
     * brought the document to a dirty tab) and beginMerge() calls it (the
     * fetch after a refused save answered, or the engine already held it).
     *
     * @param {{version: number, json: object, outline: object|null, css: string|null}} remote
     */
    function mergeOrConflict(remote) {
        const handle = env.handle();
        // 1. Fail-closed: nothing.
        if (!handle || handle.autosaves === false) {
            return;
        }

        // 2. The notice stands: the writer is choosing, and nothing is
        //    merged behind their back. The notice learns the newer version.
        if (view.conflict) {
            enterConflict(remote.version);

            return;
        }

        // 3. A save in the air: the engine retries after its answer.
        if (saveInTheAir()) {
            return;
        }

        // 4. The base: the confirmed document, or the last known one when
        //    nothing is confirmed (a lost answer, a failed request) and it
        //    is of the version the typing is based on. After Keep mine the
        //    base names a version the editor never held: no base, so the
        //    notice, until a save is accepted.
        const base =
            view.confirmed ?? (view.known !== null && view.known.version === view.baseVersion ? view.known.json : null);
        if (base === null) {
            view.merging = null;
            enterConflict(remote.version);

            return;
        }

        // 5. The merge. The echo is the document of a save whose answer was
        //    lost: wherever theirs agrees with it, that change is this tab's
        //    own and typing on top of it is kept, not offered back.
        let result = null;
        try {
            result = handle.merge(JSON.parse(base), remote.json, {
                echo: view.unanswered ? JSON.parse(view.unanswered) : null,
            });
        } catch (_) {
            result = null;
        }
        if (!result) {
            view.merging = null;
            enterConflict(remote.version);

            return;
        }

        // 6. The merged document goes in, over the typing it holds.
        let applied = false;
        try {
            applied = handle.applyRemote(result.merged, { force: true }) === true;
        } catch (_) {
            applied = false;
        }
        if (!applied) {
            view.merging = null;
            enterConflict(remote.version);

            return;
        }

        // 7. The bookkeeping. `confirmed` is theirs as this editor serialises
        //    it (plus the carried trailing paragraph) when something of mine
        //    survived, which is what the merged save is based on; when
        //    nothing did, the editor's own serialisation, so the settle check
        //    can find the editor back at it. The overwrite is ORed, never
        //    assigned, while something of mine is left to save: one owed from
        //    Put it back or from an earlier c2 merge survives a merge that owes
        //    none, until a save that carried it is accepted. A merge that
        //    leaves nothing of mine to save (changed false) sends nothing, so
        //    no save is left to carry the flag: it is cleared.
        const wasUnsaved = view.unsaved;
        view.merging = null;
        view.baseVersion = remote.version;
        view.conflict = null;
        markConfirmed(result.changed ? result.theirs : JSON.stringify(handle.editor.getJSON()), remote.version);
        view.overwriteOwed = result.changed ? view.overwriteOwed || result.overwrites : false;
        view.unsaved = result.changed;
        if (!result.changed) {
            view.mergeAttempts = 0; // nothing owed: the row of refusals is over
        }
        if (result.lost.length > 0) {
            offerLost(result.lost, 'merge', remote.version);
        }
        env.engine()?.saved(remote.version);
        if (remote.outline) {
            env.showOutline(remote.outline);
        }
        if (remote.css) {
            env.showCss(remote.css);
        }
        view.tick++;

        // 8. The draft: of the merged document while something of mine is
        //    unsaved (stamped one below while an overwrite is owed, as
        //    edited() stamps it), cleared when the server holds everything.
        const drafts = env.drafts();
        if (drafts) {
            if (result.changed) {
                drafts.saveDraft(
                    view.docUuid,
                    JSON.stringify(handle.editor.getJSON()),
                    view.overwriteOwed ? view.baseVersion - 1 : view.baseVersion
                );
            } else {
                drafts.clearDraft(view.docUuid);
            }
        }

        // 9. Something of mine survived: it is saved on the new base, now.
        //    Nothing did: this was a follow in disguise, and the word goes
        //    back to Saved once.
        if (result.changed) {
            view.resave = true;
            persist(handle.editor.getJSON());
        } else if (wasUnsaved) {
            env.report('good', 'Saved');
        }
    }

    /**
     * What a merge could not keep goes into the lost row (section 6.1).
     * Append, never replace: a paragraph lost twice in a row keeps both
     * offered versions. The whole list is parked under lost-<uuid>, its own
     * key, so a reload re-offers it and Load theirs' stale- park is not
     * overwritten.
     *
     * @param {object[]} lost the merge result's losses
     * @param {'merge'|'draft'|'suggestion'} source
     * @param {number} version the version the merge was against
     */
    function offerLost(lost, source, version) {
        const entries = lost.map((loss, n) =>
            lostEntry(loss, { source, version, n, sentence: sentenceFor(loss.reason, source, blockWord(loss.type)) })
        );
        view.lost = appendLost(view.lost, entries);
        env.drafts()?.parkLost(view.docUuid, view.lost, view.baseVersion);
        env.info(`[Dot.Doc] ${entries.length} block(s) could not be kept by the ${source} and are offered back in the notice bar.`);
    }

    /**
     * The sentence of a lost-row item (section 6.2).
     *
     * @param {'both'|'deleted'} reason
     * @param {'merge'|'draft'|'suggestion'} source
     * @param {string} word blockWord() of the block's type
     * @returns {string}
     */
    function sentenceFor(reason, source, word) {
        return LOST_SENTENCES[`${reason}:${source}`](word);
    }

```

12. `enterConflict()`: the notice ends a merge. Replace

```js
    function enterConflict(version) {
        const engine = env.engine();
        const waiting = engine ? engine.pending : null;

        view.conflict = {
            version: Math.max(
                Number.isFinite(version) ? version : 0,
                view.conflict ? view.conflict.version : 0,
                waiting ? waiting.version : 0
            ),
            ready: !!waiting,
        };
```

with

```js
    function enterConflict(version) {
        // The notice stands: no merge is tried behind the writer's back, and
        // the count of merges after refusals starts over after it.
        view.merging = null;
        view.mergeAttempts = 0;

        const engine = env.engine();
        let waiting = engine ? engine.pending : null;
        const named = Math.max(Number.isFinite(version) ? version : 0, view.conflict ? view.conflict.version : 0);

        // A document the engine holds that is OLDER than the version the
        // notice names is not "theirs": Load theirs would show a version the
        // head has moved past (a Keep mine save refused by a still newer
        // version leaves exactly that). It is dropped, and the refetch below
        // brings the newest.
        if (waiting && waiting.version < named) {
            engine.takeRemote();
            waiting = null;
        }

        view.conflict = {
            version: Math.max(named, waiting ? waiting.version : 0),
            ready: !!waiting,
        };
```

13. `applySuggestion()`: confirmed through `markConfirmed()`. Replace

```js
        view.unsaved = false;
        view.confirmed = JSON.stringify(handle.editor.getJSON());
        view.overwriteOwed = false;
        adoptVersion(version, { contentLoaded: true });
```

with

```js
        view.unsaved = false;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), version);
        view.overwriteOwed = false;
        adoptVersion(version, { contentLoaded: true });
```

14. `engineHost()`: a dirty tab merges. Replace

```js
            onConflict: (remote) => enterConflict(remote.version),
```

with

```js
            // A newer document while this tab holds unsaved typing: merged,
            // or the notice when that cannot be done.
            onConflict: (remote) => mergeOrConflict(remote),
```

14b. `restoreDraft()` writes the base row once the draft logic has run. The draft logic itself moves, unchanged, into `restoreDraftIfRestorable()`. Replace

```js
     * @returns {Promise<void>}
     */
    async function restoreDraft() {
        const drafts = env.drafts();
        if (!drafts) {
            return;
        }

        const handle = env.handle();
        // Fail-closed: the editor is read-only and holds something that is
        // not the document, so neither restore a draft nor delete one.
        if (!handle || handle.autosaves === false) {
            return;
        }

        const editor = handle.editor;

        try {
            const draft = await drafts.loadDraft(view.docUuid);
```

with

```js
     * The base row (the document this tab's typing is based on, for the
     * draft merge of a later visit) is written here, AFTER the draft logic:
     * a draft of an earlier version is merged against the row of its own
     * version, which must be read before this page writes its own.
     *
     * @returns {Promise<void>}
     */
    async function restoreDraft() {
        const drafts = env.drafts();
        if (!drafts) {
            return;
        }

        const handle = env.handle();
        // Fail-closed: the editor is read-only and holds something that is
        // not the document, so neither restore a draft nor delete one.
        if (!handle || handle.autosaves === false) {
            return;
        }

        await restoreDraftIfRestorable(drafts, handle);
        writeBase(view.confirmed, view.baseVersion);
    }

    /**
     * The draft logic of restoreDraft(): what is done with the live draft
     * found for this document, if any.
     *
     * @param {object} drafts `window.offlineDraft`
     * @param {object} handle the editor handle, mounted and not fail-closed
     * @returns {Promise<void>}
     */
    async function restoreDraftIfRestorable(drafts, handle) {
        const editor = handle.editor;

        try {
            const draft = await drafts.loadDraft(view.docUuid);
```

15. The module's return. Replace

```js
        enterConflict,
        keepMine,
```

with

```js
        enterConflict,
        beginMerge,
        mergeOrConflict,
        expireMerging,
        markConfirmed,
        offerLost,
        sentenceFor,
        keepMine,
```

- [ ] **Step 5a: Pin the seven fields in the wiring test first**

In `tests/Feature/Documents/EditorSyncWiringTest.php`, directly after the method `test_the_strip_does_not_say_saved_while_the_page_holds_unsaved_text()` (its closing `    }`), add:

```php

    /**
     * The merge (resources/js/editor/sync/host.js, Phase 2) writes seven more
     * state fields. Like every other, each is declared in the component's
     * own object, never in the module.
     */
    public function test_the_page_declares_the_merge_state_fields_the_host_writes(): void
    {
        $html = $this->editorHtml();

        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        foreach (['merging: null,', 'mergeAttempts: 0,', 'known: null,', 'sentSnapshot: null,', 'unanswered: null,', 'lost: [],', 'putBackSeq: 0,'] as $field) {
            $this->assertMatchesRegularExpression('/^\s*'.preg_quote($field, '/').'$/m', $alpine[1], "{$field} is declared");
        }
    }
```

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php`
Expected: 1 FAIL, 10 pass: the new test fails with `merging: null, is declared`.

- [ ] **Step 5b: Declare the seven fields in the Blade**

In `resources/views/livewire/documents/editor.blade.php`, replace

```js
        setAsideFrom: null,
        setAsideBase: null,
        // A reason the page can no longer stay in step (signed out, access
```

with

```js
        setAsideFrom: null,
        setAsideBase: null,
        // Phase 2, the paragraph-level merge (sync/host.js writes all seven).
        // merging: {version, since} while a newer document is being fetched
        // to merge the typing into; persist() sends nothing meanwhile.
        merging: null,
        // Merges after refused saves, in a row: MERGE_ATTEMPTS (5, sync/host.js) end in the notice.
        mergeAttempts: 0,
        // The last server document this tab held, {version, json}, as the
        // editor serialises it. Never nulled: confirmed is nulled on purpose
        // (a lost save answer, Keep mine) while this is still the merge base.
        known: null,
        // The JSON string of the save in the air, and of the last save given
        // up on or whose request failed: the echo a merge recognises as this
        // tab's own change (section 5.6 of the Phase 2 spec).
        sentSnapshot: null,
        unanswered: null,
        // What a merge could not keep, oldest first: the lost row offers it
        // back (section 6 of the Phase 2 spec).
        lost: [],
        // The saveSeq at the moment Put it back last replaced the text: a save sent at or before it did not carry that text.
        putBackSeq: 0,
        // A reason the page can no longer stay in step (signed out, access
```

(Single quotes and apostrophes only: a double quote anywhere in the attribute, comments included, cuts it short. The awk line in Task 9 Step 6 finds one.)

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 6: Run everything**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: PASS. 162 - 1 (the deleted stated-limit test) + 24 new + 1 (the dry run's older-document case) + 4 (dry run 2: the dropped overwrite, the late OK answer, Put it back followed by a merge with nothing of mine to save, the older document under the notice) = 190 tests, `fail 0`. Where a Phase 1 case not listed in Step 2 fails because a merge ran where the notice was expected, the fix is `mergeFails: true` on the tab whose merge must be impossible, and the case joins Step 2's list; where a `draftCalls` assertion fails on a `saveBase` it did not expect, the base row is being written on open and the expected list gains `'saveBase'` first; where a case that moves the clock past `SAVE_EXPIRY_MS` and then `cycle()`s sees the state before the merge (a notice `null`, a block missing), the resend went out before the document was delivered and the case needs a `land()` (see Interfaces).

Run: `npm test && npm run build`
Expected: every JavaScript test passes (517 tests: 489 + 28) and the build succeeds.

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php tests/Feature/Documents/ContextualToolbarTest.php`
Expected: PASS (`EditorSyncWiringTest` 11 tests: the 10 there today and the new one). If `test_the_editors_alpine_component_survives_being_an_html_attribute` fails, a double quote has got into the attribute; the awk line in Task 9 Step 6 finds it.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/editor/sync/host.js tests/js/sync.host.test.js resources/views/livewire/documents/editor.blade.php tests/Feature/Documents/EditorSyncWiringTest.php
git commit -m "feat(editor): merge a newer document into unsaved typing instead of raising the notice

A newer version that arrives while a tab holds unsaved typing is merged
block by block (mergeOrConflict) and the merged document saved on the
new base; a save refused as stale fetches the newer document to merge
it (beginMerge), up to MERGE_ATTEMPTS (5) times in a row, and a fetch that never
answers ends in the notice after fifteen seconds. The notice is for a
merge that cannot be done, and nothing is merged behind a writer who is
choosing. A block both people changed, or one person deleted while the
other typed in it, is recorded in the lost list and parked, oldest
first. The last confirmed document and its version are kept in known
(never nulled) and written to the base row; a save whose answer was lost
is remembered as the echo, so the tab recognises its own stored save
and keeps typing on top of it. An owed overwrite is ORed through a
merge that leaves something of mine to save, and dropped by one that
leaves nothing. The late answer of a save given up on ends a merge for
its version, and the notice drops an engine document older than the
version it names. The tab is busy under an IME composition. The Blade
declares the seven fields the host writes.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 8: Put it back, Put them all back, Dismiss all, and the re-offer after a reload

The lost row's three actions in `host.js`, and the re-offer of a parked list when the page opens. The row's markup is Task 9; until then these run only under `node --test`.

**Files:**
- Modify: `resources/js/editor/sync/host.js`
- Modify: `tests/js/sync.host.test.js`

**Interfaces:**
- Consumes: `handle.insertBlock()` (Task 4), `drafts.parkLost()`, `drafts.loadLost()`, `drafts.markLostDismissed()` (Task 5).
- Produces: `DISMISS_LOST_QUESTION` exported from `host.js`; on the host object `putBackLost(index) -> boolean`, `putBackAllLost()`, `dismissLost()`, as section 6.4–6.6 define them. `putBackLost()` is fail-closed-gated and does nothing for a missing entry; on success it removes the entry and rewrites the park with the rest (an EMPTY list when none is left: the row then has nothing to re-offer); on failure the entry stays and `syncNotice` says "Your text could not be put back.". `putBackAllLost()` walks the list oldest first and stops at the first failure. `dismissLost()` does nothing with an empty list, always asks otherwise, and on yes empties the list and stamps the park (`markLostDismissed()` moves the entries to the row's `dismissed` list, which every later `parkLost()` carries over: the 7 days the button promises hold through later losses); it is not fail-closed-gated.
- Produces (dry run 2): `LOAD_THEIRS_REPLACES_QUESTION` exported from `host.js`; `loadTheirs()` asks it, before the engine's document is taken (so a no changes nothing), when a text is already set aside and the engine holds a document; `putBack()` stamps `view.putBackSeq = view.saveSeq` once the text is in, so the answer of a save that left before it cannot write off the overwrite the put-back text owes (see Task 7 Step 4, item 5b).
- Produces: `restoreDraft()` re-offers a parked list that is not dismissed, each entry with `source: 'parked'` and its sentence prefixed "From your last visit: " (an entry already parked as such is not prefixed twice). The re-offer runs BEFORE the draft logic (dry run; spec 6.6 said after): `offerLost()` parks the WHOLE list, so a draft merge's losses are appended after the re-offered ones and keep their own source, and an earlier visit's park is read before anything overwrites it. The draft logic itself moves unchanged into `restoreDraftIfRestorable(drafts, handle)`, which Task 10 extends. The park is read on EVERY open from now on: two Phase 1 open-path assertions gain `'loadLost'` (Step 3b).
- Decision: "clear the park when empty" is written as a park of the empty list (`parkLost(uuid, [], baseVersion)`); `offline.js` needs no delete.

- [ ] **Step 1: Write the failing tests**

In `tests/js/sync.host.test.js`, replace

```js
import {
    DISCARD_QUESTION,
    MERGE_ATTEMPTS,
```

with

```js
import {
    DISCARD_QUESTION,
    DISMISS_LOST_QUESTION,
    LOAD_THEIRS_REPLACES_QUESTION,
    MERGE_ATTEMPTS,
```

and append at the end of the file:

```js

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
    B.host().dismissLost();
    assert.deepEqual(B.questions, [DISMISS_LOST_QUESTION]);
    assert.deepEqual(B.lostTexts(), ['Hello B']);
    assert.equal(B.lostPark().dismissedAt, null);

    B.confirmAnswer = true;
    B.host().dismissLost();
    assert.deepEqual(B.view.lost, []);
    assert.deepEqual(B.lostPark().entries, []);
    assert.deepEqual(B.lostPark().dismissed.map((entry) => entry.excerpt), ['Hello B'], 'the park keeps the dismissed text for its 7 days');
    assert.equal(B.lostPark().dismissedAt, B.env.now());
    assert.equal(B.text(), 'Hello A');
    assert.equal(B.sent.length, 0);

    B.host().dismissLost();
    assert.equal(B.questions.length, 2, 'nothing to dismiss: no question');
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
    assert.deepEqual(again.draftCalls, ['loadLost', 'loadDraft', 'saveBase'], 'the park is read before the draft');

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
    other.B.host().dismissLost();
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
    B.host().dismissLost();
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

    R.host().dismissLost();
    assert.deepEqual(R.view.lost, []);
    assert.deepEqual(R.questions, [DISMISS_LOST_QUESTION]);
    assert.deepEqual(R.draftCalls, ['markLostDismissed'], 'the park is stamped; no draft is touched');
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: FAIL before any case runs: `SyntaxError: The requested module '../../resources/js/editor/sync/host.js' does not provide an export named 'DISMISS_LOST_QUESTION'`.

- [ ] **Step 3: The three actions and the re-offer**

In `resources/js/editor/sync/host.js`:

1. The question. Replace

```js
export const DISCARD_QUESTION =
    'Discard this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';
```

with

```js
export const DISCARD_QUESTION =
    'Discard this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';

/**
 * What Load theirs asks when a text is already set aside and offered back: the
 * new text takes the offer's place, and the earlier one is then in no version
 * and in no park.
 */
export const LOAD_THEIRS_REPLACES_QUESTION =
    'A text is already set aside from earlier. Loading theirs sets your text on the page aside instead, and the earlier text will be gone. Continue?';

/**
 * What Dismiss all asks before it empties the lost row (section 6.5). Always
 * asked: every entry is text a merge established is on the page nowhere.
 */
export const DISMISS_LOST_QUESTION =
    'Dismiss this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';
```

2. The actions. Directly after `sentenceFor()` (its closing `}` is followed by a blank line and the docblock that begins `     * A newer version exists and this tab has unsaved typing. Stop saving`), insert before that docblock:

```js
    /**
     * Put it back (section 6.4): the lost block at `index` goes back on the
     * page, below the version that won (a `both` block, with a fresh id) or
     * where it was before it was removed (a `deleted` block, with its own
     * id, so comments and cross-references on it come back). An ordinary
     * writer's edit: undoable, autosaved on the current base, no overwrite
     * owed. The entry goes, and the park is rewritten with the rest.
     *
     * @param {number} index
     * @returns {boolean} whether the block went in
     */
    function putBackLost(index) {
        const handle = env.handle();
        // Fail-closed: nothing may be written into the editor.
        if (!handle || handle.autosaves === false) {
            return false;
        }

        const entry = view.lost[index];
        if (!entry) {
            return false;
        }

        let inserted = false;
        try {
            inserted = handle.insertBlock(entry.json, { anchors: entry.anchors, keepId: entry.reason === 'deleted' }) === true;
        } catch (_) {
            inserted = false;
        }
        if (!inserted) {
            view.syncNotice = 'Your text could not be put back.';

            return false;
        }

        view.lost = view.lost.filter((_, at) => at !== index);
        env.drafts()?.parkLost(view.docUuid, view.lost, view.baseVersion);

        return true;
    }

    /** Put them all back: oldest first; the first that cannot go in stops it, and stays listed. */
    function putBackAllLost() {
        while (view.lost.length > 0) {
            if (!putBackLost(0)) {
                return;
            }
        }
    }

    /**
     * Dismiss all (section 6.5): the row is emptied; the park stays for its
     * 7 days and is stamped dismissed, so the next visit does not offer it
     * again (markLostDismissed() moves the entries to the row's `dismissed`
     * list, which the next loss's parkLost() carries over). Always asked,
     * because every entry is on the page nowhere. Not fail-closed-gated:
     * like discardSetAside(), it touches neither the editor nor a draft.
     */
    function dismissLost() {
        if (view.lost.length === 0) {
            return;
        }

        if (!env.confirm(DISMISS_LOST_QUESTION)) {
            return;
        }

        view.lost = [];
        env.drafts()?.markLostDismissed(view.docUuid);
    }

    /**
     * The re-offer after a reload (section 6.6): a parked list that was not
     * dismissed comes back into the lost row, each entry marked as from the
     * last visit. Two tabs of one browser share the row (it is keyed per
     * document, as drafts are): known, stated.
     */
    async function reofferLost(drafts) {
        let row = null;
        try {
            row = await drafts.loadLost(view.docUuid);
        } catch (_) {
            row = null;
        }
        if (!row || row.dismissedAt || !Array.isArray(row.entries) || row.entries.length === 0) {
            return;
        }

        view.lost = row.entries.map((entry) => ({
            ...entry,
            source: 'parked',
            sentence: entry.source === 'parked' ? entry.sentence : `From your last visit: ${entry.sentence}`,
        }));
    }

```

3. The re-offer runs BEFORE the draft logic and before the base row is written. Replace

```js
        await restoreDraftIfRestorable(drafts, handle);
        writeBase(view.confirmed, view.baseVersion);
    }
```

with

```js
        // BEFORE the draft, the lost row: a list parked by a merge in an
        // earlier visit of this browser is offered again, and the draft
        // merge below appends its own losses to it. offerLost() parks the
        // WHOLE list, so the earlier park must be read first, or it would be
        // overwritten and the fresh entries read back as last visit's.
        await reofferLost(drafts);
        await restoreDraftIfRestorable(drafts, handle);
        writeBase(view.confirmed, view.baseVersion);
    }
```

3b. The park is read on every open now. In the two Phase 1 cases "with no draft, and in a browser that cannot keep one, the page simply opens" and "an edit undone inside the debounce leaves no draft: after somebody saves, reopening offers nothing", replace

```js
    assert.deepEqual(A.draftCalls, ['loadDraft', 'saveBase']);
```

with

```js
    assert.deepEqual(A.draftCalls, ['loadLost', 'loadDraft', 'saveBase']);
```

and

```js
    assert.deepEqual(again.draftCalls, ['loadDraft', 'saveBase']);
```

with

```js
    assert.deepEqual(again.draftCalls, ['loadLost', 'loadDraft', 'saveBase']);
```

3c. `putBack()` and `loadTheirs()` (dry run 2). In `putBack()`, replace

```js
            handle.editor.commands.setContent(JSON.parse(view.setAside), { errorOnInvalidContent: true });
            forgetSetAside();
```

with

```js
            handle.editor.commands.setContent(JSON.parse(view.setAside), { errorOnInvalidContent: true });
            // The saves sent so far did not carry this text (see persist()).
            view.putBackSeq = view.saveSeq;
            forgetSetAside();
```

In `loadTheirs()`, replace

```js
        const waiting = engine.takeRemote();
        if (!waiting) {
            engine.refetch(view.baseVersion);

            return;
        }

        const mine = JSON.stringify(handle.editor.getJSON());
```

with

```js
        // A text is already set aside and offered. Load theirs sets THIS tab's
        // text aside in its place, and the stale- park holds one text only, so
        // the earlier one would be nowhere: ask first, before the engine's
        // document is taken (a no changes nothing).
        if (view.setAside && engine.pending && !env.confirm(LOAD_THEIRS_REPLACES_QUESTION)) {
            return;
        }

        const waiting = engine.takeRemote();
        if (!waiting) {
            engine.refetch(view.baseVersion);

            return;
        }

        const mine = JSON.stringify(handle.editor.getJSON());
```

4. The module's return. Replace

```js
        putBack,
        discardSetAside,
        membersChanged,
```

with

```js
        putBack,
        discardSetAside,
        putBackLost,
        putBackAllLost,
        dismissLost,
        membersChanged,
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: PASS, 190 + 10 + 1 (the dry run's dismissed-park case) + 2 (dry run 2: Put it back while the flagged save is in the air, Load theirs over a standing set-aside text) = 203 tests, `fail 0`. If the two Phase 1 open-path cases fail with `+ 'loadLost'` in the diff, Step 3b was skipped; if a Task 10 case later shows a draft merge's losses as `parked` / "From your last visit", the re-offer is running after the draft logic.

Run: `npm test`
Expected: every JavaScript test passes (530 tests: 517 + 13).

- [ ] **Step 5: Commit**

```bash
git add resources/js/editor/sync/host.js tests/js/sync.host.test.js
git commit -m "feat(editor): put a lost block back, put them all back, dismiss them, re-offer after a reload

putBackLost() puts one entry of the lost row back on the page through the
handle (below the winner with a fresh id for a block both changed; where
it was with its own id for a block deleted under the typist), as an
ordinary undoable edit the autosave sends without an overwrite, and
rewrites the park with the rest; putBackAllLost() walks the list oldest
first and stops at the first failure, which stays listed and is said;
dismissLost() always asks, empties the row and stamps the park dismissed
for its 7 days. restoreDraft() re-offers a park that was not dismissed,
each entry prefixed From your last visit. putBack() stamps putBackSeq, so
the answer of a save that left before it cannot write off the overwrite
the put-back text owes; Load theirs asks before it replaces a text that
is already set aside.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 9: The lost row in the notice bar (Blade + CSS), and the amended conflict sentence

**Files:**
- Modify: `resources/views/livewire/documents/editor.blade.php` (three delegates; the lost row; the conflict sentence)
- Modify: `resources/css/shell.css` (`.doc-notice-list`, `.doc-notice-item`, `.doc-notice-excerpt`)
- Modify: `scripts/design/contrast-dom.mjs` (`.doc-notice-excerpt` joins `INKS`)
- Modify: `tests/Feature/Documents/EditorSyncWiringTest.php`

**Interfaces:**
- Consumes: `putBackLost(index)`, `putBackAllLost()`, `dismissLost()` (Task 8) and the `lost` field (Task 7).
- Produces: row 2 of `.doc-notices`, `x-bind:hidden="!lost.length"`, between the conflict row and the set-aside row; five static item slots `x-bind:hidden="!lost[i]"` each with the sentence, the excerpt in a `<q class="doc-notice-excerpt">` and a Put it back button `@click="putBackLost(i)"`; an overflow line `x-bind:hidden="lost.length <= 5"`; Put them all back and Dismiss all with titles and descriptions. Row order and bindings: `!conflict`, `!lost.length`, `!setAside`, `!syncNotice`. The conflict sentence becomes "Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one."
- Decision: Put them all back has its own title and description (`doc-notice-put-back-all-lost-does`), see Owner decisions.

Read `.ai/rules/livewire.md` ("Notices with actions live in the notice bar", "The editor's x-data object is ONE double-quoted HTML attribute") and `.ai/rules/views.md` ("An element switched with the hidden attribute needs its own [hidden] rule") before this task. Nothing in the bar may be server-rendered; no `x-show`, no `x-cloak`, no `x-for`; no double quote inside `x-data`; and the string `wire:` may not appear anywhere inside the bar's source, comments included (`test_nothing_in_the_notice_bar_depends_on_what_the_server_renders` pins `substr_count($bar, 'wire:') === 1`, the bar's own `wire:ignore`; the dry run lost that test to a Blade comment that said "the bar is wire:ignore").

- [ ] **Step 1: Pin the row in the wiring test first**

In `tests/Feature/Documents/EditorSyncWiringTest.php`:

1. Replace
```php
        $this->assertSame(['!conflict', '!setAside', '!syncNotice'], $rows);
```
with
```php
        $this->assertSame(['!conflict', '!lost.length', '!setAside', '!syncNotice'], $rows);
```

2. Replace BOTH occurrences of
```php
        foreach (['conflict', 'setAside', 'syncNotice'] as $state) {
```
with
```php
        foreach (['conflict', 'lost.length', 'setAside', 'syncNotice'] as $state) {
```

3. Replace
```php
        // The three notices, in this order. Each is hidden in the markup the
```
with
```php
        // The four notices, in this order. Each is hidden in the markup the
```

4. Replace
```php
        // A reload during a conflict no longer loses the text (the page
        // sets it aside as a draft and offers it back), so the sentence
        // does not forbid one.
        $this->assertSame(
            'Not saved — this document was changed elsewhere while you were typing. Choose one.',
            $this->words($this->notice($bar, 'conflict')->querySelector('.doc-notice-text')),
        );
```
with
```php
        // A reload during a conflict no longer loses the text (the page
        // sets it aside as a draft and offers it back), so the sentence
        // does not forbid one. Since Phase 2 the notice appears only when
        // a merge could not be done, and the sentence says so.
        $this->assertSame(
            'Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one.',
            $this->words($this->notice($bar, 'conflict')->querySelector('.doc-notice-text')),
        );
```

5. Replace
```php
        $this->assertSame(['keepMine()', 'loadTheirs()', 'putBack()', 'discardSetAside()'], $handlers);
```
with
```php
        $this->assertSame(
            ['keepMine()', 'loadTheirs()', 'putBackLost(0)', 'putBackLost(1)', 'putBackLost(2)', 'putBackLost(3)', 'putBackLost(4)', 'putBackAllLost()', 'dismissLost()', 'putBack()', 'discardSetAside()'],
            $handlers,
        );
```

6. Replace
```php
        foreach (['keepMine()', 'loadTheirs()', 'putBack()', 'discardSetAside()'] as $handler) {
```
with
```php
        foreach (['keepMine()', 'loadTheirs()', 'putBackLost(0)', 'putBackLost(1)', 'putBackLost(2)', 'putBackLost(3)', 'putBackLost(4)', 'putBackAllLost()', 'dismissLost()', 'putBack()', 'discardSetAside()'] as $handler) {
```

7. Replace
```php
        $this->assertCount(3, $bar->querySelectorAll('.doc-notice'));
```
with
```php
        $this->assertCount(4, $bar->querySelectorAll('.doc-notice'));
```

8. Replace
```php
        $this->assertSame(4, $described);
```
with
```php
        $this->assertSame(11, $described, 'Keep mine, Load theirs, five Put it back, Put them all back, Dismiss all, Put it back, Discard');
```

9. Directly after the method `test_the_notice_buttons_say_what_they_do_and_give_the_keyboard_back()` (its closing `    }`), add:

```php

    /**
     * The lost row (Phase 2, section 6 of the spec): what a merge could not
     * keep, offered back item by item, each with its reason, its first 80
     * characters and its own Put it back. Five static item slots switched
     * with the hidden attribute, never x-for: PHPUnit can pin every element,
     * and no Alpine pattern new to the bar is introduced. LOST_ROWS in
     * sync/host.js is the same five.
     */
    public function test_the_lost_row_offers_five_items_back_and_a_way_to_take_them_all_or_none(): void
    {
        $html = $this->editorHtml();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $bar = $dom->querySelector('.doc-notices');
        $this->assertNotNull($bar);
        $row = $this->notice($bar, 'lost.length');

        $this->assertSame(
            'Not everything you typed could be kept. Each item below stays here until you put it back or dismiss it; a copy is kept in this browser for 7 days.',
            $this->words($row->querySelector('.doc-notice-text')),
        );

        $items = $row->querySelectorAll('.doc-notice-list > .doc-notice-item');
        $this->assertCount(6, $items, 'five slots and the overflow line');
        foreach (range(0, 4) as $i) {
            $item = $items->item($i);
            $this->assertTrue($item->hasAttribute('hidden'));
            $this->assertSame("!lost[{$i}]", $item->getAttribute('x-bind:hidden'));
            $this->assertSame("lost[{$i}] ? lost[{$i}].sentence : ''", $item->querySelector('span[x-text]')->getAttribute('x-text'));
            $this->assertSame("lost[{$i}] ? lost[{$i}].excerpt : ''", $item->querySelector('q.doc-notice-excerpt')->getAttribute('x-text'));
            $button = $item->querySelector('button');
            $this->assertSame("putBackLost({$i})", $button->getAttribute('@click'));
            $this->assertSame('Put it back', $this->words($button));
            $this->assertSame('doc-notice-put-back-lost-does', $button->getAttribute('aria-describedby'));
        }
        $overflow = $items->item(5);
        $this->assertTrue($overflow->hasAttribute('hidden'));
        $this->assertSame('lost.length <= 5', $overflow->getAttribute('x-bind:hidden'));
        $this->assertSame('lost.length - 5', $overflow->querySelector('span[x-text]')->getAttribute('x-text'));
        $this->assertStringContainsString('more. Put them all back restores every item, these included.', $this->words($overflow));

        $actions = [];
        foreach ($row->querySelectorAll('.doc-notice-actions > button') as $button) {
            $actions[$this->words($button)] = $button->getAttribute('@click');
        }
        $this->assertSame(['Put them all back' => 'putBackAllLost()', 'Dismiss all' => 'dismissLost()'], $actions);

        // Static slots, never x-for.
        $this->assertCount(0, $bar->querySelectorAll('template'));
        $this->assertStringNotContainsString('x-for', (string) $bar->innerHTML);

        // The five is one number, in the module too.
        $this->assertMatchesRegularExpression('/^export const LOST_ROWS = 5;$/m', file_get_contents(resource_path('js/editor/sync/host.js')));

        // The three delegates hand over to the module.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertStringContainsString('putBackLost(index) { this.syncHost().putBackLost(index); }', $alpine[1]);
        $this->assertStringContainsString('putBackAllLost() { this.syncHost().putBackAllLost(); }', $alpine[1]);
        $this->assertStringContainsString('dismissLost() { this.syncHost().dismissLost(); }', $alpine[1]);

        // The row's own styles, beside the bar's, and the excerpt's ink in the contrast gate.
        $css = file_get_contents(resource_path('css/shell.css'));
        foreach (['.doc-notice-list', '.doc-notice-item', '.doc-notice-excerpt'] as $selector) {
            $this->assertStringContainsString($selector.' {', $css, "{$selector} is styled");
        }
        $this->assertMatchesRegularExpression('/\.doc-notice-list \{[^}]*max-height:/', $css, 'the list is bounded so the sticky bar cannot outgrow the viewport');
        $this->assertStringContainsString("'.doc-notice-excerpt',", file_get_contents(base_path('scripts/design/contrast-dom.mjs')));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php`
Expected: 4 FAIL, 8 pass. `test_the_notices_with_actions_are_in_a_bar_of_their_own_outside_the_status_strip` fails on the rows list (`Failed asserting that two arrays are identical`, three rows where four are expected); `test_the_notice_bar_is_out_of_livewires_morph_and_its_rows_are_switched_with_the_hidden_attribute` fails on `assertCount(4, ...)` (3 rows); `test_the_notice_buttons_say_what_they_do_and_give_the_keyboard_back` fails with `Failed asserting that 4 is identical to 11`; the new test fails in `notice()` with `the lost.length notice is not in the bar`.

- [ ] **Step 3: The delegates and the sentence**

In `resources/views/livewire/documents/editor.blade.php`, replace

```js
        putBack() { this.syncHost().putBack(); },
        discardSetAside() { this.syncHost().discardSetAside(); },
        membersChanged(members) { this.syncHost().membersChanged(members); },
```

with

```js
        putBack() { this.syncHost().putBack(); },
        discardSetAside() { this.syncHost().discardSetAside(); },
        putBackLost(index) { this.syncHost().putBackLost(index); },
        putBackAllLost() { this.syncHost().putBackAllLost(); },
        dismissLost() { this.syncHost().dismissLost(); },
        membersChanged(members) { this.syncHost().membersChanged(members); },
```

Replace

```blade
                    <span>Not saved — this document was changed elsewhere while you were typing. Choose one.</span>
```

with

```blade
                    <span>Not saved — this document was changed elsewhere while you were typing, and the two could not be combined. Choose one.</span>
```

- [ ] **Step 4: The row**

Still in `editor.blade.php`, replace

```blade
                    <span id="doc-notice-keep-mine-does" hidden>Save your version over the newer one. The other version is kept in the history.</span>
                    <span id="doc-notice-load-theirs-does" hidden>Show the newer version. You can put your text back afterwards.</span>
                </span>
            </div>

            {{-- Text the page holds for the writer, and where it came from
```

with

```blade
                    <span id="doc-notice-keep-mine-does" hidden>Save your version over the newer one. The other version is kept in the history.</span>
                    <span id="doc-notice-load-theirs-does" hidden>Show the newer version. You can put your text back afterwards.</span>
                </span>
            </div>

            {{-- ── The lost row (Phase 2) ────────────────────────────────
                 What a merge could not keep: a paragraph both people changed
                 at the same moment (theirs stays on the page, this is yours),
                 or one somebody removed while you were typing in it. Each
                 item is offered back on its own: Put it back puts it below
                 the version that is there now, or where it was before it was
                 removed, as an ordinary edit the autosave sends. Items are
                 appended and never withdrawn; a copy of the whole list is
                 parked in this browser for 7 days (sync/host.js, offerLost()).

                 Five STATIC item slots switched with hidden, not x-for: the
                 wiring test pins every element, and no Alpine pattern new to
                 this bar is introduced (the Phase 1 bar faults were only
                 visible in a browser). LOST_ROWS in sync/host.js is the same
                 five; a sixth item and beyond are counted in the last line and
                 come back with Put them all back. Nothing here is rendered by
                 the server: Livewire ignores the whole bar. --}}
            <div class="doc-notice" hidden x-bind:hidden="!lost.length">
                <p class="doc-notice-text">
                    <span class="status-word-dot status-word-dot-idle" aria-hidden="true"></span>
                    <span>Not everything you typed could be kept. Each item below stays here until you put it back or dismiss it; a copy is kept in this browser for 7 days.</span>
                </p>
                <ul class="doc-notice-list">
                    <li class="doc-notice-item" hidden x-bind:hidden="!lost[0]">
                        <span x-text="lost[0] ? lost[0].sentence : ''"></span>
                        <q class="doc-notice-excerpt" x-text="lost[0] ? lost[0].excerpt : ''"></q>
                        <button type="button" class="tool tool-mono" @click="putBackLost(0)"
                                aria-describedby="doc-notice-put-back-lost-does"
                                title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
                    </li>
                    <li class="doc-notice-item" hidden x-bind:hidden="!lost[1]">
                        <span x-text="lost[1] ? lost[1].sentence : ''"></span>
                        <q class="doc-notice-excerpt" x-text="lost[1] ? lost[1].excerpt : ''"></q>
                        <button type="button" class="tool tool-mono" @click="putBackLost(1)"
                                aria-describedby="doc-notice-put-back-lost-does"
                                title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
                    </li>
                    <li class="doc-notice-item" hidden x-bind:hidden="!lost[2]">
                        <span x-text="lost[2] ? lost[2].sentence : ''"></span>
                        <q class="doc-notice-excerpt" x-text="lost[2] ? lost[2].excerpt : ''"></q>
                        <button type="button" class="tool tool-mono" @click="putBackLost(2)"
                                aria-describedby="doc-notice-put-back-lost-does"
                                title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
                    </li>
                    <li class="doc-notice-item" hidden x-bind:hidden="!lost[3]">
                        <span x-text="lost[3] ? lost[3].sentence : ''"></span>
                        <q class="doc-notice-excerpt" x-text="lost[3] ? lost[3].excerpt : ''"></q>
                        <button type="button" class="tool tool-mono" @click="putBackLost(3)"
                                aria-describedby="doc-notice-put-back-lost-does"
                                title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
                    </li>
                    <li class="doc-notice-item" hidden x-bind:hidden="!lost[4]">
                        <span x-text="lost[4] ? lost[4].sentence : ''"></span>
                        <q class="doc-notice-excerpt" x-text="lost[4] ? lost[4].excerpt : ''"></q>
                        <button type="button" class="tool tool-mono" @click="putBackLost(4)"
                                aria-describedby="doc-notice-put-back-lost-does"
                                title="Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put it back</button>
                    </li>
                    <li class="doc-notice-item" hidden x-bind:hidden="lost.length <= 5">And <span x-text="lost.length - 5"></span> more. Put them all back restores every item, these included.</li>
                </ul>
                <span class="doc-notice-actions">
                    <button type="button" class="tool tool-mono" @click="putBackAllLost()"
                            aria-describedby="doc-notice-put-back-all-lost-does"
                            title="Put every item back on the page, oldest first: each below the version that is there now, or where it was before it was removed. Nothing else on the page changes.">Put them all back</button>
                    <button type="button" class="tool tool-mono" @click="dismissLost()"
                            aria-describedby="doc-notice-dismiss-lost-does"
                            title="Remove these items from this page. The text is in no saved version; it is kept only in this browser, for 7 days, where this browser keeps drafts.">Dismiss all</button>
                    <span id="doc-notice-put-back-lost-does" hidden>Put this text back on the page: below the version that is there now, or where it was before it was removed. Nothing else on the page changes.</span>
                    <span id="doc-notice-put-back-all-lost-does" hidden>Put every item back on the page, oldest first: each below the version that is there now, or where it was before it was removed. Nothing else on the page changes.</span>
                    <span id="doc-notice-dismiss-lost-does" hidden>Remove these items from this page. The text is in no saved version; it is kept only in this browser, for 7 days, where this browser keeps drafts.</span>
                </span>
            </div>

            {{-- Text the page holds for the writer, and where it came from
```

- [ ] **Step 5: The CSS**

In `resources/css/shell.css`, replace

```css
.doc-notice-actions {
    flex: 0 1 auto;
    min-width: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s2);
}
```

with

```css
.doc-notice-actions {
    flex: 0 1 auto;
    min-width: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--s2);
}

/*
 * The lost row's items (editor.blade.php, Phase 2): one block a merge could
 * not keep per item, oldest first, each with its sentence, its excerpt and
 * its own Put it back. The list takes a line of its own under the row's
 * sentence; an item wraps like the row does. An item is switched with the
 * hidden attribute, which `.doc-notice [hidden]` above covers.
 */
.doc-notice-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--s1);
    flex: 1 1 100%;
    min-width: 0;
    /* Five items of two or three lines each would make the sticky .doc-head taller than a phone's viewport and put Put them all back and Dismiss all out of reach: the list scrolls inside the row. */
    max-height: 40vh;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.doc-notice-item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--s2);
}

.doc-notice-excerpt {
    color: var(--ink-soft);
    overflow-wrap: anywhere;
}
```

In `scripts/design/contrast-dom.mjs`, replace

```js
    '.doc-notice-text',
    '.tool',
    '.doc-notice .tool:disabled',
];
```

with

```js
    '.doc-notice-text',
    '.doc-notice-excerpt',
    '.tool',
    '.doc-notice .tool:disabled',
];
```

Run: `node scripts/design/contrast-dom.mjs > "${TMPDIR:-/tmp}/contrast.txt"; echo "exit=$?"; tail -1 "${TMPDIR:-/tmp}/contrast.txt"`
Expected: `exit=0` and `ALL PASS`. Redirect it to a file and never pipe it: the script ends with `process.exit()`, so into a pipe (`| tail`, an agent's shell, CI) the output is cut at 64 KiB and the last line is a half-written table row, not `ALL PASS`. (`--ink-soft` inside `.doc-notice`'s `--surface` is a pair the gate already measures for `.doc-notice-text`'s dot and for `.doc-notice .tool:disabled`.)

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php tests/Feature/Documents/ContextualToolbarTest.php tests/Feature/Documents/EditorMountTest.php`
Expected: PASS (`EditorSyncWiringTest` 12 tests). If the first test of `EditorSyncWiringTest` or `ContextualToolbarTest::test_the_editors_alpine_component_survives_being_an_html_attribute` fails, a double quote has got into `x-data`; this lists the offending lines and prints nothing when the attribute is clean:

```bash
awk '/^    x-data="\{$/{f=1;next} /^    \}"$/{f=0} f && /"/{print FNR": "$0}' resources/views/livewire/documents/editor.blade.php
```

Run: `npm run build`
Expected: the build succeeds.

Then the `.ai/rules/views.md` GATE ("GATE before calling a shell change done, both modes AND both breakpoints"): `shell.css` changed, so the contrast gate of Step 5 is not the whole of it. Render the editor page from a THROWAWAY PHPUnit test (`$this->withVite()`; inline `resources/css/shell.css` and `paper.css`, strip CSS comments, substitute every `var(--token)` with the literal each mode resolves it to) to `public/__design/editor-day.html` and `editor-night.html`, and each again with the `@media (max-width: 900px)` and `(max-width: 1180px)` blocks flattened into unconditional rules; run `node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect public/__design/<file>.html` on each of the four and expect it to print nothing; canary it once (drop `Inter` into `--font-chrome`, see it reported, take it out). Delete the throwaway test and `public/__design/` before committing; neither is ever committed. (The lost row is three flex/colour rules and a bounded, scrolling list inside a bar the gate has passed before, but the rule makes no exemption for size. The second dry run ran it on eight files: day and night, each flattened at 900/1180, each with every hidden row of the bar revealed and the five slots filled with the spec's sentences; the detector printed nothing and the canary was reported.)

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/documents/editor.blade.php resources/css/shell.css scripts/design/contrast-dom.mjs tests/Feature/Documents/EditorSyncWiringTest.php
git commit -m "feat(editor): the lost row offers back what a merge could not keep

A second row of the notice bar lists the blocks a merge could not keep,
oldest first: the reason, the first 80 characters, and a Put it back for
each; Put them all back and Dismiss all for the lot. Five static item
slots switched with the hidden attribute (no x-for in the wire:ignore
bar), a sixth item and beyond counted in a last line. The list is bounded
in height and scrolls inside the row, so the sticky bar cannot outgrow a
phone's viewport and put its two buttons out of reach. The conflict
sentence now says the two could not be combined: the notice appears
only when a merge is impossible.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 10: The draft merge

A draft the document has moved past is merged into the document as it is now, after one question, when the browser still holds the base row of the draft's version (section 7.1). Otherwise today's park and set-aside.

**Files:**
- Modify: `resources/js/editor/sync/host.js`
- Modify: `tests/js/sync.host.test.js`

**Interfaces:**
- Consumes: `drafts.loadBase()` (Task 5), `handle.merge(base, theirs, {mine, deletionsWin: false})` (Task 4), `offerLost()` (Task 7).
- Produces: `DRAFT_MERGE_QUESTION` exported from `host.js` (the text of section 2); `mergeDraft(drafts, handle, draft, parsed) -> Promise<boolean>` inside the module (true when the draft was handled: merged, or cleared because it is all in the document; false to fall through to today's path), called first in the `draft.baseVersion < view.documentVersion` branch of `restoreDraftIfRestorable()`.
- Decision (dry run 2): when the merge leaves something of the draft to save (`result.changed`), `mergeDraft()` reports `('idle', 'Editing')`: `opened()` said Saved a moment before, the in-page strip hides Saved while `unsaved`, but the top bar (`shell:save-state`) does not, and nothing else would correct it until the first poll tick sends the save.
- Decision: a draft whose base row matches is merged whatever stamped its version, including a draft an owed overwrite stamped one below when nothing was confirmed since the page opened (the row IS of that version then): the merge is against the content the typing was based on, which is right, and nothing is replaced unasked. Four Phase 1 draft cases change outcome accordingly (Step 2).

- [ ] **Step 1: Write the failing tests**

In `tests/js/sync.host.test.js`, replace

```js
    DISMISS_LOST_QUESTION,
    LOAD_THEIRS_REPLACES_QUESTION,
```

with

```js
    DISMISS_LOST_QUESTION,
    DRAFT_MERGE_QUESTION,
    LOAD_THEIRS_REPLACES_QUESTION,
```

and append at the end of the file:

```js

// ───────────────────────────────────────────────────────── the draft merge (section 7.1)

/**
 * A's tab dies inside the debounce with typing based on version 2; B saves
 * version 3 meanwhile; A's browser opens the document again.
 */
async function deadTabWithDraft(edit, { confirmAnswer = true, version = 3 } = {}) {
    const w = await twoParagraphs();
    const { server, clock, A, B } = w;
    await edit(A, B); // may await: a case where B follows a beacon's version first
    assert.equal(A.drafts.get(UUID).baseVersion, 2, 'the live draft is of version 2');
    assert.equal(A.drafts.get(BASE).baseVersion, 2, 'and the base row holds version 2');
    await B.debounce();
    await B.land();
    assert.equal(server.version, version, 'somebody saved since the draft was written');
    const again = makeTab({ server, clock }, 'A2', { drafts: A.drafts, confirmAnswer });
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
```

- [ ] **Step 2: Bring four Phase 1 draft cases onto the merge**

Their drafts now meet a base row of their own version, so the page asks and merges instead of setting aside. Still in `tests/js/sync.host.test.js`:

1. Replace the whole test
```js
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
    assert.equal(server.text(), 'B1');
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});
```
with
```js
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
```

2. In "Discard of a draft from an earlier visit does not ask: the page took nothing away", replace
```js
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts });
    await again.open();
    assert.equal(again.view.setAsideFrom, 'draft');

    again.host().discardSetAside();
    assert.deepEqual(again.questions, []);
```
with
```js
    // The draft merge is declined: today's park and set-aside.
    const again = makeTab({ server, clock: { now: 2_000_000 } }, 'B2', { drafts: B.drafts, confirmAnswer: false });
    await again.open();
    assert.equal(again.view.setAsideFrom, 'draft');

    again.host().discardSetAside();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION], 'Discard itself asked nothing');
```

3. In "the beacon stored the last words and others saved since: the draft is offered, and putting it back asks first", replace
```js
    // One click must not replace two versions of somebody else's work.
    again.host().putBack();
    assert.deepEqual(again.questions, [PUT_BACK_QUESTION]);
```
with
```js
    // The draft merge was declined (it would have offered the paragraph
    // back); one click must not replace two versions of somebody else's work.
    again.host().putBack();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION, PUT_BACK_QUESTION]);
```

4. In "a save in the air when the tab closed landed, and others saved since: putting the draft back asks first", replace
```js
    again.host().putBack();
    assert.deepEqual(again.questions, [PUT_BACK_QUESTION]);
    assert.equal(again.text(), 'A last words, then B');
```
with
```js
    again.host().putBack();
    assert.deepEqual(again.questions, [DRAFT_MERGE_QUESTION, PUT_BACK_QUESTION]);
    assert.equal(again.text(), 'A last words, then B');
```

5. Replace the whole test
```js
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
        again.sent.map((save) => [save.text, save.base, save.overwrite]),
        [['B1 and more', 2, true]]
    );
    await again.land();
    assert.deepEqual(server.history, [{ version: 2, text: 'A1' }]);
});
```
with
```js
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
```

("Put it back, then the page dies before anything is sent: reopened, the text is set aside and its save overwrites" is unchanged and is the coverage of "an owed-overwrite draft is never merged": Load theirs confirmed version 2, so the row is of version 2 and the draft, stamped 1, meets no row of its own.)

- [ ] **Step 3: Run the tests to verify they fail**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: FAIL before any case runs: `SyntaxError: The requested module '../../resources/js/editor/sync/host.js' does not provide an export named 'DRAFT_MERGE_QUESTION'`.

- [ ] **Step 4: The draft merge in `host.js`**

1. The question. Replace

```js
export const DISMISS_LOST_QUESTION =
    'Dismiss this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';
```

with

```js
export const DISMISS_LOST_QUESTION =
    'Dismiss this text? It is in no saved version. It will be removed from this page and kept only in this browser, for 7 days.';

/**
 * What the page asks before it merges a draft the document has moved past
 * (section 7.1). A draft's deletion does not win there: D2 was decided for
 * live co-typing, and a days-old draft's deletion of a block somebody has
 * since changed must not remove their change.
 */
export const DRAFT_MERGE_QUESTION =
    'An unsaved draft from your last visit was found. Add its changes to the document as it is now? Changes saved by others since then are kept, and a paragraph the draft deleted that was changed since stays. Anything that could not be kept is offered back.';
```

2. The branch. Replace

```js
            if (draft.baseVersion < view.documentVersion) {
                // Not restorable, but not this page's to destroy either.
                // Park it under a stale- key so it can be recovered by
                // hand; parkStaleDraft() stamps parkedAt, and the sweep on
                // the next app boot collects it after 7 days.
                await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion);
```

with

```js
            if (draft.baseVersion < view.documentVersion) {
                // Phase 2: with the base row of the draft's own version, the
                // draft is merged into the document as it is now (one
                // question first), or cleared when it is all in the document
                // already. Without the row, with a merge that cannot be done,
                // or when the writer declines: today's park and set-aside.
                if (await mergeDraft(drafts, handle, draft, parsed)) {
                    return;
                }

                // Not restorable, but not this page's to destroy either.
                // Park it under a stale- key so it can be recovered by
                // hand; parkStaleDraft() stamps parkedAt, and the sweep on
                // the next app boot collects it after 7 days.
                await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion);
```

3. The merge. Directly before the docblock that begins

```js
    /**
     * A newer version exists and this tab has unsaved typing. Stop saving
```

insert:

```js
    /**
     * The draft merge (section 7.1): a draft the document has moved past,
     * merged against the server document it was typed on (the base row of
     * its version), with the page's document as theirs and the draft as
     * mine. `deletionsWin: false`: a block the draft deleted that somebody
     * has changed since stays, and nothing is overwritten.
     *
     * @returns {Promise<boolean>} true when the draft was dealt with here
     *          (merged, or cleared because it is all in the document);
     *          false to fall through to today's park and set-aside
     */
    async function mergeDraft(drafts, handle, draft, parsed) {
        let base = null;
        try {
            base = await drafts.loadBase(view.docUuid);
        } catch (_) {
            base = null;
        }
        if (!base || typeof base.json !== 'string' || base.baseVersion !== draft.baseVersion) {
            return false;
        }

        let result = null;
        try {
            result = handle.merge(JSON.parse(base.json), handle.editor.getJSON(), { mine: parsed, deletionsWin: false });
        } catch (_) {
            result = null;
        }
        if (!result) {
            return false;
        }

        // The beacon stored it, or it is all in the document: nothing to ask.
        if (!result.changed && result.lost.length === 0) {
            drafts.clearDraft(view.docUuid);

            return true;
        }

        if (!env.confirm(DRAFT_MERGE_QUESTION)) {
            return false;
        }

        // The pre-merge copy stays recoverable, as today.
        await drafts.parkStaleDraft(view.docUuid, draft.json, draft.baseVersion);
        drafts.clearDraft(view.docUuid);

        let applied = false;
        try {
            applied = handle.applyRemote(result.merged, { force: true }) === true;
        } catch (_) {
            applied = false;
        }
        if (!applied) {
            return false;
        }

        // `confirmed` stays: opened() set it to the page document, which is
        // theirs. What survived of the draft is unsaved on the current base
        // and goes out on the first tick once startSync() has run.
        view.unsaved = result.changed;
        if (result.changed) {
            // The page said Saved when it opened; the merged text is not.
            env.report('idle', 'Editing');
        }
        if (result.lost.length > 0) {
            offerLost(result.lost, 'draft', view.baseVersion);
        }
        if (result.changed) {
            drafts.saveDraft(view.docUuid, JSON.stringify(handle.editor.getJSON()), view.baseVersion);
            view.resave = true;
        }
        view.tick++;
        env.info(
            '[Dot.Doc] An unsaved draft based on v' +
                draft.baseVersion +
                ' was merged into the document at v' +
                view.documentVersion +
                '; the copy before the merge was kept as stale-' +
                view.docUuid +
                '.'
        );

        return true;
    }

```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: PASS, 203 + 8 + 1 (dry run 2: the word after a draft merge that leaves text to save) = 212 tests, `fail 0`. If "a draft whose text is already in the document is cleared without a question" shows `start` where `start B` is expected, B's save went out on base 2 after the beacon stored version 3 and was refused: B must `cycle()` first (the helper awaits `edit()` for that).

Run: `npm test`
Expected: every JavaScript test passes (539 tests: 530 + 9).

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor/sync/host.js tests/js/sync.host.test.js
git commit -m "feat(editor): a draft the document has moved past is merged, not only set aside

When the browser still holds the base row of the version a stale draft
was typed on, the draft is merged into the document as it is now, after
one question: changes saved by others since are kept, a block the draft
deleted that was changed since stays (no overwrite is owed), what
survived of the draft is saved on the first tick, and what could not be
kept is offered back in the lost row with the draft sentences. A draft
whose text is already in the document is cleared without a question.
Without the row, with a merge that cannot be done, or when the writer
declines, the draft is parked and set aside as before.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 11: An accepted suggestion over unsaved typing is merged

**Files:**
- Modify: `resources/js/editor/sync/host.js` (`applySuggestion()`; new `mergeSuggestion()`, `setAsideTyping()`)
- Modify: `tests/js/sync.host.test.js`

**Interfaces:**
- Produces: `applySuggestion(content, version)` (section 8): when the tab holds unsaved typing and a base is known (`confirmed`, or `known` of the base version), `handle.merge(base, content)` and the result applied as in section 5.5 steps 6–9 with `source: 'suggestion'`, `baseVersion = version`, `engine.saved(version)`, `aiError = ''`, `refreshOutline()`; when no base is known or the merge or its apply fails, the editor's text is parked as `stale-` and set aside (`setAsideFrom = 'conflict'`, `setAsideBase = version`), then today's forced apply. A tab with nothing unsaved follows as today. Fail-closed as today (`aiError`, nothing applied).
- Decision: the overwrite flag is ORed through a suggestion merge too while something of mine is left to save, and dropped when nothing is (section 5.5 step 7 as amended, dry run 2); the Phase 1 test that set `overwriteOwed` by hand and asserted it cleared is amended.
- Decisions (dry run 2): `applySuggestion()` gives up whatever save is in the air FIRST (`saving = 0`, `expiredSaveSeq = saveSeq`), before the merge decides its base and before `markConfirmed()`: left to expire later inside `persist()`, that save would null `confirmed` again and install its older document as the echo of the NEXT merge (a later change of somebody else that equals it, such as a restore of that version, would read as this tab's own and be dropped without the overwrite flag), and its OK answer would move the base back. And when the suggestion cannot be merged and a text is already set aside, the suggestion is not accepted over the typing: `aiError` says to put back or discard the text first (the other tabs still receive the suggestion through the poll), because `setAsideTyping()` would replace the offer and its `stale-` park, and the earlier text would be in no version and no offer.

- [ ] **Step 1: Write the failing tests**

In `tests/js/sync.host.test.js`, replace the whole test

```js
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
```

with

```js
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
```

and append at the end of the file:

```js

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
    assert.match(B.view.aiError, /Put back or discard the text set aside first/);
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/sync.host.test.js 2>&1 | grep -E "^ℹ (pass|fail)|^✖" | head -12`
Expected: `ℹ fail 8` and eight `✖` lines: the amended Phase 1 case fails on `A.view.lost` (`[]` where one entry is expected); "blocks it did not touch survive" fails on `A.blocks()` (the suggestion replaced everything: two blocks, no `p3`); the two set-aside cases fail on `A.view.setAside` / `B.view.setAside` being `null`; and the four dry run 2 cases: the stale echo (`2 !== 3`, the merged save's base), the base moved back (`the base does not go back to version 2`), the typing replaced over a standing set-aside text (`the suggestion did not go in over the typing`) and the owed overwrite kept (`[]` where `['B1']` is expected, then `overwriteOwed`). (Node 24's default reporter is the spec reporter, even when piped, so the TAP-style `# pass` / `not ok` lines are not there to grep.)

- [ ] **Step 3: Merge in `applySuggestion()`**

In `resources/js/editor/sync/host.js`, replace

```js
        if (!handle.applyRemote(content, { force: true })) {
            view.aiError = 'That suggestion could not be applied — the document is unchanged.';

            return;
        }

        // The editor now holds exactly the version the server stored. Text
        // this tab had marked unsaved is no longer in it, and no save will
        // answer for it: the word that still says Editing goes back to
        // Saved. Only on that change.
        if (view.unsaved) {
            env.report('good', 'Saved');
        }
        view.unsaved = false;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), version);
        view.overwriteOwed = false;
        adoptVersion(version, { contentLoaded: true });
        view.aiError = '';
        view.tick++;
        refreshOutline();
    }
```

with

```js
        // Whatever save is in the air was written against a base this tab is
        // about to leave. It is given up on HERE, before the merge decides its
        // base and before markConfirmed(): its answer, whenever it comes, is a
        // late one and is ignored once the suggestion is confirmed (see
        // persist()). Left to expire on its own later, it would null
        // `confirmed` again and install its older document as the echo of
        // the NEXT merge, which would read a later change of somebody else
        // that happens to equal it (a restore of that version) as this tab's
        // own; and answering ok it would move the base back to its version.
        if (view.saving > 0) {
            view.saving = 0;
            view.expiredSaveSeq = view.saveSeq;
        }

        // Phase 2 (section 8): over unsaved typing the suggestion is MERGED
        // like any other remote document when a base is known, so the typing
        // survives wherever the suggestion did not rewrite it, and what it
        // did rewrite is offered back. When no base is known (after Keep
        // mine), or the merge or its apply fails, the typing is set aside
        // first, as after Load theirs, and the suggestion goes in whole.
        // Phase 1 replaced it silently.
        const base =
            view.confirmed ?? (view.known !== null && view.known.version === view.baseVersion ? view.known.json : null);
        if (view.unsaved && base !== null && mergeSuggestion(handle, base, content, version)) {
            return;
        }
        if (view.unsaved) {
            // One text is set aside at a time (the offer, and the stale- park
            // that backs it). Setting this one aside would replace the offer.
            if (view.setAside) {
                view.aiError =
                    'Put back or discard the text set aside first, then accept the suggestion again.';

                return;
            }
            setAsideTyping(handle, version);
        }

        if (!handle.applyRemote(content, { force: true })) {
            view.aiError = 'That suggestion could not be applied — the document is unchanged.';

            return;
        }

        // The editor now holds exactly the version the server stored. Text
        // this tab had marked unsaved is no longer in it (it is set aside
        // above), and no save will answer for it: the word that still says
        // Editing goes back to Saved. Only on that change.
        if (view.unsaved) {
            env.report('good', 'Saved');
        }
        view.unsaved = false;
        markConfirmed(JSON.stringify(handle.editor.getJSON()), version);
        view.overwriteOwed = false;
        adoptVersion(version, { contentLoaded: true });
        view.aiError = '';
        view.tick++;
        refreshOutline();
    }

    /**
     * The suggestion merge: section 5.5's steps 6 to 9 with the suggestion
     * as theirs, `source: 'suggestion'` and the suggestion's version as the
     * new base.
     *
     * @returns {boolean} whether the suggestion was merged and applied
     */
    function mergeSuggestion(handle, base, content, version) {
        let result = null;
        try {
            result = handle.merge(JSON.parse(base), content);
        } catch (_) {
            result = null;
        }
        if (!result) {
            return false;
        }

        let applied = false;
        try {
            applied = handle.applyRemote(result.merged, { force: true }) === true;
        } catch (_) {
            applied = false;
        }
        if (!applied) {
            return false;
        }

        view.merging = null;
        view.mergeAttempts = 0;
        view.baseVersion = version;
        view.conflict = null;
        markConfirmed(result.changed ? result.theirs : JSON.stringify(handle.editor.getJSON()), version);
        view.overwriteOwed = result.changed ? view.overwriteOwed || result.overwrites : false;
        view.unsaved = result.changed;
        if (result.lost.length > 0) {
            offerLost(result.lost, 'suggestion', version);
        }
        env.engine()?.saved(version);
        view.aiError = '';
        view.tick++;

        const drafts = env.drafts();
        if (drafts) {
            if (result.changed) {
                drafts.saveDraft(
                    view.docUuid,
                    JSON.stringify(handle.editor.getJSON()),
                    view.overwriteOwed ? view.baseVersion - 1 : view.baseVersion
                );
            } else {
                drafts.clearDraft(view.docUuid);
            }
        }

        if (result.changed) {
            view.resave = true;
            persist(handle.editor.getJSON());
        } else {
            env.report('good', 'Saved');
        }
        refreshOutline();

        return true;
    }

    /**
     * The editor's unsaved text is parked and set aside before a document
     * replaces it, as Load theirs does: the writer can put it back.
     *
     * @param {object} handle
     * @param {number} version the version the page will show
     */
    function setAsideTyping(handle, version) {
        const mine = JSON.stringify(handle.editor.getJSON());
        env.drafts()?.parkStaleDraft(view.docUuid, mine, view.baseVersion);
        view.setAside = mine;
        view.setAsideFrom = 'conflict';
        view.setAsideBase = version;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/sync.host.test.js 2>&1 | tail -8`
Expected: PASS, 212 + 3 + 4 (dry run 2: the save given up on inside the merge, the answer that moves the base back, the standing set-aside text, the owed overwrite dropped) = 219 tests, `fail 0`.

Run: `npm test`
Expected: every JavaScript test passes (546 tests: 539 + 7).

- [ ] **Step 5: Commit**

```bash
git add resources/js/editor/sync/host.js tests/js/sync.host.test.js
git commit -m "feat(editor): an accepted suggestion is merged with the typing it lands on

Over unsaved typing an accepted suggestion is merged like any other
remote document: blocks the suggestion did not rewrite survive and are
saved on its version, a block it did rewrite is offered back in the lost
row. When no base is known or the merge cannot be done, the typing is
set aside first and the suggestion goes in whole, unless a text is
already set aside: then the suggestion waits for the writer to put that
one back or discard it. A save in the air is given up on first, so its
older document cannot become the echo of a later merge. Phase 1
replaced the typing silently.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 12: The random-walk convergence harness

**Files:**
- Create: `tests/js/sync.merge.walk.test.js`
- Create: `tests/js/sync.merge.walk.failures.test.js` (Step 2b)

**Interfaces:**
- Consumes: `world()`, `makeTab()` and the tab actions of `tests/js/support/syncWorld.js` (Task 6), the host as Tasks 7–11 left it.
- Produces: a `node --test` file that walks two and three tabs through 200 random steps each for seeds 1–50 (`WALK_SEED=<n>` replays one seed, `WALK_SEEDS=<n>` runs seeds 1 to n), quiesces (bounded at 50 rounds), and checks section 12's six invariants: (1) every tab's document equals the server's; (2) every typed token is in the server document, in some tab's lost row, in that tab's lost park, in the server's kept versions, or was knowingly deleted; (3) ids are unique in every document; (4) no merge was ever impossible (`handle.merge()` never returned `null`) and no conflict notice stands once everything has landed; (5) `overwriteOwed` false everywhere; (6) every accepted save that dropped a token from the head that nobody knowingly deleted carried `overwrite`.
- Produces (dry run 2, Step 2b): a second file, `sync.merge.walk.failures.test.js`, that takes the same fake world through the failures of section 11 this walk never injects (lost and late save answers, failed requests and polls, the clock past the 15 s expiries, composition, an impossible merge for a stretch, a restore or an import, an accepted suggestion, a tab that dies and reopens over its draft, an individual Put it back, a browser without drafts), with eight invariants (A to H) listed in its docblock. 100 seeds by default (`ADV_SEEDS`), two and three tabs; `ADV_SEED`, `ADV_TABS` and `ADV_FEATURES` replay or narrow it. It is the check behind the repairs of dry run 2.
- Decision (see Owner decisions): "knowingly deleted" means every token that was in the deleter's own copy of the block at the moment they deleted it.
- Decisions (dry run): the one notice the walk can still raise is the `MERGE_ATTEMPTS` bound (section 5.8), and the quiesce resolves it as a writer would, with Keep mine (the merged document saved as an overwrite, so the head it replaces is kept and invariants 2 and 6 still hold); `tab.notices` counts them. The quiesce settles each tab (debounce, land, and while it is merging or has a save out: poll, land) before the next tab's save lands, then polls every tab once more: the plan's first draft landed every tab's save in a fixed order every round, so the last tab's merged save was refused by the previous tab's save round after round and reached the bound inside the quiesce itself. With `MERGE_ATTEMPTS = 5` and the fixes of Tasks 1, 7 and 8 the dry run measured no notice at all in 100 runs (at 3: 11 runs with the plan's quiesce, 2 with this one). A merge that returns `null` is a failure of the walk whatever its inputs: the empty-output case (both sides deleted what the other kept) is a merge since Task 1.

- [ ] **Step 1: Write the walk**

Create `tests/js/sync.merge.walk.test.js`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { LOST, blocksOf, world } from './support/syncWorld.js';

/**
 * The random-walk convergence harness (section 12 of the Phase 2 spec).
 *
 * Two or three tabs on the shared block-shaped fake world (support/
 * syncWorld.js) take 200 random steps: typing a unique token into a block,
 * inserting, deleting, moving and re-aligning blocks, letting the debounce
 * fire, letting saves reach the server and answer, polling, letting the
 * clock move, and now and then putting the lost row back or dismissing it.
 * Then everything that is in flight is let land (quiesce), and six things
 * must hold:
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
 *
 * Seeds 1 to 50 by default; `WALK_SEEDS=<n>` runs seeds 1 to n (the dry run
 * ran 2000 once: 4000 tests, about 12 seconds); `WALK_SEED=<n>` replays one.
 * On a failure the message carries the seed, the replay command and the last
 * thirty steps.
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

async function walk(seed, tabCount) {
    const random = mulberry32(seed);
    const pick = (list) => list[Math.floor(random() * list.length)];
    const w = world();
    const tabs = [];
    for (let index = 0; index < tabCount; index += 1) {
        const tab = w.tab(String.fromCharCode(65 + index));
        await tab.open();
        tabs.push(tab);
    }
    for (const tab of tabs) {
        await tab.poll();
    }
    for (const tab of tabs) {
        await tab.cycle();
    }

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
    /** Tokens that were in the deleter's own copy of a block when they deleted it. */
    const knowinglyDeleted = new Set();
    const trace = [];
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
                tokensIn({ content: [tab.block(id)] }).forEach((token) => knowinglyDeleted.add(token));
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
    }
    await quiesce(tabs, trace);

    return { w, tabs, typed, knowinglyDeleted, trace };
}

for (const seed of SEEDS) {
    for (const tabCount of [2, 3]) {
        test(`seed ${seed}, ${tabCount} tabs: every tab converges on the server and no token is silently lost`, async () => {
            const { w, tabs, typed, knowinglyDeleted, trace } = await walk(seed, tabCount);
            const { server } = w;
            const because = (what) =>
                `${what} (seed ${seed}, ${tabCount} tabs; replay with WALK_SEED=${seed} node --test tests/js/sync.merge.walk.test.js)\n${trace.slice(-30).join('\n')}`;

            // 1. Convergence.
            tabs.forEach((tab) => {
                assert.deepEqual(tab.handle.editor.getJSON(), server.json, because(`${tab.name} differs from the server`));
            });

            // 2. No silent loss.
            const kept = new Set(tokensIn(server.json));
            tabs.forEach((tab) => {
                tab.view.lost.forEach((entry) => tokensIn({ content: [entry.json] }).forEach((token) => kept.add(token)));
                const park = tab.drafts.get(LOST);
                if (park) {
                    // What is offered, and what Dismiss all moved aside: both are in the browser.
                    [...park.entries, ...park.dismissed].forEach((entry) => tokensIn({ content: [entry.json] }).forEach((token) => kept.add(token)));
                }
            });
            server.kept.forEach((row) => tokensIn(row.json).forEach((token) => kept.add(token)));
            knowinglyDeleted.forEach((token) => kept.add(token));
            const silentlyLost = [...typed.keys()].filter((token) => !kept.has(token));
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

            // 6. A head that lost a token nobody knowingly deleted was kept.
            server.trace.forEach(({ version, from, to, overwrite }) => {
                const after = tokensIn(to);
                const dropped = [...tokensIn(from)].filter((token) => !after.has(token) && !knowinglyDeleted.has(token));
                if (dropped.length > 0) {
                    assert.ok(overwrite, because(`version ${version} dropped ${dropped.join(', ')} from the head without saying it overwrites`));
                }
            });

            // And the walk did something.
            assert.ok(typed.size > 0, because('nothing was typed'));
            assert.ok(blocksOf(server.json).length >= 1);
        });
    }
}
```

- [ ] **Step 2: Run the walk**

Run: `node --test tests/js/sync.merge.walk.test.js 2>&1 | tail -12`
Expected: PASS, 100 tests (50 seeds, two and three tabs), `fail 0`, in well under a minute. There is no failing-first step for this file: it is a check of what Tasks 1–11 built, and a failure here is a finding, not a missing feature. On a failure the message names the seed and prints the replay command and the last thirty steps; replay it (`WALK_SEED=<n> node --test tests/js/sync.merge.walk.test.js`), read the trace, and fix the module the trace points at, with a host test for the sequence first. A notice during the walk is not a defect: it can only be the `MERGE_ATTEMPTS` bound (merged saves refused that many times in a row under three-way contention, the designed outcome of section 5.8), and the quiesce answers it with Keep mine, after which every invariant must still hold; `tab.notices` counts them, and the dry run counted NONE in 100 runs at `MERGE_ATTEMPTS = 5` (at 3 it was 11 runs with the first draft's quiesce and 2 with this one, so a notice that does appear is worth a look at the seed, not a change to the walk). What must never happen is a merge that returns `null` (invariant 4 prints its inputs' ids), a token lost without a trace (invariant 2; the dry run's ten were all dismissed entries overwritten by the next park, Task 5), or `did not quiesce` (the dry run's were all the bound reached inside a quiesce that landed every tab's save in one fixed order; the per-tab settle above is the remedy, not a lower weight for `saveArrives`, which the dry run measured at 93 of 100).

Then once, wider: `WALK_SEEDS=2000 node --test tests/js/sync.merge.walk.test.js 2>&1 | tail -8`
Expected: 4000 tests, 0 fail, about 12 seconds. (The default 50 seeds are too few to see everything: with `overwriteOwed` ORed unconditionally, which is what this plan did before dry run 2, seeds 416, 861, 1582, 2249 and 2341 fail with `still owes an overwrite`. Task 7 item 11 is the repair.)

- [ ] **Step 2b: The walk with the failures of section 11 switched on**

The walk above never fails a request, loses an answer or lets the clock pass an expiry, so a green 100 of 100 says nothing about those states: dry run 2 found the repairs of Tasks 7, 8, 10 and 11 only by extending it. Create `tests/js/sync.merge.walk.failures.test.js`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { SAVE_EXPIRY_MS } from '../../resources/js/editor/sync/host.js';
import { LOST, STALE, UUID, blocksOf, para, world } from './support/syncWorld.js';

/**
 * The random walk with the failures of section 11 switched on. Same fake world
 * as sync.merge.walk.test.js, but the steps also include: a save whose answer
 * is lost (and may come late), a save request that fails, a poll that fails,
 * the clock jumping past the 15 s expiries, an IME composition, an impossible
 * merge for a stretch, a restore or import by somebody else, an accepted
 * suggestion, a tab that dies and reopens over its own draft store, an
 * individual Put it back, and a browser that keeps no drafts.
 *
 * Checked after every step:
 *   A. "Saved" is never said while the editor holds a token no stored version
 *      ever had.
 * Checked once everything has landed:
 *   B. an accepted save that dropped a token nobody knowingly deleted said it
 *      overwrites;
 *   C. no document is sent twice on one base, unless the first request failed
 *      or was given up on (15 s);
 *   D. every tab converges on the server's document, and a fresh token typed
 *      on each tab is saved;
 *   E. no token is lost without a trace (a version, the lost row, a set-aside
 *      text, a draft, or a park);
 *   F. no merge returned null with the merge not made impossible, and no
 *      notice or merge is left standing;
 *   G. no overwrite is still owed;
 *   H. no duplicate block id.
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
    const knowinglyDeleted = new Set(); // tokens in a block somebody deleted on purpose
    const everStored = new Set(); // tokens any stored version has ever held
    const excused = new Set(); // tokens the writer, or a browser without drafts, knowingly let go
    const lateAnswers = []; // lost answers that may still arrive
    let tokens = 0;
    let inserted = 0;
    let generation = 0;
    let events = 0; // orders the sends and the answers of saves, for invariant C
    let actor = 'unknown';
    const actors = new Map();
    tokensIn(server.json).forEach((token) => everStored.add(token));

    const realSave = server.save.bind(server);
    server.save = (json, base, overwrite = false) => {
        const result = realSave(json, base, overwrite);
        if (result.ok) {
            actors.set(result.version, { actor, base, overwrite });
        }

        return result;
    };

    const track = (tab) => {
        const realArrive = tab.saveArrives;
        tab.saveArrives = () => {
            actor = `${tab.name}.saveArrives`;

            return realArrive();
        };
        const realLeave = tab.leave;
        tab.leave = () => {
            actor = `${tab.name}.beacon`;

            return realLeave();
        };
        // When each save was sent and answered, for invariant C.
        const realSend = tab.env.save;
        tab.env.save = (json, baseVersion, overwrite) => {
            const promise = realSend(json, baseVersion, overwrite);
            const record = tab.sent[tab.sent.length - 1];
            record.at = clock.now;
            record.sendSeq = ++events;
            record.key = `${baseVersion}|${overwrite}|${JSON.stringify(json)}`;
            record.answered = false;
            promise.then(
                () => {
                    record.answered = 'ok';
                    record.answerSeq = ++events;
                },
                () => {
                    record.answered = 'failed';
                    record.answerSeq = ++events;
                }
            );

            return promise;
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
        generation += 1;
        const tab = w.tab(`${String.fromCharCode(65 + index)}${generation}`, { drafts, ...options });
        tab.slot = index;
        tab.drafts = drafts;
        tab.noDrafts = options.draftStore === false;
        track(tab);
        if (tab.draftStore) {
            // The Phase 1 stale- park holds one text: parking again replaces the earlier one, knowingly.
            const realPark = tab.draftStore.parkStaleDraft;
            tab.draftStore.parkStaleDraft = async (uuid, json, baseVersion) => {
                const previous = tab.drafts.get(`stale-${uuid}`);
                if (previous) {
                    tokensOfString(previous.json).forEach((token) => excused.add(token));
                }

                return realPark(uuid, json, baseVersion);
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

    // A. checked after every step
    const afterStep = (label) => {
        server.trace.forEach((row) => tokensIn(row.to).forEach((token) => everStored.add(token)));
        server.kept.forEach((row) => tokensIn(row.json).forEach((token) => everStored.add(token)));
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
        // The earlier offered text is replaced by this one, a choice the writer confirmed or the park holds one text.
        if (tab.view.setAside) {
            tokensOfString(tab.view.setAside).forEach((token) => excused.add(token));
        }
        await tab.host().loadTheirs();
        trace.push(`${tab.name} loads theirs`);
        if (tab.view.setAside && random() < 0.5) {
            if (tab.view.unsaved) {
                excuseUnstored(tab);
            }
            tab.host().putBack();
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
                tokensIn({ content: [tab.block(id)] }).forEach((token) => knowinglyDeleted.add(token));
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
                    actor = `${tab.name}.lostAnswerStored`;
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
                    actor = `${tab.name}.late`;
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
                    actor = `${tab.name}.failedButStored`;
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
                tab.host().applySuggestion(content, version);
                trace.push(`${tab.name} accepts a suggestion (v${version})`);
            },
        ],
        [
            on('putback', 2),
            () => {
                const tab = pick(slots);
                if (tab.view.lost.length > 0) {
                    tab.host().putBackLost(Math.floor(random() * tab.view.lost.length));
                    trace.push(`${tab.name} puts one back`);
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
            () => {
                const tab = pick(slots);
                if (tab.view.lost.length === 0) {
                    return;
                }
                if (tab.noDrafts) {
                    tab.view.lost.forEach((entry) => tokensOfEntry(entry).forEach((token) => excused.add(token)));
                }
                tab.host().dismissLost();
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
                    if (tab.view.unsaved) {
                        excuseUnstored(tab);
                    }
                    tab.host().putBack();
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
    knowinglyDeleted.forEach((token) => kept.add(token));
    excused.forEach((token) => kept.add(token));
    slots.forEach((tab) => {
        tab.view.lost.forEach((entry) => tokensOfEntry(entry).forEach((token) => kept.add(token)));
        tokensOfString(tab.view.setAside).forEach((token) => kept.add(token));
        for (const [key, row] of tab.drafts.entries()) {
            if (key === UUID || key === STALE) {
                tokensOfString(row.json).forEach((token) => kept.add(token));
            } else if (key === LOST) {
                [...(row.entries ?? []), ...(row.dismissed ?? [])].forEach((entry) => tokensOfEntry(entry).forEach((token) => kept.add(token)));
            }
        }
    });
    const silently = [...typed.keys()].filter((token) => !kept.has(token));
    if (silently.length > 0) {
        violations.push(`E: tokens lost without a trace: ${silently.map((token) => `${token} by ${typed.get(token).author} in ${typed.get(token).block}`).join(', ')}`);
    }

    // ── B. a save that dropped content said it overwrites.
    server.trace.forEach(({ version, from, to, overwrite }) => {
        const after = tokensIn(to);
        const dropped = [...tokensIn(from)].filter((token) => !after.has(token) && !knowinglyDeleted.has(token));
        if (dropped.length > 0 && !overwrite) {
            violations.push(`B: version ${version} dropped ${dropped.join(',')} from the head without saying it overwrites (by ${JSON.stringify(actors.get(version))})`);
        }
    });

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
                const legitimate = (earlier.answered === 'failed' && earlier.answerSeq < record.sendSeq) || (unansweredThen && waited >= SAVE_EXPIRY_MS);
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
```

Run: `node --test tests/js/sync.merge.walk.failures.test.js 2>&1 | tail -8`
Expected: PASS, 200 tests (100 seeds, two and three tabs), `fail 0`, in a few seconds. Like the first walk it has no failing-first step: it checks what Tasks 1 to 11 built, and a failure is a finding. On the code of this plan WITHOUT the repairs of dry run 2 it fails within its first hundred seeds on 23 (invariant E: tokens lost without a trace, a second accepted suggestion replacing a standing set-aside text; Task 11) and 100 (invariant B: a save that dropped tokens from the head without saying it overwrites), and within its first five hundred on 146, 189, 247, 333, 386, 420, 440, 442 and 453 as well (among them 146, 420 and 442: the save given up on inside the suggestion merge, Task 11; 247: the older document under the notice, Task 7). Replay one: `ADV_SEED=<n> ADV_TABS=<2|3> node --test tests/js/sync.merge.walk.failures.test.js`; `ADV_TRACE=1` prints its steps. Wider, once: `ADV_SEEDS=1500 node --test tests/js/sync.merge.walk.failures.test.js 2>&1 | tail -8` (3000 tests, 0 fail, about 20 seconds). What it does not cover: characters inside one block, two tabs of one browser sharing a draft, and the server's own failures.

Run: `npm test`
Expected: every JavaScript test passes (846 tests: 546 after Task 11, plus the first walk's 100 and this one's 200).

- [ ] **Step 3: Commit**

```bash
git add tests/js/sync.merge.walk.test.js tests/js/sync.merge.walk.failures.test.js
git commit -m "test(editor): a random-walk convergence harness with a no-silent-loss invariant

Two and three tabs take 200 seeded random steps (typing unique tokens,
inserting, deleting, moving and re-aligning blocks, debouncing, saves
arriving and answering, polls, the clock, Put them all back, Dismiss all)
on the shared fake world, then everything in flight lands. Every tab
must equal the server; every token must be on the server, in a lost row
or its park (offered or dismissed), in a kept version, or knowingly
deleted; ids stay unique; no merge was impossible and no notice stands
(the MERGE_ATTEMPTS bound, the one notice the walk can raise, is
answered with Keep mine in the quiesce, which settles each tab before
the next tab's save lands); nothing is owed; and a save that dropped a
token nobody deleted knowingly said it overwrites. Seeds 1 to 50;
WALK_SEEDS widens it, WALK_SEED replays one, and a failure prints the
seed and the trace. A second file takes the same world through the
failures of section 11 (lost and late save answers, failed requests and
polls, the clock past the expiries, composition, restores, accepted
suggestions, tabs that die over a draft, individual Put it back, a
browser without drafts) with eight invariants; it found the repairs of
Tasks 7, 8, 10 and 11 that the first walk could not see.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 13: The real fixture pair, and the dead `version-restored` dispatch

**Files:**
- Create: `tests/Feature/Documents/MergeFixtureTest.php`
- Create (by the test): `tests/fixtures/merge/stored-form.json`
- Modify: `tests/js/sync.merge.editor.test.js` (the merge of the pair)
- Modify: `app/Livewire/Documents/VersionHistory.php` (one line deleted)
- Modify: `tests/Feature/Documents/VersionHistoryTest.php` (one assertion)

**Interfaces:**
- Consumes: `tests/fixtures/merge/editor-form.json` (Task 4), `DocumentStore::save()` and `::json()`.
- Produces: `stored-form.json`, the server's form of the editor's form (written by `MergeFixtureTest` when missing or when `MERGE_FIXTURE_WRITE=1`; asserted otherwise); a case in `sync.merge.editor.test.js` that merges the pair over the editor's own schema and expects no change and no loss in either direction (skipped, with a message, while `stored-form.json` does not exist).
- Produces: `VersionHistory::restore()` dispatches nothing; `VersionHistoryTest` pins it.
- Decision (dry run 2): the case also pins the levelling of the COMPARE copy on stamped blocks: a paragraph holding the server-stamped cross-reference, and the stamped contents list, both edited by the writer, merge with nothing offered back. The unit test `levelling: a paragraph B typed in is kept ...` (Task 1) pins the same on the mini schema.

- [ ] **Step 1: Write the PHPUnit test**

Create `tests/Feature/Documents/MergeFixtureTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The real fixture pair of the paragraph-level merge (Phase 2, section 12
 * of the spec). tests/js/sync.merge.editor.test.js captures
 * editor-form.json, one of everything as TipTap's editor serialises it;
 * this test stores that document through DocumentStore::save() and writes
 * what the store hands back to the editor (stamped contents list and
 * cross-reference labels, `[]` for the empties, no `align: null` on a
 * paragraph or heading) as stored-form.json. The node suite then merges the
 * two over the editor's own schema: a canonicalisation difference that the
 * merge's levelling does not absorb shows up there as a false change or a
 * false loss.
 *
 * The stored form is written when the file is missing or when
 * MERGE_FIXTURE_WRITE=1 is set, and asserted against on every other run,
 * so a change in what the store does to a document is seen here first.
 */
class MergeFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const EDITOR_FORM = 'tests/fixtures/merge/editor-form.json';

    private const STORED_FORM = 'tests/fixtures/merge/stored-form.json';

    public function test_the_stored_form_is_what_the_store_makes_of_the_editor_form(): void
    {
        $editorForm = base_path(self::EDITOR_FORM);
        $this->assertFileExists($editorForm, 'tests/js/sync.merge.editor.test.js captures editor-form.json: run npm test first');
        $json = json_decode(file_get_contents($editorForm), true, 512, JSON_THROW_ON_ERROR);

        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Fixture');
        $store->save($doc, $json, $user);

        $stored = $store->json(Document::findOrFail($doc->id));

        $this->assertSame('doc', $stored['type']);
        $this->assertSame(
            array_map(fn (array $block) => $block['attrs']['id'], $json['content']),
            array_map(fn (array $block) => $block['attrs']['id'], $stored['content']),
            'every top-level block keeps its id on the way through the store',
        );

        $encoded = json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $path = base_path(self::STORED_FORM);
        if (! file_exists($path) || getenv('MERGE_FIXTURE_WRITE')) {
            file_put_contents($path, $encoded);
            fwrite(STDERR, "[fixture] wrote {$path}\n");
        }

        $this->assertJsonStringEqualsJsonFile($path, $encoded);
    }
}
```

- [ ] **Step 2: Run it, twice**

Run: `php artisan test --compact tests/Feature/Documents/MergeFixtureTest.php`
Expected: PASS, 1 test (8 assertions), and `[fixture] wrote .../tests/fixtures/merge/stored-form.json` on stderr. Run it again: PASS, nothing written. If `DocumentStore::save()` throws `InvalidArgumentException` naming a block, `DocumentSchema::validate()` refuses something in the editor form: take that block out of `FIXTURE_DOC` in `tests/js/sync.merge.editor.test.js`, re-capture with `MERGE_FIXTURE_WRITE=1 node --test tests/js/sync.merge.editor.test.js`, and say which block in the commit message.

Open `tests/fixtures/merge/stored-form.json` and confirm by eye: `"vars": []` at the top, no `"align": null` on any paragraph, `entries` of the contents list filled, a `label` on the cross-reference.

- [ ] **Step 3: Merge the pair over the editor's schema**

Append at the end of `tests/js/sync.merge.editor.test.js`:

```js

// ───────────────────────────────────────────────────────── the real pair: the editor's form against the server's

const STORED = fileURLToPath(new URL('../fixtures/merge/stored-form.json', import.meta.url));

test(
    'the stored form merges with the editor form as no change and no loss, both ways round, and a real edit stays mine',
    { skip: !existsSync(STORED) && 'stored-form.json is produced by MergeFixtureTest: php artisan test --compact tests/Feature/Documents/MergeFixtureTest.php' },
    () => {
        const editorForm = JSON.parse(readFileSync(FIXTURE, 'utf8'));
        const storedForm = JSON.parse(readFileSync(STORED, 'utf8'));
        const { editor } = realEditor(editorForm);
        const schema = editor.schema;

        // The page typed nothing; the server re-stamped and re-serialised. A follow: nothing of mine.
        const stored = mergeDocuments(schema, { base: editorForm, mine: editorForm, theirs: storedForm });
        assert.notEqual(stored, null);
        assert.equal(stored.changed, false, 'a canonicalisation difference is not a change');
        assert.deepEqual(stored.lost, []);
        assert.equal(stored.overwrites, false);

        // The other way round: a tab that loaded the stored form against the editor's form.
        const back = mergeDocuments(schema, { base: storedForm, mine: storedForm, theirs: editorForm });
        assert.notEqual(back, null);
        assert.equal(back.changed, false);
        assert.deepEqual(back.lost, []);

        // The writer edited ONE paragraph after the server stored the document: only that block is mine,
        // and the stamps the server wrote are on screen.
        const typed = JSON.parse(JSON.stringify(editorForm));
        typed.content[typed.content.length - 1].content = [{ type: 'text', text: 'Centred end, and more.' }];
        const edited = mergeDocuments(schema, { base: editorForm, mine: typed, theirs: storedForm });
        assert.notEqual(edited, null);
        assert.equal(edited.changed, true);
        assert.deepEqual(edited.lost, []);
        assert.equal(edited.merged.content.at(-1).content[0].text, 'Centred end, and more.');
        assert.deepEqual(edited.merged.content[2].attrs.entries, storedForm.content[2].attrs.entries, 'the stamped contents list is on screen');

        // The writer also edited the paragraph that holds the cross-reference (the server stamped its label) and
        // the contents list (the server stamped its entries): both blocks are mine, and nothing is offered back.
        const stamped = JSON.parse(JSON.stringify(editorForm));
        stamped.content[1].content.push({ type: 'text', text: ' (checked)' });
        stamped.content[2].attrs.depth = 2;
        const stampedMerge = mergeDocuments(schema, { base: editorForm, mine: stamped, theirs: storedForm });
        assert.notEqual(stampedMerge, null);
        assert.deepEqual(stampedMerge.lost, [], 'a server stamp inside a block the writer edited is not a change by somebody else');
        assert.ok(stampedMerge.merged.content[1].content.at(-1).text.endsWith(' (checked)'));
        assert.equal(stampedMerge.merged.content[2].attrs.depth, 2);

        editor.destroy();
    }
);
```

Run: `node --test tests/js/sync.merge.editor.test.js`
Expected: PASS, 9 tests, 0 failures, none skipped. A failure in the last two assertions (a `lost` entry for a block the writer edited, with a server stamp inside it) means the COMPARE copy is not levelled: `merge.js` must parse `levelDerived(levelEmpty(json))` for comparison and keep the stamps only in the output copy (dry run 2: replacing it by `levelEmpty(json)` alone left every other test green). A failure in the first merge (`changed` true, or a `lost` entry) is a canonicalisation gap: the message names the block; compare that block in the two files, and extend `levelEmpty()`/`levelDerived()` in `sync/merge.js` (with a mini-schema case in `sync.merge.test.js` first) only if the difference is one the server writes on EVERY save; a difference the schema should not have produced goes to the owner.

Run: `npm test`
Expected: every JavaScript test passes (847 tests: 846 after Task 12, plus the pair case).

- [ ] **Step 4: Pin that a restore dispatches nothing, then delete the dispatch**

In `tests/Feature/Documents/VersionHistoryTest.php`, replace

```php
        Livewire::actingAs($user)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('restore', $old->id);
```

with

```php
        // The `version-restored` browser event had no listener anywhere:
        // open editors follow a restore through the sync poll, and merge it
        // over unsaved typing (Phase 2). Nothing is dispatched.
        Livewire::actingAs($user)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('restore', $old->id)
            ->assertNotDispatched('version-restored');
```

Run: `php artisan test --compact tests/Feature/Documents/VersionHistoryTest.php`
Expected: 1 FAIL (`Failed asserting that an event [version-restored] was not fired`), 2 pass.

In `app/Livewire/Documents/VersionHistory.php`, replace

```php
        app(AuditLogger::class)->record('version.restored', $this->document, [
            'version_number' => $version->version_number,
        ]);

        $this->dispatch('version-restored', content: $this->document->content);
        $this->previewId = null;
```

with

```php
        app(AuditLogger::class)->record('version.restored', $this->document, [
            'version_number' => $version->version_number,
        ]);

        // No browser event: nothing listened for one. Open editors follow a
        // restore through the sync poll (a clean tab) or merge it over their
        // unsaved typing (resources/js/editor/sync/host.js).
        $this->previewId = null;
```

Run: `php artisan test --compact tests/Feature/Documents/VersionHistoryTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
git add tests/Feature/Documents/MergeFixtureTest.php tests/fixtures/merge/stored-form.json tests/js/sync.merge.editor.test.js app/Livewire/Documents/VersionHistory.php tests/Feature/Documents/VersionHistoryTest.php
git commit -m "test(documents): the real merge fixture pair; the dead version-restored dispatch goes

MergeFixtureTest stores the editor's own serialisation of one of
everything through DocumentStore and writes what the store hands back as
stored-form.json; sync.merge.editor.test.js merges the pair over the
editor's schema and expects no change and no loss either way round, so a
canonicalisation gap the merge's levelling does not absorb is seen as a
test failure, not as a paragraph offered back to a writer. The
version-restored browser event, which nothing listened for, is gone from
VersionHistory::restore(): open editors follow or merge a restore through
the sync poll.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

Expected from PHPStan: `[OK] No errors`.

---

## Task 14: The standing rules, the roadmap, the whole suite, the browser pass, the pull request

**Files:**
- Modify: `.ai/rules/editor.md`, `.ai/rules/livewire.md`, `.ai/rules/views.md`, `.ai/rules/app.md`
- Modify: `docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md` (the Phase 2 paragraph)
- Modify: `docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md` (section 5.5, and a new section 17)
- Add: this plan, `docs/superpowers/plans/2026-10-16-coediting-phase-2-paragraph-merge.md` (untracked until Step 5; the roadmap paragraph and the rules cite it)

**Interfaces:** none.

- [ ] **Step 1: `.ai/rules/editor.md` (and one sentence each in `views.md`, `livewire.md` and `app.md`)**

Four amendments to existing sentences of `editor.md` (items 1 to 4), one new section at its end (item 5), two more amendments (items 6 and 7), and one sentence each in `views.md`, `livewire.md` and `app.md` that Phase 2 makes untrue (item 8). `grep -rin 'all three notices\|three notices' .ai/rules` finds the ones in item 8.

1. In the section `## Other people's saves arrive through the sync engine, and applyRemote() is a minimal, non-destructive change`, replace

```markdown
In `dirty` it reports a conflict: saving is suspended and the writer chooses "Keep mine" (save over the newer version, with the base moved up to it; the server keeps the replaced version in the history) or "Load theirs"
```

with

```markdown
In `dirty` it hands the document to `mergeOrConflict()` (see "A newer document over unsaved typing is MERGED block by block" below): the typing is merged into it block by block and saved on the new base, and only when the merge cannot be done does it report a conflict: saving is then suspended and the writer chooses "Keep mine" (save over the newer version, with the base moved up to it; the server keeps the replaced version in the history) or "Load theirs"
```

2. In the same section, replace

```markdown
`handle.applyRemote(json, {force})` replaces only the range that differs (`sync/narrow.js` `diffRange()`, built on `Fragment.findDiffStart/findDiffEnd`), in one transaction tagged `addToHistory: false`
```

with

```markdown
`handle.applyRemote(json, {force})` changes what differs BLOCK BY BLOCK (`blockSteps()` in `sync/apply.js`: a changed textblock inside the block only, any other changed block whole, deletions and insertions as their own steps, a deletion and an insertion at one point as one replace so the document never passes through an empty `doc`; the steps are probed on a copy first, a narrowed step that fails is retried whole, and when a step still fails, the probe does not reproduce the target, or there are more than `MAX_BLOCK_STEPS` (500) steps, it falls back to the one range that differs, `sync/narrow.js` `diffRange()` built on `Fragment.findDiffStart/findDiffEnd`, and then to the whole document), in one transaction tagged `addToHistory: false`
```

3. In the section `## A remote change empties the undo and redo history`, replace

```markdown
A remote document is not only ever applied to a tab with no unsaved typing: the ENGINE applies one only to such a tab (a tab that is typing gets the conflict notice instead), and the two applies that go over unsaved typing, with `force`, are the writer's own choice: Load theirs, which sets the typing aside first, and an accepted suggestion, which replaces whatever the tab holds.
```

with

```markdown
A remote document is not only ever applied to a tab with no unsaved typing: the ENGINE applies one only to such a tab, and the applies that go over unsaved typing, with `force`, are a MERGED document (the typing is in it, and what could not be kept is offered back: `mergeOrConflict()`, the draft merge and the suggestion merge in `sync/host.js`) and the writer's own choice: Load theirs, which sets the typing aside first, and an accepted suggestion that could not be merged, which sets it aside first too.
```

4. In the section `## The editor page's save and conflict decisions live in sync/host.js, which holds no state`, replace

```markdown
Like the rest of `sync/` it takes nothing from outside `sync/` (it has no import at all; `apply.js` imports `./narrow.js`), and it has no DOM, no `window`, no Livewire, no clock and no console of its own.
```

with

```markdown
Like the rest of `sync/` it takes nothing from outside `sync/` (its one import is `./merge.js`, the lost-row helpers; `apply.js` imports `./narrow.js`; `insert.js` imports `./narrow.js`), and it has no DOM, no `window`, no Livewire, no clock and no console of its own.
```

and replace

```markdown
The module reads and writes `saving`, `savingSince`, `saveSeq`, `expiredSaveSeq`, `unsaved`, `resave`, `confirmed`, `overwriteOwed`, `conflict`, `setAside`, `setAsideFrom`, `setAsideBase`, `syncNotice`, `memberKey`, `baseVersion`, `aiError` and `tick`, and only reads `docUuid` and `documentVersion`; a new state field is declared in the `x-data` object, never in the module.
```

with

```markdown
The module reads and writes `saving`, `savingSince`, `saveSeq`, `expiredSaveSeq`, `unsaved`, `resave`, `confirmed`, `overwriteOwed`, `conflict`, `setAside`, `setAsideFrom`, `setAsideBase`, `syncNotice`, `memberKey`, `baseVersion`, `aiError`, `tick`, `merging`, `mergeAttempts`, `known`, `sentSnapshot`, `unanswered`, `lost` and `putBackSeq`, and only reads `docUuid` and `documentVersion`; a new state field is declared in the `x-data` object, never in the module.
```

5. Append this section at the end of the file:

```markdown

## A newer document over unsaved typing is MERGED block by block; the notice is for a merge that cannot be done
Phase 2 of co-editing (`docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md`). A server document that arrives while the tab holds unsaved typing, whether by the poll (`engineHost().onConflict`) or after a save was refused as stale (`saveAnswered()` -> `beginMerge()`), goes to `mergeOrConflict()` in `sync/host.js`, which merges it with `handle.merge()` (`mergeDocuments()` in `sync/merge.js`, pure, run under `node --test` with `tests/fixtures/merge/*.json`), applies the merged document with `applyRemote(merged, {force: true})` and saves it on the new base. The merge matches TOP-LEVEL blocks by id against the base (the document of the version the typing is based on) and decides each block on its own: a block only one side changed takes that side; a block both changed takes THEIRS and mine is offered back (owner decision D1: the later saver loses that block only, on every round); a block they deleted while I typed in it goes and my words are offered back with up to five preceding ids as its place (D2); a block I deleted that they edited goes too and the save carries `overwrite`, so `DocumentStore::save()` keeps their version in the history first (case c2; they are not told, Q1; the label is "Before <name> kept their version", Q2). Characters inside one block are NOT merged, and a list, table, columns block, callout or figure is one block: the loser's whole table is offered back. Nothing is compared as JSON: every input is parsed twice through the schema, a compare copy with the server-stamped `toc.entries`/`crossRef.label` blanked and `[]` levelled to `{}` (the `DERIVED` table, duplicated from `derived.js`; `tests/js/derived.test.js` holds the two identical), and an output copy with the stamps kept. The order is theirs unless only I moved blocks; an inserted block is anchored after its nearest preceding block that is on the page, first when it had no predecessor, LAST when every predecessor is gone (a typist's paragraph lands after an imported document, not before its title). This editor's own trailing paragraph (TrailingNode) is carried into both the merged document and `theirs`, so a merge in which nothing of mine survived still leaves `confirmed` equal to what the editor serialises and `settleIfBackAtConfirmed()` reachable. When each side deleted what the other kept and no block is left, the merge is one empty paragraph under the id of mine's last block (`doc` is `block+`; two deletions are a merge, not the notice). A parse throw, a top-level block without an id, two blocks sharing one, or a merged document that fails `check()` make the merge impossible: `null`, and today's notice, whose sentence now says the two could not be combined. A lost entry's `json` is a plain copy (`Node.toJSON()` builds `attrs` with `Object.create(null)`, which strict `deepEqual`, IndexedDB and Alpine all treat differently from a plain object); `doc.attrs` are compared as JSON strings, so `vars` with the same keys in another order count as mine's change (one extra save, nothing lost). A block whose id the server re-stamps, or that TipTap re-ids on a block conversion, looks like delete + insert. `tests/js/sync.merge.editor.test.js` pins which conversions do: `toggleHeading` and `toggleCodeBlock` keep the id; wrapping a paragraph in a bullet list, ordered list, task list or quote gives the wrapper a new top-level id (the paragraph id moves inside it). So a saved edit to a paragraph that somebody else then wraps is dropped from the page (case c2: kept in the history, nobody told), and a typist in a paragraph somebody else wraps is told it was removed. `applyRemote()` applies block by block only up to `MAX_BLOCK_STEPS` (500) steps; a document-sized change takes the one range of Phase 1.

The loop is bounded and holds while a merge is under way. A save refused as stale (`result.conflict && result.version > baseVersion`) sets `resave` (the text is still owed) and, unless the notice stands or `mergeAttempts` has reached `MERGE_ATTEMPTS` (5; the spec's 3 let three writers in three different paragraphs reach the notice with every merge possible), counts one attempt and calls `beginMerge()`, which does nothing fail-closed, merges the engine's pending document at once when it is new enough, and otherwise DROPS a pending document older than the refused version (`engine.takeRemote()`: left there, the `retry()` after the answered save would merge it, end `merging` and send on a base known to be stale), sets `merging = {version, since}` and `refetch()`es (not `poke()`: the engine may have SEEN that version). While `merging` is set `persist()` sends nothing (`resave = true`; a save on a base known to be stale is churn), `syncState()` stays `dirty`, and `resendIfOwed()` calls `expireMerging()` first: a fetch unanswered for `MERGE_FETCH_EXPIRY_MS` (15 s) ends in the notice, and the owed save goes out after the writer's choice. An accepted save, `applyFromSync()` and a merge that leaves nothing of mine to save (`changed` false: a follow in disguise, the echo case) reset the count; an OK answer for a version at or above `merging.version` ends the merge too (`saveAnswered()`: a late answer of a save given up on, arriving while the fetch is out, names the very version `engine.saved()` marks as seen, so the fetch would never be delivered); `enterConflict()` clears `merging` and the count and drops a pending engine document OLDER than the version the notice names (`engine.takeRemote()`, then it refetches: Load theirs must show the version the notice names, not one the head has moved past); `adoptVersion()` ends a merge at or below the adopted version as it ends a conflict. On a 200 poll the engine runs `onMembers()` (and with it `resendIfOwed()`) BEFORE it delivers the document, so a save given up on at 15 s goes out again on its old base first and the merge happens on that resend's refusal, with the held document in hand. `mergeOrConflict()` itself does nothing fail-closed, updates the notice without merging while one stands (nothing is merged behind a writer who is choosing), waits while a save is in the air, and takes as its base `confirmed` or, when that is null (a lost answer, a failed request), `known` IF `known.version === baseVersion`: after Keep mine the base names a version the editor never held, there is no base, and the notice stands until a save is accepted (a stated limit). `known` (`{version, json}`) is the last server document this tab held and is NEVER nulled: `markConfirmed(json, version)` sets `confirmed`, `known` and clears `unanswered`, from `opened()`, an accepted save, `applyFromSync()`, `applySuggestion()` and the merge (`theirs` as this editor serialises it when something of mine survived, the editor's own serialisation when nothing did); `confirmed = null` stays a direct assignment where it was. `adoptVersion()` after this page's own restyle moves `known.version` with the base only when `known` was current (a restyle changes stamps, which the levelling absorbs). The echo: `persist()` records `sentSnapshot`; a save given up on (`saveInTheAir()`) or whose request failed sets `unanswered = sentSnapshot`; the merge takes it as `echo`, and wherever theirs agrees with the echo that block's base is theirs, so a tab recognises its OWN stored save and typing on top of it is kept (this closes the Phase 1 limit "a save whose answer is lost raises the notice against itself"); somebody else's edit of that block after the echo is an honest D1. `overwriteOwed` is ORed with the merge's `overwrites`, never assigned, while something of mine is left to save: an overwrite owed from Put it back or from an earlier c2 merge survives a merge that owes none but still has something of mine to save, until a save that carried it is accepted; a merge that leaves nothing of mine to save (`changed` false) clears it, because no save is left to carry it (the draft merge and the suggestion merge follow the same rule). A Put it back pressed after a flagged save left keeps its own overwrite: `putBack()` stamps `putBackSeq = saveSeq` and `persist()` passes `overwrite && putBackSeq < seq` to `saveAnswered()`, so only a save sent AFTER the put-back settles it (declare `putBackSeq` in the Blade: `undefined < n` is false, and the overwrite would be owed for ever). `syncState()` is `busy` while `handle.composing` (an IME composition): nothing is applied under one.

What a merge could not keep goes to the notice bar's lost row (`offerLost()`): entries `{key, id, type, reason: both|deleted, source: merge|draft|suggestion|parked, json, anchors, version, sentence, excerpt}` are APPENDED to `view.lost`, never replaced or coalesced per id (the only entry skipped is an exact duplicate: same id, identical json), so two losses of one paragraph in a row keep both offered versions (the D1 ping-pong); the WHOLE list is parked under `lost-<uuid>` (`parkLost()`), its own key, never the `stale-` slot Load theirs uses. `putBackLost(index)` puts an entry back through `handle.insertBlock()` (`insertBlockTransaction()` in `sync/insert.js`: after the first of its anchors that is on the page, `insertionPoint()` in `narrow.js`, else the end, else the start; a `both` block with a FRESH id below the winner, a `deleted` block with its OWN id where it was, so comments and cross-references on it come back; nested ids follow the same rule) as an ordinary undoable edit that fires `update` and is autosaved on the current base with NO overwrite flag (it replaces nothing); the entry goes and the park is rewritten with the rest. `putBackAllLost()` walks oldest first and stops at the first failure, which stays listed with `syncNotice = 'Your text could not be put back.'`. `dismissLost()` always asks (`DISMISS_LOST_QUESTION`: every entry is on the page nowhere), empties the row and stamps the park `dismissedAt`; `markLostDismissed()` moves the entries to the row's `dismissed` list, which every later `parkLost()` carries over while it rewrites `entries`, so the text the button promised "kept only in this browser, for 7 days" survives the next loss and is never offered again; it is NOT fail-closed-gated, like `discardSetAside()`. `restoreDraft()` re-offers a park's `entries` when the row was not dismissed, with `source: 'parked'` and the sentence prefixed "From your last visit: ", and does so BEFORE the draft logic: `offerLost()` parks the whole list, so a draft merge's losses are appended after the re-offered ones with their own source, and an earlier visit's park is read before anything overwrites it. Two tabs of one browser share the park (keyed per document, as drafts are). The row is five STATIC item slots switched with `hidden` (`LOST_ROWS = 5` in `host.js`, mirrored by the markup), never `x-for` (`.ai/rules/livewire.md`).

Drafts remember their base. `base-<uuid>` (`saveBase()`/`loadBase()` in `offline.js`) holds `confirmed` with its version, written by `markConfirmed()` only, never per keystroke, and by `restoreDraft()` AFTER the draft check (`opened()` passes `writeRow: false`): a draft the document has moved past is merged against the row of ITS version, which the page must read before it writes its own. `restoreDraftIfRestorable()` calls `mergeDraft()` first in the stale branch: with a row of the draft's version, `handle.merge(base, pageDocument, {mine: draft, deletionsWin: false})`; no change and no loss clears the draft without a question (the beacon stored it, or it is all in the document); otherwise `DRAFT_MERGE_QUESTION`, and on yes the pre-merge copy is parked as `stale-` as today, the merged document applied, losses offered with the `draft` sentences, and what survived saved on the first tick (the word says Editing until then: `opened()` said Saved a moment before). `deletionsWin: false` because D2 was decided for live co-typing: a days-old draft's deletion of a block somebody changed since does not win and owes no overwrite. No row (an older build, or swept after 7 days), a merge that cannot be done, or a declined question fall to today's park and set-aside. The sweep (`parkedRowIsExpired()`) expires `stale-`, `lost-` and `base-` rows alike and never a live draft. An accepted suggestion over unsaved typing is merged the same way (`mergeSuggestion()`, source `suggestion`), and when it cannot be the typing is set aside first (`setAsideTyping()`), never replaced silently. Whatever save is in the air is given up on first (its older document must not become the echo of the next merge, nor its OK answer move the base back); and when a text is ALREADY set aside, an unmergeable suggestion is not accepted over the typing at all (`aiError` asks the writer to put back or discard the standing text first, the other tabs still receive it through the poll), because the `stale-` park holds one text. Load theirs asks (`LOAD_THEIRS_REPLACES_QUESTION`) before it replaces a text that is already set aside, for the same reason. A restore or an import is merged like any remote document (decision 13: no replacement heuristic). Every new path that writes into the editor carries the fail-closed gate (`.ai/rules/livewire.md`). The server changed nothing: no endpoint, no column, no dependency, `PROTOCOL` stays 1; the only `app/` change was deleting the dead `version-restored` dispatch. `tests/js/sync.merge.walk.test.js` walks two and three tabs through 200 seeded random steps and checks convergence, no silent loss, unique ids, no notice, nothing owed and kept heads; `WALK_SEED=<n>` replays a seed and `WALK_SEEDS=<n>` runs seeds 1 to n. `tests/js/sync.merge.walk.failures.test.js` repeats it with the failures of section 11 switched on (lost and late save answers, failed requests and polls, the clock past the 15 s expiries, composition, restores and imports, accepted suggestions, tabs that die over a draft, individual Put it back, a browser without drafts; `ADV_SEED`, `ADV_TABS`, `ADV_SEEDS`, `ADV_FEATURES`); a change to the loop is not done until both walks are green.
```

6. In the section `## Draft recency is decided by VERSION, never by a clock; drafts are cleared only when the save settled`, replace

```markdown
a stale one (`conflict: true`) raises the conflict notice.
```

with

```markdown
a stale one (`conflict: true`) starts a merge (`beginMerge()`) and raises the conflict notice only when that merge cannot be done.
```

7. Back in the section `## Other people's saves arrive through the sync engine, and applyRemote() is a minimal, non-destructive change`, replace

```markdown
when a server document has been applied (`applyFromSync()`, and `applySuggestion()` for an accepted suggestion), and when
```

with

```markdown
when a server document has been applied or merged into the typing (`applyFromSync()`, `applySuggestion()` for an accepted suggestion, and `mergeOrConflict()`, the draft merge and the suggestion merge, which leave it set exactly when something of the typing is still to be saved), and when
```

and replace

```markdown
BOTH set `overwriteOwed`:
```

with

```markdown
BOTH set `overwriteOwed` (so does a merge whose deletion beats a block somebody else edited and saved, ORed in and never assigned: see the Phase 2 section at the end):
```

8. Three one-sentence amendments in other rule files. In `.ai/rules/views.md` (the section about the `hidden` attribute), replace

```markdown
without the rule all three notices show all the time
```

with

```markdown
without the rule every notice shows all the time
```

In `.ai/rules/livewire.md` (the section about the notice bar), replace

```markdown
the next render hides all three notices
```

with

```markdown
the next render hides every notice
```

In `.ai/rules/app.md` (the paragraph about `DocumentStore::save()` and `$overwrite`), replace

```markdown
a writer knowingly saving over a newer version that their base is on.
```

with

```markdown
a writer knowingly saving over a newer version that their base is on; since Phase 2 also the merged save of a tab whose deletion beat a block somebody else edited and saved (case c2: `mergeOrConflict()` ORs the merge's `overwrites` into `overwriteOwed`), where the label "Before <name> kept their version" misdescribes a merge (owner question 2, accepted).
```

- [ ] **Step 2: `.ai/rules/livewire.md`**

In the section `## Every editor-mutating path in the bridge carries the same fail-closed gate`, replace

```markdown
resendIfOwed(), keepMine(), loadTheirs(), putBack() AND applySuggestion(), which was missing the gate and let an accepted suggestion mutate a read-only view.
```

with

```markdown
resendIfOwed(), keepMine(), loadTheirs(), putBack(), applySuggestion() (which was missing the gate and let an accepted suggestion mutate a read-only view), and since Phase 2 mergeOrConflict(), beginMerge() (it fetches a document only to write it back, and its 15 s expiry would otherwise raise "Not saved" on a read-only page), putBackLost(), putBackAllLost(), the draft merge (mergeDraft()) and the suggestion merge (mergeSuggestion()).
```

and replace

```markdown
discardSetAside() is deliberately NOT gated: it touches neither the editor nor a draft, it only withdraws an offer (asking first when the offer is of this tab's own unsaved typing, set aside by Load theirs, which is in no saved version).
```

with

```markdown
discardSetAside() and dismissLost() are deliberately NOT gated: each touches neither the editor nor a draft, it only withdraws an offer (discardSetAside() asking first when the offer is of this tab's own unsaved typing, set aside by Load theirs; dismissLost() always asking, because every entry of the lost row is text a merge established is on the page nowhere, and stamping the lost- park dismissed, its entries moved to the row's `dismissed` list that later parks carry over, so the text keeps its 7 days and is not offered again).
```

In the section `## Notices with actions live in the notice bar, never in the status strip`, replace

```markdown
the conflict notice with Keep mine and Load theirs, the set-aside notice with Put it back and Discard, and the sync notice.
```

with

```markdown
the conflict notice with Keep mine and Load theirs, the lost row (Phase 2: what a merge could not keep, five static item slots each with its sentence, its excerpt and a Put it back of its own, an overflow line, Put them all back and Dismiss all; `x-bind:hidden="!lost.length"` and `"!lost[i]"`; never `x-for`, so PHPUnit pins every element and no Alpine pattern new to the bar is introduced; the list is `max-height: 40vh` and scrolls inside the row, so the sticky head cannot outgrow a phone's viewport and put Put them all back and Dismiss all out of reach), the set-aside notice with Put it back and Discard, and the sync notice.
```

and replace

```markdown
`EditorSyncWiringTest` pins where the bar is, that it is `wire:ignore` and its source holds nothing the server renders, the `hidden` attribute and the three bindings, the CSS rule, the sentences, the four buttons with their titles and descriptions, the one click listener, that the strip holds no button, the `aiError` word's `wire:ignore`, the Saved wrapper's condition, and that the page hands over to `sync/host.js` (`createSyncHost(this`, `this.syncHost().engineHost()`, `getOverwrite`).
```

with

```markdown
`EditorSyncWiringTest` pins where the bar is, that it is `wire:ignore` and its source holds nothing the server renders, the `hidden` attribute and the four row bindings (`!conflict`, `!lost.length`, `!setAside`, `!syncNotice`), the CSS rule, the sentences (the conflict sentence says the two could not be combined), the eleven buttons with their titles and descriptions (Keep mine, Load theirs, five Put it back, Put them all back, Dismiss all, Put it back, Discard), the lost row's five slots and overflow line, the one click listener, that the strip holds no button, the `aiError` word's `wire:ignore`, the Saved wrapper's condition, the seven merge state fields and the three lost-row delegates, and that the page hands over to `sync/host.js` (`createSyncHost(this`, `this.syncHost().engineHost()`, `getOverwrite`).
```

- [ ] **Step 3: The roadmap's Phase 2 paragraph**

In `docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md`, replace

```markdown
**What you see:** two people working in different paragraphs both keep typing
and see each other's paragraphs appear 1-3 seconds behind. If both change the
same paragraph in the same second, both versions are kept one under the other
with a notice. Nothing is dropped.
```

with

```markdown
**What you see:** two people working in different paragraphs both keep typing
and see each other's paragraphs appear 1-3 seconds behind. If both change the
same paragraph in the same second, the version saved first stays on the page
and the other is offered back in the notice bar, where Put it back puts it
under the first (owner decision D1: the later save loses that block only).
If one person deletes a paragraph the other is typing in, the deletion wins
and the typed words are offered back the same way, returning to where they
were (D2). Nothing is dropped. Built: spec
`docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md`,
plan `docs/superpowers/plans/2026-10-16-coediting-phase-2-paragraph-merge.md`.
```

- [ ] **Step 3b: Record the dry-run amendments in the spec**

The plan departs from the approved spec in the places below (each is argued under "Owner decisions this plan assumes"), and Step 7 puts the spec's section 2 and section 13 into the pull request: the spec on the branch must say what the code does. In `docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md`, replace

```markdown
   view.overwriteOwed = view.overwriteOwed || result.overwrites;       // OR, never assign (Judges 2 and 3)
```

with

```markdown
   view.overwriteOwed = result.changed ? view.overwriteOwed || result.overwrites : false;   // OR while something of mine is left to save; cleared when nothing is
```

and, in the paragraph "Why step 7's OR", replace

```markdown
OR keeps it until an accepted save carried it (existing rule).
```

with

```markdown
OR keeps it until an accepted save carried it (existing rule). Third sequence: the second merge ends with p5 deleted by both sides, so `changed` is false, nothing is sent and no save is left to carry the flag; an OR would leave `overwriteOwed` true at rest, and the next unrelated save would carry `overwrite: true` (a spurious "Before <name> kept their version" row) while `edited()` stamped the drafts one version below the base. A merge that leaves nothing of mine to save clears the flag; the OR holds only while `result.changed`.
```

Then append to the end of the file:

```markdown

---

## 17. Amendments made while planning (2026-10-17)

First round of dry runs:

- Sections 2, 3.1, 5.1, 5.4, 5.8, 11 and decision 16: `MERGE_ATTEMPTS` is 5, not 3 (at 3 the random walk ended a tab in the notice in 11 of 100 runs with every merge possible).
- Sections 4.6, 11 (row 1) and 13: two deletions that leave no block merge to one empty paragraph under the id of mine's last block, not to the notice.
- Section 5.4: `beginMerge()` drops a pending document older than the refused version (`engine.takeRemote()`) before it refetches; section 5.9: `beginMerge()` is fail-closed-gated.
- Section 3.1: `mergeAttempts` is also reset by a merge that leaves nothing of mine to save.
- Section 6.6: the lost row's re-offer runs BEFORE the draft logic in `restoreDraft()`.
- Sections 6.5, 7 and decision 18: the `lost-` row keeps a `dismissed` list beside `entries`.
- Section 6.3: Put them all back has its own title and description (`doc-notice-put-back-all-lost-does`).

Second round:

- Sections 3.1 and 12 (the wiring test and the harness): the page declares SEVEN merge fields, not six; `putBackSeq` joins `merging`, `mergeAttempts`, `known`, `sentSnapshot`, `unanswered` and `lost`. `putBack()` sets `putBackSeq = saveSeq`, and `persist()` passes `overwrite && putBackSeq < seq` to `saveAnswered()`: a Put it back pressed while a save that carried the overwrite is in the air is settled only by a save sent after it.
- Section 5.5 step 7 and its "Why step 7's OR" paragraph (also section 8's suggestion merge): the overwrite is ORed only while `result.changed`; a merge that leaves nothing of mine to save clears it.
- Section 5.4: `saveAnswered()` ends a merge whose version is at or below the answered one (a late OK answer of a save given up on, arriving while the fetch is out).
- Section 5.8: `enterConflict()` drops an engine document older than the version the notice names, then refetches.
- Section 8: an accepted suggestion first gives up the save in the air; when it cannot be merged and a text is already set aside, it is not accepted over the typing (`aiError` says to deal with the standing text first).
- Section 6.4: Load theirs asks (`LOAD_THEIRS_REPLACES_QUESTION`) before it replaces a text that is already set aside.
- Section 7.1: a draft merge that leaves text to save reports Editing until the first tick sends it.
- Section 5.10: more than `MAX_BLOCK_STEPS` (500) block steps take the one-range apply.
- Section 6.1: `excerpt()` cuts by code point.
- Section 4.4: when mine ends in the empty paragraph the editor added after the last confirmation and theirs ends in a NON-empty paragraph, the empty paragraph is an ordinary insertion and may be saved between two blocks. Intended: it cannot be told from one the writer typed on purpose, nothing is lost, and either writer can delete it.
- Section 12: the walk runs wider with `WALK_SEEDS`; a second walk, `sync.merge.walk.failures.test.js`, injects the failures of section 11 that the first never does.
- Section 13 gains: wrapping a paragraph in a bullet list, an ordered list, a task list or a quote gives the wrapper a new top-level id (the paragraph id moves inside it), so a merge sees a deletion plus an insertion; `toggleHeading` and `toggleCodeBlock` keep the id. When both sides changed the same document attribute (`vars`, `style`) the later saver's value wins silently and nothing is offered back (section 4.6).
```

- [ ] **Step 4: Run everything**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
npm test
npm run build
vendor/bin/phpstan analyse --memory-limit=1G
node scripts/design/contrast-dom.mjs > "${TMPDIR:-/tmp}/contrast.txt"; echo "exit=$?"; tail -1 "${TMPDIR:-/tmp}/contrast.txt"
grep -c 'database is locked' storage/logs/laravel.log || true
awk '/^    x-data="\{$/{f=1;next} /^    \}"$/{f=0} f && /"/{print FNR": "$0}' resources/views/livewire/documents/editor.blade.php
```

Expected: every PHPUnit test passes: 537 passed, 4 skipped, 3309 assertions (the pre-existing skips; 534 passed, 4 skipped before this plan; `MergeFixtureTest` 1, `VersionHistoryTest` 3, `EditorSyncWiringTest` 12); every JavaScript test passes: 847 in all (`sync.host.test.js` 219, `sync.merge.test.js` 48, `sync.merge.editor.test.js` 9, `sync.merge.walk.test.js` 100, `sync.merge.walk.failures.test.js` 200, `sync.apply.test.js` 25, `sync.narrow.test.js` 13, `offline.test.js` 10, `derived.test.js` 8, `sync.history.test.js` 12, `uniqueIds.test.js` 19, the rest as before); the build succeeds; PHPStan `[OK] No errors`; the contrast gate prints `exit=0` and `ALL PASS` (`.doc-notice-excerpt` measured in both modes; redirect it to a file as shown and never pipe it: the script ends with `process.exit()`, so into a pipe the output is cut at 64 KiB and `ALL PASS` never appears); `grep -c 'database is locked'` prints `0`; the awk line prints nothing.

- [ ] **Step 5: Commit the rules**

```bash
git add .ai/rules/editor.md .ai/rules/livewire.md .ai/rules/views.md .ai/rules/app.md docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md docs/superpowers/plans/2026-10-16-coediting-phase-2-paragraph-merge.md docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md
git commit -m "docs(rules): the paragraph-level merge, the lost row, base rows and the draft merge

The plan the rules and the roadmap cite is committed with them, and the
spec gains section 17: the amendments made while planning.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

- [ ] **Step 6: Verify in a browser with two accounts (the owner, on the owner's machine)**

This is the only test of the page's script with the real editor, the real server and two real sessions. The engineer executing this plan does NOT start the dev server: the owner runs this pass on their machine (`php artisan migrate` is not needed: no migration), with `npm run build` done first, in two separate browser sessions as two different users who can both edit the document (make the second a collaborator from the share screen). For each case confirm what is described and check the browser console. "A" is the tab whose save lands first; "B" the later one.

The Phase 2 cases (section 2 of the spec, in its order):

1. **Different paragraphs, both keep typing (poll first).** A and B type steadily in different paragraphs. Each one's paragraph appears in the other within 1.5–3 s of a pause; neither caret moves; the save word cycles Editing → Saving → Saved; no notice anywhere. Both pages, reloaded, show the same document.
2. **Different paragraphs, refusal first.** Throttle B's network (developer tools, "Slow 3G") and repeat. B's save is refused, B fetches and merges, and B's text is saved on the new base: same outcome, no notice. Un-throttle.
3. **Same paragraph, same moment (D1).** Both type in the SAME paragraph within a second. A sees nothing. B's paragraph changes to A's text under B's eyes (B's caret at the end of A's change) and the lost row appears with the header sentence and one item: "This paragraph was changed elsewhere at the same moment. The other version is on the page; this is yours." followed by B's text in quotes and Put it back. Press Put it back: B's version appears as a NEW paragraph under A's, with the caret at its end, and reaches A within 1.5–3 s. Keep both typing in the same paragraph a few more times: B gets one more item per round and none disappears. With five items showing at 400px width, Put them all back and Dismiss all are reachable and the list scrolls inside the row.
4. **A deletes the paragraph B is typing in (D2, first direction).** B types in a paragraph; A deletes that paragraph. In B the paragraph disappears from under the caret (the caret stays where it was); the lost row says "This paragraph was removed elsewhere while you were typing in it." with B's words. Put it back puts it back where it was. If a comment was anchored on it before, the comment is on it again.
5. **B deletes a paragraph A edited a moment ago (D2, second direction).** A edits a paragraph and pauses (Saved); B deletes that paragraph before B's tab has followed A's save. A's paragraph vanishes in A; A sees no notice; the history page (`/documents/{uuid}/history`) has a version added just now whose preview holds A's edit (the label in the `document_versions` row is "Before <B> kept their version").
6. **A saves while B is mid-word.** Not separately observable; it is case 1 with faster typing. Confirm no refused save appears in B's network panel as a 409 followed by a notice.
7. **The echo: kill the connection mid-save.** In B type a sentence and, in the developer tools, switch to "Offline" the moment Saving appears (the request leaves, the answer is lost). Type one more word. Go online. Within about 20 s B says Saved, no notice appears, the lost row stays empty, and A shows both the sentence and the word.
8. **Five merges refused in a row (MERGE_ATTEMPTS).** Not reproducible by hand without a script; skip, covered by `sync.host.test.js`.
9. **Fetch never answers.** Block `/sync` requests in B's developer tools (request blocking on the URL pattern), then type in B while A types in the same document so B's save is refused. After 15 s B shows the conflict notice with the amended sentence "…and the two could not be combined. Choose one." Unblock; Load theirs works; Keep mine works.
10. **Reload during the lost row.** After case 3, before pressing Put it back, reload B. The lost row comes back with the item prefixed "From your last visit: ". Put it back works. Press Dismiss all, confirm, reload again: the row does not come back.
11. **Stale draft on reopen.** In B, type a sentence in one paragraph and kill the tab inside the debounce (close it within a second of typing). In A, edit a DIFFERENT paragraph and pause. Reopen the document in B's browser: the question "An unsaved draft from your last visit was found. Add its changes to the document as it is now? …" appears; Yes shows both changes, saves B's sentence, and the lost row is empty. Repeat with A editing the SAME paragraph: Yes shows A's text in that paragraph and the lost row holds B's sentence with "…after your draft was written." Repeat once more and answer No: the Phase 1 set-aside notice ("A draft from your last visit here differs…") appears instead.
12. **Accepted AI suggestion while B types.** With the assistant configured: B types a new paragraph and keeps typing; A accepts a suggestion that rewrites the document. B's new paragraph survives (at the end if every neighbour was rewritten), the rewritten paragraph B had typed in is in the lost row with "…rewritten by the accepted suggestion…", and B's paragraph reaches A. Without the assistant: skip, covered by `sync.host.test.js`.
13. **Restore from the history page while B types.** B types a new paragraph; A restores an older version from `/documents/{uuid}/history`. B's new paragraph survives (anchored after its nearest surviving neighbour, else at the end); nothing of B's is lost without an item in the lost row; A follows B's paragraph a moment later. The history page shows no error (the dead event is gone).
14. **The lost row across a Livewire re-render.** With the lost row showing in B, make a third session open and close the document (B's presence strip re-renders). The row, its items and its buttons are unchanged and still work.
15. **Attribute change, list, table.** A centres a paragraph B is typing in: B's paragraph takes A's alignment and B's words are in the lost row (an attribute change is a whole-block change). Both edit different cells of one table: the later saver's whole table is in the lost row.
16. **`database is locked`.** `grep -c 'database is locked' storage/logs/laravel.log` before and after the pass: the number must not have gone up.

The Phase 1 cases that must still pass (Phase 1 plan, Task 8 Step 12 and Task 9 Step 4b): 1 (follow), 4 (presence), 5 (same account, two tabs: an edit in one appears in the other, AND typing in the same paragraph in both offers the loser back as in case 3 above), 7 (reload both: same document), 8 (access removed), 9 (a style change does not conflict with yourself), 13 (Load theirs, then Put it back, from a conflict produced by case 9 above), 14 (the replaced version is in the history after Keep mine), 15 (a style change by somebody else arrives whole), 16 (a document that does not end in a paragraph: nobody saves by themselves), 17 (an edit undone at once), 18 (reloading instead of choosing: now the draft merge question of case 11, then the lost row, instead of the set-aside notice), 19 (Saved does not show beside the notice), and the offline typing check (type offline, go online, Saved returns, the text is there on reload, no draft question).

Take a screenshot of cases 1, 3 and 11 as proof.

- [ ] **Step 7: Open the pull request**

```bash
git push -u origin coediting-phase-2
```

Open the pull request with `gh pr create` against `main`. Its description states, in this order: what the owner will see (section 2 of the spec, in plain words); the limits (section 13 of the spec, verbatim, with the additions recorded in its section 17); the browser cases of Step 6 with which were run and their result; and the "Before this goes live" list below. End the description with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge it: `main` requires a review the owner cannot give themselves, and merging deploys to production.

---

## Before this goes live

Not tasks: things the owner does or decides, in this order.

1. **Run the browser pass of Task 14 Step 6 on your machine**, with `npm run build` first. The engineer executing the plan never starts the dev server. Cases 1, 3, 4, 5, 10 and 11 are the ones no unit test can stand in for (the real editor, the real caret, the real IndexedDB). Record the result in the pull request.
2. **Confirm the two fixtures were produced, not hand-written.** `tests/fixtures/merge/editor-form.json` comes from the node suite and `stored-form.json` from `MergeFixtureTest`; `git log --oneline -- tests/fixtures/merge/` shows them added in Tasks 4 and 13. If the merge of the pair (Task 13 Step 3) was made to pass by widening `levelEmpty()`/`levelDerived()`, the commit says what difference was absorbed: read it, because that difference is one the server writes on every save.
3. **Merging deploys. Nothing on the server changes:** no migration, no new route, no new column, `PROTOCOL` stays 1. Tabs left open across the deploy run the Phase 1 JavaScript: their saves state a base, a refusal shows them the old notice, and they co-edit with Phase 2 tabs without harm (the server is the same). No reload is required of anyone this time; tell people a reload gets them the merge.
4. **The knobs** (section 15, decision 16): `MERGE_ATTEMPTS = 5`, `MERGE_FETCH_EXPIRY_MS = 15 s`, `LOST_ROWS = 5`, `ANCHOR_LIMIT = 5` in `resources/js/editor/sync/host.js` and `merge.js`, and Phase 1's `FAST_MS` in `engine.js`. Starting values; measure on production before changing any. `MERGE_ATTEMPTS` was 3 in the spec and is 5 here on the dry run's evidence (see Owner decisions): the bound counts refusals, not time, so with three people typing in three DIFFERENT paragraphs whose saves keep landing in the same order, the slowest writer's merged save is refused by the others' saves round after round and reaches the notice with none of their text stored, although every merge was possible (the walk's harness showed it at 3 after three rounds; at 5 no run of 100 reached it). If the notice is seen on production under contention with every merge possible, the next step is a time-based bound (a merge loop older than `MERGE_FETCH_EXPIRY_MS` ends in the notice, not the nth refusal) rather than a higher count; the owner decides, and turning it back to 3 is one line and one test name.
5. **Load.** Two typists now save about every 1.2 s each, plus one `/sync` fetch and one save per refused save (section 10). Run `grep -c 'database is locked' storage/logs/laravel.log` before and after the first week; if it rises, Phase 5's WAL mode and the session-renewal exclusion come forward (Phase 1's "Before this goes live", items 7 and 10).
6. **What a writer sees that is new**, for whoever supports them: the lost row and its three buttons; the amended conflict sentence; the draft question on reopening a document after a tab died with unsaved typing; "a copy is kept in this browser for 7 days" means IndexedDB in that browser, not the server. An alignment, heading-level or tick change that loses to somebody's text edit of the same block is offered back as the WHOLE paragraph with its old text (the excerpt shows the text, not the alignment, so the writer cannot tell what the loss was), and Put it back makes a second copy of that paragraph below the winner's; the writer re-applies the alignment to the winner's paragraph and dismisses the copy. Two people deleting different things that between them empty the document end with one empty paragraph, not a notice.
7. **The limits (section 13 of the spec) belong in the pull request and in what users are told:** same paragraph at the same moment, the later saver loses that paragraph on every round and types into the other's version, with every lost version offered back; lists, tables, columns, callouts and figures are one block each; undo reaches back only to the last incoming change (Phase 3); a D1 loser's caret moves to the end of the winner's paragraph; blocks both people moved take the server's order silently; the person whose saved paragraph a c2 merge deleted is not told and finds it in the history under "Before <name> kept their version"; a tab that pressed Keep mine cannot merge until a save is accepted; normalise drift (a block the server altered on save reads as D1 when edited again); a block re-id'd by the server, or by TipTap when a paragraph is wrapped in a bullet list, an ordered list, a task list or a quote (the wrapper gets a new top-level id and the paragraph id moves inside it), looks like delete plus insert: a saved edit to a paragraph that somebody else then wraps is dropped from the page (case c2: kept in the history, nobody told), and a typist in a paragraph somebody else wraps is told it was removed; when both sides changed the same document attribute (`vars`, `style`) the later saver's value wins silently; two tabs of one browser share the live draft, the base row and the lost park; a tab closed inside the debounce in a browser without drafts loses its last words; a merge during a restore, import or suggestion puts the typist's new blocks at the end when every anchor is gone; `contentJson` still travels in the Livewire snapshot (Phase 5); drafts from before Phase 2 (no base row) are set aside as today.
8. **Open decisions you may overturn later, each at a known cost** (section 15): a notice instead of a merge for whole-document replacements (decision 13, about 20 lines); telling the c2 victim (decision 14, about 60 lines and one more notice, Phase 3 makes it exact); a truer history label for a merge-kept version (Q2, a second flag through `saveContent()`, the beacon body and the autosave controller).
9. **Phase 3 keeps** the per-block apply, the lost row with its append rule and old-id reinsertion, `known`, the `lost-` park and the fail-closed and notice-bar patterns, and **replaces** `mergeDocuments`, `merging`, `mergeAttempts`, the echo, the `base-` row and the draft merge with the step log (section 14). Nothing on the server has to be unwound.
