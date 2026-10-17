# Co-editing Phase 3: Typing Together (the ordered edit log) — Design

> Written 2026-10-17 for a decision, not for building. No product code was changed. Builds on the roadmap (`docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md`, Phase 3), Phase 1 (live since 2026-10-16) and Phase 2 (`docs/superpowers/specs/2026-10-16-coediting-phase-2-paragraph-merge-design.md`, merged and live). The evidence (scripts and raw results) is in `docs/superpowers/research/2026-10-17-phase-3/`. A human approves this design, or chooses not to build it, before any implementation plan is written.

Sections marked **(owner)** are written for the person deciding; the rest is for the people building it. Every statement about existing code cites `file:line` on `main` at `da13655`. Every number is either measured here (section 4 says how) or labelled an estimate.

---

## 0. The decision in one page (owner)

**What Phase 3 buys.** Today (Phase 2) two people in *different* paragraphs both keep their words. Two people in the *same* paragraph at the same moment cannot both keep theirs: the later save loses that paragraph and is offered it back in the notice bar (owner decision D1). Undo reaches back only to the last change somebody else made (`.ai/rules/editor.md:51-52`). Phase 3 makes the same paragraph, even the same sentence, work: both people's characters stay, each sees the other's typing one to two and a half seconds later, carets do not jump, and undo takes back only your own typing.

**What it costs.** An estimated 12-16 working days (section 6), a new table and five columns on `documents`, a new request shape between the page and the server, about 170 lines of vendored code, and a period of running two editing engines side by side (the old one stays as the instant rollback). It raises the number of requests per active typist from about one save per pause plus a poll every 1.5 s to roughly 1.7 requests a second while typing (estimate, section 3.4); what that costs on the shared host has not been measured (roadmap "Verify on the server first").

**What the evidence says (section 4).** The approach works with this editor: 2,400 seeded runs of 2 and 3 headless editors built from the project's real extensions, 300 random edits each, through a fake server that delays, drops and duplicates messages, all converged to the same document, with unique block ids in all 1,600 runs that keep the id plugins on, and the documents they converged on pass the server's own write path unchanged (100 of 100 checked). It also found three things the roadmap did not know: the published `prosemirror-collab` package throws inside its rebase on some structural changes (8 of 200 three-person runs, 1 of 200 two-person runs), which loses the thrower's unsent typing unless the code is guarded (the guarded copy: 0 in 1,600 runs); the package silently drops typing that lands inside text somebody else deleted at the same moment (about 1% of typed characters in a typing-heavy mix), which must be caught and offered back; and the roadmap's advice to keep the block-id plugins away from remote changes is wrong in this model (doing so left duplicate ids in 38 and blocks without an id in 16 of 800 runs). On SQLite the append took 1-6 ms at p95 with 5 to 32 concurrent PHP processes and raised no lock error (section 4.3). All timings are from a laptop, not the host.

**Recommendation: do not build Phase 3 now.** Phase 2 has only just gone live and nobody yet knows how often two people actually type in the same paragraph at the same second. Instead, for two to three weeks: count the lost-row offers Phase 2 makes (a one-line log per offer, about half a day) and run the Phase 0 host read-outs the roadmap lists. Build Phase 3 when either (a) the count shows same-paragraph collisions are a weekly occurrence in real documents, or (b) you want same-sentence co-writing as a product promise regardless. If you choose to build, build it as specified here, in the staged order of section 5, starting with the reduced cut (Phase 3a-3b with the automatic fallback to Phase 2), which is usable on its own. Section 6 gives the reasoning and the alternative recommendations.

**If you want to proceed, the answers needed are in the table of section 2.** "Go with the recommendations" answers all of them.

---

## 1. What a writer sees (owner)

"Here" is the person reading; "elsewhere" is any other tab, including the same account in another tab (as in Phase 2).

### 1.1 Everyday co-typing

| Situation | Phase 2 today | Phase 3 (steps mode) |
|---|---|---|
| Two people in different paragraphs | Both keep typing; the other's paragraph appears 1.5-3 s after they pause. | The same, but the other's characters appear while they type, 1-2.5 s behind (estimate: up to 1 s before the page sends, up to 1.5 s before the other page asks, plus the round trip). |
| Two people in the same paragraph, same moment | The later save loses the paragraph; it is offered back in the lost row (D1). | Both keep every character. No notice. |
| Two people in the same sentence | As above. | Both keep every character, interleaved where they typed. Measured in scenario S1 (section 4.2): "We will meet on Monday." + "everyone" + "at nine" from two people became "We will meet everyone on Monday at nine." on both pages. |
| A caret while the other person types before it in the same line | A caret inside a paragraph the other person changed moves to the end of their change. | Stays after the character it was after (S1: the caret moved right by exactly the 9 characters inserted before it). |
| Undo | Reaches back only to the last change that arrived from elsewhere. | Takes back only your own typing, across other people's changes (S2, S3). |
| One person presses Enter in a paragraph the other is typing in | Can duplicate the words after the split or leave them only in the lost row (Phase 2 stated limit). | Both changes stay: the paragraph splits, the other person's words land in whichever half they typed in (S5). |
| Somebody deletes text you are typing inside, at the same moment | Paragraph-level: the deletion wins, your paragraph is offered back (D2). | Character-level: the deletion wins and your words are offered back in the lost row (new sentence below). Nothing is dropped silently. |
| Somebody wraps your paragraph in a list, changes it to a heading, aligns it | A change of the whole block (D1). | Both changes stay. |
| Two people edit different cells of one table | The later saver's whole table is offered back. | Both cells keep their text. |
| Save word in the bar | Editing → Saving → Saved. | The same three words. "Saved" now means every character you typed is in the shared log on the server; Editing means some is still on its way. |

### 1.2 New sentences (exact)

