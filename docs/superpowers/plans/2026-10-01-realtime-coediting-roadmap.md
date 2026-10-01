# Real-time co-editing roadmap

Written 2026-10-01. This is the phased plan, not a task list: each phase gets
its own task-level implementation plan (with tests written first) when it is
about to start. The full analysis behind it - three system maps, three
competing designs and a skeptical review of them - is kept locally at
`.superpowers/research/2026-10-01-realtime-coediting-analysis.json`
(gitignored).

## The complaint

Two people open the same shared document. Neither sees the other's changes
until they refresh the page.

## Why it does not work today

Verified against the live site and the code. These are independent problems;
fixing any one alone changes nothing the owner would notice.

1. **No browser ever opens a live connection.** The JavaScript is built on
   GitHub's servers (`deploy.yml`, `npm run build`) with no `VITE_REVERB_*`
   values, so the published bundle contains `key: undefined`,
   `wsHost: 'localhost'`, port 8080. The Echo client throws on the missing key,
   `resources/js/bootstrap.js` swallows the error, `window.Echo` is never
   defined, and the editor's `setupEcho()` returns on its first line. The
   notification bell and live comments are dead for the same reason: the server
   publishes, nothing listens.
2. **There is nothing to connect to.** No websocket server is supervised on the
   host (`bootstrap.yml` needs systemd; this shared cPanel account has none),
   nothing proxies `wss` on port 443, and port 8080 is filtered from the
   internet.
3. **There is no fallback.** No polling, no server-sent events. With the socket
   absent, nothing ever fetches another person's changes - hence "only after a
   refresh".
4. **Even a working socket would drop real documents.** `DocumentUpdated`
   carries the whole document twice (HTML and JSON); Reverb refuses publishes
   over 10,000 bytes, roughly 150-250 words. The failure is swallowed.
5. **The editing model is save-then-replace, not live editing.** A change
   leaves a browser only 1.2 seconds after its author STOPS typing, as the
   entire document. The receiver's editor replaces everything: text they typed
   but had not saved is destroyed, the caret jumps, and undo stops working.
6. **Saves silently overwrite each other - this is losing work today.**
   `Editor::saveContent` sends no base version and `DocumentStore::save()`
   increments unconditionally. When two people have a document open, each
   autosave replaces the other's work with a stale copy. A refresh shows
   whichever whole document was saved last.
7. **One account in two windows shows nothing by design.** Updates from the
   same user id are ignored (`editor.blade.php`), so testing with a single
   account cannot work even when everything else does.
8. Restore, import, accepting an AI suggestion, changing the style and the
   page-unload save all change content without telling open editors.

## What "real time" will mean here

The production host cannot run a websocket server, so the design must be
correct with **no socket at all**. The target is: several people typing in the
same document, even the same sentence, each seeing the others about one second
behind, with carets that stay put and undo that only undoes your own typing.
A socket (Phase 6) later makes it faster; it is never required for
correctness.

## Recommended approach

**An ordered log of small edits, with Laravel as the referee** (the standard
ProseMirror collaboration model, `prosemirror-collab`).

Each browser sends its small edits ("steps") tagged with the version they were
made against. One Laravel endpoint accepts a batch only if that version is
still current - otherwise the browser re-applies its edits on top of the
newer ones and retries - appends them to a log, and hands every other browser
the edits it has not seen. The server only orders the edits; it never has to
run the editor. The stored document is still written only by `DocumentStore`,
which still validates and normalises every checkpoint.

Why this one:

- **Fits the host.** The busy path is a small row insert per typing burst.
  It works over plain HTTP requests, so it needs no socket and no extra
  service.
- **Keeps people's text.** Two people typing in the same sentence both keep
  their characters. A headless trial with this editor's real plugins
  (history, block ids, trailing paragraph) converged, kept the caret in
  place and undid only the undoer's own text.
- **Preserves what is already built.** `DocumentStore` stays the only writer,
  schema validation stays, and page-break decorations survive because edits
  arrive as small steps instead of whole-document replacements.
- **One small dependency** (`prosemirror-collab`, about 200 lines, depends
  only on a package already installed).

Rejected:

- **Yjs / CRDT** (TipTap's Collaboration extension). Best merging on paper,
  wrong for this host and codebase: the live truth becomes a binary blob PHP
  cannot read, validate or repair; it needs a provider process the host
  cannot run; it replaces the whole document on each remote update (wiping
  page decorations); five new dependencies. It also contradicts the standing
  rule that all content writes go through `DocumentStore`.
- **Keep whole-document saves and merge them in the browser** as the final
  design. It turns the heaviest operation (a full save: outline, HTML render,
  search text, inside the SQLite write lock) into something that runs about
  once a second per typist, and its character-level merge visibly corrupts a
  sentence two people edit at once. Its first third is good and is reused as
  Phases 1-2 below.

## Phases

Each phase ships something usable on its own. Sizes are working-day
estimates from the design review, not commitments.

### Phase 0 - Facts and switches (about half a day, no feature code)

- Run the server read-outs under "Verify on the server first".
- If production has `BROADCAST_CONNECTION=reverb`, set it to `null`: every
  save, editor open and comment is currently paying up to 2 seconds for a
  publish that cannot succeed.
- Record the owner's decisions (see below).
- Agree a two-account test setup on production for acceptance.

### Phase 1 - No more silent overwrites; changes arrive without a refresh (3-4 days)

**What you see:** when one person types, everyone else with the document open
sees it within 2-3 seconds, no refresh. Nobody's save can silently erase
someone else's. One account in two tabs stays in sync. The faces in the bar
update within seconds.

- `DocumentStore::save()` takes an expected version and re-reads the version
  inside the transaction; a stale write is refused, not applied.
- `Editor::saveContent` and the page-unload save send their base version; a
  save with none is refused.
- New `POST /documents/{uuid}/sync`: returns the version and who is here,
  plus the document and outline when the version has moved. Authorises from
  the database on every call. Must not load the whole document row on a
  quiet poll.
- A small sync loop in `resources/js/editor/sync/`: about every 1.5 s when
  others are present, 10-15 s alone, paused when the tab is hidden.
- A tab with no unsaved typing applies the remote document as a narrowed
  replace (only the changed range), so caret, undo and page breaks survive.
- Per-tab presence, with a leave signal on unload.
- Webhooks move to after the save commits.

**Limit:** if two people type at the same moment, the second save is refused
and that text is kept aside with a notice. Phase 2 removes this.

### Phase 2 - Both can type: paragraph-level merge (2-3 days)

**What you see:** two people working in different paragraphs both keep typing
and see each other's paragraphs appear 1-3 seconds behind. If both change the
same paragraph in the same second, both versions are kept one under the other
with a notice. Nothing is dropped.

- A three-way merge of top-level blocks, matched by the block ids the schema
  already assigns, against the last server copy the tab knew. Compares
  editor nodes, not raw JSON (see "Traps").
- The refused-save loop: refused, pull, merge, apply, save on the new base.
- Offline drafts remember the server copy they were based on, so a stale
  draft can be merged and offered instead of only set aside.

Deliberately not included: merging characters within one paragraph, and
merging inside tables and lists. That is Phase 3's job.

### Phase 3 - Type together for real: the ordered edit log (10-15 days)

**What you see:** several people type in the same document, even the same
sentence, and see each other about a second behind. Carets do not jump. Undo
only undoes your own typing.

- A `document_steps` table and epoch / sequence / checkpoint counters;
  compare-and-append inside one transaction; the sync call carries edits both
  ways.
- Checkpoints of the full document go through `DocumentStore`, tagged with
  the edit sequence they represent, so a late checkpoint can never overwrite
  newer content.
- Whole-document replacements (AI replace, draft restore, accepted
  suggestion, version restore, import) get a defined route: a new epoch that
  reloads open editors, keeping their last unsent seconds as recoverable
  text.
- On page unload the unsent edits go first (they are small); the full
  checkpoint is best-effort.
- A config switch `off` / `follow` / `steps` gives an instant rollback to
  Phase 2 behaviour.
- A convergence test with two and three headless editors, run under plain
  `node --test`, exists **before** anything ships.

### Phase 4 - See each other: named carets and selections (3-4 days)

Remote carets and selections with name labels, reported as block id plus
offset on the sync call. A "typing" state in the presence strip. View-only
people are shown but have no caret. Additive; can be switched off.

### Phase 5 - Hardening and load (4-5 days, one item at a time)

A measured multi-typist test on the real host; SQLite WAL mode if the
filesystem allows; pruning of old edits and presence rows; throttled
webhooks; contributors recorded on version snapshots; an admin "reset live
session" action; rule files brought up to date.

### Phase 6 - Optional: faster than polling (1-2 days plus an account)

A "something changed" poke carrying only a version number, over a hosted
Pusher-compatible service or Reverb on a small separate server. Realtime
settings move from build-time `VITE_*` values to a runtime value in the page,
so a build without them can never ship a dead client again. This also revives
the live notification bell and live comments. Correctness is unchanged if the
socket drops. Needs the owner to choose and pay for the outside service.

## Decisions needed from the owner

1. **Speed bar.** Is "about one second behind while the other person types"
   acceptable, at no extra cost? If it must be keystroke-instant, Phase 6
   (an outside service or small server, paid) becomes required.
2. **Same paragraph, same second (Phase 2 only):** keep both versions one
   under the other with a notice (recommended), or keep yours and drop
   theirs.
3. **Delete beats typing.** If one person deletes a paragraph while another
   is typing in it, the typing in that paragraph is lost. This is standard
   for this kind of merging. Accept?
4. **Reloads.** Restoring a version, importing over a document or accepting
   an AI suggestion would reload the editor for everyone who has it open,
   keeping their last unsent seconds as recoverable text. Accept?
5. **One account in two tabs or devices** should co-edit live. Recommended:
   yes.
6. **View-only collaborators and public-link visitors** see edits live.
   Recommended: yes for text, no caret from them.
7. **Named carets** in the first co-typing release, or one phase later as
   planned.
8. **Version history:** cut automatic versions by time only, not whenever the
   author changes. (Changes one existing test.)
9. **Webhooks:** once checkpoints happen every few seconds, fire `on_save` at
   most once a minute per document, or only when the editing session ends.
10. **Approve one new dependency**, `prosemirror-collab` (MIT), or copy its
    roughly 200 lines into the repo.
11. **Scale to design for:** how many people in one document at once, how many
    documents being edited at once, how long real documents get.
12. **Retire the earlier Yjs + Hocuspocus decision** (D4 in
    `docs/superpowers/specs/2026-09-07-dot-doc-platform-design.md`) and later
    drop the unused `documents.ydoc_state` column.

## Verify on the server first

Run in `/home/infodotc/doc.infodot.co.za/doc`. Names and non-secret values
only.

- Broadcasting and session settings:
  `grep -E '^(BROADCAST_CONNECTION|REVERB_HOST|REVERB_PORT|REVERB_SCHEME|SESSION_DRIVER|CACHE_STORE)=' .env`
- Account limits that cap polling: `uapi ResourceUsage get_usages`
- PHP limits:
  `php -i | grep -E '^(max_execution_time|post_max_size|memory_limit|opcache.enable)'`
- SQLite journal mode: `sqlite3 database/database.sqlite 'PRAGMA journal_mode;'`
  (try WAL only on a COPY of the file, never the live one)
- Real document sizes:
  `sqlite3 database/database.sqlite 'select count(*), max(length(content_json)), avg(length(content_json)) from documents;'`
- Duplicate version rows from past overlapping saves:
  `sqlite3 database/database.sqlite 'select document_id, version_number, count(*) from document_versions group by 1,2 having count(*) > 1;'`
- Free disk quota (the edit log lives in the same SQLite file).
- What one authenticated request costs on this host, and whether the host
  throttles one request a second from one address (ModSecurity, Imunify360
  and LiteSpeed per-IP limits are common on shared cPanel; two colleagues
  behind one office connection look like one address). This sets the
  affordable poll interval.

## Traps the implementation must respect

Found by checking the three designs against the code. Each of these would
have shipped a bug.

- **Never compare editor JSON with server JSON using `==`.**
  `DocumentSchema::normalise()` removes `align` when it is null, and the
  editor emits `align: null` on every paragraph and heading. A naive "did the
  server change it?" check is true for every document; a naive merge thinks
  every paragraph was edited locally. Compare ProseMirror nodes, or ignore
  null attributes and derived fields.
- **Remote edits must not trigger local repair plugins.** TrailingNode and
  the block-id plugin react to a remote transaction by creating local edits
  with locally generated ids. Remote transactions need the `skipTrailingNode`
  meta, the block-id and figure-repair filters must ignore them, and the
  server must never split one author's batch when serving "edits since N".
- **Remote edits arm the local autosave** unless filtered: TipTap emits
  `update` for any document-changing transaction.
- **A whole-document checkpoint cannot rely on the unload beacon.** Browsers
  cap beacon bodies at roughly 64 KB. Today's unload save has the same silent
  limit.
- **An editor created with `element: null` has no plugins in its state.**
  Convergence tests must build `EditorState` from
  `editor.extensionManager.plugins`, or they test nothing.
- **Old tabs.** A tab left open across a deploy runs old JavaScript. Every
  sync call carries a protocol version; in steps mode the legacy save paths
  are refused with "reload to continue", otherwise one stale tab resets
  everyone on each autosave.
- **`EditorStyleRootTest`** finds the editor's Livewire snapshot by the
  string `contentJson`; taking that property out of the snapshot breaks it
  until it is re-keyed.
- **The service worker** replays queued Livewire saves when the connection
  returns. Once base versions are required those stale whole-document saves
  must be refused, and `public/sw.js` should ignore non-GET requests that are
  not Livewire.
- Broadcast and relay failures are swallowed by empty `catch` blocks in
  `Editor.php`; log them.

## Unknowns that could change the plan

- Polling cost on the real host is unmeasured. Every sync request boots
  Laravel and writes the session row to the same SQLite file. Estimated fine
  for a handful of editors; Phase 0 and Phase 5 measure it.
- Phase 3 replaces autosave, drafts, the unload path and the Blade bridge at
  once, with no existing realtime tests to lean on. 10-15 days is the
  reviewer's corrected estimate (the design's own was 8-10).
- The headless trial used a hand-written copy of the collaboration algorithm,
  not the package itself. It is indicative, not proof.

## Where to resume

1. Get the owner's answers to decisions 1-5 at least (the rest have
   recommended defaults).
2. Run the Phase 0 server read-outs.
3. Write the task-level plan for Phase 1 and build it.