| When | Where | Text |
|---|---|---|
| Your typing landed inside text removed elsewhere at the same moment | Lost row item (Phase 2 row, `source: 'steps'`) | **Some words you typed were inside text that was removed elsewhere at the same moment. They are not on the page; this is what you typed.** + excerpt + **Put it back** (puts the words in as a new paragraph after the nearest surviving paragraph). |
| A draft from your last visit was replayed and some of it could not be kept | Lost row item (`source: 'draft'`) | Phase 2's draft sentences, unchanged (`host.js:72` `LOST_SENTENCES`). |
| Somebody restored a version / imported a file / accepted a suggestion while you had the document open | Sync notice (Phase 1 row 4) | **A saved version was restored elsewhere. The page now shows it; undo starts again from here.** / **A file was imported over this document elsewhere. The page now shows it; undo starts again from here.** / **A suggestion was accepted elsewhere. The page now shows it; undo starts again from here.** Your unsent typing is merged into it paragraph by paragraph (Phase 2's merge); what cannot be kept goes to the lost row with Phase 2's sentences. The person who did it sees no notice. |
| The page fell out of step with the others (a change it could not apply) | Sync notice | **This page fell out of step with the others and was brought up to date. Anything that could not be kept is listed below.** |
| The second fall-out-of-step for one document within 24 hours | Sync notice | **Typing together was switched off for this document after an error. Your changes are still saved, paragraph by paragraph.** (That document runs as Phase 2 from then on; section 3.12.) |
| The operator switches steps mode off while you have the page open | Sync notice | **Typing together was switched off. This page keeps saving as before.** (Downgrade in place, section 5.3. No reload.) |
| A tab opened before the deploy tries to save | Status strip (existing) | **This page is out of date. Reload it to keep editing.** (the existing text, `app/Livewire/Documents/Editor.php:116`). |
| A view-only collaborator | Status strip | **Read only** (the existing word). The editor is not editable for them (today it is, and every save fails: section 3.10). |

Nothing else changes on screen. The conflict notice (Keep mine / Load theirs) cannot appear in steps mode: there are no competing whole-document saves left to refuse.

### 1.3 What happens to each piece of Phase 2

The switch `DOTDOC_COEDITING` = `off` / `follow` / `steps` (section 5.1). `follow` is exactly today's behaviour (Phase 1 + Phase 2). Everything below is about what a page in **steps** mode uses; a page in `follow` mode runs all of it exactly as today.

| Phase 2 machinery | In steps mode | Why |
|---|---|---|
| The block merge `mergeDocuments()` (`resources/js/editor/sync/merge.js:276`) | **Kept and reused** for four rare paths: an epoch change over unsent typing (restore, import, accepted suggestion, fault), a draft that cannot be replayed (log pruned or epoch changed), the downgrade to `follow`, and fault recovery. | It is the tested way to fold "my document" into "a document that replaced it". |
| The refused-save loop (`beginMerge()` `host.js:1097`, `mergeOrConflict()` `host.js:1185`, `MERGE_ATTEMPTS`, `merging`, `MERGE_FETCH_EXPIRY_MS`) | **Not used.** A step batch that arrives late is rejected and rebased by the client (section 3.3); nothing is ever refused as a whole document. | |
| The lost row (`offerLost()` `host.js:1352`, `putBackLost()` `host.js:1407`, `dismissLost()` `host.js:1489`, `lost-` parks, append rule, five static slots) | **Reused unchanged**, with one new source (`steps`) and sentence. | Dropped typing must be offered back (section 3.6). |
| Drafts: live `<uuid>` row, `base-<uuid>` rows, the draft merge (`mergeDraft()` `host.js:1579`) | **Replaced** by a steps draft (`steps-<uuid>@<tabId>`: the document at the last confirmed step plus the unsent steps). The draft merge stays as the fallback when the steps cannot be replayed. `base-` rows are not written. | Unsent steps are smaller and replay exactly; section 3.8. |
| The echo rule (`unanswered`, `sentSnapshot`) | **Retired.** | The step log recognises a tab's own steps exactly by client id (`receiveTransaction()` in prosemirror-collab). |
| The park queue of set-aside texts (`offerNextPark()` `host.js:1993`) | **Reused unchanged.** | |
| Back-off for a send that keeps failing (`saveFailures`, `saveRetryAt`, `SAVE_RETRY_DELAYS_MS` `host.js:63`) | **Reused** for step sends. | |
| Conflict notice, Keep mine, Load theirs, `overwriteOwed`, `overwriteFloor`, `putBackSeq` | **Unreachable** in steps mode; kept for `follow`. | |
| Undo history emptied after every remote change (`clearHistoryTransaction()` `resources/js/editor/sync/apply.js:438`) | **Only at an epoch change and at the downgrade.** Remote steps keep your history. | Exact steps make history mapping safe (`.ai/rules/editor.md:56` predicted this; S2/S3 confirm). |
| Per-block apply (`remoteTransaction()` / `blockSteps()` `apply.js:74`, `apply.js:234`) | **Reused** to show a new epoch's document without destroying the caret outside the changed blocks. | |
| The engine (`resources/js/editor/sync/engine.js`) | **Extended**: the same poll loop, cadence, stop reasons and back-off; the request and answer carry steps (section 3.3). | |

---

## 2. Decisions (owner)

Roadmap decisions 2 and 3 were taken for Phase 2 (D1, D2). The rest are below. **Owner** = only the owner can decide (money, outside services, dependencies and licences, product promises, a recorded earlier decision). **Default** = an engineering default the owner may overrule.

| # | Question | Recommendation | Reasons | Cost of the alternative | Who |
|---|---|---|---|---|---|
| 1 | Speed bar: is "1-2.5 s behind while the other person types" acceptable at no extra cost? | **Yes.** Keep polling (1.5 s with company, `engine.js:37`). | No outside service, no new bill, and correctness never depends on speed. | Keystroke-instant needs Phase 6: a hosted socket service or a small separate server (a monthly bill and an account), 1-2 days plus the account. Polling faster (0.75 s) doubles poll requests on a host whose request cost is unmeasured. | Owner |
| 4 | Whole-document replacements (restore, import, accepted suggestion) while others have the document open: reload everybody? | **No reload.** A new epoch: open pages show the new document block by block, their unsent typing is merged in by Phase 2's merge, what cannot be kept goes to the lost row, and undo starts again (sentences in 1.2). | Keeps Phase 2's owner answer Q4 (replacements are merged, not a notice). A reload would throw away the caret, the scroll position and the undo history for everybody and still need the same merge for unsent typing. | A reload is about 1 day less code but every replacement interrupts every open page; unsent seconds would sit in a notice instead of on the page. | Owner (UX) |
| 5 | One account in two tabs or devices co-edits live? | **Yes.** | Each tab has its own client id (`createTabId()` `host.js:173`); nothing special is needed. Phase 1 already counts another tab of the same account as company. | None worth having. | Default |
| 6 | View-only collaborators and public-link visitors see edits live? | **View-only collaborators: yes, read-only, no caret. Public links: no** (they keep showing the stored document on load). | Viewers already poll (`DocumentSyncController.php:60` authorises `view`). Public pages are a static render (`routes/web.php:74-92`) with no editor and no session; making them live needs a public, unauthenticated sync endpoint (rate limits, privacy) for little gain. | Live public pages: an anonymous endpoint and its abuse controls, about 2 days. | Owner (public links); default (viewers) |
| 7 | Named carets in the first co-typing release? | **No: Phase 4 as planned**, after Phase 3 has run for a while. | Phase 3 is already the largest change; carets are additive and switchable. Same-sentence typing without seeing the other caret is workable for small teams (text appears where they type). | +3-4 days in this phase and a larger poll answer. | Owner |
| 8 | Automatic versions by time only? | **Yes, in steps mode only:** one automatic version per document per 10 minutes of activity, cut by the checkpoint that crosses the 10-minute line; named and restore versions unchanged. | Today a version is cut whenever the author differs from the last version's author or two minutes passed (`DocumentStore.php:307-319`). Checkpoints come from whichever tab is at the head (section 3.5), so the "author" would alternate and the history would gain a row every checkpoint (every ~10 s). Changes `DocumentStoreTest::test_auto_versions_coalesce_within_two_minutes_for_same_author` (`tests/Feature/Documents/DocumentStoreTest.php:39-56`, the other-author assertion at 52-54) for steps mode. | Keeping author-based cuts floods the history; contributors per version need a new column (Phase 5). | Owner (changes the history page) |
| 9 | `on_save` webhooks when checkpoints happen every few seconds? | **At most one per document per minute, leading edge, plus one trailing delivery a minute later** if checkpoints happened in between (a delayed queued job; the cron queue worker exists, `routes/console.php:28`). | `WebhookService::fire()` is synchronous with a 5 s timeout (`app/Services/WebhookService.php:71-74`) and runs after every save (`DocumentStore.php:158`); at one checkpoint per ~10 s it would call the receiver six times a minute and hold a PHP worker up to 5 s each time. | Firing on every checkpoint: receivers get bursts; firing only at session end needs a "session ended" signal the server does not have (pages close without telling reliably). | Owner (affects integrations) |
| 10 | Approve `prosemirror-collab` as a dependency, or copy its lines? | **Copy (vendor) it** into `resources/js/editor/sync/collab.js`, 156 lines of `dist/index.js` 1.3.1 plus two changes, keeping the MIT copyright notice. | The package as published threw inside `receiveTransaction()` in 8 of 200 three-person runs and 1 of 200 two-person runs (`ReplaceAroundStep.invert`, "Position -n outside of fragment"; section 4.2), which forces the page to reload and lose its unsent typing. The guarded copy: 0 throws in 1,600 runs, same convergence. The second change reports the steps the rebase drops, so the page can offer the words back; the package drops them silently. The package itself depends only on `prosemirror-state`, already installed. | Depending on the package: no guard, silent drops, an upstream fix to wait for; the copy needs a manual check on each ProseMirror upgrade (the tests catch it). | Owner (licence and dependency approval) |
| 11 | Scale to design for | **Up to 5 people typing in one document, up to 20 documents being edited at once on the host, documents up to about 50,000 words routinely and 100,000 words workable.** | Tested: 3 editors per document in the convergence trial, 24 simulated typists over 4 documents on SQLite (section 4.3), fill work for a 43,000-word document about 6 ms and 107,000 words 16-26 ms locally (section 4.3). | Larger rooms need faster send batching and a socket (Phase 6) and should be measured on the host first. | Owner (the facts) |
| 12 | Retire the Yjs + Hocuspocus decision (D4) and drop `documents.ydoc_state`? | **Yes.** Mark D4 superseded in `docs/superpowers/specs/2026-09-07-dot-doc-platform-design.md:26`; drop the column in a Phase 5 migration. | The column is written and read nowhere (only its migration mentions it, `database/migrations/2026_09_07_000001_add_structured_content_to_documents_table.php:15`). Yjs was rejected in the roadmap for this host. | Keeping it: an unused binary column and a contradictory decision record. | Owner (a recorded decision) |

---

## 3. Architecture

### 3.1 The model in one paragraph

The server keeps, per document, an ordered list of ProseMirror steps (`document_steps`) numbered `seq` within an `epoch`. A page holds the document at some `seq` plus its own unconfirmed steps. It sends its steps tagged with the `seq` they were made against; the server appends them only if that `seq` is still the head (one compare-and-append transaction), otherwise it answers with the steps the page has not seen, the page rebases its own steps on top of them (prosemirror-collab) and sends again. Every page applies the same steps in the same order, so all converge. The server never runs the editor: it orders and stores steps and validates their content. The stored document (`content_json` and everything derived from it) is still written only by `DocumentStore`, from **checkpoints**: a page with nothing unconfirmed sends its whole document tagged with the `seq` it represents, and `DocumentStore` writes it only if that `seq` is newer than the last checkpoint of the same epoch. A whole-document replacement on the server (restore, import, accepted suggestion) starts a new epoch.

### 3.2 Data model

Migration (one file, additive; `.ai/rules/migrations.md` has nothing that applies):

```php
Schema::table('documents', function (Blueprint $table) {
    $table->unsignedInteger('step_epoch')->default(1);
    $table->unsignedBigInteger('step_seq')->default(0);        // head of the log in this epoch
    $table->unsignedBigInteger('checkpoint_seq')->default(0);  // seq the stored content_json represents
    $table->string('step_epoch_reason', 16)->nullable();       // start|restore|import|suggestion|fault|normalise
    $table->timestamp('steps_disabled_at')->nullable();        // per-document fallback to `follow` (3.12)
});

Schema::create('document_steps', function (Blueprint $table) {
    $table->id();
    $table->foreignId('document_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('epoch');
    $table->unsignedBigInteger('seq');
    $table->string('client_id', 64);                 // the tab id, as prosemirror-collab compares it
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->text('step');                            // the step's JSON, validated (3.10), never re-encoded
    $table->timestamp('created_at');
    $table->unique(['document_id', 'epoch', 'seq']);
    $table->index('created_at');                     // pruning (3.9)
});
```

- `documents.version` keeps its meaning: it goes up on every content write (checkpoint, restyle, replacement), so history, Livewire, the outline refresh and protocol-1 tabs keep working.
- The steps' `seq` restarts at 0 in each epoch; `(document_id, epoch, seq)` is unique, which is the database's own guard against two appends at one position.
- `text` rather than `json`: the server validates the step once on the way in and hands the same bytes out; nothing queries inside it.

### 3.3 The sync protocol (PROTOCOL 2)

One endpoint carries polls and sends, so a page makes one kind of request: `POST /documents/{uuid}/sync` (`DocumentSyncController`, today `app/Http/Controllers/DocumentSyncController.php:32-93`). The engine's `PROTOCOL` (`engine.js:50`) becomes 2; the controller already accepts the field (`DocumentSyncController.php:50`).

**Request** (protocol 2):

```json
{
  "protocol": 2,
  "tab": "8f3c…",              // as today, also the prosemirror-collab client id
  "version": 41,               // the document version the page knows (outline/css/protocol-1 behaviour)
  "epoch": 3,
  "seq": 1207,                 // the page's collab version: steps it has applied
  "send": {                    // only when the page has unconfirmed steps
    "steps": [ { "stepType": "replace", "from": 412, "to": 412, "slice": { "content": [ { "type": "text", "text": "e" } ] } } ]
  }
}
```

**Answer** (200):

```json
{
  "mode": "steps",                      // or "follow" when the switch or the document says so (5.3)
  "epoch": 3, "head": 1215, "checkpoint": 1180, "version": 42,
  "accepted": true,                     // true / false (stale) / null (nothing was sent)
  "from": 1207,                         // the steps below follow the caller's seq
  "steps": [ … ], "clients": [ "8f3c…", "b71e…" ],   // at most 1000; "more": true if cut
  "members": [ … ], "others": 2,        // as today
  "outline": { … }, "css": "…",         // only when version moved past the caller's
  "reset": { "reason": "restore", "json": { … }, "epoch": 4, "seq": 0, "steps": [], "clients": [] }  // epoch changed or caller below the prune floor
}
```

- **Accepted**: the batch was appended at `from + 1 …`; `steps` holds everything after the caller's `seq`, its own batch included, so `receiveTransaction()` confirms it by client id exactly as in the trial. (Leaving the caller's own step bodies out is a later optimisation; batches are small, section 4.2.)
- **Stale** (`accepted: false`): nothing was appended; `steps` holds what the caller lacks. The page rebases and sends again at once, not on the next tick. Measured rejection rates are high when several people type continuously (52% of appends with 3 simulated typists in the SQLite bench, 57% of batches in the 3-page trial), so the immediate resend matters; it costs one extra request per rejection.
- **Quiet poll** stays cheap: the controller reads `step_epoch`, `step_seq`, `checkpoint_seq`, `version` with the six small columns it reads today (`DocumentSyncController.php:53-56`, pinned by `DocumentSyncTest::test_a_quiet_poll_never_loads_the_document_content`, `tests/Feature/Documents/DocumentSyncTest.php:85`), and touches `document_steps` only when `seq < head`.
- A batch is never split: it is appended in one transaction and served in order (the roadmap's "never split one author's batch").
- **Errors**: a caller without `update` that sends steps gets the ordinary 200 answer with `accepted: null` and `"readOnly": true`, nothing appended, and the page turns read-only (a 403 with JSON would stop the engine for good, `engine.js:199-212`, and a demoted editor should keep following); 422 for a step that fails validation (the page treats it as a fault, 3.12); 413 over the size limit; 429 over the rate limit (retried with back-off). 401/419/403/404 with JSON on the poll itself stop the engine as today (`engine.js:52`, `engine.js:199-212`).

**The compare-and-append transaction** (Laravel; `DB::transaction` opens `BEGIN IMMEDIATE` on SQLite because `config/database.php:49` sets `transaction_mode` to IMMEDIATE, and waits up to 5 s for the lock, `config/database.php:46`):

```php
$result = DB::transaction(function () use ($documentId, $epoch, $seq, $steps, $clientId, $userId) {
    $row = Document::withTrashed()->lockForUpdate()
        ->select(['id', 'step_epoch', 'step_seq', 'steps_disabled_at'])
        ->findOrFail($documentId);                         // the write lock is already held (IMMEDIATE)
    if ($row->steps_disabled_at !== null) return AppendResult::disabled();
    if ((int) $row->step_epoch !== $epoch)  return AppendResult::epochChanged((int) $row->step_epoch);
    if ((int) $row->step_seq !== $seq)      return AppendResult::stale((int) $row->step_seq);
    $now = now();
    DB::table('document_steps')->insert(array_map(fn ($json, $i) => [
        'document_id' => $documentId, 'epoch' => $epoch, 'seq' => $seq + $i + 1,
        'client_id' => $clientId, 'user_id' => $userId, 'step' => $json, 'created_at' => $now,
    ], $steps, array_keys($steps)));
    DB::table('documents')->where('id', $documentId)->where('step_seq', $seq)
        ->update(['step_seq' => $seq + count($steps)]);
    return AppendResult::accepted($seq + count($steps));
});
// After the commit: read the steps after the caller's seq (a separate, read-only query).
```

SQLite has one writer at a time; this transaction holds the lock for the inserts and one update (measured p95 1.1-2.3 ms locally, 5.9 ms beside 1 MB checkpoints, section 4.3). WAL mode (readers never wait for the writer) is recommended where the host's filesystem allows it; the bench shows rollback-journal mode also works at this load. `journal_mode` is `null` today (`config/database.php:47`), which leaves whatever the file has (SQLite's default is DELETE); the roadmap's read-out `PRAGMA journal_mode` tells which.

**Mixed versions during a deploy.** A tab opened before the deploy runs protocol-1 JavaScript. In steps mode the server keeps answering its polls as today (version, and the whole document when the version moved, which happens at each checkpoint), so it follows with a lag of up to one checkpoint interval; its whole-document saves (`Editor::saveContent()` `Editor.php:109-162` and the beacon `DocumentAutosaveController.php:76`) are refused with the existing "This page is out of date. Reload it to keep editing." answer (`Editor.php:116`), because a whole-document save in steps mode would overwrite the log's tail. Its offline draft is kept and offered after the reload (Phase 2's draft merge). The engine's stop codes are not used for this: none of their sentences is true here.

### 3.4 Cadence and load (estimates)

- Sends: 250 ms after typing pauses, or every 1 s during continuous typing, one send in the air at a time (the bundle's 1.2 s autosave debounce, `resources/js/editor/index.js:61`, does not apply in steps mode).
- Polls: unchanged: 1.5 s with company, 10 s alone, none while hidden (`engine.js:37-40`). A send's answer counts as a poll.
- Estimated requests: a person typing about 1.7 per second (one send a second plus polls between), plus about 0.5 a second of immediate resends when three people type continuously; a reader 0.67 per second. Three typists and two readers: about 7 requests a second for one document. Each request boots Laravel and writes the session row (`.env.example` has `SESSION_DRIVER=database`). The per-request cost and any per-IP throttling on the shared host are **unmeasured** (roadmap "Verify on the server first"); that read-out must come before the cadence is fixed.

### 3.5 Checkpoints through DocumentStore

New `DocumentStore::checkpoint(Document $doc, array $json, User $actor, int $epoch, int $seq, array $opts = []): Document`, beside `save()` (`DocumentStore.php:109-161`). The page calls `POST /documents/{uuid}/checkpoint` (a plain controller like the autosave one, so the document is not carried in a Livewire snapshot both ways).

1. Cheap read first: if `checkpoint_seq >= $seq` or the epoch differs, answer 409 `{reason}` without normalising anything.
2. Outside the transaction, as `save()` does: `normalise(ensureIds($json))`, `validate()` (`DocumentStore.php:114-118`).
3. **Positions must survive the server's repairs.** Compare the normalised document with the input ignoring attributes (types, text and nesting only). Attribute-only differences (an `align` dropped, a derived stamp, an id) do not move positions; a text or structure difference (a control character stripped, `DocumentSchema.php:174-177`; a structural repair) would make every later step land in the wrong place for a page that opens from this checkpoint. Such a checkpoint is stored normalised **and starts a new epoch** (reason `normalise`), so every page reloads from it (3.7). Measured: 100 of 100 converged trial documents were fixed points; the check caught all three canaries (section 4.2). To make the epoch path rare, the editor strips the same control characters on paste (there is no paste filter today; nothing in `resources/js/editor` uses `transformPasted`).
4. Inside one transaction: lock the row (`lockStored()` `DocumentStore.php:226-229`); refuse unless `step_epoch === $epoch && checkpoint_seq < $seq && $seq <= step_seq` (a late checkpoint can never overwrite a newer one, and nobody can checkpoint steps that are not in the log); `bringUpTo()` and `fill()` as `save()` does (`DocumentStore.php:142-143`; `fill()` renders inside the transaction, measured 1.4 ms for 10,700 words and 5.7 ms for 42,800 locally); `version + 1`; `checkpoint_seq = $seq`; the automatic version rule of decision 8; `keepAs` (a label) cuts a named version of THIS content (used by Put it back, 3.7).
5. After the commit: the throttled webhook of decision 9.

**Who checkpoints, and when.** Any page with `update`, nothing unconfirmed, and `seq > checkpoint` (the poll answer tells every page the current `checkpoint`): ten seconds after the last checkpoint it knows of, or 200 steps after it, or two seconds after its own last confirmed send when it goes quiet; a document over 200 KB stretches the ten seconds in proportion (a 435 KB document every 22 s; estimate). Two pages checkpointing the same `seq` cost one refused cheap read. A page that opens on a document whose `step_seq > checkpoint_seq` checkpoints as soon as it reaches the head, which repairs a tail left by a page that closed abruptly.

**What reads the stored copy.** Export, publish, search, templates, history previews, AI context and webhooks read `content_json`/`content`, which can be up to one checkpoint interval behind the log while people type, and longer if every page closes abruptly inside a burst (the tail is in the log, and the next page to open writes it). That is the honest cost of a server that cannot run the editor; section 6 lists it as a risk.

### 3.6 Rebase, dropped typing, and the lost row

The vendored `collab.js` (decision 10) is prosemirror-collab 1.3.1 with two changes, both exercised by the trial (`docs/superpowers/research/2026-10-17-phase-3/collab-copy.mjs`):

1. `rebaseSteps()` computes the inverse of a mapped step **before** applying it and treats an inverse that throws as a step that cannot be rebased, instead of letting the throw escape `receiveTransaction()`.
2. It reports every step it drops.

prosemirror-collab drops a local step during a rebase when it cannot be mapped (both ends fell inside text somebody else deleted) or no longer applies. In the trial it hit about 8.7% of typed characters in the adversarial mix and 0.9% in the typing mix (section 4.2); in ordinary use it happens only when two people work on the same words at the same second. The page turns the dropped steps' inserted text into one lost-row entry per rebase (`source: 'steps'`, the sentence in 1.2, anchors = the ids of up to five top-level blocks before the step's original position, mapped through the remote steps), so "nothing is dropped silently" still holds. Dropped steps without text (a mark on deleted text, a list wrap of a deleted range) are not offered: there is nothing to put back (a stated limit).

### 3.7 Whole-document replacement routes

| Route | Today | Steps mode |
|---|---|---|
| AI panel replace and insert (`handle.applyHtml()` `index.js:550-588`, called from `editor.blade.php:522`) | A local `setContent` / `insertContent`, autosaved. | Unchanged: it is a local transaction, so it becomes steps (one large step for a replace) sent like typing, undoable. No epoch. |
| Accepted suggestion (`Editor::acceptSuggestion()` `Editor.php:224-250`, then `applySuggestion()` `host.js:2259`) | Server save with no base, `keepReplacedAs` "Before accepted suggestion", dispatch to the page. | The page checkpoints first (so "Before accepted suggestion" holds the latest text), then the server saves through `DocumentStore` with a new epoch (reason `suggestion`) in the same transaction. The accepter's page adopts the new epoch directly; other pages see the reset (1.2). |
| Version restore (`VersionHistory::restore()` `app/Livewire/Documents/VersionHistory.php:102-121` → `DocumentStore::restore()` `DocumentStore.php:272-282`) | Save with no base, "Before restoring v<n>". | Same save, plus a new epoch (reason `restore`) in the same transaction. The history page has no editor, so the log's tail since the last checkpoint is not in "Before restoring v<n>"; open pages that hold that tail send it as a **late checkpoint for the closed epoch**, which the server never applies to content but keeps as a named version "Latest edits before restoring v<n>" when it is newer than the epoch's last checkpoint. |
| Import over the document (`DocumentImportController.php:107-111`) | Save with no base, "Before import of <file>". | As restore (reason `import`). |
| Restyle (`DocumentStore::restyle()` `DocumentStore.php:196-214`, `Editor::setStyle()` `Editor.php:280-298`) | Refills from the stored row, version + 1. | Unchanged and **no epoch**: it changes only derived stamps and CSS, which do not move positions. `checkpoint_seq` is not touched. Pages get the CSS and outline from the poll (version moved). |
| Draft restore at open (`restoreDraft()` `host.js:2099`, `setContent` at `host.js:2233`) and Put it back of a set-aside text (`putBack()` `host.js:1880`, `setContent` at `host.js:1909`) | Local `setContent`. | Local transactions, sent as steps. Put it back first writes a checkpoint with `keepAs` "Before <name> kept their version" so the replaced text is in the history, as today. |
| Keep mine / Load theirs | The conflict notice. | Unreachable. |

**What a page does on a new epoch** (`reset` in the answer): (1) if it holds unconfirmed steps, it builds `mine` (its document now) and `base` (its document at its last confirmed `seq`, obtained by applying the inverses of its unconfirmed steps) and runs `mergeDocuments(base, mine, theirs = reset.json)`; (2) it shows `theirs` or the merge result with the per-block apply (`remoteTransaction()`), restarts its collab state at the new epoch and `seq`, and, when the merge kept something of its own, applies the difference as ordinary local steps so they are sent; (3) offers what the merge could not keep (Phase 2 sentences, source `merge`/`suggestion`); (4) empties the undo history (`clearHistoryTransaction()`); (5) shows the sentence in 1.2 unless this page caused the epoch. If it held confirmed steps newer than the closed epoch's last checkpoint, it first sends them as the late checkpoint above.

### 3.8 Drafts, offline typing and unload

- **Steps draft** (IndexedDB, `offline.js`): `steps-<uuid>@<tabId>` = `{epoch, seq, baseDoc, steps, clientId, savedAt}`, rewritten 300 ms after each change while anything is unconfirmed, deleted when nothing is. Swept after 7 days like the other parked rows (`offline.js:52`, `parkedRowIsExpired()` `offline.js:67`). It replaces the live whole-document draft in steps mode.
- **Offline, page still open**: the steps stay unconfirmed; on return (`backOnline()` `host.js:2488`) they are sent and rebased over everything that happened meanwhile, silently, unless the log no longer reaches back to the page's `seq` (pruned, 3.9) or the epoch changed, in which case the epoch path (3.7) merges them paragraph by paragraph.
- **Reopen with a steps draft**: same epoch and the log reaches back → build the editor state at `draft.seq` from `draft.baseDoc`, apply the draft's steps as local, then fetch, rebase and send; drops go to the lost row with the `draft` sentences. Otherwise → Phase 2's draft merge with its question (`DRAFT_MERGE_QUESTION` `host.js:152`).
- **Unload** (`pagehide`, today `index.js:499` and `editor.blade.php:274-286`): first the unsent steps, by beacon, to `POST /documents/{uuid}/steps` (the same append; 409 when stale, then the draft carries them to the next visit); then, only if nothing was unconfirmed and the document is at most 60 KB, a checkpoint by beacon. Step batches are small (p95 6 KB, largest 18 KB in the trial), well under the roughly 64 KB browsers allow a beacon (roadmap trap). The presence leave beacon is unchanged.

### 3.9 Pruning and storage

- Keep every step of the current epoch after `checkpoint_seq`, always. Delete steps at or below `checkpoint_seq`, and every step of an earlier epoch, once they are 48 hours old: a daily scheduled command `documents:prune-steps` (the scheduler runs, `routes/console.php:28-49`). A page whose `seq` is below the oldest kept step gets a `reset` with the current checkpoint and the steps after it (its unsent typing goes through the epoch path).
- Size (estimate from the trial): a step is 146 bytes of JSON at the median (p95 227), about 230 bytes with row and index overhead. A person typing continuously makes roughly 3-5 steps a second, about 3.6 MB an hour before pruning. With 48 hours kept, the table holds at most two days of activity. The nightly SQLite backup (`routes/console.php:49`) grows by the same amount.
- Optional later (Phase 5): merge consecutive single-character steps of one batch before sending (`Step.merge`) to cut rows by several times.

### 3.10 Security

- **Authorisation on every request, from the database** (`.ai/rules/app.md` "DocumentPolicy answers from the database every time"): polling needs `view` (as today), sending steps and checkpoints need `update`, re-checked inside each request; a removed collaborator's next request stops them.
- **View-only users never append.** A page without `update` mounts the editor read-only and never sends. This fixes an existing gap: today `Editor::mount()` authorises only `view` (`Editor.php:62`), the only `setEditable(false)` is fail-closed (`index.js:297-302`), so a viewer can type and every save fails `authorize('update')` (`Editor.php:111`).
- **Size limits**: at most 1,000 steps and 2 MB per batch (an AI replace of a document over 2 MB is refused with `aiError`; `post_max_size` on the host must allow it, a read-out); a checkpoint at most 4 MB.
- **Rate limits**: the sync route has no throttle today by design (`routes/web.php:166`). Add a per-user-per-document limiter (240 requests a minute; legitimate peaks measured here are about 2 a second), never per IP (colleagues behind one office address look like one IP, as the roadmap warns).
- **Step validation** (`App\Documents\Steps\StepValidator`, PHP, run before the append): JSON shape per step type (`replace`, `replaceAround`, `addMark`, `removeMark`, `addNodeMark`, `removeNodeMark`, `attr`, `docAttr`; anything else refused); positions non-negative integers; every node in a slice is a type `DocumentSchema` knows (`DocumentSchema.php:10-17`), every mark is in `MARKS` (`DocumentSchema.php:19`); block ids, when present, match the 8-character rule (slices may carry `null` ids: the id plugins assign them in a following step); attributes only from a per-type list, and the style-bearing ones pass the same rules `normalise()` applies (align, column count, image `src`, text colour, link `href`), so a stored step can never carry a value `normalise()` would strip; no C0 control characters in text; `attr` steps may not set derived attributes (`toc.entries`, `crossRef.label`).
- **What validation cannot guarantee**: that a step applies to the document (the server cannot apply it). A page that cannot apply a received step reports a fault (3.12). Honest clients cannot produce such a step (they applied it themselves on an identical document); a malicious editor could, but an editor can already replace the whole document today.

### 3.11 Undo, IME, block ids, plugins

- **Undo**: TipTap's history (StarterKit `undoRedo`) with the collab plugin's `historyPreserveItems`. Remote steps arrive with `addToHistory: false` and are mapped through the history exactly; S2 and S3 show undo taking back only the undoer's own text, including the Phase 1 case that forced the history clearing (`.ai/rules/editor.md:54`). An undo whose target someone else deleted does nothing (prosemirror-history drops it): stated limit.
- **IME composition**: receiving steps waits while `handle.composing` (`index.js:528`), as `syncState()` makes the tab `busy` today (`host.js:913-914`); sending continues. Not testable under node: the browser pass must cover macOS Japanese/Chinese input and Android Gboard.
- **Block ids**: keep `UniqueID` and `BlockIdRepair` (`resources/js/editor/extensions/blockId.js`) running on transactions that came from the server. Their repair becomes an ordinary local step that every page receives. Filtering them out (the roadmap's "Traps": "the block-id and figure-repair filters must ignore them") left duplicate ids in 38 and blocks without ids in 16 of 800 runs (section 4.2), most likely because a rebase can keep a split and drop the id step that followed it; leaving them on gave 0 and 0 in 1,600. That trap was right for Phase 2's coarse apply and is reversed here.
- **ensureIds does not re-stamp duplicates between siblings**: `ensureIds()` passes `$seen` into arrow functions (`DocumentSchema.php:32` and `:55`), which capture it by value, so two top-level blocks with one id keep it and `validate()` refuses the save (`DocumentSchema.php:391-396`); verified with a two-paragraph document. In steps mode that means a checkpoint carrying a duplicate id is refused, not silently re-stamped (which would have moved a comment anchor). Good for Phase 3; the rule text in `.ai/rules/app.md` ("ensureIds() ... already made it valid and document-unique") is wrong and should be corrected separately.
- **TrailingNode**: left on and untagged. When a received change leaves the document ending in a table or a rule, each page appends its own empty paragraph as an ordinary local step (13 such echo steps in 200 three-page runs). Tagging received transactions `skipTrailingNode` removed those in the trial but would not in a browser, where the heading-number plugin dispatches after every apply and the paragraph follows in that transaction (`.ai/rules/editor.md:49`). Two pages can therefore each add one; cosmetic, nothing lost (stated limit).
- **Figure repair** (`extensions/figure.js:226`) and images were not in the trial (they need a browser for `src`); the real suite must include them under a DOM shim.
- **Derived stamps** (`toc.entries`, `crossRef.label`): written by the server at each checkpoint (`Outline::apply()` in `fill()`, `DocumentStore.php:325-326`), never by the editor (`crossRef.js:107` inserts `label: null`; `toc.js:60` renders from the live outline). Pages that opened from different checkpoints hold different stamp values; positions are identical (attributes do not change sizes). Measured: a late joiner that opened a stamped checkpoint and replayed the rest of the log matched everyone else modulo stamps in 2,400 of 2,400 runs.

### 3.12 Failure modes (what the writer sees)

| Failure | What happens | What the writer sees |
|---|---|---|
| Server down / request lost | The steps stay unconfirmed and in the steps draft; the engine backs off (`engine.js:91-97`), sends resume with `SAVE_RETRY_DELAYS_MS`. | Not saved, as today; Editing returns once sends work. |
| Answer lost after the append | The next poll returns the page's own steps; `receiveTransaction()` recognises them by client id and confirms them. | Nothing. (Phase 2's echo rule is not needed.) |
| Duplicate request | The second copy names a `seq` that is no longer the head and is rejected; nothing is appended twice. Measured: 0 duplicate appends in 2,400 runs with 5% duplication. | Nothing. |
| Batch rejected as stale | Rebase and resend at once. | Nothing. |
| A step fails validation (422) | Treated as a fault below. | As below. |
| A received step cannot be applied, or `receiveTransaction()` throws despite the guard | The page reports `POST /documents/{uuid}/fault {epoch, seq}`; the server starts a new epoch from the last checkpoint (reason `fault`); every page treats its whole current document as `mine` against that checkpoint (3.7), so confirmed text that was not yet checkpointed is merged back in by Phase 2's merge (identical blocks from several pages collapse by id). A second fault for the document within 24 hours sets `steps_disabled_at`: that document runs in `follow` mode. | The fault sentence; the switched-off sentence on the second. |
| Epoch change (restore, import, suggestion) | 3.7. | The replacement sentence; lost-row items if any. |
| Page below the prune floor | `reset` with the checkpoint; epoch path. | As an epoch change, without a sentence. |
| Two tabs of one account | Two client ids; they co-edit like two people. | Nothing special. |
| Two tabs in one browser and the steps draft | One steps draft per tab (`@<tabId>`), so they never overwrite each other. | Nothing. |
| The switch flipped to `follow` | Downgrade in place (5.3). | The switched-off sentence. |
| A tab opened before the deploy | Follows, cannot save. | "This page is out of date. Reload it to keep editing." |

---

## 4. Evidence

Everything below was run on 2026-10-17 on the author's Mac (Node 24.14.0, PHP 8.5.9 CLI without opcache, SQLite through PDO), in a scratch copy outside the repository: `package.json` and `package-lock.json` of `main`, `npm ci`, plus `prosemirror-collab@1.3.1` installed in the scratch copy only, and a copy of `resources/js/editor/`. The scripts and raw results are in `docs/superpowers/research/2026-10-17-phase-3/` (each script's header says how to run it). This replaces the roadmap's "indicative, not proof" trial, which used a hand-written copy of the algorithm.

### 4.1 What was built for the trial

- **Editors**: real TipTap `Editor`s with `element: null` and the project's extension stack as `buildExtensions()` has it (`index.js:71-127`): StarterKit (with its history, TrailingNode, link), HeadingNumbered, Highlight, Subscript, Superscript, Align, DocTextStyle, Table (non-resizable, as `tests/js/sync.merge.editor.test.js` uses it) with rows, headers and cells, TaskList, TaskItem, Toc, CrossRef, PageBreak, SectionBreak, Callout, Columns, Column, Variable, DocAttrs, BlockIdRepair and BlockId (UniqueID), loaded through `tests/js/moduleLoader.js` exactly as the Phase 2 editor test does, with the plugins put into the state (`editor.view.updateState(state.reconfigure(...))`, the roadmap's element-null trap). **Left out**: Figure and image (their `src` needs a browser upload), PaginationExtension, SlashMenu and Placeholder (DOM only). Block ids were made deterministic per seed by seeding `crypto.getRandomValues`.
- **Authority**: an in-memory log with the compare-and-append rule of 3.3 (append only at the head, never split a batch, serve "steps since n" with client ids).
- **Network**: every request and every answer is delayed 1-5 ticks, lost with probability 0.10 and duplicated with probability 0.05; a send unanswered for 15 ticks is given up and resent; steps travel as JSON (`Step.toJSON()` / `Step.fromJSON()` with each page's own schema).
- **Edits** (300 per run, by a random page each tick, between random sends and polls): type a unique token at a random position; delete 1-6 characters; bold/italic/underline/highlight a range; Enter (`splitBlock`); Backspace at a block start (`joinBackward`); insert a paragraph, heading, page break, rule, bullet list, 2x2 table, two columns or callout after a random top-level block; toggle heading; toggle bullet list; add a table row or column; delete a table row; insert a cross-reference or a variable; align; delete a whole top-level block; undo; redo. Two mixes: **adversarial** (the list above at roughly equal weights, many structural edits in a 10-block document) and **typing** (75% typing, 8% deletes, 5% marks, 5% Enter, 2% Backspace-join, 2% new blocks, 1% each heading, list, undo).
- **Quiesce**: loss and duplication off, every page sends and polls until all are at the head with nothing unconfirmed (bounded at 500 rounds).
- **Checks per run**: every page's document equals the authority's replay of the log (as JSON); ids unique and present; `doc.check()` passes; a **late joiner** opens a checkpoint taken at the middle of the log with server stamps written into it (`toc.entries`, `crossRef.label`) and replays the rest; and, from a separate guarded analysis of each rebase, why each dropped step was dropped.
- **Variants**: `package` (the npm package) or `copy` (the guarded copy, `collab-copy.mjs`); `naive` (id plugins as the project has them) or `filtered` (UniqueID and BlockIdRepair ignore transactions from the authority, as the roadmap's trap advised); `skiptrailing` (received transactions tagged `skipTrailingNode`).

### 4.2 Results (200 seeds per row, 300 edits per seed)

| Collab | Ids | Mix | Pages | Converged | Ids unique | Ids missing (runs) | `check()` | Late joiner equal modulo stamps | `receiveTransaction()` threw | Dropped typed chars / typed chars |
|---|---|---|---|---|---|---|---|---|---|---|
| package | naive | adversarial | 2 | 200 | 200 | 0 | 200 | 200 | 1 | 2,897 / 69,483 |
| package | naive | adversarial | 3 | 200 | 200 | 0 | 200 | 200 | 8 | 5,915 / 68,796 |
| package | filtered | adversarial | 2 | 200 | **195** | **3** | 200 | 200 | 1 | 2,897 / 69,483 |
| package | filtered | adversarial | 3 | 200 | **187** | **4** | 200 | 200 | 8 | 5,915 / 68,796 |
| copy | naive | adversarial | 2 | 200 | 200 | 0 | 200 | 200 | 0 | 2,897 / 69,504 |
| copy | naive | adversarial | 3 | 200 | 200 | 0 | 200 | 200 | 0 | 5,973 / 68,736 |
| copy | filtered | adversarial | 2 | 200 | **195** | **3** | 200 | 200 | 0 | 2,897 / 69,504 |
| copy | filtered | adversarial | 3 | 200 | **185** | **6** | 200 | 200 | 0 | 5,973 / 68,736 |
| copy | naive | typing | 2 | 200 | 200 | 0 | 200 | 200 | 0 | 946 / 162,124 |
| copy | naive | typing | 3 | 200 | 200 | 0 | 200 | 200 | 0 | 1,525 / 163,164 |
| copy | naive, skiptrailing | adversarial | 2 | 200 | 200 | 0 | 200 | 200 | 0 | 2,897 / 69,504 |
| copy | naive, skiptrailing | adversarial | 3 | 200 | 200 | 0 | 200 | 200 | 0 | 5,968 / 68,742 |

Read with care:

- **Convergence: 2,400 of 2,400 runs.** But in the 18 package runs where `receiveTransaction()` threw (`ReplaceAroundStep.invert`: "Position -n outside of fragment", from `rebaseSteps()`), the harness rebuilt that page from the authority, which is what a real page would have to do, and its unconfirmed steps were lost. With the guarded copy: 0 throws in 1,600 runs and the same convergence.
- **Filtering the id plugins is wrong here**: 38 of 800 filtered runs ended with two blocks sharing an id and 16 with a block that had no id, against 0 and 0 in 1,600 unfiltered runs. The cost of leaving them on is local repair steps after a receive ("echo" steps): 218 in the 3-page adversarial copy row (192 node-markup steps (`replaceAround`), 13 trailing paragraphs, 11 id fixes), 55 in the 3-page typing row. The `replaceAround` echoes were not attributed to a plugin in this trial (prosemirror-tables' table normalisation is the likely source).
- **Dropped typing**: the "dropped" column is text inside local steps that `rebaseSteps()` discarded, almost all because the text was inside a range somebody else deleted at the same moment (`unmappable:replace+text`: 981 of 1,049 text-carrying drops in the 3-page adversarial copy row; the rest no longer applied). The ratio includes the text of inserted blocks and undo/redo re-insertions, so it is approximate: about 8.7% of typed characters in the adversarial mix (3 pages, a 10-block document, everyone everywhere), about 0.9% in the typing mix. In a real document with people in different places it is lower again (estimate). The package drops these silently; the copy reports them (scenario S4 below), which is what section 3.6 relies on.
- **Trailing paragraphs**: runs that ended with more than one empty paragraph at the end (17 of 200 three-page runs, at most 4) came from the random Enter and insert edits, not from TrailingNode: tagging received transactions `skipTrailingNode` removed its 13 echo steps and left the 17 unchanged.
- **Duplicate requests**: 0 batches appended twice in 2,400 runs with 5% duplication (`duplicateAccepted`).
- **Stamps**: 2,400 of 2,400 late joiners that opened a stamped checkpoint and replayed the rest matched the others once `toc.entries` and `crossRef.label` were blanked: stamps do not move positions.
- **Sizes**: a step is 146 bytes of JSON at the median, 227 at p95, 2,651 at most (adversarial) and 97 / 227 / 859 in the typing mix; a send batch 1.0-1.5 KB at the median, 4-6.3 KB at p95, 17.9 KB at most (largest 105 steps). The trial's documents stayed small (largest 11.9 KB of JSON), so this says nothing about very large documents.
- **Rejections**: 3,796 of 7,552 batches (50%) with 2 pages and 5,376 of 9,366 (57%) with 3 were rejected as stale and resent after a rebase.
- One edit threw inside a TipTap command in the 3-page adversarial rows (1 in 60,000 edits); it changed nothing and is not a sync issue.

**Hand-written scenarios** (`collab.scenarios.mjs`, both package and copy: 10 of 10 checks pass): S1 two people type into "We will meet on Monday." at the same moment ("everyone" after "meet", "at nine" after "Monday"); B's send is refused, B rebases and resends; both pages read "We will meet everyone on Monday at nine."; A's caret stays at 22, B's moves from 31 to 40 (after its own words). S2 A undoes: only "everyone" goes; B undoes: only "at nine" goes. S3 the Phase 1 history case (`.ai/rules/editor.md:54`): Enter at the start of "Hello", the other person types "Above" in the new paragraph and "Well, " before "Hello", the first undoes; result "AboveWell, Hello" on both pages (the split is undone, nobody's words are deleted). S4 B types " XYZZY" inside a sentence A deletes at the same moment: the deletion wins on both pages, and the copy reports " XYZZY" as dropped (the package does not). S5 A presses Enter in the paragraph B's caret is in; B keeps typing; "second (B) half" lands in the right half, and the new block has its own id on both pages.

**The server's write path**: the 100 documents the first 100 three-page runs converged on were passed through `DocumentSchema::ensureIds()` and `normalise()` (PHP, the real classes): 100 were fixed points once `align: null`, `{}`/`[]` and derived stamps were levelled (no attribute, text or structure change), and `validate()` accepted all 100. A canary set (a control character in text, an empty bullet list, two blocks sharing an id) was caught: two as text/structure changes, the third as a document `validate()` refuses (which is also how the `ensureIds()` duplicate finding of 3.11 was found).

### 4.3 SQLite under concurrent PHP processes

`sqlite-bench.php` runs each simulated request as PHP-FPM would: a new PDO connection, `busy_timeout` 5000, the session row write, a presence upsert every 5 s, the quiet version read, the compare-and-append in `BEGIN IMMEDIATE` (1-12 steps of 97 bytes each), the read of steps since. One typist per document also checkpoints every 10 s (content JSON, HTML and search text of the document size given, holding the lock 5 ms or 30 ms extra to stand in for `fill()`). A rejected append is resent after 60 ms (not timed). Readers poll without appending. 120 s per scenario, run one after another.

| Scenario | Journal | Processes (typists + readers) | Pace | Requests | Append p50 / p95 / p99 / max (ms) | Request p50 / p95 / p99 / max (ms) | Checkpoint p50 / max (ms) | Lock errors | Appends rejected as stale |
|---|---|---|---|---|---|---|---|---|---|
| A one document, 100 KB | DELETE | 5 (3 + 2) | 1.5 s | 667 | 0.1 / 2.3 / 2.6 / 6.2 | 2.1 / 3.9 / 9.0 / 11.5 | 7.5 / 8.6 | 0 | 261 of 504 |
| B one document, 100 KB | WAL | 5 (3 + 2) | 1.5 s | 663 | 0.0 / 1.4 / 1.7 / 2.2 | 1.4 / 2.5 / 7.7 / 8.9 | 6.8 / 7.6 | 0 | 256 of 500 |
| C four documents, 100 KB each | DELETE | 20 (12 + 8) | 1.5 s | 2,652 | 0.1 / 2.3 / 2.6 / 5.5 | 1.8 / 3.9 / 9.4 / 12.6 | 7.2 / 8.9 | 0 | 1,039 of 2,006 |
| D four documents, 100 KB each | WAL | 20 (12 + 8) | 1.5 s | 2,644 | 0.0 / 1.2 / 1.4 / 2.9 | 1.3 / 2.4 / 7.3 / 11.3 | 6.6 / 10.2 | 0 | 1,034 of 1,998 |
| E one document, 1 MB | DELETE | 5 (3 + 2) | 1.5 s | 663 | 0.1 / 5.9 / 6.7 / 11.0 | 2.7 / 7.2 / 38.0 / 44.0 | 36.7 / 39.0 | 0 | 257 of 500 |
| F stress, four documents, 100 KB each | DELETE | 32 (24 + 8) | 0.5 s | 20,274 | 0.0 / 2.1 / 3.9 / 60.8 | 1.1 / 5.8 / 22.1 / 121.1 | 7.3 / 10.5 | 0 | 12,595 of 18,368 |
| G stress, four documents, 100 KB each | WAL | 32 (24 + 8) | 0.5 s | 20,190 | 0.0 / 1.1 / 1.3 / 38.4 | 1.0 / 2.4 / 4.7 / 39.2 | 6.9 / 8.3 | 0 | 12,547 of 18,284 |

**`fill()` cost** (`fill-cost.php`, the real `Outline`, `HtmlRenderer` and `DocumentSchema` with the app booted on an in-memory database, median of 7, `results/fill-cost.json`): 2,134 words (21 KB JSON) 0.3 ms; 10,686 words (109 KB) 1.4 ms; 42,768 words (436 KB) 5.7 ms; 106,906 words (1.09 MB) 15.7 ms. `normalise()` + `validate()`, which run outside the transaction: 0.1, 0.8, 3.3 and 10.5 ms. An earlier run of the same script gave 25.8 ms for the largest document: treat these as orders of magnitude.

### 4.4 What the evidence shows, and what it does not

It shows: prosemirror-collab over this project's real schema and plugins converges under latency, loss and duplication with 2 and 3 pages; exact steps give per-character merging, carets that stay with their text and undo of your own typing only; the published package needs the two changes of decision 10; the id plugins must stay on; documents the editors converge on pass the server's write path unchanged; derived stamps do not disturb positions; on this machine the compare-and-append costs about 1-2 ms at p95 and SQLite raised no lock error with up to 32 processes, in either journal mode; `fill()` is cheap enough to stay inside the transaction for documents up to about 100,000 words.

It does not show: anything about the shared host (disk, CPU, PHP-FPM limits, per-IP throttles; every timing above is from a fast laptop SSD and could be several times slower there, unmeasured); the cost of booting Laravel per request (the bench uses bare PDO); browsers (IME, the DOM, pagination decorations, paste, the unload beacon); figures and images; documents larger than about 12 KB in the convergence runs; more than three pages; real people's editing patterns (the dropped-typing rate of real use is unknown); and the Phase 3 code itself, which does not exist: the epoch path, drafts, the downgrade and fault recovery are designed here, not tested.

---

## 5. Rollout and safety

### 5.1 The switch

`config/dotdoc.php` gains `'coediting' => env('DOTDOC_COEDITING', 'follow')`: `off` (no live sync: the engine does not start, saves still state their base), `follow` (today), `steps` (Phase 3). The page reads the mode at render. While `steps` is new, an allow-list `DOTDOC_STEPS_TEAMS` (team ids) limits steps mode to named teams, so the owner's team can run it for a week before anybody else; `documents.steps_disabled_at` turns it off for one document automatically (3.12) or by hand (an admin "reset live session" action, roadmap Phase 5).

### 5.2 Staged plan

| Stage | Contents | Usable on its own? |
|---|---|---|
| **3a Server log** | Migration, `DocumentStepLog` (append, since, prune, new epoch), `StepValidator`, `DocumentStore::checkpoint()`, the protocol-2 branch of the sync controller, the steps beacon and fault endpoints, epoch on restore/import/suggestion, protocol-1 refusal in steps mode, the switch. PHPUnit throughout. | Deployable dark (`follow` everywhere). |
| **3b Client co-typing** | `sync/collab.js` (vendored), `sync/steps.js` (pure: payloads, receive, dropped-step entries, checkpoint timing), engine protocol 2, host steps branch, read-only viewers, history kept across remote steps, paste filter. | Yes, behind the allow-list: same-sentence typing works; anything unusual (epoch, fault, pruned log) reloads the page with the unsent typing parked as a draft. **This is the reduced cut.** |
| **3c Replacements and drafts** | The epoch path with Phase 2's merge, late checkpoints for a closed epoch, the steps draft and its replay, the unload beacon pair, the downgrade in place, fault recovery by merge. | Completes the spec. |
| **3d Proof and docs** | The convergence suite (5.4), the browser pass, `.ai/rules/editor.md`, `livewire.md`, `app.md` updated, the roadmap's Phase 3 paragraph and decisions recorded. | Gate to widen the allow-list. |

### 5.3 Rollback

- **Instant**: set `DOTDOC_COEDITING=follow`. New pages load in Phase 2 mode. Open steps-mode pages learn it from the next answer (`mode: "follow"`) and **downgrade in place**: a page with nothing unconfirmed and at the head first sends a checkpoint (the server still accepts checkpoints for 10 minutes after the flip), then treats its document as unsaved typing on the stored version and continues with Phase 2's merge loop. Pages holding identical confirmed text produce identical blocks, which Phase 2's merge collapses (case a4). Unconfirmed steps are kept as a steps draft and merged by Phase 2's draft merge.
- **Database**: the migration is additive; rolling back leaves unused columns and a table. Nothing in `follow` mode reads them.
- **Per document**: `steps_disabled_at`.

### 5.4 What must pass before anything ships

1. **Convergence suite under plain `node --test`** (`tests/js/sync.steps.walk.test.js`), modelled on Phase 2's walks (`tests/js/sync.merge.walk.test.js`): seeded (`mulberry32`), 2 and 3 real TipTap editors from the project's extension stack (as `tests/js/sync.merge.editor.test.js` builds them, plus figures and images under a DOM shim), a fake authority with latency, loss and duplication, the operations of section 4.2 plus epochs, faults, pruning, offline periods, reloads with steps drafts and the downgrade. Invariants: every page equals the authority's replay; ids unique and present; `check()` passes; the authority's documents pass `DocumentSchema` as fixed points (a PHPUnit fixture pair, as Phase 2's `MergeFixtureTest`); every typed token is on the page, in a lost-row entry, in a park, or inside a range somebody deleted; nothing unconfirmed at quiesce. Defaults: 200 seeds each; `WALK_SEEDS` widens; a failing seed prints and replays with `WALK_SEED`.
2. **Mutation checks** (each must fail at least one test): remove the invert guard; stop reporting dropped steps; filter the id plugins on remote transactions; let a checkpoint through with `checkpoint_seq >= seq`; accept a stale `seq`; split a batch when serving; skip the epoch check; clear the history after remote steps; accept a step with an unknown node type.
3. **PHPUnit**: append (accepted, stale, epoch changed, disabled, viewer refused, size and rate limits), concurrent appends in two processes against a file database, checkpoint ordering, late checkpoint kept as a version, epoch on each replacement route, the quiet poll still reads no content, protocol-1 refusal in steps mode, pruning.
4. **The existing suites stay green in `follow` mode** (both Phase 2 walks: a change to the loop is not done until both are green, `.ai/rules/editor.md:138`).
5. **Browser pass (owner, two accounts, on a staging copy)**: same sentence, Enter and Backspace at the same spot, lists and tables at the same time, undo across the other person's typing, offline for a minute then back, close mid-sentence and reopen, a restore and an accepted suggestion while the other types, the switch flipped back, IME input, a phone.
6. **On the host before widening**: the Phase 0 read-outs (request cost, per-IP limits, `journal_mode`, `post_max_size`), then a measured three-typist session with `storage/logs/laravel.log` "database is locked" counted before and after.

### 5.5 Monitoring

Log lines (no content, ids only): each fault (document, epoch, seq), each epoch start and its reason, each automatic disable, rejected-batch counts per document per hour, dropped-step offers per document per hour, checkpoint refusals by reason, append p95 per hour. The Guardian already watches `laravel.log`; a fault or disable line is an alert.

---

## 6. Estimate, risks, recommendation (owner)

### 6.1 Estimate (working days, estimates)

| Stage | Days |
|---|---|
| 3a Server log, validator, checkpoint, protocol, epochs, switch | 3-4 |
| 3b Client co-typing (the reduced cut) | 4-5 |
| 3c Replacements, drafts, unload, downgrade, fault recovery | 3-4 |
| 3d Convergence suite, mutation checks, browser pass, rules and roadmap | 2-3 |
| **Total** | **12-16** |

The roadmap's reviewer said 10-15 before Phase 2 existed; Phase 2 adds the paths that must keep working in `follow` mode and the downgrade between the two, and the evidence added two pieces of work (the guarded copy and the dropped-typing offer). The reduced cut (3a + 3b + the parts of 3d that cover them) is 8-11 days.

### 6.2 The biggest risks

1. **Request load on the shared host (unmeasured).** About 7 requests a second for one document with three typists and two readers (estimate, 3.4). If the host throttles or the per-request cost is high, the cadence has to slow and "1-2.5 s behind" becomes 3-5 s. Mitigation: the Phase 0 read-outs first; the cadence constants are the knobs.
2. **Two engines side by side.** `follow` and `steps` must both keep working, and a page must move from one to the other in place. Phase 2's host module is already 2,575 lines (`host.js`); the steps branch and the downgrade add to it. Mitigation: the steps decisions go into their own pure module (`steps.js`) with the host only routing, and both walks gate every change.
3. **The stored copy lags the log.** Exports, published pages, search and webhooks see the last checkpoint, normally seconds old, but after every page closes abruptly inside a burst, the tail waits in the log until the next page opens the document. Mitigation: quiet-time and unload checkpoints, the first opener's checkpoint; a server that could replay steps (a Node process) would close it, and the host cannot run one.
4. Further: the vendored copy must be re-checked on ProseMirror upgrades (the suite catches it); IME behaviour can only be checked in browsers; a fault path that is rare is the path least exercised (the suite injects it).

### 6.3 Recommendation

**Do not build Phase 3 now; measure first.** Reasons:

- The owner's request was to fix the not-started issues. The complaint that started the roadmap (changes invisible until a refresh, saves silently overwriting each other) is fixed by Phases 1 and 2, which are live. What remains is the same-paragraph, same-second case, and nobody knows yet how often it happens: Phase 2 has only just gone live.
- Phase 3 is the largest and riskiest change in the roadmap (12-16 days, a second editing engine, a new request shape) and its main operational unknown (host request cost) is unmeasured.
- The cheap measurement settles it: log each lost-row offer (document id, reason, source; no text) for two to three weeks (about half a day to add), and run the Phase 0 read-outs.

**Build it** (as specified, staged) when the offers show same-paragraph collisions in real documents every week, or when same-sentence co-writing is wanted as a promise regardless of frequency. **Build the reduced cut** (3a + 3b, 8-11 days) if you want co-typing for a small team soon and can accept that restores, imports, accepted suggestions and very long offline periods reload the page with the unsent typing parked as a draft instead of merged in place. **Do not build it at all** if the measurement shows collisions are rare: Phase 2 then meets the need, and the effort is better spent on Phase 4's carets or Phase 5's hardening.

---

## 7. Questions that block implementation planning

1. **Build now, measure first, or the reduced cut?** (section 6.3). Nothing else is worth planning until this is answered.
2. **The owner-only decisions of section 2** (1, 4, 6 for public links, 7, 8, 9, 10, 11, 12). "Go with the recommendations" answers all of them. Decision 10 (vendoring MIT code) is the only one the plan cannot work around.
3. **Have the Phase 0 host read-outs been run?** (roadmap "Verify on the server first": per-request cost, per-IP throttling, `PRAGMA journal_mode`, `post_max_size`, free disk). They do not change the design, but they fix the cadence constants and decide WAL; the plan's acceptance step needs them, and they cannot be run from here (this work never contacts production).
