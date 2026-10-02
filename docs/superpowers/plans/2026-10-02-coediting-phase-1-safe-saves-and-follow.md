# Co-editing Phase 1: Safe Saves and Follow-Without-Refresh — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When one person edits a shared document, everyone else with it open sees the change within about 2-3 seconds without refreshing, and no save can silently overwrite someone else's work.

**Architecture:** Every editor save states the document version it was based on, and `DocumentStore::save()` refuses a save based on an older version. A new plain HTTP endpoint (`POST /documents/{uuid}/sync`) reports the current version and who is present, and hands over the document when the caller's copy is behind. A small dependency-free engine in the browser polls that endpoint (fast when others are present, slow when alone, paused when the tab is hidden) and applies a newer document as one replacement of only the range that differs, so the caret, undo history and page breaks outside that range survive. No websocket is involved anywhere.

**Tech Stack:** Laravel 13 / PHP 8.5, Livewire 3, PHPUnit, TipTap 3 on ProseMirror, Alpine (via Livewire), `node --test` for JavaScript, SQLite in test and production (the local dev database is PostgreSQL).

**Roadmap:** `docs/superpowers/plans/2026-10-01-realtime-coediting-roadmap.md` (this is its Phase 1). Background analysis, gitignored: `.superpowers/research/2026-10-01-realtime-coediting-analysis.json`.

## Global Constraints

- Before editing any file, open `.ai/rules/index.md` and read every rule file whose globs match it. For this plan that is `app.md` (`app/**`), `outline.md` (`app/Documents/Outline/**`), `migrations.md` (`database/migrations/**`), `editor.md` (`resources/js/editor/**`), `views.md` (`resources/views/**`, `resources/css/**`, `resources/js/**`), `livewire.md` (`resources/views/livewire/**`), `notifications.md` (`app/Events/**`), `publishing.md` (`routes/web.php`).
- Every change is tested first: write the failing test, watch it fail, then implement.
- All document content writes go through `App\Documents\DocumentStore`. Nothing in this plan writes `content`, `content_json`, `search_text`, `word_count` or `version` any other way.
- Never compare editor JSON with server JSON using `==` or `JSON.stringify`. The server strips `align` when it is null and the editor emits `align: null` on every paragraph and heading. Compare ProseMirror nodes (`Fragment.findDiffStart`), or use `DotDoc.documentsDiffer`. Parsed nodes match except in two places, which `remoteTransaction()` (Task 6: the half of `applyRemote()` that builds the change) levels before it compares: the server-derived `toc.entries` and `crossRef.label`, and an empty `sectionBreak.setup` or `doc.vars`, which PHP sends as `[]` where the editor holds `{}`.
- Every path that writes into the editor or reads a draft checks `handle.autosaves === false` first (the fail-closed rule in `.ai/rules/livewire.md`).
- No new npm or composer dependency. `prosemirror-model`, `prosemirror-state` and `prosemirror-transform` are already installed (TipTap depends on them) and may be imported in tests; the editor bundle imports the same code through `@tiptap/pm/...`, as it already does elsewhere.
- PHP: run `vendor/bin/pint --dirty --format agent` after changes; `vendor/bin/phpstan analyse --memory-limit=1G` must report no errors and the baseline must not gain entries.
- JavaScript under `resources/js/editor/sync/` is dependency-free so `node --test` can load it: no npm package and nothing from the rest of the editor bundle. One file there may import another, with the `.js` extension written out (`./narrow.js`), because `node --test` does not resolve an extensionless import. Tests live in `tests/js/*.test.js` and run with `npm test`.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Work on a branch off `main` named `coediting-phase-1`. Do not merge: `main` requires a review the owner cannot give themselves, and merging deploys to production.
- Tasks 2 to 8 leave the editor page in an intermediate state: from Task 2 on, a save from a real browser is refused until Task 8 wires the page. The branch is shippable and browser-testable only after Task 8.

## Owner decisions this plan assumes

The owner has not yet answered the roadmap's decision list. Phase 1 only depends on one of them, and uses the recommended answer:

- **One account open in two tabs stays in sync** (roadmap decision 5: recommended yes). The old code ignored updates from the same user id; the new engine keys on version, not on who saved.

One more decision is not on the roadmap's list. This plan makes it, and the owner should know:

- **The service worker's offline save queue is removed** (Task 9). When the connection dropped, the service worker kept the page's save request, handed the page a made-up reply, and sent the kept request again when the connection came back. It never worked: the reply it faked froze the page's save machinery until reload, and its replay would overwrite newer work. What protects text typed offline is the offline draft in the browser; the page sends that text itself once it is back online.

## What Phase 1 does NOT do

- It does not merge two people's simultaneous typing. If a newer version arrives while a tab has unsaved typing, that tab stops saving and shows a notice with two choices: **Keep mine** (save over the newer version, knowingly; the version that is replaced is first kept in the document's history) or **Load theirs** (show the newer document; the local text is set aside, and a "Put it back" control in the page restores it, which saves it over the version that was loaded exactly as **Keep mine** would have: the replaced version is first kept in the history). Phase 2 replaces this notice with an automatic paragraph-level merge.
- It does not tell the person whose text was replaced. When somebody chooses **Keep mine** (or **Load theirs** and then **Put it back**), the other person's tab follows to the kept version with no notice. Their text is kept as a version in the document's history and nowhere else. That version is stored with the label "Before <name> kept their version", but the history page lists versions by number, time and author and does not show labels today, and it names the person who made the choice as that version's author.
- It does not recognise the writer's own save when the answer to it is lost. A save that the server stored, but whose response never reached the page (the connection dropped mid-response), is sent again after 15 seconds on the old base, is refused against the writer's own stored save, and shows the conflict notice although nobody else touched the document. Either choice is safe: no text is lost and nothing of anybody else's is replaced. **Keep mine** then leaves a "Before <name> kept their version" copy of the writer's own text in the history. Telling the two apart needs a node-level comparison of the waiting document with the editor's, which belongs with Phase 2's merge.
- It does not save the words typed in the last second before a tab is closed while that tab's own save is still in the air. The unload beacon states the old base, the save in the air lands first, and the beacon is refused as stale; today it is accepted. Those words are in the offline draft, and the next time the document is opened in that browser the page says "Your text was set aside." and offers **Put it back**. In a browser that cannot keep a draft they are lost.
- It does not keep the caret and undo history through every remote change. A newer document is applied as ONE replaced range. When a single apply carries two separate changes, the text between them is replaced too: a caret there moves to the end of the range, and local edits there can no longer be undone.
- It does not stop two people who OPEN a document that does not end in a paragraph at the same moment from colliding. Such a document comes from an import, a restore or an accepted AI suggestion. The editor adds an empty paragraph at the end when the page loads and autosaves it, as it does today; when two tabs do that together, the second save is refused and that tab shows the conflict notice although nobody typed. Either choice is safe. (A tab that is already open when such a document arrives does not do this: see Task 6.)
- It does not take `contentJson` out of the Livewire snapshot (the roadmap listed this under Phase 1). It is an optimisation, not needed for correctness, and it breaks `EditorStyleRootTest`; it moves to Phase 5.
- It does not change the `DocumentUpdated` broadcast payload or add a websocket. If a socket happens to be connected (local development with Reverb), the broadcast is used only as a "check now" poke.

## File Structure

| File | Responsibility |
|---|---|
| `app/Documents/StaleDocumentException.php` (create) | Thrown when a save was based on an older version. Carries the current version. |
| `app/Documents/DocumentStore.php` (modify) | `save()` gains the `expectedVersion` and `keepReplacedAs` options; counts the new version from the database; fires webhooks after commit. |
| `app/Livewire/Documents/Editor.php` (modify) | `saveContent()` requires a base version, reports a conflict, and when told the save is an overwrite asks the store to keep the replaced version in the history; presence is read by `refreshPresence()`; `heartbeat()` and `leaving()` stay as empty methods for tabs opened before the deploy. |
| `app/Http/Controllers/DocumentAutosaveController.php` (modify) | The unload beacon requires `base_version`; a stale one answers 409. |
| `app/Documents/Outline/EditorOutline.php` (create) | The outline payload the editor needs (numbers, TOC, page setup), shared by the Livewire component and the sync endpoint. |
| `phpstan-baseline.neon` (modify) | Two entries for `Editor.php` shrink when `outline()` moves out (Task 3). Nothing is added. |
| `database/migrations/*_create_document_presences_table.php` (create) | One row per open tab per document. |
| `app/Services/PresenceService.php` (rewrite) | Table-backed, per tab, race-free presence. |
| `app/Events/UserJoinedDocument.php`, `app/Events/UserLeftDocument.php` (delete) | Dead once presence is carried by the sync poll. |
| `app/Http/Controllers/DocumentSyncController.php` (create) | The sync endpoint. |
| `routes/web.php` (modify) | Registers `documents.sync`. |
| `resources/js/editor/sync/narrow.js` (create) | The smallest range that differs between two ProseMirror documents. |
| `resources/js/editor/sync/apply.js` (create) | The transaction that makes an editor show a server document: what is levelled first, the editor's own trailing paragraph, the one replaced range and its whole-document fallback. No imports from the editor bundle, so `node --test` runs every branch. |
| `resources/js/editor/sync/engine.js` (create) | The polling state machine. No DOM, no fetch, no timers of its own: all injected. |
| `resources/js/editor/sync/request.js` (create) | The one `fetch` call the engine uses. |
| `resources/js/editor/index.js` (modify) | `applyRemote()` becomes a narrowed, non-destructive apply (guards and dispatch here, the transaction from `sync/apply.js`); the unload beacon carries the base version; exposes the sync modules on `window.DotDoc`. |
| `resources/views/livewire/documents/editor.blade.php` (modify) | Starts the engine, sends the base version with saves, shows the conflict notice, refreshes presence. |
| `resources/js/editor/pagination/index.js` (modify) | Comment only: how a remotely applied document gets repaginated. |
| `public/sw.js` (modify) | The service worker stops intercepting non-GET requests that are not Livewire, answers an offline Livewire request with a network error, no longer queues and replays saves, and no longer touches IndexedDB. |
| `.ai/rules/*.md` (modify) | The standing rules that this change rewrites (`app.md`, `editor.md`, `livewire.md`, `notifications.md`, `views.md`) and the index row that puts `public/sw.js` under `views.md`. |
| `wiki.md` (modify) | Drops the two deleted events from its events table and says what `DocumentUpdated` now means. |

---

## Task 1: `DocumentStore::save()` refuses a save based on an older version

**Files:**
- Create: `app/Documents/StaleDocumentException.php`
- Modify: `app/Documents/DocumentStore.php:74-99`
- Test: `tests/Feature/Documents/DocumentStoreVersionGuardTest.php` (create)

**Interfaces:**
- Produces: `App\Documents\StaleDocumentException` with `public readonly int $currentVersion`.
- Produces: `DocumentStore::save(Document $doc, array $json, User $actor, array $opts = []): Document` accepts `$opts['expectedVersion']` (`int`). When present and not equal to the version in the database, it throws `StaleDocumentException` and writes nothing. When absent, the save proceeds as before (restore, import, accepting a suggestion and changing the style rely on that).
- Produces: `DocumentStore::save()` also accepts `$opts['keepReplacedAs']` (`string`, a label). When present, the document as it is STORED is kept as a `named` version with that label before it is replaced, inside the same transaction as the write: the version exists only if the save is stored, and holds exactly what the save replaced. A save that is refused as stale, or whose content the schema rejects, keeps nothing. Task 2 uses it for the editor's "Keep mine" and "Put it back".

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Documents/DocumentStoreVersionGuardTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Documents\StaleDocumentException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\WebhookService;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A save states the version it was based on, and one based on an older
 * version is refused instead of silently replacing somebody else's work.
 */
class DocumentStoreVersionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    private function doc(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Guarded');
    }

    public function test_a_save_based_on_the_current_version_is_stored(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $saved = app(DocumentStore::class)->save($doc, $this->para('Mine'), $user, ['expectedVersion' => $doc->version]);

        $this->assertSame(2, $saved->version);
        $this->assertSame('Mine', $doc->fresh()->search_text);
    }

    public function test_a_save_based_on_an_older_version_is_refused_and_changes_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('Somebody else got here first'), $user, ['expectedVersion' => 1]);

        try {
            $store->save(Document::findOrFail($doc->id), $this->para('Based on what I opened'), $user, ['expectedVersion' => 1]);
            $this->fail('A save based on version 1 must be refused once the document is at version 2.');
        } catch (StaleDocumentException $e) {
            $this->assertSame(2, $e->currentVersion);
        }

        $fresh = $doc->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame('Somebody else got here first', $fresh->search_text);
    }

    /** Restore, import and "accept suggestion" replace the document on purpose and state no base. */
    public function test_a_save_that_states_no_base_version_still_goes_through(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('First'), $user);
        $saved = $store->save(Document::findOrFail($doc->id), $this->para('Second'), $user);

        $this->assertSame(3, $saved->version);
    }

    /**
     * Two requests can each hold a model loaded at version 1. The second
     * must become version 3, never a second "version 2".
     */
    public function test_the_new_version_is_counted_from_the_database_not_from_a_stale_model(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $first = Document::findOrFail($doc->id);
        $second = Document::findOrFail($doc->id);

        $store->save($first, $this->para('One'), $user);
        $saved = $store->save($second, $this->para('Two'), $user);

        $this->assertSame(3, $saved->version);
        $this->assertSame(3, $doc->fresh()->version);
    }

    /**
     * The editor's "Keep mine": the caller replaces a newer version on
     * purpose and asks for what it replaces to be kept. That version may
     * have no entry of its own in the history.
     */
    public function test_a_save_can_keep_the_version_it_replaces_as_a_named_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('Theirs'), $user, ['version' => 'none']);

        $saved = $store->save(Document::findOrFail($doc->id), $this->para('Mine'), $user, [
            'expectedVersion' => 2,
            'keepReplacedAs' => 'Before Thandi kept their version',
        ]);

        $this->assertSame(3, $saved->version);
        $this->assertSame('Mine', $doc->fresh()->search_text);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->sole();

        $this->assertSame('Before Thandi kept their version', $kept->label);
        $this->assertSame(2, $kept->version_number);
        $this->assertSame('Theirs', $kept->content_json['content'][0]['content'][0]['text']);
    }

    /** The kept version is part of the save: a save that is not stored keeps nothing. */
    public function test_a_save_that_is_not_stored_keeps_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('Theirs'), $user, ['version' => 'none']);

        try {
            $store->save(Document::findOrFail($doc->id), $this->para('Mine'), $user, [
                'expectedVersion' => 1,
                'keepReplacedAs' => 'Before Thandi kept their version',
            ]);
            $this->fail('A stale save must be refused even when it asks to keep what it replaces.');
        } catch (StaleDocumentException) {
            // Refused, as it should be.
        }

        try {
            $store->save(Document::findOrFail($doc->id), ['type' => 'doc', 'content' => [['type' => 'marquee']]], $user, [
                'expectedVersion' => 2,
                'keepReplacedAs' => 'Before Thandi kept their version',
            ]);
            $this->fail('Content the schema does not know must be refused.');
        } catch (InvalidArgumentException) {
            // Refused, as it should be.
        }

        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->count());
        $this->assertSame('Theirs', $doc->fresh()->search_text);
    }

    public function test_the_webhook_fires_once_after_the_save_has_committed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $outerLevel = DB::transactionLevel();

        $this->mock(WebhookService::class, function (MockInterface $mock) use ($outerLevel) {
            $mock->shouldReceive('fire')->once()->andReturnUsing(function () use ($outerLevel) {
                $this->assertSame($outerLevel, DB::transactionLevel(), 'The webhook must fire after the save transaction has closed.');
            });
        });

        app(DocumentStore::class)->save($doc, $this->para('Announce me'), $user, ['expectedVersion' => 1]);
    }

    public function test_a_refused_save_fires_no_webhook(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->mock(WebhookService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('fire'));

        $this->expectException(StaleDocumentException::class);

        app(DocumentStore::class)->save($doc, $this->para('Too late'), $user, ['expectedVersion' => 99]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Documents/DocumentStoreVersionGuardTest.php`
Expected: 6 fail, 2 pass. `test_a_save_based_on_an_older_version_is_refused_and_changes_nothing` fails on its own `fail()` message ("A save based on version 1 must be refused once the document is at version 2."); `test_a_save_can_keep_the_version_it_replaces_as_a_named_version` fails with `No query results for model [App\Models\DocumentVersion].` (nothing was kept, so `sole()` finds no named version); `test_a_save_that_is_not_stored_keeps_nothing` fails on its first `fail()` message ("A stale save must be refused even when it asks to keep what it replaces."); `test_a_refused_save_fires_no_webhook` fails with `Failed asserting that exception of type "App\Documents\StaleDocumentException" is thrown`; the stale-model test fails with `Failed asserting that 2 is identical to 3`; the webhook test fails with `Failed asserting that 2 is identical to 1`. No "Class not found" appears: a `catch` block and `expectException()` do not load the class. The current-version test and the no-base test already pass: they guard behaviour that exists today.

- [ ] **Step 3: Create the exception**

Create `app/Documents/StaleDocumentException.php`:

```php
<?php

namespace App\Documents;

use RuntimeException;

/**
 * A save was based on an older version of the document than the one stored.
 *
 * Thrown by DocumentStore::save() when the caller states the version it
 * started from (`expectedVersion`) and somebody has saved since. Nothing is
 * written. `$currentVersion` is what the document is at now, so the caller
 * can tell the browser what it is behind.
 */
class StaleDocumentException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct("The document is at version {$currentVersion}, newer than the version this save was based on.");
    }
}
```

- [ ] **Step 4: Guard the save**

In `app/Documents/DocumentStore.php`, replace the `save()` method (the docblock line `/** @param array{version?:string,label?:string|null} $opts */` through the method's closing brace) with:

```php
    /**
     * `expectedVersion` is how a writer says "this is the version my copy
     * was based on". When it is given and the stored document has moved on,
     * the save is refused with StaleDocumentException and nothing is
     * written - two people with the same document open would otherwise each
     * replace the other's work with a stale whole-document copy. Writers
     * that replace the document on purpose (restore, import, an accepted
     * suggestion, a style change) state no base and always go through.
     *
     * `keepReplacedAs` is for a writer that replaces a newer version on
     * purpose (the editor's "Keep mine" and "Put it back"). The stored
     * document is first kept as a `named` version with this label, inside
     * the same transaction as the write that replaces it. A save that is
     * refused, or whose content the schema rejects, keeps nothing.
     *
     * @param  array{version?:string,label?:string|null,expectedVersion?:int,keepReplacedAs?:string}  $opts
     *
     * @throws StaleDocumentException
     */
    public function save(Document $doc, array $json, User $actor, array $opts = []): Document
    {
        // normalise() before validate(): style-bearing attrs (align, column
        // count) are clamped on the way in so a bad value never reaches the
        // renderers or the next editor to open the document.
        $json = $this->schema->normalise($this->schema->ensureIds($json));
        $errors = $this->schema->validate($json);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        $doc = DB::transaction(function () use ($doc, $json, $actor, $opts) {
            // The version as the DATABASE has it now, read inside the
            // transaction - never the model's own copy, which was loaded
            // when the request began. SQLite takes the write lock when an
            // IMMEDIATE transaction opens (config/database.php), so this
            // read and the write below cannot interleave with another save;
            // lockForUpdate() gives the same guarantee on PostgreSQL/MySQL.
            $current = (int) DB::table('documents')->where('id', $doc->getKey())->lockForUpdate()->value('version');

            $expected = $opts['expectedVersion'] ?? null;
            if ($expected !== null && $expected !== $current) {
                throw new StaleDocumentException($current);
            }

            // "Keep mine": the caller is replacing a newer version on
            // purpose. Keep what is stored, as a named version, inside the
            // same transaction as the write that replaces it - so it exists
            // only if this save is stored, and holds exactly what it replaced.
            if (isset($opts['keepReplacedAs'])) {
                $this->cutVersion(Document::query()->findOrFail($doc->getKey()), $actor, 'named', $opts['keepReplacedAs']);
            }

            $this->fill($doc, $json);
            $doc->version = $current + 1;
            $doc->save();

            $kind = $opts['version'] ?? 'auto';
            if ($kind !== 'none' && $this->shouldCut($doc, $actor, $kind)) {
                $this->cutVersion($doc, $actor, $kind, $opts['label'] ?? null);
            }

            return $doc;
        });

        // After the commit, not inside it: a webhook is an HTTP call to
        // somebody else's server, and it must neither hold the database's
        // write lock while it waits nor announce a save that then rolls back.
        app(WebhookService::class)->fire($doc, 'on_save');

        return $doc;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/DocumentStoreVersionGuardTest.php tests/Feature/Documents/DocumentStoreTest.php`
Expected: PASS (the existing `DocumentStoreTest` must still pass unchanged at this point).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Documents/StaleDocumentException.php app/Documents/DocumentStore.php tests/Feature/Documents/DocumentStoreVersionGuardTest.php
git commit -m "feat(documents): refuse a save based on an older version

DocumentStore::save() takes an expectedVersion and refuses the write
when the stored document has moved on, instead of letting a stale
whole-document copy replace somebody else's work. The new version is
counted from the database, not the in-memory model, and the on_save
webhook fires after the transaction has committed. A caller that
replaces a newer version on purpose can ask for the replaced version
to be kept as a named version (keepReplacedAs), cut inside the same
transaction so a refused save keeps nothing.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 2: Editor saves state their base version

**Files:**
- Modify: `app/Livewire/Documents/Editor.php:88-127` (`saveContent` and its docblock), and the `dispatch()` line in each of `acceptSuggestion()` and `setStyle()`
- Modify: `app/Http/Controllers/DocumentAutosaveController.php`
- Modify: `tests/Feature/Documents/EditorMountTest.php:153-197`
- Modify: `tests/Feature/Documents/DocumentStoreTest.php:79-92`
- Modify: `tests/Feature/Documents/DocumentAutosaveTest.php`
- Test: `tests/Feature/Documents/EditorSaveBaseVersionTest.php` (create)

**Interfaces:**
- Consumes: `DocumentStore::save(..., ['expectedVersion' => int, 'keepReplacedAs' => string])` and `StaleDocumentException::$currentVersion` from Task 1.
- Produces: `Editor::saveContent(array $content, ?int $baseVersion = null, bool $overwrite = false): array` returning `['ok' => bool, 'conflict' => bool, 'version' => int]`. `conflict` is true only when the save was refused because the document had moved on; `version` is then the document's current version. `$overwrite` is true only for the page's "Keep mine" or "Put it back" choice (Task 8): the save is passed `keepReplacedAs` with the label `Before <name> kept their version`, so `DocumentStore::save()` keeps the STORED document as a named version inside the save's own transaction and the text that is replaced stays in the history. An overwrite that is refused (a stale base, or content the schema rejects) keeps nothing.
- Produces: `POST /documents/{uuid}/autosave` requires `base_version` (integer). A stale one answers HTTP 409 with `{"conflict": true, "version": <current>}`.
- Produces: the Livewire browser events `suggestion-accepted` and `style-changed` each carry a `version` parameter: the document's version after that action. Both actions save the document from inside the open page, so the page has to learn the new version or its own next save would be refused as stale.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Documents/EditorSaveBaseVersionTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Events\DocumentUpdated;
use App\Livewire\Documents\Editor;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The two ways the editor saves - the Livewire action and the unload
 * beacon - both say which version their copy was based on.
 */
class EditorSaveBaseVersionTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    private function doc(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Shared');
    }

    public function test_a_save_based_on_the_current_version_is_accepted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('Typed'), 1)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => 2]);
    }

    public function test_a_save_based_on_an_older_version_is_refused_as_a_conflict(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        // Somebody else saves while this page is open.
        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user);

        $editor->call('saveContent', $this->para('Mine, based on version 1'), 1)
            ->assertReturned(['ok' => false, 'conflict' => true, 'version' => 2]);

        $this->assertSame('Theirs', $doc->fresh()->search_text);
    }

    /** A tab still running the previous JavaScript sends no base version at all. */
    public function test_a_save_with_no_base_version_is_refused_with_a_reload_message(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('From an old tab'))
            ->assertReturned(['ok' => false, 'conflict' => false, 'version' => 1])
            ->assertHasErrors('content')
            ->assertSee('Reload');

        $this->assertSame(1, $doc->fresh()->version);
    }

    /**
     * "Keep mine": the writer saves over a newer version on purpose. The
     * text that is replaced must still be somewhere - the other person's
     * save may have cut no version of its own.
     */
    public function test_an_overwrite_first_keeps_the_replaced_text_in_the_version_history(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $doc = $this->doc($user);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        // Somebody else saves while this page is open, and no version is cut for it.
        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user, ['version' => 'none']);

        // The page has moved its base up to their version and says so.
        $editor->call('saveContent', $this->para('Mine'), 2, true)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => 3]);

        $this->assertSame('Mine', $doc->fresh()->search_text);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->sole();

        $this->assertSame('Before Thandi kept their version', $kept->label);
        $this->assertSame(2, $kept->version_number);
        $this->assertSame('Theirs', $kept->content_json['content'][0]['content'][0]['text']);
    }

    /** An ordinary save cuts no such version, and neither does an overwrite that is itself refused. */
    public function test_only_an_accepted_overwrite_cuts_the_named_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);
        $editor->call('saveContent', $this->para('An ordinary save'), 1);

        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user, ['version' => 'none']);

        // Based on version 2, but the document is at 3: refused, nothing kept.
        $editor->call('saveContent', $this->para('Mine'), 2, true)
            ->assertReturned(['ok' => false, 'conflict' => true, 'version' => 3]);

        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->count());
    }

    /** Content the schema refuses is not stored, so nothing was replaced and nothing is kept. */
    public function test_an_overwrite_the_schema_refuses_keeps_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]], 1, true)
            ->assertReturned(['ok' => false, 'conflict' => false, 'version' => 1]);

        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->count());
    }

    /**
     * The broadcast after a save can fail (no socket server). The save has
     * already been stored, so the writer is told it succeeded - and the
     * failure is reported, not swallowed.
     */
    public function test_a_broadcast_that_fails_after_a_save_is_reported_and_the_save_still_succeeds(): void
    {
        Exceptions::fake();
        Event::listen(DocumentUpdated::class, function (): void {
            throw new RuntimeException('The socket server is down.');
        });
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('Typed'), 1)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => 2]);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The socket server is down.');
    }

    /**
     * Changing the style and accepting a suggestion both save the document
     * from inside the open page. The page must be told the version that
     * produced, or its own next autosave would be refused as stale.
     */
    public function test_a_style_change_tells_the_page_the_new_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('setStyle', 'legal')
            ->assertDispatched('style-changed', fn (string $event, array $params) => ($params['version'] ?? null) === 2);
    }

    public function test_an_accepted_suggestion_tells_the_page_the_new_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $suggestion = AiSuggestion::create([
            'document_id' => $doc->id,
            'user_id' => $user->id,
            'suggestion_text' => '<p>Suggested body</p>',
            'created_at' => now(),
        ]);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('acceptSuggestion', $suggestion->id)
            ->assertDispatched('suggestion-accepted', fn (string $event, array $params) => ($params['version'] ?? null) === 2);
    }

    public function test_the_beacon_is_refused_with_409_when_its_base_version_is_stale(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user);

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->para('Last words'), 'base_version' => 1])
            ->assertStatus(409)
            ->assertExactJson(['conflict' => true, 'version' => 2]);

        $this->assertSame('Theirs', $doc->fresh()->search_text);
    }

    public function test_the_beacon_is_rejected_when_it_states_no_base_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->para('Last words')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_version');

        $this->assertSame(1, $doc->fresh()->version);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Documents/EditorSaveBaseVersionTest.php`
Expected: all 11 FAIL. The seven tests that call `saveContent` (the accepted save, the conflict, the missing base, the three overwrite tests and the failed broadcast) fail on the return value with `Failed asserting that two arrays are equal`: it has no `conflict` key yet, and the conflict and missing-base saves come back `ok: true`. The two event tests find no `version` parameter (`Failed asserting that an event [style-changed] was fired`, and the same for `suggestion-accepted`); the beacon tests get 200 where they expect 409 and 422.

- [ ] **Step 3: Make `saveContent` require and enforce the base version**

In `app/Livewire/Documents/Editor.php`, add the import alongside the other `App\Documents` imports:

```php
use App\Documents\StaleDocumentException;
```

Replace the `saveContent` method and its docblock with:

```php
    /**
     * `$baseVersion` is the document version the browser's copy was based
     * on. A save based on an older version is refused - two people with the
     * document open would otherwise each replace the other's work with a
     * stale whole-document copy - and a save that states no base at all
     * comes from a tab still running JavaScript from before this rule, which
     * must reload rather than write.
     *
     * `$overwrite` is the page's "Keep mine" or "Put it back" choice: the
     * writer knows a newer version exists, has their base on it, and is
     * replacing it on purpose. The person who wrote that version is not
     * asked, and their save may have cut no version of its own (the unload
     * beacon never does; an autosave skips it while the same author's last
     * one is under two minutes old), so the save is told to keep what it
     * replaces as a named version (`keepReplacedAs`). DocumentStore::save()
     * cuts it inside the save's own transaction: a save that is refused as
     * stale, or whose content the schema rejects, keeps nothing.
     *
     * $wire actions resolve with the return value, so the editor bridge
     * reads all three keys: it keeps the offline draft when `ok` is false (a
     * refused save must not quietly lose the writer's work), shows the
     * "changed elsewhere" choice when `conflict` is true, and stamps
     * `version` onto the next draft as its base.
     *
     * @return array{ok:bool,conflict:bool,version:int}
     */
    public function saveContent(array $content, ?int $baseVersion = null, bool $overwrite = false): array
    {
        $this->authorize('update', $this->document);

        $this->resetErrorBag('content');

        if ($baseVersion === null) {
            $this->addError('content', 'This page is out of date. Reload it to keep editing.');
            $this->saved = false;

            return ['ok' => false, 'conflict' => false, 'version' => $this->document->version];
        }

        $opts = ['expectedVersion' => $baseVersion];
        if ($overwrite) {
            // The label column holds 120 characters.
            $opts['keepReplacedAs'] = 'Before '.Str::limit(Auth::user()->name, 80, '').' kept their version';
        }

        try {
            $this->document = app(DocumentStore::class)->save($this->document, $content, Auth::user(), $opts);
        } catch (StaleDocumentException $e) {
            $this->saved = false;

            return ['ok' => false, 'conflict' => true, 'version' => $e->currentVersion];
        } catch (InvalidArgumentException $e) {
            $this->addError('content', $e->getMessage());
            $this->saved = false;

            return ['ok' => false, 'conflict' => false, 'version' => $this->document->version];
        }
        $this->contentJson = $this->document->content_json;
        $this->saved = true;

        try {
            DocumentUpdated::dispatch($this->document, Auth::user(), $this->document->content, $this->document->content_json, $this->document->version);
        } catch (\Throwable $e) {
            // Broadcasting unavailable: the save itself has succeeded, so
            // carry on - but log it. This catch used to be empty, which hid
            // a misconfigured broadcast connection for as long as it lasted.
            report($e);
        }
        app(PresenceService::class)->heartbeat($this->document, Auth::user());

        return ['ok' => true, 'conflict' => false, 'version' => $this->document->version];
    }
```

(The `PresenceService::heartbeat` line stays for now; Task 4 removes it. `Illuminate\Support\Str` is already imported in this file. The two other empty `catch (\Throwable)` blocks in `Editor.php`, in `mount()` and `leaving()`, are left alone here: Task 4 deletes both together with the dispatches they wrap.)

In the same file, make the two actions that save from inside the open page report the version they produced. In `acceptSuggestion()` replace

```php
        $this->dispatch('suggestion-accepted', content: $this->contentJson);
```

with

```php
        // `version` so the page can move its base up: this save came from
        // the page itself, and its next autosave must not be refused as stale.
        $this->dispatch('suggestion-accepted', content: $this->contentJson, version: $this->document->version);
```

and in `setStyle()` replace

```php
        $this->dispatch('style-changed', css: $engine->css($engine->resolve($this->document), 'canvas'));
```

with

```php
        $this->dispatch('style-changed', css: $engine->css($engine->resolve($this->document), 'canvas'), version: $this->document->version);
```

- [ ] **Step 4: Make the beacon require and enforce the base version**

In `app/Http/Controllers/DocumentAutosaveController.php`, add the import:

```php
use App\Documents\StaleDocumentException;
```

Replace the validation rules and the `try` block inside `store()` with:

```php
        $request->validate([
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],
            'content.content' => ['sometimes', 'array'],
            'content.attrs' => ['sometimes', 'array'],
            // The version the page's copy was based on. A beacon that states
            // none comes from a tab running JavaScript from before saves
            // carried one, and is refused: it would overwrite blind.
            'base_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            // The RAW input, never `validated()`. validate() returns only the
            // keys it was given rules for, so saving the validated array threw
            // `content.attrs` (schema/style/vars) away and ensureIds() then
            // re-stamped its defaults on every navigation away — the document's
            // style and variables reset themselves behind the writer's back.
            // Rules here are a shape check; DocumentSchema::validate(), run
            // inside DocumentStore::save(), is what actually vets the content.
            $document = $store->save($document, $request->input('content'), Auth::user(), [
                'version' => 'none',
                'expectedVersion' => $request->integer('base_version'),
            ]);
        } catch (StaleDocumentException $e) {
            // Somebody saved after this page last synced. The page is already
            // gone, so nobody reads this; what matters is that nothing was
            // overwritten. The offline draft in the browser still holds the
            // text, and the editor page offers it back the next time the
            // document is opened there (Put it back).
            return response()->json(['conflict' => true, 'version' => $e->currentVersion], 409);
        } catch (InvalidArgumentException $e) {
            // DocumentSchema::validate() refused it — an unknown node type, or
            // a block with no valid id. Report it as a validation failure so
            // the caller gets a 422 rather than a 500.
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }
```

- [ ] **Step 5: Update the existing tests that call the two save paths**

`tests/Feature/Documents/EditorMountTest.php`: in `test_a_rejected_save_is_reported_to_the_browser_and_shown_on_the_page`, the comment above the call begins `// saveContent() returns ['ok' => bool, 'version' => int] because $wire`. Change that first line to `// saveContent() returns ['ok' => bool, 'conflict' => bool, 'version' => int] because $wire` (the rest of the comment is unchanged). Then pass the base version as the second argument to `saveContent` and expect the new key:

```php
            ->call('saveContent', ['type' => 'doc', 'content' => [
                ['type' => 'mermaidDiagram', 'attrs' => ['id' => BlockId::generate()]],
            ]], $version)
            ->assertReturned(['ok' => false, 'conflict' => false, 'version' => $version])
```

In `test_an_accepted_save_returns_the_new_version_for_the_draft_base`:

```php
            ->call('saveContent', ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => 'Typed']]],
            ]], $doc->version)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => $doc->version + 1]);
```

`tests/Feature/Documents/DocumentStoreTest.php`, `test_editor_accepts_json_and_rejects_invalid`: the first call is against a freshly created document (base `1`); the second follows one accepted save (base `2`). The second MUST state its base: without one the save is refused with the "reload" error, `assertHasErrors('content')` would still pass, and the test would no longer prove that invalid content is rejected.

```php
        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('typed'), 1)
            ->assertSet('saved', true);
        $this->assertSame('typed', $doc->fresh()->search_text);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]], 2)
            ->assertHasErrors('content')
            ->assertReturned(['ok' => false, 'conflict' => false, 'version' => 2]);
```

`tests/Feature/Documents/DocumentAutosaveTest.php`: every `postJson(route('documents.autosave', ...), [...])` whose body has a `content` key gains `'base_version' => $doc->version` (lines 47, 69, 87, 100, 115, 137, 152 and 163). The body-less post at line 120 (`[]`) stays as it is: it asserts a validation failure. Leave the `Request::create(...)` call at lines 176-184 alone: it never reaches the controller, and that test has no `$doc`. Run `grep -n "documents.autosave" tests/Feature/Documents/DocumentAutosaveTest.php` to confirm none was missed (it lists ten lines: the eight above, line 120 and line 177). For the guest and "not mine" cases, where the request is refused before validation, adding the key changes nothing but keeps the bodies uniform.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/EditorSaveBaseVersionTest.php tests/Feature/Documents/EditorMountTest.php tests/Feature/Documents/DocumentStoreTest.php tests/Feature/Documents/DocumentAutosaveTest.php`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Documents/Editor.php app/Http/Controllers/DocumentAutosaveController.php tests/Feature/Documents
git commit -m "feat(editor): saves state the version they were based on

Editor::saveContent() and the unload beacon both carry a base version.
A save based on an older version is refused as a conflict instead of
overwriting; a save that states none (a tab running the previous
JavaScript) is refused with a reload message. A save that says it is
an overwrite keeps the document it replaces as a named version, cut
inside the save's own transaction, so a refused overwrite keeps
nothing. A failed broadcast after a save is now logged instead of
swallowed.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 3: Extract the editor's outline payload into `EditorOutline`

The sync endpoint (Task 5) must return the same outline the Livewire component returns today. Move the body of `Editor::outline()` into a class both can call.

**Files:**
- Create: `app/Documents/Outline/EditorOutline.php`
- Modify: `app/Livewire/Documents/Editor.php` (`outline()` and unused imports)
- Modify: `phpstan-baseline.neon` (regenerated; two `Editor.php` entries shrink)
- Modify: `tests/Feature/Documents/EditorOutlineTest.php` (append one test; the file exists with three tests that must stay)

**Interfaces:**
- Produces: `App\Documents\Outline\EditorOutline::of(Document $document): array` returning exactly the array `Editor::outline()` returns today: keys `numbers`, `toc`, `figures`, `tables`, `pageSetup`, `headerSegments`, `footerSegments`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Documents/EditorOutlineTest.php` already exists with three tests. Do not replace the file and do not touch those tests. Add these two imports, keeping the list in alphabetical order (they go directly after `use App\Documents\DocumentStore;`):

```php
use App\Documents\Outline\EditorOutline;
use App\Documents\Schema\BlockId;
```

Then append this method inside the class, after the last existing test:

```php
    public function test_it_describes_the_stored_document_exactly_as_the_editor_component_does(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $headingId = BlockId::generate();
        $doc = app(DocumentStore::class)->create($user, 'Outlined', ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['id' => $headingId, 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Introduction']]],
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => 'Body']]],
        ]]);

        $outline = app(EditorOutline::class)->of($doc);

        $this->assertSame(
            ['numbers', 'toc', 'figures', 'tables', 'pageSetup', 'headerSegments', 'footerSegments'],
            array_keys($outline),
        );
        $this->assertSame('Introduction', $outline['toc'][0]['text']);
        $this->assertSame($headingId, $outline['toc'][0]['id']);

        $fromComponent = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->instance()->outline();

        $this->assertSame($fromComponent, $outline);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Documents/EditorOutlineTest.php`
Expected: 1 fails, 3 pass. The new test fails with `Target class [App\Documents\Outline\EditorOutline] does not exist.`; the three existing tests pass.

- [ ] **Step 3: Create `EditorOutline`**

Create `app/Documents/Outline/EditorOutline.php`:

```php
<?php

namespace App\Documents\Outline;

use App\Models\Document;
use App\Models\DocumentStyle;
use App\Print\HeaderFooterBands;
use App\Print\PageSetup;
use App\Styles\StyleEngine;

/**
 * Everything the editor needs to know about a stored document that it
 * cannot work out for itself: heading and figure numbers, the table of
 * contents, and the resolved page shape.
 *
 * Numbering and page setup are both authoritative on the server (numbering
 * depends on the style's numbering tokens, see .ai/rules/styles.md; page
 * setup merges the document's own override over its style, see
 * App\Print\PageSetup), so the editor asks for both rather than computing
 * either. Two callers: the Livewire Editor component (at mount and after its
 * own save) and DocumentSyncController (when it hands a newer document to a
 * tab that is behind) - one class so the two can never disagree.
 *
 * `headerSegments`/`footerSegments` are pre-split by HeaderFooterBands, the
 * SAME class PrintRenderer uses for the PDF export, so the live pagination
 * view never re-parses a `{{ }}` template itself.
 */
class EditorOutline
{
    public function __construct(
        private Outline $outline,
        private StyleEngine $styles,
        private HeaderFooterBands $bands,
    ) {}

    /**
     * @return array{
     *     numbers: array<string,string>,
     *     toc: list<array{id:string,level:int,text:string,number:string}>,
     *     figures: list<array{id:string,number:string,text:string}>,
     *     tables: list<array{id:string,number:string,text:string}>,
     *     pageSetup: array{size:string,orientation:string,margins:array{top:string,right:string,bottom:string,left:string},header:string,footer:string},
     *     headerSegments: list<array{type:'text'|'field',value:string}>,
     *     footerSegments: list<array{type:'text'|'field',value:string}>,
     * }
     */
    public function of(Document $document): array
    {
        $style = $document->resolvedStyle() ?? DocumentStyle::resolve('report');
        $result = $this->outline->build($document->content_json ?? [], $style?->tokens['numbering'] ?? []);

        // PageSetup::fromDocument() requires a non-null DocumentStyle;
        // StyleEngine::resolve() is the guaranteed-non-null resolver.
        $setup = PageSetup::fromDocument($document, $this->styles->resolve($document));

        $vars = array_merge($document->variables ?? [], [
            'title' => $document->title,
            'date' => now()->format('Y-m-d'),
            'team' => $document->team->name ?? '',
        ]);

        return [
            'numbers' => $result->numbers,
            'toc' => $result->toc,
            'figures' => $result->figures,
            'tables' => $result->tables,
            'pageSetup' => $setup->toArray(),
            'headerSegments' => $this->bands->segments($setup->header, $vars),
            'footerSegments' => $this->bands->segments($setup->footer, $vars),
        ];
    }
}
```

The `'team'` line is `$document->team->name ?? ''`, not the `$this->document->team?->name ?? ''` that `Editor::outline()` has today. The result is the same when the team is null (the existing deleted-team test covers it), and PHPStan reports the `?->` form as two new errors in this new file.

- [ ] **Step 4: Make `Editor::outline()` delegate**

In `app/Livewire/Documents/Editor.php`, replace the `outline()` method (from its signature line `public function outline(): array` to its closing brace; keep the docblock above it) with:

```php
    public function outline(): array
    {
        // Called straight from JS on every save round trip, so it carries its
        // own authorisation rather than trusting mount()'s.
        $this->authorize('view', $this->document);

        return app(EditorOutline::class)->of($this->document);
    }
```

In the imports, replace the line `use App\Documents\Outline\Outline;` with `use App\Documents\Outline\EditorOutline;`, and delete these two lines, which the file no longer uses: `use App\Print\HeaderFooterBands;` and `use App\Print\PageSetup;`. (Do not leave the `Outline` import for Pint to remove: it does not. `HeaderFooterBands` is still named in the docblock of `outline()`, by its full name, which needs no import. `DocumentStyle` and `StyleEngine` are still used by `setStyle()` and `render()`.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/EditorOutlineTest.php tests/Feature/Documents/EditorMountTest.php`
Expected: PASS, with 4 tests in `EditorOutlineTest` (the three that were there and the new one).

- [ ] **Step 5b: Format, then bring the PHPStan baseline down**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected from PHPStan: 2 errors, both about baseline entries for `app/Livewire/Documents/Editor.php` that no longer match (the `nullsafe.neverNull` entry "was not matched in reported errors"; the `Model::$name` entry "is expected to occur 2 times, but occurred only 1 time"). Both were about the `team?->name` line that has just moved out. No error may name `app/Documents/Outline/EditorOutline.php`; if one does, the `'team'` line in Step 3 was not typed as shown.

Regenerate the baseline and check what changed:

```bash
vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline phpstan-baseline.neon
git diff phpstan-baseline.neon
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: the diff shows exactly two changes, both under `path: app/Livewire/Documents/Editor.php`: the `Access to an undefined property Illuminate\Database\Eloquent\Model::$name` entry goes from `count: 2` to `count: 1`, and the `Using nullsafe property access "?->name"` entry (`identifier: nullsafe.neverNull`) is removed. Nothing is added. The last command prints `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add app/Documents/Outline/EditorOutline.php app/Livewire/Documents/Editor.php tests/Feature/Documents/EditorOutlineTest.php phpstan-baseline.neon
git commit -m "refactor(outline): one class builds the editor's outline payload

The sync endpoint will hand a newer document to a tab that is behind,
together with its numbering and page setup. EditorOutline is what the
Livewire component's outline() did inline, so both callers return the
same thing.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 4: Presence per open tab, stored in a table

Presence today is one cache entry per document holding an array of users, rewritten read-modify-write on a 60-second Livewire heartbeat. Two requests at once lose each other's update, it cannot tell two tabs of one account apart, and members who leave linger for five minutes. The sync poll needs "is anyone else here?" on every call to pick its speed, so presence becomes one small row per open tab.

**Files:**
- Create: `database/migrations/<timestamp>_create_document_presences_table.php`
- Rewrite: `app/Services/PresenceService.php`
- Modify: `app/Livewire/Documents/Editor.php` (mount, `saveContent`, `heartbeat`, `leaving`)
- Delete: `app/Events/UserJoinedDocument.php`, `app/Events/UserLeftDocument.php`
- Test: `tests/Feature/Documents/PresenceServiceTest.php` (create)

**Interfaces:**
- Produces `App\Services\PresenceService`:
  - `public const TTL_SECONDS = 30;`
  - `touch(int $documentId, User $user, string $tabId): void` — records that this tab is here now. Rewrites its row at most once every 5 seconds, and writes it with one `upsert` statement so two requests for the same new tab cannot collide.
  - `leave(int $documentId, string $tabId): void` — removes this tab's row.
  - `members(int $documentId): array` — `list<array{id:int,name:string,avatar:string}>`, one entry per distinct user with a tab seen in the last `TTL_SECONDS`, in order of first arrival.
  - `others(int $documentId, string $tabId): int` — how many OTHER live tabs there are (another tab of the same account counts).
- Produces `Editor::refreshPresence(): void` — re-reads `$activeUsers` for the presence strip. It replaces what `heartbeat()` and `leaving()` did. Those two stay as EMPTY public methods (`heartbeat(): void`, `leaving(): void`): a tab opened before the deploy still calls `heartbeat()` every 60 seconds and `leaving()` on unload, and a missing method would answer each call with an error page.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Documents/PresenceServiceTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who has a document open right now, one row per open tab.
 */
class PresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_touched_tab_makes_its_user_a_member(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');

        $members = $presence->members($doc->id);

        $this->assertCount(1, $members);
        $this->assertSame($user->id, $members[0]['id']);
        $this->assertSame('Thandi', $members[0]['name']);
        $this->assertArrayHasKey('avatar', $members[0]);
    }

    public function test_two_tabs_of_one_account_are_one_member_but_each_sees_the_other(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');
        $presence->touch($doc->id, $user, 'tab-b');

        $this->assertCount(1, $presence->members($doc->id));
        $this->assertSame(1, $presence->others($doc->id, 'tab-a'));
        $this->assertSame(1, $presence->others($doc->id, 'tab-b'));
    }

    public function test_a_tab_alone_has_no_others(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');

        $this->assertSame(0, $presence->others($doc->id, 'tab-a'));
    }

    public function test_members_are_listed_in_order_of_arrival(): void
    {
        $first = User::factory()->withPersonalTeam()->create();
        $second = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($first, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $second, 'tab-b');
        $presence->touch($doc->id, $first, 'tab-a');

        $this->assertSame([$second->id, $first->id], array_column($presence->members($doc->id), 'id'));
    }

    public function test_a_tab_that_leaves_is_gone_at_once(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');
        $presence->leave($doc->id, 'tab-a');

        $this->assertSame([], $presence->members($doc->id));
    }

    public function test_a_tab_not_heard_from_within_the_ttl_is_no_longer_present(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');

        $this->travel(PresenceService::TTL_SECONDS + 1)->seconds();

        $this->assertSame([], $presence->members($doc->id));
        $this->assertSame(0, $presence->others($doc->id, 'tab-z'));

        // The next arrival sweeps the dead row out.
        $presence->touch($doc->id, $other, 'tab-b');

        $this->assertSame(1, DB::table('document_presences')->where('document_id', $doc->id)->count());
    }

    /** The poll runs every 1.5 seconds; it must not write to the database every time. */
    public function test_a_row_is_not_rewritten_more_than_once_every_five_seconds(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');
        $firstSeen = DB::table('document_presences')->value('last_seen_at');

        $this->travel(2)->seconds();
        $presence->touch($doc->id, $user, 'tab-a');
        $this->assertSame($firstSeen, DB::table('document_presences')->value('last_seen_at'));

        $this->travel(4)->seconds();
        $presence->touch($doc->id, $user, 'tab-a');
        $this->assertNotSame($firstSeen, DB::table('document_presences')->value('last_seen_at'));
    }

    public function test_presence_is_per_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $one = app(DocumentStore::class)->create($user, 'One');
        $two = app(DocumentStore::class)->create($user, 'Two');
        $presence = app(PresenceService::class);

        $presence->touch($one->id, $user, 'tab-a');

        $this->assertSame([], $presence->members($two->id));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Documents/PresenceServiceTest.php`
Expected: FAIL (`touch` does not exist with this signature; there is no `document_presences` table).

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_document_presences_table --no-interaction`

Replace the generated file's contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per open editor tab per document. Short-lived by design: a row
     * that has not been refreshed for PresenceService::TTL_SECONDS no longer
     * counts, and the next arrival on that document deletes it.
     */
    public function up(): void
    {
        Schema::create('document_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tab_id', 64);
            $table->timestamp('last_seen_at')->index();
            $table->unique(['document_id', 'tab_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_presences');
    }
};
```

- [ ] **Step 4: Rewrite `PresenceService`**

Replace the whole of `app/Services/PresenceService.php` with:

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who has a document open right now.
 *
 * One row per open editor TAB in `document_presences`, refreshed by the
 * editor's sync poll (App\Http\Controllers\DocumentSyncController). It used
 * to be one cache entry per document holding an array of users, rewritten
 * read-modify-write: two requests arriving together lost each other's
 * update, two tabs of one account were indistinguishable, and somebody who
 * left lingered for five minutes. A row per tab has none of those problems,
 * and "is anyone ELSE here?" - which decides how fast a tab polls - is one
 * indexed count.
 *
 * Query builder, not a model: these rows are bookkeeping with a lifetime of
 * seconds, and nothing else in the app has a reason to load one.
 */
class PresenceService
{
    /**
     * A tab not heard from for this long is gone. Comfortably longer than
     * the slowest poll (10 seconds, when a tab is alone in the document).
     */
    public const TTL_SECONDS = 30;

    /**
     * The poll runs as often as every 1.5 seconds. Refreshing the row on
     * every one would be a database write per poll per tab for no benefit:
     * a row younger than this is left alone.
     */
    private const TOUCH_EVERY_SECONDS = 5;

    private const TABLE = 'document_presences';

    /** Record that this tab has the document open now. */
    public function touch(int $documentId, User $user, string $tabId): void
    {
        $now = now();

        $row = DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('tab_id', $tabId)
            ->first(['user_id', 'last_seen_at']);

        $fresh = $row !== null
            && (int) $row->user_id === $user->id
            && Carbon::parse($row->last_seen_at)->gt($now->copy()->subSeconds(self::TOUCH_EVERY_SECONDS));

        if ($fresh) {
            return;
        }

        // ONE statement. updateOrInsert() is two (does a row exist? then
        // insert), so two requests for the same new tab arriving together
        // would both see no row, and the second insert would hit the unique
        // index on (document_id, tab_id) and answer 500.
        DB::table(self::TABLE)->upsert(
            [['document_id' => $documentId, 'tab_id' => $tabId, 'user_id' => $user->id, 'last_seen_at' => $now]],
            ['document_id', 'tab_id'],
            ['user_id', 'last_seen_at'],
        );

        // Sweep this document's dead rows while we are writing anyway, so
        // the table never needs a scheduled prune to stay small.
        DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('last_seen_at', '<', $now->copy()->subSeconds(self::TTL_SECONDS))
            ->delete();
    }

    /** The tab is closing. */
    public function leave(int $documentId, string $tabId): void
    {
        DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('tab_id', $tabId)
            ->delete();
    }

    /**
     * The people here now, one entry each however many tabs they have open,
     * in order of arrival.
     *
     * @return list<array{id:int,name:string,avatar:string}>
     */
    public function members(int $documentId): array
    {
        $userIds = $this->live($documentId)->orderBy('id')->pluck('user_id')->unique()->values();

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        return $userIds
            ->map(fn ($id) => $users->get($id))
            ->filter()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->profile_photo_url,
            ])
            ->values()
            ->all();
    }

    /**
     * How many OTHER live tabs have this document open. Another tab of the
     * same account counts: it is somebody to stay in step with.
     */
    public function others(int $documentId, string $tabId): int
    {
        return $this->live($documentId)->where('tab_id', '!=', $tabId)->count();
    }

    private function live(int $documentId): Builder
    {
        return DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('last_seen_at', '>=', now()->subSeconds(self::TTL_SECONDS));
    }
}
```

- [ ] **Step 5: Run the presence tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/PresenceServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Move the Editor component onto the new presence**

In `app/Livewire/Documents/Editor.php`:

1. In `mount()`, replace these lines

```php
        $presence = app(PresenceService::class);
        $presence->join($this->document, Auth::user());
        $this->activeUsers = $presence->getMemberList($this->document->id);

        try {
            UserJoinedDocument::dispatch($this->document, Auth::user());
        } catch (\Throwable) {
            // Broadcasting unavailable — continue without real-time presence
        }
```

with

```php
        // The browser registers this tab's presence with its first sync poll
        // (it owns the tab id). Until then, show whoever is already here plus
        // the person opening the page.
        $this->activeUsers = $this->presentMembers();
```

2. In `saveContent()`, delete the line `app(PresenceService::class)->heartbeat($this->document, Auth::user());`.

3. Replace the `heartbeat()` and `leaving()` methods (both whole methods, including the `try`/`catch` inside `leaving()`) with the four methods below. `heartbeat()` and `leaving()` are KEPT, with empty bodies:

```php
    /**
     * Called by tabs opened before the sync poll shipped: their JavaScript
     * still calls this every 60 seconds. It does nothing now - presence is
     * recorded by the sync poll - but it must exist, or each of those calls
     * would be answered with an error page. Remove one release later.
     */
    public function heartbeat(): void {}

    /**
     * Called by tabs opened before the sync poll shipped, when they close.
     * It does nothing now, for the same reason as heartbeat(). Remove one
     * release later.
     */
    public function leaving(): void {}

    /**
     * Re-read who is here for the presence strip. The editor's sync poll
     * calls this when the set of people it is told about changes - presence
     * itself is recorded by that poll (DocumentSyncController), not here.
     */
    public function refreshPresence(): void
    {
        $this->authorize('view', $this->document);

        $this->activeUsers = $this->presentMembers();
    }

    /**
     * @return list<array{id:int,name:string,avatar:string}>
     */
    private function presentMembers(): array
    {
        $members = app(PresenceService::class)->members($this->document->id);
        $me = Auth::user();

        if (! collect($members)->contains('id', $me->id)) {
            $members[] = ['id' => $me->id, 'name' => $me->name, 'avatar' => $me->profile_photo_url];
        }

        return $members;
    }
```

4. Delete the imports `use App\Events\UserJoinedDocument;` and `use App\Events\UserLeftDocument;`.

- [ ] **Step 7: Delete the two events nothing dispatches any more**

Confirm nothing else uses them: `grep -rn "UserJoinedDocument\|UserLeftDocument" app tests routes resources` must list only the two class files themselves. Then:

```bash
git rm app/Events/UserJoinedDocument.php app/Events/UserLeftDocument.php
```

(The Blade file still calls `@this.heartbeat()` and `@this.leaving()` and listens for `.user.joined` / `.user.left`; Task 8 rewrites that part. Until then those calls reach the two empty methods and do nothing, so between this task and Task 8 the page records no presence at all.)

- [ ] **Step 8: Add tests for the component's presence methods**

Append both methods to `tests/Feature/Documents/PresenceServiceTest.php`, inside the class (add `use App\Livewire\Documents\Editor;` and `use Livewire\Livewire;` to the imports):

```php
    public function test_the_editor_lists_the_person_opening_it_and_whoever_is_already_there(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner']);
        $doc = app(DocumentStore::class)->create($owner, 'Open', null, ['is_public' => true]);
        $visitor = User::factory()->withPersonalTeam()->create(['name' => 'Visitor']);
        app(PresenceService::class)->touch($doc->id, $visitor, 'tab-v');

        $editor = Livewire::actingAs($owner)->test(Editor::class, ['uuid' => $doc->uuid]);

        $this->assertSame(['Visitor', 'Owner'], array_column($editor->get('activeUsers'), 'name'));

        app(PresenceService::class)->leave($doc->id, 'tab-v');
        $editor->call('refreshPresence');

        $this->assertSame(['Owner'], array_column($editor->get('activeUsers'), 'name'));
    }

    /**
     * A tab opened before this shipped still calls heartbeat() every 60
     * seconds and leaving() when it closes. Both must answer without an
     * error, and neither records presence any more.
     */
    public function test_the_old_presence_calls_from_a_tab_opened_before_the_deploy_do_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('heartbeat')
            ->call('leaving')
            ->assertOk();

        $this->assertSame(0, DB::table('document_presences')->count());
    }
```

- [ ] **Step 9: Run the tests**

Run: `php artisan test --compact tests/Feature/Documents/PresenceServiceTest.php tests/Feature/Documents/EditorMountTest.php tests/Feature/Files/SharedTreeMigrateTest.php`
Expected: PASS, with 10 tests in `PresenceServiceTest`. (`SharedTreeMigrateTest` runs the real migrations; `.ai/rules/migrations.md` requires it to stay green when a migration is added.)

- [ ] **Step 10: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
git add -A app/Services/PresenceService.php app/Livewire/Documents/Editor.php app/Events database/migrations tests/Feature/Documents/PresenceServiceTest.php
git commit -m "feat(presence): one row per open tab instead of a shared cache entry

Presence was a single cache entry per document, rewritten
read-modify-write on a 60 second heartbeat: concurrent requests lost
each other's update, two tabs of one account were indistinguishable,
and people who left lingered for five minutes. It is now a small table
with one row per tab, refreshed by the sync poll. UserJoinedDocument
and UserLeftDocument are deleted: nothing dispatches them now.
Editor::heartbeat() and leaving() stay as empty methods so a tab
opened before the deploy gets no error from its old timer.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

Expected from PHPStan: `[OK] No errors` (Task 3 already brought the baseline level). If it reports a stale baseline entry for `PresenceService.php` or `Editor.php` instead ("was not matched in reported errors"), regenerate the baseline with `vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline phpstan-baseline.neon`, confirm `git diff phpstan-baseline.neon` adds no entry and raises no count (a lowered count shows as one removed and one added line), and add `phpstan-baseline.neon` to the commit.

---

## Task 5: The sync endpoint

**Files:**
- Create: `app/Http/Controllers/DocumentSyncController.php`
- Modify: `routes/web.php` (next to the `documents.autosave` route)
- Test: `tests/Feature/Documents/DocumentSyncTest.php` (create)

**Interfaces:**
- Consumes: `PresenceService::touch/leave/members/others` (Task 4), `EditorOutline::of()` (Task 3), `DocumentStore::json()`, `App\Styles\StyleEngine::resolve()` and `::css()`.
- Produces: `POST /documents/{uuid}/sync`, route name `documents.sync`, inside the existing authenticated group.
  - Request JSON: `{"version": <int the caller has>, "tab": "<tab id, [A-Za-z0-9-]{1,64}>", "leaving": <optional bool>, "protocol": <optional int>}`. `protocol` is the version of this request format the caller speaks (the engine in Task 7 sends `1`). It is validated and otherwise unused in this phase: it is there so a later phase can tell a tab running old JavaScript to reload.
  - Response 200 when the caller is up to date: `{"version": int, "changed": false, "members": [...], "others": int}`.
  - Response 200 when the caller is behind: the same, with `"changed": true`, `"json": <document JSON as the editor opens it>`, `"outline": <EditorOutline payload>`, `"css": <string: the document style's canvas stylesheet, StyleEngine::css($style, 'canvas')>`. The stylesheet travels with the document because somebody else may have changed the style: without it the follower gets the new numbering through the outline but keeps the old fonts and colours.
  - Response 200 to `leaving: true`: `{"left": true}`.
  - 403 when the caller may not view the document, 404 when it does not exist, 422 on a malformed body.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Documents/DocumentSyncTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The endpoint an open editor polls: what version is the document at, who
 * else is here, and - only when the caller is behind - the document itself.
 */
class DocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    private function doc(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Synced');
    }

    public function test_a_caller_that_is_up_to_date_gets_the_version_and_who_is_here_but_no_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $doc = $this->doc($user);

        // `protocol` is what the engine sends with every poll; it is accepted.
        $response = $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a', 'protocol' => 1])
            ->assertOk()
            ->assertJson(['version' => 1, 'changed' => false, 'others' => 0]);

        $this->assertSame(['Thandi'], array_column($response->json('members'), 'name'));
        $this->assertArrayNotHasKey('json', $response->json());
        $this->assertArrayNotHasKey('outline', $response->json());
        $this->assertArrayNotHasKey('css', $response->json());
    }

    public function test_a_caller_that_is_behind_gets_the_document_its_outline_and_its_stylesheet(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        app(DocumentStore::class)->save($doc, $this->para('Newer text'), $user);

        $response = $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])
            ->assertOk()
            ->assertJson(['version' => 2, 'changed' => true]);

        $this->assertSame('doc', $response->json('json.type'));
        $this->assertSame('Newer text', $response->json('json.content.0.content.0.text'));
        $this->assertSame(
            ['numbers', 'toc', 'figures', 'tables', 'pageSetup', 'headerSegments', 'footerSegments'],
            array_keys($response->json('outline')),
        );

        // The canvas stylesheet of the document's style, exactly as the
        // editor page prints it: somebody else may have changed the style.
        $styles = app(StyleEngine::class);
        $this->assertNotSame('', $response->json('css'));
        $this->assertSame($styles->css($styles->resolve($doc->fresh()), 'canvas'), $response->json('css'));
    }

    /**
     * This runs every 1.5 seconds per open tab. When nothing has changed it
     * must never read the document's content out of the database.
     */
    public function test_a_quiet_poll_never_loads_the_document_content(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])
            ->assertOk();

        $documentQueries = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, '"documents"')));

        $this->assertNotEmpty($documentQueries);
        foreach ($documentQueries as $sql) {
            $this->assertStringNotContainsString('*', $sql, "A quiet poll selected every column: {$sql}");
            $this->assertStringNotContainsString('content', $sql, "A quiet poll read document content: {$sql}");
        }
    }

    public function test_each_poll_records_the_tab_and_reports_the_others(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $guest = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $guest->id, 'role' => 'editor']);

        $this->actingAs($owner)->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-owner'])
            ->assertJson(['others' => 0]);

        $response = $this->actingAs($guest)->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-guest'])
            ->assertJson(['others' => 1]);

        $this->assertCount(2, $response->json('members'));
    }

    public function test_a_leaving_tab_is_removed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a'])->assertOk();
        $this->assertSame(1, DB::table('document_presences')->count());

        $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-a', 'leaving' => true])
            ->assertOk()
            ->assertExactJson(['left' => true]);

        $this->assertSame(0, DB::table('document_presences')->count());
    }

    /** Access is checked on every poll, so removing someone cuts off an open page. */
    public function test_someone_who_may_not_view_the_document_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);

        $this->actingAs($outsider)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-x'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('document_presences')->count());
    }

    public function test_a_guest_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($owner);

        $this->postJson(route('documents.sync', $doc->uuid), ['version' => 1, 'tab' => 'tab-x'])
            ->assertUnauthorized();
    }

    public function test_an_unknown_document_is_not_found(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->postJson(route('documents.sync', 'no-such-uuid'), ['version' => 1, 'tab' => 'tab-a'])
            ->assertNotFound();
    }

    public function test_a_malformed_body_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => 'latest', 'tab' => 'has spaces and <tags>', 'protocol' => 'one'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['version', 'tab', 'protocol']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Documents/DocumentSyncTest.php`
Expected: FAIL with `Route [documents.sync] not defined`.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/DocumentSyncController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\Outline\EditorOutline;
use App\Models\Document;
use App\Services\PresenceService;
use App\Styles\StyleEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What an open editor polls to stay in step with everybody else.
 *
 * There is no websocket to push a change to a browser - production is shared
 * hosting that cannot run one - so each open editor asks, every second or
 * two while somebody else is present: what version is the document at, who
 * is here, and (only when my copy is behind) what does it say now.
 *
 * A plain controller rather than a Livewire action for the same reason as
 * DocumentAutosaveController: a Livewire request drags the component's whole
 * snapshot - which includes the document - up and down on every call, and
 * the unload path cannot use Livewire at all.
 *
 * It is called constantly, so the quiet case has to stay cheap: it selects
 * six small columns and never the content.
 */
class DocumentSyncController extends Controller
{
    public function store(
        Request $request,
        string $uuid,
        DocumentStore $store,
        PresenceService $presence,
        EditorOutline $outline,
        StyleEngine $styles,
    ): JsonResponse {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'tab' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'leaving' => ['sometimes', 'boolean'],
            // Which version of this request format the caller speaks. Not
            // acted on yet: it is accepted now so that a later phase can
            // tell a tab still running old JavaScript to reload.
            'protocol' => ['sometimes', 'integer'],
        ]);

        $document = Document::query()
            ->select(['id', 'uuid', 'owner_id', 'team_id', 'is_public', 'version'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        // On every poll, not once at page load: somebody removed from the
        // document stops receiving it on their very next request.
        Gate::authorize('view', $document);

        $user = $request->user();

        if ($request->boolean('leaving')) {
            $presence->leave($document->id, $data['tab']);

            return response()->json(['left' => true]);
        }

        $presence->touch($document->id, $user, $data['tab']);

        $payload = [
            'version' => (int) $document->version,
            'changed' => false,
            'members' => $presence->members($document->id),
            'others' => $presence->others($document->id, $data['tab']),
        ];

        if ($payload['version'] > (int) $data['version']) {
            // Only now is the whole row worth reading.
            $full = Document::query()->whereKey($document->id)->firstOrFail();

            $payload['version'] = (int) $full->version;
            $payload['changed'] = true;
            $payload['json'] = $store->json($full);
            $payload['outline'] = $outline->of($full);
            // The style may be what changed. The outline carries its
            // numbering and page setup; this carries its fonts and colours.
            $payload['css'] = $styles->css($styles->resolve($full), 'canvas');
        }

        return response()->json($payload);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add the import with the other controllers, in alphabetical order (directly after `use App\Http\Controllers\DocumentImportController;`):

```php
use App\Http\Controllers\DocumentSyncController;
```

and, directly after the `documents.autosave` route:

```php
    // What an open editor polls to stay in step: the current version, who
    // is here, and the document itself when the caller's copy is behind.
    // No throttle middleware: it is called every second or two by design.
    // See App\Http\Controllers\DocumentSyncController.
    Route::post('/documents/{uuid}/sync', [DocumentSyncController::class, 'store'])
        ->name('documents.sync');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/DocumentSyncTest.php`
Expected: PASS.

If `test_a_quiet_poll_never_loads_the_document_content` fails because the VIEW POLICY loads something with `*` from `"documents"`, read the reported SQL: the policy must only touch `teams`, `team_user` and `document_collaborators`. A `select *` from `documents` means some relation or accessor on the partial model re-fetched it; fix the cause, do not loosen the assertion.

- [ ] **Step 6: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
git add app/Http/Controllers/DocumentSyncController.php routes/web.php tests/Feature/Documents/DocumentSyncTest.php
git commit -m "feat(sync): an endpoint an open editor polls to stay in step

POST /documents/{uuid}/sync reports the document's version and who is
here, records the calling tab's presence, and hands over the document,
its outline and its stylesheet only when the caller's copy is behind.
Access is checked on every call. A quiet poll selects six small columns
and never the content.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 6: Apply a newer document as a minimal change

Today `applyRemote()` replaces the whole document: the caret jumps, undo stops working, every page-break decoration is dropped, and any unsaved local typing is destroyed. It becomes a single replacement of only the range that differs, and it refuses to run over unsaved typing. The caret, the undo history and the page-break decorations then survive for everything outside that one range. It is ONE range: when a single apply carries two separate changes, the text between them is replaced too, a caret there moves to the end of the range, and local edits there can no longer be undone.

The work is split in two so that the part that can go wrong without anybody noticing is tested first. WHAT changes is decided by `remoteTransaction()` in a new module with no imports from the editor bundle, which `node --test` runs branch by branch (Steps 5 to 8). `applyRemote()` in the editor bundle keeps only WHETHER it may run and what happens around the dispatch (Step 9). That half is NOT covered by `npm test`: no file under `tests/js` can load the editor bundle (it needs TipTap and a DOM). It is checked in the browser in Task 8 Step 12, and nowhere else.

**Files:**
- Create: `resources/js/editor/sync/narrow.js`
- Create: `resources/js/editor/sync/apply.js`
- Modify: `resources/js/editor/index.js` (imports, `beaconSave`, `applyRemote`)
- Test: `tests/js/sync.narrow.test.js` (create)
- Test: `tests/js/sync.apply.test.js` (create)

**Interfaces:**
- Produces: `diffRange(current, next)` from `resources/js/editor/sync/narrow.js`. Both arguments are ProseMirror `Node`s (documents). Returns `null` when their content is identical, otherwise `{ from: number, toA: number, toB: number }`: replace `current` between `from` and `toA` with `next.slice(from, toB)`, applied as a `ReplaceStep` (never `tr.replace()`).
- Produces: `remoteTransaction(state, json, ReplaceStep)` from `resources/js/editor/sync/apply.js`. `state` is the editor's ProseMirror `EditorState`, `json` a Dot.Doc document as the server sent it, `ReplaceStep` the class from `prosemirror-transform` (the editor bundle passes the one from `@tiptap/pm/transform`). Returns `null` when the document cannot be parsed by the editor's schema or cannot be applied at all; otherwise a `Transaction` that has NO steps when the editor already shows the document. The transaction carries no metas and is not dispatched.
- Produces: `handle.applyRemote(json, { force = false } = {})` on the editor handle. Returns `true` when the document was applied (including when the editor already showed it), `false` when nothing was changed (invalid content, or unsaved local typing and `force` is false). `true` means "applied", not "identical": a local repair plugin may still have adjusted what arrived, and a document that does not end in a paragraph has the editor's own empty trailing paragraph after it.
- Produces: mount option `getBaseVersion: () => number`. The unload beacon sends it as `base_version`.

- [ ] **Step 1: Write the failing tests**

Create `tests/js/sync.narrow.test.js`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema } from 'prosemirror-model';
import { EditorState, TextSelection } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { diffRange } from '../../resources/js/editor/sync/narrow.js';

// A stand-in for the editor's schema: blocks with ids, like every Dot.Doc
// block. diffRange() only uses Fragment.findDiffStart/findDiffEnd, so the
// real schema is not needed to test it.
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'text*', attrs: { id: { default: null }, align: { default: null } } },
        // Like the editor's table cells, figures and columns: `isolating`.
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        text: {},
    },
});

const para = (id, text, attrs = {}) => ({
    type: 'paragraph',
    attrs: { id, ...attrs },
    ...(text ? { content: [{ type: 'text', text }] } : {}),
});
const doc = (...content) => schema.nodeFromJSON({ type: 'doc', content });

/** Apply the range diffRange() reports, the way remoteTransaction() does (sync/apply.js). */
function follow(state, next) {
    const range = diffRange(state.doc, next);
    if (range === null) {
        return state;
    }

    return state.apply(state.tr.step(new ReplaceStep(range.from, range.toA, next.slice(range.from, range.toB))));
}

test('identical documents need no change at all', () => {
    const a = doc(para('p1', 'One'), para('p2', 'Two'));
    const b = doc(para('p1', 'One'), para('p2', 'Two'));

    assert.equal(diffRange(a, b), null);
});

test('a document the server stored without align equals the editor copy that has align null', () => {
    // DocumentSchema::normalise() drops `align` when it is null; the editor
    // emits it on every paragraph. As ProseMirror nodes they are the same.
    const fromEditor = doc(para('p1', 'One', { align: null }));
    const fromServer = schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph', attrs: { id: 'p1' }, content: [{ type: 'text', text: 'One' }] }] });

    assert.equal(diffRange(fromEditor, fromServer), null);
});

test('a change inside one paragraph touches only that paragraph', () => {
    const before = doc(para('p1', 'First paragraph'), para('p2', 'Second paragraph'), para('p3', 'Third paragraph'));
    const after = doc(para('p1', 'First paragraph'), para('p2', 'Second, edited paragraph'), para('p3', 'Third paragraph'));

    const range = diffRange(before, after);
    const secondStart = before.child(0).nodeSize;
    const secondEnd = secondStart + before.child(1).nodeSize;

    assert.ok(range.from > secondStart && range.toA < secondEnd, 'the changed range stays inside the second paragraph');

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});

test('a caret in an untouched paragraph stays exactly where it was', () => {
    const before = doc(para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third paragraph'));
    const after = doc(para('p1', 'First, with more words added by somebody else'), para('p2', 'Second'), para('p3', 'Third paragraph'));

    // Caret after "Third" in the last paragraph.
    const thirdStart = before.child(0).nodeSize + before.child(1).nodeSize + 1;
    const state = EditorState.create({ doc: before, selection: TextSelection.create(before, thirdStart + 5) });

    const result = follow(state, after);

    assert.ok(result.doc.eq(after));
    assert.equal(result.doc.textBetween(result.selection.from - 5, result.selection.from), 'Third');
});

test('an inserted paragraph is added without touching its neighbours', () => {
    const before = doc(para('p1', 'One'), para('p3', 'Three'));
    const after = doc(para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three'));

    const result = follow(EditorState.create({ doc: before }), after);

    assert.ok(result.doc.eq(after));
});

test('a removed paragraph is removed', () => {
    const before = doc(para('p1', 'One'), para('p2', 'Two'), para('p3', 'Three'));
    const after = doc(para('p1', 'One'), para('p3', 'Three'));

    const result = follow(EditorState.create({ doc: before }), after);

    assert.ok(result.doc.eq(after));
});

test('repeated characters do not make the start and end of the range cross', () => {
    // "aa" -> "aaa": the first difference is at the end and so is the last
    // one. Without the overlap correction the range comes out inverted.
    const before = doc(para('p1', 'aa'));
    const after = doc(para('p1', 'aaa'));

    const range = diffRange(before, after);
    assert.ok(range.toA >= range.from && range.toB >= range.from);

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});

test('a shrinking run of repeated characters is handled too', () => {
    const before = doc(para('p1', 'aaaa'));
    const after = doc(para('p1', 'aa'));

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});

test('a change inside an isolating node plus a change after it is reproduced exactly', () => {
    // tr.replace() "fits" this slice by nesting a second copy of the box
    // inside the first, without throwing. A ReplaceStep applies it as is.
    const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
    const before = doc(box('b1', para('p1', 'In the box')), para('p2', 'Two'), para('p3', 'Three'));
    const after = doc(box('b1', para('p1', 'In the box, edited')), para('p2', 'Two'));

    const result = follow(EditorState.create({ doc: before }), after);
    assert.ok(result.doc.eq(after));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/sync.narrow.test.js`
Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `resources/js/editor/sync/narrow.js`.

- [ ] **Step 3: Create `narrow.js`**

Create `resources/js/editor/sync/narrow.js`:

```js
/**
 * The smallest range that has to change to turn one document into another.
 *
 * A document that arrives from the server used to be applied as one
 * whole-document replacement. That moved the caret to wherever its old
 * numeric offset happened to land, left the undo history pointing at
 * positions that no longer existed, and dropped every page-break decoration
 * (they are anchored to positions inside the replaced range). Replacing only
 * what differs keeps all three for everything OUTSIDE the range: positions
 * before and after it map straight through.
 *
 * It is ONE range, from the first difference to the last. When the two
 * documents differ in two separate places, everything between those places
 * is inside the range and is replaced too: a caret there moves to the end
 * of the range, and local edits there can no longer be undone.
 *
 * Both arguments are ProseMirror nodes, compared as nodes - NOT as JSON. The
 * server strips `align` when it is null and the editor emits `align: null`
 * on every paragraph, so the two JSON forms of the same document never
 * match, while the parsed nodes do. Two things still differ as nodes, and
 * remoteTransaction() in ./apply.js levels both before it calls this: the
 * `toc.entries` and `crossRef.label` the server stamps into the stored
 * document, and an empty `sectionBreak.setup`, which PHP sends as `[]`
 * where the editor holds `{}`.
 *
 * Apply the result as a ReplaceStep, never with tr.replace(): tr.replace()
 * runs ProseMirror's fitter, which re-shapes an open slice around isolating
 * nodes (table cells, figures, columns) and returns a DIFFERENT document
 * without throwing.
 *
 * Dependency-free: it only calls methods on the nodes it is given, so
 * `tests/js` can run it under `node --test`.
 *
 * @param {import('prosemirror-model').Node} current the document the editor holds
 * @param {import('prosemirror-model').Node} next    the document it should hold
 * @returns {{from: number, toA: number, toB: number}|null} null when the
 *          content is already identical; otherwise replace `current` between
 *          `from` and `toA` with `next.slice(from, toB)`
 */
export function diffRange(current, next) {
    const from = current.content.findDiffStart(next.content);

    if (from === null) {
        return null;
    }

    let { a: toA, b: toB } = current.content.findDiffEnd(next.content);

    // With repeated content ("aa" -> "aaa") the scan from the end runs past
    // the scan from the start. Push both ends forward by the overlap so the
    // range is never inverted.
    const overlap = from - Math.min(toA, toB);

    if (overlap > 0) {
        toA += overlap;
        toB += overlap;
    }

    return { from, toA, toB };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/sync.narrow.test.js`
Expected: PASS (9 tests).

- [ ] **Step 5: Write the failing tests for the transaction builder**

Create `tests/js/sync.apply.test.js`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { Schema, Slice } from 'prosemirror-model';
import { EditorState } from 'prosemirror-state';
import { ReplaceStep } from 'prosemirror-transform';

import { remoteTransaction } from '../../resources/js/editor/sync/apply.js';

// A stand-in for the editor's schema, with the node types that
// remoteTransaction() treats specially declared the way the editor declares
// them: a contents list and a cross-reference the server stamps, a section
// break whose `setup` PHP may send as `[]`, an `isolating` container (like
// table cells, figures and columns), and the document-level attrs.
const schema = new Schema({
    nodes: {
        doc: { content: 'block+', attrs: { schema: { default: 1 }, style: { default: 'report' }, vars: { default: {} } } },
        paragraph: { group: 'block', content: 'inline*', attrs: { id: { default: null }, align: { default: null } } },
        toc: { group: 'block', atom: true, attrs: { id: { default: null }, entries: { default: [] } } },
        sectionBreak: { group: 'block', atom: true, attrs: { id: { default: null }, setup: { default: {} } } },
        box: { group: 'block', content: 'block+', isolating: true, attrs: { id: { default: null } } },
        crossRef: {
            group: 'inline',
            inline: true,
            atom: true,
            attrs: { kind: { default: 'heading' }, targetId: { default: null }, label: { default: null } },
        },
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
const toc = (id, entries) => ({ type: 'toc', attrs: { id, entries } });
const ref = (targetId, label) => ({ type: 'crossRef', attrs: { kind: 'heading', targetId, label } });
const sectionBreak = (id, setup) => ({ type: 'sectionBreak', attrs: { id, setup } });
const box = (id, ...content) => ({ type: 'box', attrs: { id }, content });
const doc = (content, attrs) => ({ type: 'doc', ...(attrs ? { attrs } : {}), content });

const stateOf = (json) => EditorState.create({ doc: schema.nodeFromJSON(json) });
const stepTypes = (tr) => tr.steps.map((step) => step.toJSON().stepType);
/** Where the top-level block with this id starts. */
const startOf = (node, id) => {
    let found = null;
    node.forEach((child, offset) => {
        if (child.attrs.id === id) {
            found = offset;
        }
    });

    return found;
};

test('a document the editor already shows needs no step', () => {
    const json = doc([para('p1', 'One'), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(json), json, ReplaceStep);

    assert.equal(tr.docChanged, false);
});

test('a change in one paragraph is one replace step inside that paragraph', () => {
    const held = doc([para('p1', 'First'), para('p2', 'Second'), para('p3', 'Third')]);
    const arriving = doc([para('p1', 'First'), para('p2', 'Second, edited'), para('p3', 'Third')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.steps[0].from > startOf(state.doc, 'p2'));
    assert.ok(tr.steps[0].to < startOf(state.doc, 'p3'));
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a re-stamped contents list and cross-reference are levelled with attribute steps, not replaced', () => {
    // The server stamped the contents list and the reference label, and
    // somebody edited the LAST paragraph. Without the levelling the replaced
    // range would start at the contents list, at the top of the document.
    const held = doc([
        toc('t1', []),
        para('p1', 'See ', ref('h1', null), ' for more.'),
        para('p2', 'Second'),
        para('p3', 'Third'),
    ]);
    const arriving = doc([
        toc('t1', [{ id: 'h1', level: 1, text: 'Introduction', number: '1' }]),
        para('p1', 'See ', ref('h1', '1 Introduction'), ' for more.'),
        para('p2', 'Second'),
        para('p3', 'Third, edited'),
    ]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['attr', 'attr', 'replace']);
    assert.ok(tr.steps[2].from > startOf(state.doc, 'p3'), 'the replaced range starts inside the last paragraph');
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('when only the stamped attributes differ there is no replace step at all', () => {
    const held = doc([toc('t1', []), para('p1', 'See ', ref('h1', null))]);
    const arriving = doc([toc('t1', [{ id: 'h1', text: 'Introduction' }]), para('p1', 'See ', ref('h1', '1 Introduction'))]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['attr', 'attr']);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('an empty section-break setup the server sends as [] is not a change', () => {
    // The editor holds {} for a section break it created; PHP sends [].
    const held = doc([para('p1', 'One'), sectionBreak('s1', {}), para('p2', 'Two')]);
    const arriving = doc([para('p1', 'One'), sectionBreak('s1', []), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.equal(tr.docChanged, false);
});

test('a tab that loaded the [] form keeps it, and a section break it has never seen gets {}', () => {
    const held = doc([para('p1', 'One'), sectionBreak('s1', []), para('p2', 'Two')]);
    const arriving = doc([para('p1', 'One'), sectionBreak('s1', []), para('p2', 'Two'), sectionBreak('s2', []), para('p3', 'Three')]);
    const state = stateOf(held);

    assert.equal(remoteTransaction(state, held, ReplaceStep).docChanged, false);

    const tr = remoteTransaction(state, arriving, ReplaceStep);
    const setups = [];
    tr.doc.descendants((node) => {
        if (node.type.name === 'sectionBreak') {
            setups.push(node.attrs.setup);
        }
    });

    assert.deepEqual(setups, [[], {}]);
});

test('a setup that really changed is replaced', () => {
    const held = doc([para('p1', 'One'), sectionBreak('s1', {}), para('p2', 'Two')]);
    const arriving = doc([para('p1', 'One'), sectionBreak('s1', { orientation: 'landscape' }), para('p2', 'Two')]);

    const tr = remoteTransaction(stateOf(held), arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('empty document variables the server sends as [] are not a change', () => {
    const content = [para('p1', 'One')];

    // The editor's own empty value is {}.
    const own = remoteTransaction(stateOf(doc(content, { vars: {} })), doc(content, { vars: [] }), ReplaceStep);
    assert.equal(own.docChanged, false);

    // A tab that loaded the server's [] holds [].
    const loaded = remoteTransaction(stateOf(doc(content, { vars: [] })), doc(content, { vars: [] }), ReplaceStep);
    assert.equal(loaded.docChanged, false);
});

test('document-level attrs that changed are set with their own steps', () => {
    const content = [para('p1', 'One')];
    const state = stateOf(doc(content, { style: 'report', vars: {} }));

    const tr = remoteTransaction(state, doc(content, { style: 'legal', vars: { client: 'Acme' } }), ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['docAttr', 'docAttr']);
    assert.equal(tr.doc.attrs.style, 'legal');
    assert.deepEqual(tr.doc.attrs.vars, { client: 'Acme' });

    // Emptied again by somebody else: [] arrives, {} is what the editor holds.
    const emptied = remoteTransaction(state.apply(tr), doc(content, { style: 'legal', vars: [] }), ReplaceStep);
    assert.deepEqual(stepTypes(emptied), ['docAttr']);
    assert.deepEqual(emptied.doc.attrs.vars, {});
});

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

test('a step that throws falls back to replacing the whole document', () => {
    class Throws {
        constructor() {
            throw new RangeError('this slice does not fit');
        }
    }
    const held = doc([para('p1', 'First'), para('p2', 'Second')]);
    const arriving = doc([para('p1', 'First, edited'), para('p2', 'Second')]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, Throws);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.equal(tr.steps[0].from, 0);
    assert.equal(tr.steps[0].to, state.doc.content.size);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a step that applies but does not reproduce the target falls back too', () => {
    // Stands in for ProseMirror re-shaping a slice: it applies without
    // throwing and leaves a different document behind (here: the range is
    // deleted and nothing is put in its place).
    class Wrong extends ReplaceStep {
        constructor(from, to) {
            super(from, to, Slice.empty);
        }
    }
    const held = doc([toc('t1', []), para('p1', 'First one'), para('p2', 'Second')]);
    const arriving = doc([toc('t1', [{ id: 'h1' }]), para('p1', 'First two!'), para('p2', 'Second')]);

    const tr = remoteTransaction(stateOf(held), arriving, Wrong);

    // The attribute step, the step that went wrong, then everything.
    assert.deepEqual(stepTypes(tr), ['attr', 'replace', 'replace']);
    assert.ok(tr.doc.eq(schema.nodeFromJSON(arriving)));
});

test('a document this schema cannot parse gives null', () => {
    const state = stateOf(doc([para('p1', 'One')]));

    assert.equal(remoteTransaction(state, doc([{ type: 'marquee' }]), ReplaceStep), null);
    // Parses, but breaks the schema: a document needs at least one block.
    assert.equal(remoteTransaction(state, doc([]), ReplaceStep), null);
});

test('the editor own empty trailing paragraph is carried over when the document does not end in a paragraph', () => {
    // TipTap's TrailingNode added `trail` after the box. The server's
    // document does not have it, and must not take it away.
    const held = doc([para('p1', 'One'), box('b1', para('p2', 'In the box')), para('trail')]);
    const arriving = doc([para('p1', 'One, edited'), box('b1', para('p2', 'In the box'))]);
    const state = stateOf(held);

    const tr = remoteTransaction(state, arriving, ReplaceStep);

    assert.deepEqual(stepTypes(tr), ['replace']);
    assert.ok(tr.steps[0].to < startOf(state.doc, 'b1'), 'only the first paragraph is touched');
    assert.equal(tr.doc.lastChild.attrs.id, 'trail');
    assert.equal(tr.doc.childCount, 3);

    // The same document again changes nothing.
    assert.equal(remoteTransaction(state.apply(tr), arriving, ReplaceStep).docChanged, false);
});

test('a trailing paragraph is not carried over when the document ends in a paragraph or already has it', () => {
    // The server's document ends in a paragraph: it is the whole truth.
    const endsInParagraph = remoteTransaction(
        stateOf(doc([para('p1', 'One'), para('trail')])),
        doc([para('p1', 'One')]),
        ReplaceStep
    );
    assert.equal(endsInParagraph.doc.childCount, 1);

    // The held last paragraph is in the server's document, further up:
    // somebody added a box after it.
    const moved = doc([para('p1', 'One'), para('trail'), box('b1', para('p2', 'In the box'))]);
    const known = remoteTransaction(stateOf(doc([para('p1', 'One'), para('trail')])), moved, ReplaceStep);
    assert.ok(known.doc.eq(schema.nodeFromJSON(moved)));

    // The held last paragraph has text in it: it is content, not padding.
    const withText = doc([para('p1', 'One'), box('b1', para('p2', 'In the box'))]);
    const typed = remoteTransaction(
        stateOf(doc([para('p1', 'One'), box('b1', para('p2', 'In the box')), para('p9', 'Typed')])),
        withText,
        ReplaceStep
    );
    assert.ok(typed.doc.eq(schema.nodeFromJSON(withText)));
});
```

- [ ] **Step 6: Run the tests to verify they fail**

Run: `node --test tests/js/sync.apply.test.js`
Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `resources/js/editor/sync/apply.js`.

- [ ] **Step 7: Create `apply.js`**

Create `resources/js/editor/sync/apply.js`. The import of `./narrow.js` is written WITH its extension: `node --test` does not resolve an extensionless import, and Vite accepts either form.

```js
import { diffRange } from './narrow.js';

/**
 * The transaction that makes an editor show a document the server sent.
 *
 * This is the half of applyRemote() (../index.js) that decides WHAT changes.
 * applyRemote() keeps the half that decides WHETHER it may (the fail-closed
 * and unsaved-typing guards) and what happens around it (the transaction's
 * metas, the dispatch, the autosave bookkeeping). It lives here, apart from
 * the editor bundle, so `tests/js/sync.apply.test.js` can run every branch
 * of it under `node --test`.
 *
 * In order:
 *
 *   1. PHP cannot tell an empty object from an empty list, so the server
 *      sends `[]` where the editor's own value is `{}`: a section break's
 *      `setup` and the document's `vars`. ProseMirror compares the two as
 *      different, so a section break would look changed on every apply. They
 *      are levelled on a COPY before parsing: an empty `setup` takes
 *      whichever empty form this editor already holds for that section break
 *      (`{}` for one it has never seen), and an empty `vars` becomes `{}`.
 *   2. TipTap's TrailingNode plugin keeps an empty paragraph after a document
 *      that does not end in one. That paragraph is this editor's own: it is
 *      not in the server's document. It is carried over, so it is not
 *      removed and re-added (with a new id) on every apply.
 *   3. The server stamps `toc.entries` and `crossRef.label` on every save, so
 *      this editor's copies go stale as soon as a heading moves. They are
 *      brought level FIRST, as attribute steps (which shift no positions):
 *      otherwise a re-stamped contents list at the top stretches the replaced
 *      range from there to the real change, and the caret and undo history
 *      inside that stretch are lost.
 *   4. The one range that still differs (see ./narrow.js) is replaced with a
 *      ReplaceStep, NOT tr.replace(): tr.replace() runs ProseMirror's fitter,
 *      which re-shapes an open slice around isolating nodes (table cells,
 *      figures, columns) and hands back a DIFFERENT document without
 *      throwing. The result is checked against the target; if the step
 *      throws or the result differs, everything is replaced instead:
 *      correct, just not gentle.
 *   5. Document-level attrs (schema, style, vars) are not content, so the
 *      range never carries them. They are set with their own steps.
 *
 * Dependency-free: `ReplaceStep` is handed in (the editor bundle passes the
 * one from `@tiptap/pm/transform`, the tests the one from
 * `prosemirror-transform`), and everything else is a method on the state it
 * is given.
 *
 * @param {import('prosemirror-state').EditorState} state the editor's state
 * @param {object} json a Dot.Doc document, as the server sent it
 * @param {typeof import('prosemirror-transform').ReplaceStep} ReplaceStep
 * @returns {import('prosemirror-state').Transaction|null} null when the
 *          document cannot be parsed by this schema or cannot be applied at
 *          all; otherwise a transaction, which has NO steps when the editor
 *          already shows the document. It carries no metas and has not been
 *          dispatched.
 */
export function remoteTransaction(state, json, ReplaceStep) {
    const isEmptyValue = (value) => value !== null && typeof value === 'object' && Object.keys(value).length === 0;

    // 1. Level `[]` against `{}`.
    const heldSetups = new Map();
    state.doc.descendants((node) => {
        if (node.type.name === 'sectionBreak') {
            heldSetups.set(node.attrs.id, node.attrs.setup);
        }
    });
    const incoming = JSON.parse(JSON.stringify(json));
    const levelEmptySetups = (node) => {
        if (node.type === 'sectionBreak' && node.attrs && isEmptyValue(node.attrs.setup)) {
            node.attrs.setup = Array.isArray(heldSetups.get(node.attrs.id)) ? [] : {};
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(levelEmptySetups);
        }
    };
    levelEmptySetups(incoming);
    if (incoming.attrs && isEmptyValue(incoming.attrs.vars)) {
        incoming.attrs.vars = {};
    }

    let next = null;
    try {
        next = state.schema.nodeFromJSON(incoming);
        next.check();
    } catch (_) {
        // A document this editor cannot parse: the caller leaves what is on
        // screen alone rather than blanking it.
        return null;
    }

    // 2. Carry the editor's own trailing paragraph over.
    const heldLast = state.doc.lastChild;
    if (
        next.lastChild &&
        next.lastChild.type.name !== 'paragraph' &&
        heldLast &&
        heldLast.type.name === 'paragraph' &&
        heldLast.content.size === 0
    ) {
        let known = false;
        next.descendants((node) => {
            known = known || node.attrs.id === heldLast.attrs.id;

            return !known;
        });
        if (!known) {
            next = next.copy(next.content.addToEnd(heldLast));
        }
    }

    const tr = state.tr;

    // 3. Level the server-stamped attributes, as attribute steps.
    const stamped = { toc: new Map(), crossRef: new Map() };
    next.descendants((node) => {
        if (node.type.name === 'toc') {
            stamped.toc.set(node.attrs.id, node.attrs.entries);
        }
        if (node.type.name === 'crossRef') {
            stamped.crossRef.set(`${node.attrs.kind}|${node.attrs.targetId}`, node.attrs.label);
        }
    });
    state.doc.descendants((node, pos) => {
        if (node.type.name === 'toc' && stamped.toc.has(node.attrs.id)) {
            const entries = stamped.toc.get(node.attrs.id);
            if (JSON.stringify(entries) !== JSON.stringify(node.attrs.entries)) {
                tr.setNodeAttribute(pos, 'entries', entries);
            }
        }
        if (node.type.name === 'crossRef') {
            const key = `${node.attrs.kind}|${node.attrs.targetId}`;
            if (stamped.crossRef.has(key) && stamped.crossRef.get(key) !== node.attrs.label) {
                tr.setNodeAttribute(pos, 'label', stamped.crossRef.get(key));
            }
        }
    });

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

    // 5. Document-level attrs. `{}` and `[]` are the same empty value here
    // (see 1), and are not a difference.
    Object.keys(next.attrs || {}).forEach((key) => {
        const held = state.doc.attrs[key];
        const arriving = next.attrs[key];
        if (isEmptyValue(held) && isEmptyValue(arriving)) {
            return;
        }
        if (JSON.stringify(held) !== JSON.stringify(arriving)) {
            tr.setDocAttribute(key, arriving);
        }
    });

    return tr;
}
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `node --test tests/js/sync.apply.test.js`
Expected: PASS (15 tests).

- [ ] **Step 9: Rewrite `applyRemote()` and send the base version with the beacon**

In `resources/js/editor/index.js`:

1. Add two imports. Directly after the first line of the file, `import { Editor, generateJSON } from '@tiptap/core';`, add:

```js
import { ReplaceStep } from '@tiptap/pm/transform';
```

and directly after the `./validation` import add:

```js
import { remoteTransaction } from './sync/apply';
```

(`index.js` does not import `./sync/narrow` itself: only `apply.js` calls `diffRange()`.)

2. In `beaconSave(json)`, replace the `Blob` construction with one that carries the base version:

```js
            const body = new Blob(
                [
                    JSON.stringify({
                        _token: opts.csrfToken || '',
                        content: json,
                        // The version this page's copy was based on. The
                        // server refuses the save if somebody has saved
                        // since, rather than overwrite them blind.
                        base_version: typeof opts.getBaseVersion === 'function' ? opts.getBaseVersion() : null,
                    }),
                ],
                { type: 'application/json' }
            );
```

and add `getBaseVersion?: () => number` to the `@param` type in `mount`'s docblock.

3. Replace the whole `applyRemote` property (its docblock and function) with:

```js
        /**
         * Make the editor show a document that somebody else saved.
         *
         * Applied as ONE replacement of only the range that differs, in a
         * transaction built by remoteTransaction() (sync/apply.js, which
         * also explains what is levelled first and why). The transaction is
         * kept out of the undo history (a collaborator's paragraph is not
         * something YOU can undo) and tagged `preventUpdate` so TipTap does
         * not emit `update` for it - otherwise the autosave debounce would
         * arm and send the document straight back.
         *
         * The caret, the undo history and the page-break decorations survive
         * for everything OUTSIDE that one range. When a single apply carries
         * two separate changes, the text between them is replaced too: a
         * caret there moves to the end of the range, and local edits there
         * can no longer be undone.
         *
         * It never runs over unsaved local typing unless `force` is given:
         * while the debounce is armed or a save is owed, what the editor
         * holds exists nowhere else. The caller decides what happens to that
         * text first (the conflict notice in the Blade bridge).
         *
         * @param {object} json a Dot.Doc document
         * @param {{force?: boolean}} options
         * @returns {boolean} true when the document was APPLIED. That is not
         *          a promise that the editor is now identical to it: a local
         *          repair plugin (a ragged table padded, a figure with no
         *          image removed) may have adjusted what arrived, and a
         *          document that does not end in a paragraph has this
         *          editor's own empty trailing paragraph after it. False
         *          when nothing was changed.
         */
        applyRemote: (json, { force = false } = {}) => {
            if (!json || editor.isDestroyed || !autosave) {
                return false;
            }

            if (!force && (dirty || saveTimer !== null)) {
                return false;
            }

            if (!isContentValid(json, schemaNames())) {
                return false;
            }

            const tr = remoteTransaction(editor.state, json, ReplaceStep);

            if (tr === null) {
                // A document this editor cannot parse or cannot apply: leave
                // what is on screen alone rather than blanking it.
                return false;
            }

            if (tr.docChanged) {
                tr.setMeta('addToHistory', false);
                // `preventUpdate` also covers what plugins append inside
                // this dispatch. TipTap's TrailingNode appends an empty
                // paragraph there when the document does not end in one;
                // it must NOT be told to skip it (`skipTrailingNode`), or it
                // appends the paragraph on the next transaction of any kind
                // instead, with `update` fired and the autosave armed.
                tr.setMeta('preventUpdate', true);
                editor.view.dispatch(tr);
            }

            // Whatever was pending is superseded (only reachable with
            // `force`). The editor now holds the applied document. That is
            // normally exactly the stored one; if a repair plugin adjusted
            // it or a trailing paragraph was added (see @returns), that
            // difference is deliberately NOT saved from here - every reading
            // tab would save its own and conflict with the others. It goes
            // with the writer's next edit.
            clearTimeout(saveTimer);
            saveTimer = null;
            dirty = false;
            lastSaved = JSON.stringify(editor.getJSON());

            return true;
        },
```

Do NOT add `tr.setMeta('skipTrailingNode', true)` here. That meta only stops TipTap's TrailingNode plugin inside the same dispatch; the plugin still owes the paragraph and appends it on the next transaction of any kind (the heading-number plugin dispatches one straight after every apply), with a fresh id, `update` fired and the autosave armed. Every following tab would then save its own trailing paragraph about a second after following. Left alone, TrailingNode appends the paragraph inside the apply's own dispatch, where `preventUpdate` keeps it out of the autosave, and `remoteTransaction()` carries that paragraph over on every later apply.

- [ ] **Step 10: Run the JavaScript tests and build**

Run: `npm test`
Expected: PASS (all existing tests plus the 24 new ones: 9 in `sync.narrow.test.js` and 15 in `sync.apply.test.js`).

Run: `npm run build`
Expected: the build succeeds.

- [ ] **Step 11: Commit**

```bash
git add resources/js/editor/sync/narrow.js resources/js/editor/sync/apply.js resources/js/editor/index.js tests/js/sync.narrow.test.js tests/js/sync.apply.test.js
git commit -m "feat(editor): apply a newer document as a minimal change

applyRemote() replaced the whole document: the caret jumped, undo
stopped working, page-break decorations were dropped and unsaved
typing was destroyed. It now replaces only the one range that differs
(as a ReplaceStep, checked against the target), keeps the change out
of undo and out of autosave, and refuses to run over unsaved typing
unless forced. Everything outside that range keeps its caret, undo
history and page breaks. The transaction is built in sync/apply.js,
apart from the editor bundle, so every branch of it runs under
node --test. The unload beacon states the base version.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 7: The sync engine

A state machine with no DOM, no `fetch` and no timers of its own - all three are injected - so every rule in it can be tested under `node --test`.

**Files:**
- Create: `resources/js/editor/sync/engine.js`
- Create: `resources/js/editor/sync/request.js`
- Test: `tests/js/sync.engine.test.js` (create)

**Interfaces:**
- Produces `createSyncEngine(options)`:
  - `options.request(payload) => Promise<{status: number, body: object|null}>`; `payload` is `{version, tab, protocol}`, where `protocol` is the constant `PROTOCOL` (`1`).
  - `options.host`:
    - `state() => 'clean' | 'busy' | 'dirty' | 'closed'` — `clean`: nothing unsaved; `busy`: a save is in flight; `dirty`: unsaved typing or an unresolved conflict; `closed`: the editor is read-only (fail-closed) and must not be written to.
    - `applyRemote(remote) => boolean` — `remote` is `{version, json, outline, css}` (`outline` and `css` are `null` when the response carried none).
    - `onConflict(remote) => void` — a newer document exists and the tab is dirty.
    - `onRefused(remote) => void` — `applyRemote(remote)` returned false or threw: the editor could not open that version. Called once per version; the engine keeps polling.
    - `onMembers(members, others) => void`.
    - `onStopped(reason) => void` — `reason` is `'signed-out' | 'forbidden' | 'gone'`. The engine stops only when the response has a JSON body, that is, when the application itself answered 401, 419, 403 or 404. The same status with no JSON body (a firewall or rate limiter in front of the application) is a failure to retry with back-off.
    - A host callback that throws never ends the polling. A throw from `applyRemote` counts as a refusal: the version is remembered, `onRefused` is called, and it is not offered again until `refetch()`. A throw from any callback during a poll (`onMembers`, `onConflict`, `onRefused`) is swallowed and the next poll is armed as usual.
  - `options.version` (number), `options.tab` (string).
  - Optional `options.visible() => boolean`, `options.setTimer(fn, ms)`, `options.clearTimer(id)`.
  - Returns `{ start(), poke(), stop(), saved(version), refetch(version), retry(), takeRemote(), visibilityChanged(), pending }`. `refetch(version)` sets the version the engine asks from back to `version` and polls at once, so the server sends the current document again even though the engine had already seen it; a version the editor refused earlier is offered to it again.
- Produces constants `FAST_MS = 1500`, `SLOW_MS = 10000`, `MAX_BACKOFF_MS = 30000`, `PROTOCOL = 1`.
- Produces `createSyncRequest(url, csrfToken, fetchImpl?) => (payload) => Promise<{status, body}>`.

- [ ] **Step 1: Write the failing tests**

Create `tests/js/sync.engine.test.js`:

```js
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/sync.engine.test.js`
Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `resources/js/editor/sync/engine.js`.

- [ ] **Step 3: Create the engine**

Create `resources/js/editor/sync/engine.js`:

```js
/**
 * Keeps an open editor in step with what other people save.
 *
 * There is no websocket in production (shared hosting cannot run one), so
 * the editor asks. This is the loop that asks, and decides what to do with
 * the answer:
 *
 *   - It polls `POST /documents/{uuid}/sync` with the newest version it has
 *     SEEN. Quickly while somebody else has the document open, slowly when
 *     alone, not at all while the tab is hidden.
 *   - When the answer carries a newer document it hands it to the host -
 *     but only if the host is `clean`. A tab with unsaved typing is told
 *     there is a conflict instead; a tab with a save in flight is left
 *     alone until that save settles; a read-only tab is never written to.
 *   - It stops for good when the APPLICATION says the session has ended,
 *     access was removed or the document is gone. The application answers
 *     those with a JSON body; the same status with no JSON body came from
 *     something in front of it (a firewall, a rate limiter) and is only a
 *     failure to retry.
 *
 * `seen` is not the version the editor's content is BASED on (the host owns
 * that, and sends it with every save). It is only "the newest version this
 * tab has already downloaded", so an unresolved conflict does not make it
 * fetch the same document again on every poll.
 *
 * No DOM, no fetch and no timers of its own: the request function, the
 * timer and the visibility check are all injected, which is what lets
 * `tests/js/sync.engine.test.js` drive every case by hand.
 */

/** While somebody else has the document open. */
export const FAST_MS = 1500;

/** While this tab is alone in the document. */
export const SLOW_MS = 10000;

/** The slowest it will ever retry after failures. */
export const MAX_BACKOFF_MS = 30000;

/**
 * The version of the sync request format this code speaks, sent with every
 * poll. The server does not act on it yet; it is there so a later phase can
 * tell a tab still running this JavaScript to reload.
 */
export const PROTOCOL = 1;

const STOP_REASONS = { 401: 'signed-out', 419: 'signed-out', 403: 'forbidden', 404: 'gone' };

/**
 * @param {{
 *   request: (payload: {version: number, tab: string, protocol: number}) => Promise<{status: number, body: object|null}>,
 *   host: {
 *     state: () => 'clean'|'busy'|'dirty'|'closed',
 *     applyRemote: (remote: {version: number, json: object, outline: object|null, css: string|null}) => boolean,
 *     onConflict: (remote: {version: number, json: object, outline: object|null, css: string|null}) => void,
 *     onRefused: (remote: {version: number, json: object, outline: object|null, css: string|null}) => void,
 *     onMembers: (members: object[], others: number) => void,
 *     onStopped: (reason: 'signed-out'|'forbidden'|'gone') => void,
 *   },
 *   version: number,
 *   tab: string,
 *   visible?: () => boolean,
 *   setTimer?: (fn: () => void, ms: number) => unknown,
 *   clearTimer?: (id: unknown) => void,
 * }} options
 */
export function createSyncEngine(options) {
    const { request, host, tab } = options;
    const visible = options.visible ?? (() => true);
    const setTimer = options.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
    const clearTimer = options.clearTimer ?? ((id) => clearTimeout(id));

    let seen = options.version;
    /** The newest server document not yet in the editor. */
    let remote = null;
    /** A version the editor could not open, so it is not offered again. */
    let refused = null;
    let others = 0;
    let failures = 0;
    let timer = null;
    let inFlight = false;
    let again = false;
    let stopped = false;

    function nextDelay() {
        if (failures > 0) {
            return Math.min(MAX_BACKOFF_MS, FAST_MS * 2 ** failures);
        }

        return others > 0 ? FAST_MS : SLOW_MS;
    }

    function disarm() {
        if (timer !== null) {
            clearTimer(timer);
            timer = null;
        }
    }

    function arm() {
        disarm();
        if (!stopped && visible()) {
            timer = setTimer(poll, nextDelay());
        }
    }

    /** Give the waiting document to the host, if the host can take it. */
    function deliver() {
        if (remote === null) {
            return;
        }

        const state = host.state();

        if (state === 'busy' || state === 'closed') {
            return;
        }

        if (state === 'dirty') {
            host.onConflict(remote);

            return;
        }

        if (remote.version === refused) {
            return;
        }

        let applied = false;
        try {
            applied = host.applyRemote(remote) === true;
        } catch (_) {
            // The host threw while applying (a plugin or a node view failed
            // inside the dispatch). Same as a refusal: the editor does not
            // show this version.
            applied = false;
        }

        if (applied) {
            remote = null;
        } else {
            // The editor could not open this version. Remember it, so it is
            // not offered again on every poll, and tell the host once so the
            // page can say so. Polling goes on: a later version may open.
            refused = remote.version;
            host.onRefused(remote);
        }
    }

    async function poll() {
        if (stopped) {
            return;
        }

        if (inFlight) {
            again = true;

            return;
        }

        inFlight = true;
        disarm();

        let response = null;
        try {
            response = await request({ version: seen, tab, protocol: PROTOCOL });
        } catch (_) {
            response = null;
        }

        inFlight = false;

        if (stopped) {
            return;
        }

        // Only when the response has a JSON body: that is the application
        // answering. A firewall or rate limiter in front of it answers 403
        // with an HTML page, which must not be read as "access removed".
        const reason = response && response.body ? STOP_REASONS[response.status] : undefined;
        if (reason) {
            stopped = true;
            again = false;
            host.onStopped(reason);

            return;
        }

        if (!response || response.status !== 200 || !response.body) {
            failures += 1;
        } else {
            failures = 0;

            const body = response.body;
            others = Number(body.others) || 0;
            // A host callback that throws must not end the loop: the next
            // poll would never be armed, and the tab would stop following
            // and drop out of presence with nothing on screen to say so.
            try {
                host.onMembers(Array.isArray(body.members) ? body.members : [], others);
            } catch (_) {
                // Keep polling.
            }

            const incoming = Number(body.version);
            // Compared with `seen` as it is NOW: this tab's own save may have
            // landed while the request was in the air.
            if (body.changed === true && incoming > seen) {
                seen = incoming;
                remote = { version: incoming, json: body.json, outline: body.outline ?? null, css: body.css ?? null };
            }

            try {
                deliver();
            } catch (_) {
                // Keep polling.
            }
        }

        if (again) {
            again = false;
            poll();

            return;
        }

        arm();
    }

    return {
        /** Begin polling. The first poll also registers this tab's presence. */
        start: poll,

        /** Poll now instead of waiting for the timer. */
        poke: poll,

        stop() {
            stopped = true;
            again = false;
            disarm();
        },

        /** This tab's own save landed at `version`. */
        saved(version) {
            if (version > seen) {
                seen = version;
            }

            if (remote !== null && remote.version <= version) {
                remote = null;
            }
        },

        /**
         * Download the current document again, asking from `version`. For
         * when the host needs a document the engine has already seen and no
         * longer holds - an ordinary poll would be told "nothing changed".
         */
        refetch(version) {
            seen = version;
            refused = null;
            poll();
        },

        /** The host's state changed (a save settled): try to deliver again. */
        retry: deliver,

        /** Hand over the waiting document, for the "Load theirs" choice. */
        takeRemote() {
            const waiting = remote;
            remote = null;

            return waiting;
        },

        /** The newest server document not yet in the editor, or null. */
        get pending() {
            return remote;
        },

        visibilityChanged() {
            if (visible()) {
                poll();
            } else {
                disarm();
            }
        },
    };
}
```

- [ ] **Step 4: Create the request function**

Create `resources/js/editor/sync/request.js`:

```js
/**
 * The one HTTP call the sync engine makes.
 *
 * Kept apart from the engine so the engine stays free of `fetch` and can be
 * tested with hand-answered requests. A plain POST, not a Livewire action:
 * a Livewire request carries the component's whole snapshot - which
 * includes the document - up and down on every call.
 *
 * @param {string} url       POST /documents/{uuid}/sync
 * @param {string} csrfToken the page's CSRF token
 * @param {(url: string, init: object) => Promise<{status: number, json: () => Promise<object>}>} [fetchImpl]
 * @returns {(payload: object) => Promise<{status: number, body: object|null}>}
 */
export function createSyncRequest(url, csrfToken, fetchImpl = (...args) => fetch(...args)) {
    return async (payload) => {
        const response = await fetchImpl(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        let body = null;
        try {
            body = await response.json();
        } catch (_) {
            // Not JSON. The application always answers this request with
            // JSON (it is sent with Accept: application/json), an expired
            // session included - so this came from something in front of
            // the application, such as a firewall's or a rate limiter's
            // page. The engine treats a response with no body as a failure
            // to retry, never as a reason to stop.
            body = null;
        }

        return { status: response.status, body };
    };
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `node --test tests/js/sync.engine.test.js`
Expected: PASS (29 tests).

- [ ] **Step 6: Expose both on `window.DotDoc`, then run everything**

In `resources/js/editor/index.js`, add directly after the `./sync/apply` import:

```js
import { createSyncEngine } from './sync/engine';
import { createSyncRequest } from './sync/request';
```

and in the `DotDoc` object, after `documentsDiffer,`:

```js
    // The follow-other-people's-saves loop. The Blade bridge builds one per
    // editor page; see sync/engine.js.
    sync: { createSyncEngine, createSyncRequest },
```

Then:

Run: `npm test && npm run build`
Expected: all tests pass and the build succeeds.

- [ ] **Step 7: Commit**

```bash
git add resources/js/editor/sync/engine.js resources/js/editor/sync/request.js resources/js/editor/index.js tests/js/sync.engine.test.js
git commit -m "feat(editor): a polling engine that follows other people's saves

A state machine with no DOM, fetch or timers of its own. It polls the
sync endpoint quickly while others are present, slowly when alone and
not at all while hidden; applies a newer document only to a clean tab;
reports a conflict to a tab with unsaved typing; and stops when the
application says the session ended or access was removed. A firewall's
403 with no JSON body only backs it off. A host callback that throws
does not end the loop. Every poll states the protocol version it
speaks.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 8: Wire the engine into the editor page

**Files:**
- Modify: `resources/views/livewire/documents/editor.blade.php` (the `x-data` component at the top, two event attributes on the root element, and the `.doc-status` strip)
- Modify: `resources/js/editor/pagination/index.js` (comment only)
- Test: `tests/Feature/Documents/EditorSyncWiringTest.php` (create)

**Interfaces:**
- Consumes: `Editor::saveContent($content, $baseVersion, $overwrite)` → `{ok, conflict, version}` (Task 2); the `version` parameter on the `suggestion-accepted` and `style-changed` browser events (Task 2); `Editor::refreshPresence()` (Task 4); route `documents.sync` and its `css` response key (Task 5); `handle.applyRemote(json, {force})` and mount option `getBaseVersion` (Task 6); `window.DotDoc.sync.createSyncEngine` / `createSyncRequest`, the host callbacks `state`, `applyRemote`, `onConflict`, `onRefused`, `onMembers`, `onStopped`, and the engine's `start`, `poke`, `stop`, `saved`, `refetch`, `retry`, `takeRemote`, `pending`, `visibilityChanged` (Task 7).
- Produces: nothing other tasks use.

Read `.ai/rules/livewire.md`, `.ai/rules/views.md` and `.ai/rules/editor.md` in full before editing this file.

Two rules hold for every step of this task:

- **No double-quote character anywhere inside `x-data`.** The whole Alpine component is the value of ONE double-quoted HTML attribute (`x-data="{ ... }"`). A double quote inside it, in code or in a comment, ends the attribute at that point: the component is cut off mid-line, the rest of it becomes stray attributes and text, `x-init` is lost and the editor never mounts. Write strings with single quotes, and in comments write the words without quotation marks. Every `js` code block in Steps 3 to 8b goes inside that attribute and contains no double quote; do not add one while typing them in. `tests/Feature/Documents/ContextualToolbarTest.php` guards this, and so does the test in Step 1.
- **Do not remove `x-init="init()"` from the root element.** Alpine already calls a component's `init()` by itself, so with `x-init` it runs twice on first load. The second call is what leaves `owns` false on the data object, and that is what stops `destroy()` tearing the editor down every time Livewire re-renders this element. Do not move it either: it is the attribute directly after `x-data`, and the test in Step 1 finds the end of `x-data` by it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Documents/EditorSyncWiringTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The editor page is where the sync engine is started. None of that script
 * runs under PHPUnit; what can be guarded here is that the page carries the
 * pieces, that they sit inside an Alpine component the browser can still
 * read, and that the page no longer carries what they replaced.
 */
class EditorSyncWiringTest extends TestCase
{
    use RefreshDatabase;

    private function editorHtml(): string
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Wired');

        return $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();
    }

    public function test_the_editor_page_starts_the_sync_engine_against_its_own_document(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Wired');

        $html = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();

        $this->assertStringContainsString(route('documents.sync', $doc->uuid), $html);

        // The whole Alpine component is ONE double-quoted HTML attribute.
        // `[^"]*` reads it exactly as a browser does, so a double quote
        // anywhere inside the component - a comment is enough - ends the
        // attribute there. The match is anchored to what must FOLLOW the
        // attribute in the template, x-init, so an attribute that is cut
        // short does not match at all - even when the cut happens to fall
        // directly after a closing brace.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"\s+x-init="init\(\)"/s', $html, $alpine));
        $this->assertStringContainsString('createSyncEngine', $alpine[1]);
        $this->assertStringContainsString('getBaseVersion', $alpine[1]);
        $this->assertStringContainsString('refreshPresence', $alpine[1]);
        $this->assertStringEndsWith('}', trim($alpine[1]));
    }

    /**
     * Asserted on the buttons' markup, not on their words: the words also
     * appear in the component's own comments, which would pass this before
     * any button existed.
     */
    public function test_the_page_offers_both_ways_out_of_a_conflict_and_a_way_back(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('@click="keepMine()"', $html);
        $this->assertStringContainsString('@click="loadTheirs()"', $html);
        $this->assertStringContainsString('@click="putBack()"', $html);
    }

    /**
     * heartbeat() and leaving() are empty methods kept for tabs opened
     * before this shipped. The page itself must not call them any more.
     */
    public function test_the_page_no_longer_calls_the_old_presence_methods(): void
    {
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('.heartbeat()', $html);
        $this->assertStringNotContainsString('.leaving()', $html);
        $this->assertStringNotContainsString('.user.joined', $html);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php`
Expected: FAIL on all three.

- [ ] **Step 3: Replace the component's state fields**

In `resources/views/livewire/documents/editor.blade.php`, in the `x-data` object, replace

```js
        echo: null,
        heartbeatTimer: null,
```

with

```js
        echo: null,
        // This tab's identity for presence and the sync poll. Per page load,
        // not per browser: two tabs of one account are two tabs.
        tabId: (window.crypto && window.crypto.randomUUID)
            ? window.crypto.randomUUID()
            : 'tab-' + Math.random().toString(36).slice(2) + Date.now().toString(36),
        // How many of this tab's own saves are in the air, and when the
        // latest one left. The sync engine decides nothing about a newer
        // document until they have settled - but a request that never
        // answers must not freeze following for ever, so `busy` expires.
        saving: 0,
        savingSince: 0,
        // True from the first local edit until a save of exactly what the
        // editor holds has been confirmed by the server. The bundle's own
        // `pending` flag is not enough: it drops the moment a document is
        // HANDED to persist(), long before the server has stored it.
        unsaved: false,
        // A save is owed: one was asked for while another was in the air,
        // or one never answered. resendIfOwed() sends it.
        resave: false,
        // The document, as a JSON string, as the server last confirmed it:
        // what the page opened with, what the last accepted save stored, or
        // the last server document applied. See syncState().
        confirmed: null,
        // The next save puts this tab's own text back over a version it
        // loaded (Put it back): it goes as an overwrite.
        overwriteOwed: false,
        // Set while a newer version exists on the server AND this tab holds
        // unsaved typing. Saving is suspended until the writer chooses.
        // `version` is the newest version known to be in the way; `ready`
        // is whether that document has been downloaded yet.
        conflict: null,
        // The writer's own text, as a JSON string, after they chose to load
        // the newer version - or a draft from an earlier visit that the
        // document has since moved past. Kept so the page can offer to put
        // it back.
        setAside: null,
        // A reason the page can no longer stay in step (signed out, access
        // removed, document deleted, a version this editor cannot open).
        // Shown in the status strip.
        syncNotice: '',
        // The people last reported by the poll, as a comparable string, so
        // the presence strip is only re-rendered when it actually changes.
        memberKey: '',
```

- [ ] **Step 4: Four edits inside `init()`**

1. Seed the outline only on the first call. Replace

```js
            try {
                window.DotDoc.setOutline(JSON.parse(host.dataset.outline || '{}'));
            } catch (_) {}
```

with

```js
            // Only the first call seeds it. Livewire re-inits this element
            // whenever the rendered x-data string changes (every render
            // after the version has moved), and data-outline sits on a
            // wire:ignore element, so it still holds the PAGE-LOAD outline:
            // a later instance would put that back over a newer one.
            if (this.owns) {
                try {
                    window.DotDoc.setOutline(JSON.parse(host.dataset.outline || '{}'));
                } catch (_) {}
            }
```

2. Pass the base version to the editor bundle. In the `window.DotDoc.mount(host, { ... })` options, directly after the `csrfToken:` line, add:

```js
                // The version this page's copy is based on, read at the
                // moment of the unload beacon.
                getBaseVersion: () => this.baseVersion,
```

3. Remember what the server last confirmed. Directly after the line `if (!this.owns) return;` (it follows `const editor = handle.editor;`), add a blank line and:

```js
            this.confirmed = JSON.stringify(editor.getJSON());
```

It goes AFTER the `owns` check on purpose: only the first data object, the one every handler is bound to, keeps this state.

4. Record that there is unsaved text. In the `editor.on('update', () => { ... })` listener, directly after the line `this.isTyping = true;`, add:

```js
                this.unsaved = true;
```

- [ ] **Step 5: Start the engine and replace the heartbeat**

Still in `init()`. Replace

```js
            this.refreshOutline();
            this.restoreDraftIfRestorable();
            this.setupEcho();
```

with

```js
            this.refreshOutline();
            this.setupEcho();
            // The engine starts only once the draft check has finished. If
            // its first poll brought a newer document before the draft from
            // a previous session had been read, applying that document
            // would delete the draft unexamined.
            this.restoreDraftIfRestorable().finally(() => this.startSync());
```

In the `app-online` listener, replace the comment block and the `this.persist(editor.getJSON());` line, that is, these six lines

```js
                // Flush the current document now that we are back online. The
                // draft is NOT cleared here: persist() clears it itself, and
                // only once the save has actually stored what the editor is
                // holding. Clearing it alongside an un-awaited save was how a
                // failed reconnect save lost the offline work outright.
                this.persist(editor.getJSON());
```

with

```js
                // Back online: send what was typed while offline, and ONLY
                // that. resendIfOwed() saves when this tab holds unsaved
                // text and does nothing otherwise. Saving unconditionally,
                // as this listener used to, sent an idle reader's stale
                // copy: refused as a conflict that reader never caused, or
                // stored as a new version that threw everybody who was
                // typing into one. The draft is NOT cleared here: persist()
                // clears it itself, and only once the save has stored what
                // the editor is holding.
                this.resave = true;
                this.resendIfOwed();
                this.syncEngine()?.poke();
```

Replace the heartbeat block and the `beforeunload` block, that is, exactly these lines at the end of `init()`

```js
            // Heartbeat every 60 seconds to keep presence alive. It
            // re-arms itself with setTimeout rather than running on a repeating
            // timer, so a slow round trip cannot stack beats on top of each
            // other, and destroy() only ever has one handle to clear.
            const beat = () => {
                this.heartbeatTimer = setTimeout(() => {
                    @this.heartbeat();
                    beat();
                }, 60000);
            };
            beat();

            // Notify server when tab/window is closed
            window.addEventListener('beforeunload', () => {
                @this.leaving();
            });
```

with

```js
            // A hidden tab stops polling; coming back polls at once.
            document.addEventListener('visibilitychange', () => this.syncEngine()?.visibilityChanged());

            // Tell the server this tab is going, so the people left behind
            // stop seeing a face that is no longer here. By beacon, like the
            // unload save: nothing else is delivered from a closing page.
            window.addEventListener('pagehide', () => {
                if (typeof navigator.sendBeacon !== 'function') return;
                navigator.sendBeacon(
                    '{{ route('documents.sync', $document->uuid) }}',
                    new Blob([JSON.stringify({
                        _token: document.querySelector('meta[name=csrf-token]').content,
                        version: this.baseVersion,
                        tab: this.tabId,
                        leaving: true,
                    })], { type: 'application/json' })
                );
            });
```

(The string `beat();` occurs more than once in the old block. Delete the whole block shown, down to and including the closing `});` of the `beforeunload` listener; the `},` that closes `init()` stays.)

- [ ] **Step 6: Replace `persist()` and add `resendIfOwed()`**

Replace the whole `persist(json) { ... }` method and the comment block above it (from `// $wire actions resolve with the PHP method's return value, so a` down to the `},` that closes `persist`) with these two methods:

```js
        // $wire actions resolve with the PHP method's return value, so a
        // refused save is visible here. saveContent() answers
        // {ok, conflict, version}:
        //   ok       - stored; `version` becomes the base of the next save.
        //   conflict - somebody saved first. Nothing was written. Saving is
        //              suspended and the writer is asked what to do.
        //   neither  - the content was rejected (DocumentSchema), or the page
        //              is out of date; the error renders in the status area.
        // On anything but `ok` the offline draft is KEPT - it is the only
        // remaining copy of what the writer typed.
        //
        // One save in the air at a time. Livewire sends a second call after
        // the first, with the arguments it was CALLED with, so a second
        // autosave fired before the first had answered would state the old
        // base and be refused: the tab would conflict with itself. A save
        // asked for in the meantime is remembered in `resave` and sent by
        // resendIfOwed() once the first has answered.
        //
        // `force` is the Keep mine choice: the base version has just been
        // moved up to the newer document's, so this save knowingly replaces
        // it. It goes to the server as the overwrite flag, which keeps the
        // replaced version in the history first. The save that follows Put
        // it back carries the same flag (`overwriteOwed`): it too replaces
        // a version on purpose, the one this tab loaded.
        persist(json, { force = false } = {}) {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            // Fail-closed (the content check refused the document): the editor
            // is read-only and must not write anything back.
            if (handle && handle.autosaves === false) return Promise.resolve();

            // An unresolved conflict: every save would be refused. The draft
            // keeps the text; the notice asks the question.
            if (this.conflict && !force) {
                this.report('danger', 'Not saved');
                return Promise.resolve();
            }

            if (this.saving > 0 && !force) {
                this.resave = true;
                return Promise.resolve();
            }

            this.report('idle', 'Saving');

            // What is being SENT, captured now: the draft is cleared only if
            // the editor still holds exactly this when the answer arrives.
            const snapshot = JSON.stringify(json);

            this.saving++;
            this.savingSince = Date.now();

            return @this.saveContent(json, this.baseVersion, force || this.overwriteOwed).then((result) => {
                this.saving = Math.max(0, this.saving - 1);

                if (result && result.ok) {
                    this.baseVersion = result.version;
                    this.conflict = null;
                    this.confirmed = snapshot;
                    this.overwriteOwed = false;
                    this.syncEngine()?.saved(result.version);
                    if (this.clearDraftIfSettled(snapshot)) this.unsaved = false;
                    this.report('good', 'Saved');
                } else if (result && result.conflict && result.version <= this.baseVersion) {
                    // Refused against a version this tab is already based
                    // on: one of its own saves got there first. That is not
                    // a conflict - send again on the new base.
                    this.resave = true;
                } else if (result && result.conflict) {
                    this.enterConflict(result.version);
                } else {
                    this.report('danger', 'Not saved');
                }

                // A newer document may have been waiting for this save to
                // settle before the engine decided what to do with it.
                this.syncEngine()?.retry();
                this.resendIfOwed();

                return this.refreshOutline();
            }).catch(() => {
                this.saving = Math.max(0, this.saving - 1);
                this.report('danger', 'Not saved');
                this.syncEngine()?.retry();
            });
        },

        // Send the save that is owed, if one is and nothing stands in its
        // way. Called when a save answers, on every answered poll and when
        // the browser comes back online. It only ever sends text that is
        // still unsaved HERE (`unsaved`); it never sends a copy the writer
        // has not touched.
        resendIfOwed() {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            // Fail-closed: nothing is written back.
            if (!handle || handle.autosaves === false) return;
            // A save that has not answered in 15 seconds is not coming back
            // (see syncState()): stop waiting for it and send again.
            if (this.saving > 0 && Date.now() - this.savingSince >= 15000) {
                this.saving = 0;
                this.resave = true;
            }
            if (this.saving > 0 || !this.resave) return;
            this.resave = false;
            // handle.pending means the bundle's own debounce is still armed
            // and will call persist() itself.
            if (this.unsaved && !this.conflict && !handle.pending) {
                this.persist(handle.editor.getJSON());
            }
        },
```

- [ ] **Step 6b: Make `clearDraftIfSettled()` report whether the save settled, stop the restore deleting the draft, and offer a draft the document has moved past**

Replace the whole `clearDraftIfSettled(snapshot) { ... }` method (keep the four-line comment above it, which begins `// Drop the offline draft only when the document the server just`) with:

```js
        // Returns whether the save settled, that is, whether the editor
        // still holds exactly what was stored. persist() clears `unsaved`
        // on that answer, so it is given even when there is no draft store.
        clearDraftIfSettled(snapshot) {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            if (!handle || handle.autosaves === false) return false;
            if (handle.pending) return false;
            if (JSON.stringify(handle.editor.getJSON()) !== snapshot) return false;
            if (window.offlineDraft) window.offlineDraft.clearDraft(this.docUuid);
            return true;
        },
```

In `restoreDraftIfRestorable()`, the last statement of the outer `try` deletes the draft straight after a successful restore. Replace these lines at the end of that method

```js
                    console.info('[Dot.Doc] The offline draft could not be applied and has been kept.');
                    return;
                }
                window.offlineDraft.clearDraft(this.docUuid);
            } catch (_) {}
```

with

```js
                    console.info('[Dot.Doc] The offline draft could not be applied and has been kept.');
                    return;
                }
                // The draft is NOT cleared here. The restore emits `update`,
                // which rewrites the draft and sets `unsaved`; the save that
                // follows can now be refused as a conflict, and until it has
                // settled the draft is the only stored copy of this text.
                // persist() clears it once the save has gone through.
            } catch (_) {}
```

Still in `restoreDraftIfRestorable()`: a draft that is BEHIND the document is parked where only developer tools reach it. That now happens to ordinary people: somebody who reloads instead of answering the conflict notice, and somebody whose last words before closing the tab were refused by the server (the unload beacon stated a base that the tab's own save, still in the air, had already moved past). Offer that draft in the page. In the branch that begins `if (draft.baseVersion < this.documentVersion) {`, replace these four lines (the first two of them also appear further down, in the branch for a declined restore, followed by a different message; leave that one alone)

```js
                    await window.offlineDraft.parkStaleDraft(this.docUuid, draft.json, draft.baseVersion);
                    window.offlineDraft.clearDraft(this.docUuid);
                    console.info(
                        '[Dot.Doc] An offline draft based on v' + draft.baseVersion +
```

with

```js
                    await window.offlineDraft.parkStaleDraft(this.docUuid, draft.json, draft.baseVersion);
                    window.offlineDraft.clearDraft(this.docUuid);
                    // Offer it in the page as well: the status strip shows
                    // Your text was set aside, with Put it back. Not when
                    // the document already says exactly this (the unload
                    // beacon stored it): there is nothing to put back.
                    if (window.DotDoc.documentsDiffer(parsed, editor.getJSON())) {
                        this.setAside = draft.json;
                    }
                    console.info(
                        '[Dot.Doc] An offline draft based on v' + draft.baseVersion +
```

(`draft.json` is already a JSON string, which is what `setAside` holds; `parsed` and `editor` are the method's own variables, declared above this branch.)

- [ ] **Step 7: Add the sync methods**

Directly after `refreshOutline() { ... },` add:

```js
        // The engine is parked on the editor element, not in Alpine's
        // reactive data, for the same reason the editor handle is.
        syncEngine() {
            return this.$refs.editorEl?.__dotdocSync ?? null;
        },

        // What the sync engine may do with a newer document right now.
        syncState() {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            if (!handle || handle.autosaves === false) return 'closed';
            if (this.saving > 0) {
                // A save that has not answered in 15 seconds is not coming
                // back (a dropped connection leaves the $wire promise
                // pending for ever); stop waiting for it.
                if (Date.now() - this.savingSince < 15000) return 'busy';
                this.saving = 0;
            }
            // An edit undone again inside the bundle's debounce never
            // reaches persist(): the bundle finds nothing to send, so
            // nothing would clear `unsaved`, and this tab would stop
            // following and raise a conflict over text it does not hold.
            // When the editor is back to exactly what the server last
            // confirmed, nothing is unsaved and no overwrite is owed.
            if (this.unsaved && !this.conflict && !handle.pending
                && JSON.stringify(handle.editor.getJSON()) === this.confirmed) {
                this.unsaved = false;
                this.overwriteOwed = false;
            }
            // `unsaved`, not only handle.pending: after a save that never
            // answered or was rejected, the bundle reports nothing pending
            // while this tab still holds text that exists nowhere else.
            if (this.conflict || this.unsaved || handle.pending) return 'dirty';
            return 'clean';
        },

        // This page just saved the document through some other action
        // (a style change, an accepted suggestion). Move the base up to the
        // version that produced, so the next autosave is not refused.
        //
        // A style change re-saves what the SERVER holds, not what this
        // editor holds. If the version it produced is not exactly one past
        // this tab's base, somebody else saved first and the editor does
        // not have their text: leave the base alone and let the engine
        // bring the newer document (or raise the conflict). Adopting the
        // version blindly would make this tab's next save erase their work.
        // `contentLoaded` is for the caller that has just put exactly that
        // version's content into the editor.
        adoptVersion(version, { contentLoaded = false } = {}) {
            if (!Number.isFinite(version)) return;
            if (!contentLoaded && version !== this.baseVersion + 1) {
                this.syncEngine()?.poke();
                return;
            }
            this.baseVersion = version;
            this.syncEngine()?.saved(version);
            // An autosave that travelled with that action may have been
            // refused against the version the action itself produced.
            if (this.conflict && this.conflict.version <= version) {
                this.conflict = null;
                this.resave = true;
                this.resendIfOwed();
            }
        },

        startSync() {
            const host = this.$refs.editorEl;
            if (!host || host.__dotdocSync || !window.DotDoc?.sync) return;

            host.__dotdocSync = window.DotDoc.sync.createSyncEngine({
                version: this.baseVersion,
                tab: this.tabId,
                request: window.DotDoc.sync.createSyncRequest(
                    '{{ route('documents.sync', $document->uuid) }}',
                    document.querySelector('meta[name=csrf-token]').content
                ),
                visible: () => document.visibilityState !== 'hidden',
                host: {
                    state: () => this.syncState(),
                    applyRemote: (remote) => this.applyFromSync(remote),
                    onConflict: (remote) => this.enterConflict(remote.version),
                    onRefused: () => {
                        this.syncNotice = 'A newer version could not be opened here. Reload the page.';
                    },
                    // Every answered poll is also the moment to send a save
                    // that never answered.
                    onMembers: (members) => { this.membersChanged(members); this.resendIfOwed(); },
                    onStopped: (reason) => {
                        this.syncNotice = {
                            'signed-out': 'You have been signed out. Reload the page to keep editing.',
                            'forbidden': 'You no longer have access to this document.',
                            'gone': 'This document no longer exists.',
                        }[reason] || 'This page has stopped updating. Reload it.';
                        this.report('danger', 'Not saved');
                    },
                },
            });

            host.__dotdocSync.start();
        },

        // Put a newer server document into the editor. `force` is the Load
        // theirs choice, the only case allowed to replace unsaved typing.
        applyFromSync(remote, { force = false } = {}) {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            // Fail-closed: what the editor shows is not the document, so
            // nothing may be written into it.
            if (!handle || handle.autosaves === false) return false;
            if (!handle.applyRemote(remote.json, { force })) return false;

            this.baseVersion = remote.version;
            this.unsaved = false;
            this.confirmed = JSON.stringify(handle.editor.getJSON());
            // Whatever this tab had put back is no longer in the editor.
            this.overwriteOwed = false;
            // The server now holds newer content than any draft.
            if (window.offlineDraft) window.offlineDraft.clearDraft(this.docUuid);

            if (remote.outline) {
                window.DotDoc.setOutline(remote.outline);
                window.DotDoc.pagination.setPageSetup(
                    remote.outline.pageSetup, remote.outline.headerSegments, remote.outline.footerSegments
                );
            }

            // Somebody else may have changed the document style: the outline
            // carries its numbering and page setup, this carries its fonts
            // and colours.
            if (remote.css) {
                const style = document.getElementById('doc-style');
                if (style) style.textContent = remote.css;
            }

            this.tick++;
            return true;
        },

        // A newer version exists and this tab has unsaved typing. Stop
        // saving and ask; the draft keeps the text in the meantime.
        // `version` is the version that is in the way: Keep mine needs only
        // that number, not the document itself.
        enterConflict(version) {
            const engine = this.syncEngine();
            const waiting = engine ? engine.pending : null;
            this.conflict = {
                version: Math.max(
                    Number.isFinite(version) ? version : 0,
                    this.conflict ? this.conflict.version : 0,
                    waiting ? waiting.version : 0
                ),
                ready: !!waiting,
            };
            this.report('danger', 'Not saved');
            // Download the newer document if the engine does not hold it.
            // refetch, not poke: the engine may already have SEEN that
            // version, and an ordinary poll would be told nothing changed.
            if (!this.conflict.ready) engine?.refetch(this.baseVersion);
        },

        // Keep mine: save this tab's text over the newer version, on
        // purpose. The base moves up to that version so the save is
        // accepted. The waiting document is left with the engine: when the
        // save lands, persist() tells the engine, which drops it; if the
        // save is lost, both buttons still work.
        keepMine() {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            if (!handle || handle.autosaves === false || !this.conflict) return;
            // One choice at a time: a Keep mine save is already in the air.
            if (this.syncState() === 'busy') return;
            const waiting = this.syncEngine()?.pending;
            const target = Math.max(this.conflict.version || 0, waiting ? waiting.version : 0);
            if (!target) return;
            this.baseVersion = Math.max(this.baseVersion, target);
            this.persist(handle.editor.getJSON(), { force: true });
        },

        // Load theirs: show the newer document. This tab's text is parked
        // as a stale- draft and also held in `setAside`, so the page can
        // offer to put it back.
        async loadTheirs() {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            const engine = this.syncEngine();
            if (!handle || handle.autosaves === false || !engine) return;
            // One choice at a time: a Keep mine save is already in the air.
            if (this.syncState() === 'busy') return;

            const waiting = engine.takeRemote();
            if (!waiting) { engine.refetch(this.baseVersion); return; }

            const mine = JSON.stringify(handle.editor.getJSON());

            if (window.offlineDraft) {
                await window.offlineDraft.parkStaleDraft(this.docUuid, mine, this.baseVersion);
                console.info(
                    '[Dot.Doc] Your unsaved text was kept as stale-' + this.docUuid +
                    ' and will be removed after 7 days.'
                );
            }

            if (!this.applyFromSync(waiting, { force: true })) {
                this.syncNotice = 'The newer version could not be opened here. Reload the page.';
                return;
            }

            this.setAside = mine;
            this.conflict = null;
            this.report('good', 'Saved');
        },

        // Put it back: the writer chose Load theirs and wants their own
        // text after all (or opened the page with a draft the document had
        // moved past). setContent() emits `update`, so the ordinary
        // autosave sends the text on the current base.
        putBack() {
            const handle = window.DotDoc?.get(this.$refs.editorEl);
            // Fail-closed: nothing may be written into the editor.
            if (!handle || handle.autosaves === false || !this.setAside) return;
            try {
                handle.editor.commands.setContent(JSON.parse(this.setAside), { errorOnInvalidContent: true });
                this.setAside = null;
                // This replaces the version that was loaded, exactly as Keep
                // mine would have: the save that carries it says so, and the
                // server keeps the replaced version in the history first.
                this.overwriteOwed = true;
            } catch (_) {
                this.syncNotice = 'Your text could not be put back.';
            }
        },

        // The presence strip is rendered by Livewire; only ask it to
        // re-render when the set of people actually changed.
        membersChanged(members) {
            const key = members.map((member) => member.id).join(',');
            if (key === this.memberKey) return;
            const first = this.memberKey === '';
            this.memberKey = key;
            // The first report matches what the page was rendered with unless
            // somebody else is already here.
            if (first && members.length <= 1) return;
            @this.refreshPresence();
        },
```

- [ ] **Step 8: Turn the Echo listener into a poke, and make `destroy()` survive a re-render**

In `setupEcho()`, replace the `.listen('.document.updated', ...)` handler and the two `.user.joined` / `.user.left` listeners (everything from `.listen('.document.updated', (e) => {` down to the `})` that closes the `.user.left` listener) with:

```js
                .listen('.document.updated', () => {
                    // If a socket happens to be connected (local development
                    // with Reverb), a broadcast means one thing only: check
                    // now. The sync engine is what applies a document.
                    this.syncEngine()?.poke();
                })
```

Keep the `.comment.posted` listener as it is.

In `destroy()`, replace

```js
            clearTimeout(this.heartbeatTimer);
            clearTimeout(this.typingTimeout);
```

with

```js
            // Alpine also runs this on the OLD data object each time Livewire
            // morphs a changed x-data string onto this element, which is
            // every render after the document version has moved. The editor
            // and the sync engine live on the element and must survive
            // that; only a real removal tears them down.
            if (this.$el && this.$el.isConnected) return;
            clearTimeout(this.typingTimeout);
            this.syncEngine()?.stop();
            if (this.$refs.editorEl) this.$refs.editorEl.__dotdocSync = null;
```

The guard is the first statement of `destroy()`. The rest of the method (leaving the Echo channel, destroying the editor) is unchanged and now runs only after it.

- [ ] **Step 8b: Teach the two in-page writers the new version**

`applySuggestion` receives a document the server has ALREADY stored (the writer accepted it), so it replaces local typing on purpose and must learn the new version. Replace its signature

```js
        applySuggestion(content) {
```

with

```js
        applySuggestion(content, version) {
```

and replace these lines in its body

```js
            if (!handle.applyRemote(content)) {
                this.aiError = 'That suggestion could not be applied — the document is unchanged.';
                return;
            }
```

with

```js
            if (!handle.applyRemote(content, { force: true })) {
                this.aiError = 'That suggestion could not be applied — the document is unchanged.';
                return;
            }
            // The editor now holds exactly the version the server stored.
            this.unsaved = false;
            this.confirmed = JSON.stringify(handle.editor.getJSON());
            this.overwriteOwed = false;
            this.adoptVersion(version, { contentLoaded: true });
```

(the rest of the method is unchanged), and in the comment inside the method replace "The same fail-closed gate the Echo listener has." with "The same fail-closed gate applyFromSync() has."

On the root element's event attributes, which are outside `x-data`, replace

```blade
    @suggestion-accepted.window="applySuggestion($event.detail.content)"
```

with

```blade
    @suggestion-accepted.window="applySuggestion($event.detail.content, $event.detail.version)"
```

and replace

```blade
    @style-changed.window="document.getElementById('doc-style').textContent = $event.detail.css; refreshOutline()"
```

with

```blade
    @style-changed.window="document.getElementById('doc-style').textContent = $event.detail.css; adoptVersion($event.detail.version); refreshOutline()"
```

In `resources/js/editor/pagination/index.js`, the comment above `editor.on('update', scheduleRepaginate);` explains how a remotely applied document gets repaginated and names the Echo listener. Replace these ten lines of it

```js
    //    docChanged). It does NOT itself cover a remote update applied via
    //    applyRemote() - that call uses `emitUpdate: false` specifically so
    //    a collaborator's edit never fires the LOCAL autosave/update chain
    //    (see .ai/rules/editor.md's applyRemote() rule) - so `update` alone
    //    never fires for it. What actually covers a remote update is the
    //    Blade bridge's Echo listener, which already calls refreshOutline()
    //    immediately after every successful applyRemote() (independent of
    //    this `update` listener), and refreshOutline() calls setPageSetup()
    //    below, which schedules a pass - so the guarantee holds, just via
    //    that path rather than this one.
```

with

```js
    //    docChanged). It does NOT itself cover a document applied by the
    //    sync engine: applyRemote() tags its transaction `preventUpdate` so
    //    a collaborator's edit never fires the LOCAL autosave/update chain
    //    (see .ai/rules/editor.md), so `update` never fires for it. What
    //    covers that case is the Blade bridge's applyFromSync(), which calls
    //    setPageSetup() below with the outline that arrived alongside the
    //    document, and setPageSetup() schedules a pass.
```

- [ ] **Step 9: Show the conflict, the set-aside text and the sync notice in the status strip**

In the `.doc-status` span, directly after the `aiError` status word (the `<span class="status-word status-word-danger" x-show="aiError" ...>` element and its closing `</span>`), add:

```blade
            {{-- A newer version was saved elsewhere while this tab held
                 unsaved typing. Saving is suspended until the writer picks
                 one: nothing is overwritten and nothing is thrown away
                 without being asked. Reloading instead of choosing opens
                 the newer version and sets this tab's text aside: the page
                 then offers Put it back, but only in a browser that could
                 keep the offline draft. Hence the last sentence. --}}
            <span class="status-word status-word-danger" x-show="conflict" x-cloak>
                <span class="status-word-dot" aria-hidden="true"></span>
                <span>Not saved — this document was changed elsewhere while you were typing. Do not reload: choose one.</span>
                <button type="button" class="tool tool-mono" @click="keepMine()"
                        title="Save your version over the newer one. The other version is kept in the history.">Keep mine</button>
                <button type="button" class="tool tool-mono" @click="loadTheirs()"
                        :disabled="!conflict || !conflict.ready"
                        title="Show the newer version. You can put your text back afterwards.">Load theirs</button>
            </span>

            {{-- The writer chose Load theirs, or opened the page with a draft
                 the document had moved past. Their own text is held by the
                 page so they can have it back. --}}
            <span class="status-word status-word-idle" x-show="setAside" x-cloak>
                <span class="status-word-dot" aria-hidden="true"></span>
                <span>Your text was set aside.</span>
                <button type="button" class="tool tool-mono" @click="putBack()">Put it back</button>
            </span>

            <span class="status-word status-word-danger" x-show="syncNotice" x-cloak>
                <span class="status-word-dot" aria-hidden="true"></span>
                <span x-text="syncNotice"></span>
            </span>
```

Below it, the "Saved / Ready" word must not show while there is a conflict or a sync notice, and its `x-show` must sit on an element that is rendered the same on EVERY render. Today the word is the `@else` branch of `@error('content')` and carries the `x-show` itself. After a rejected save has come and gone, Livewire puts a NEW element there; Alpine binds it to the newest data object, whose `conflict`, `syncNotice` and `isTyping` never change (see the re-creation rule Task 10 records in `.ai/rules/livewire.md`), and from then on "Saved" would show beside the conflict notice and while typing. Replace the whole block, that is, these eight lines

```blade
            @error('content')
                <x-shell.status-word tone="danger" :title="$message"
                                     :word="'Not saved — '.\Illuminate\Support\Str::limit($message, 60)" />
            @else
                <x-shell.status-word tone="good" :word="$saved ? 'Saved' : 'Ready'"
                                     wire:loading.remove wire:target="saveContent,saveTitle"
                                     x-show="!isTyping && !isOffline" />
            @enderror
```

with

```blade
            @error('content')
                <x-shell.status-word tone="danger" :title="$message"
                                     :word="'Not saved — '.\Illuminate\Support\Str::limit($message, 60)" />
            @enderror

            {{-- The x-show sits on a wrapper that is rendered the same on
                 EVERY render, and the Saved / Ready word inside it comes and
                 goes with the error above. With the x-show on the word
                 itself, a word that came back after a rejected save was a
                 new element, bound to the newest Alpine data object, whose
                 conflict, syncNotice and isTyping never change
                 (.ai/rules/livewire.md): Saved then showed beside the
                 conflict notice and while typing. --}}
            <span x-show="!isTyping && !isOffline && !conflict && !syncNotice">
                @unless ($errors->has('content'))
                    <x-shell.status-word tone="good" :word="$saved ? 'Saved' : 'Ready'"
                                         wire:loading.remove wire:target="saveContent,saveTitle" />
                @endunless
            </span>
```

The danger word is unchanged and still shows whatever the page is doing. The good word keeps its `wire:loading.remove` and loses its own `x-show`.

- [ ] **Step 10: Confirm the conflict's "ready" flag follows the download**

`enterConflict(version)` runs both when a save is refused (the newer document may not be downloaded yet) and when the engine reports a conflict (it is). It sets `ready` from `engine.pending`. When the document is not there it calls `engine.refetch(this.baseVersion)`, and the engine calls `onConflict` again on every poll while the conflict stands, so `ready` becomes true on the poll that brings the document. No further code is needed; confirm by reading `deliver()` in `resources/js/editor/sync/engine.js`: in the `dirty` state it calls `host.onConflict(remote)` every time it runs.

- [ ] **Step 11: Run the tests**

Run: `php artisan test --compact tests/Feature/Documents/EditorSyncWiringTest.php tests/Feature/Documents/EditorMountTest.php tests/Feature/Styles/EditorStyleRootTest.php tests/Feature/Documents/ContextualToolbarTest.php`
Expected: PASS. If `ContextualToolbarTest::test_the_editors_alpine_component_survives_being_an_html_attribute` or the first test of `EditorSyncWiringTest` fails, a double quote has got into the `x-data` attribute. This lists the offending lines with their line numbers, and prints nothing when the attribute is clean:

```bash
awk '/^    x-data="\{$/{f=1;next} /^    \}"$/{f=0} f && /"/{print FNR": "$0}' resources/views/livewire/documents/editor.blade.php
```

Run: `npm run build`
Expected: the build succeeds.

- [ ] **Step 12: Verify in a browser with two sessions**

This is the only test of the page's script. Do not skip it and do not report the task done without it.

Run `php artisan migrate` against the local development database first (it is PostgreSQL, and needs the `document_presences` table), then `npm run build`. Start the dev server with the `dotdoc` launch configuration (port 8014). Open the same document in two separate browser sessions as two different users who can both edit it (make the second a collaborator from the share screen). In each case, confirm what is described and check the browser console for errors. Stay online throughout: the service worker is not corrected until Task 9.

1. **Follow.** Type a sentence in ONE place in A and stop. Within about 3 seconds it appears in B without a refresh. B's caret, if placed in another paragraph beforehand, has not moved.
2. **Undo survives.** In B, type a word, wait for "Saved", let A add a paragraph in ONE other place, then press undo in B: B's own word is undone and A's paragraph stays. (This holds for a change in one place. When A changes two separate places between two of B's polls, everything between them is replaced in B, and an undo of something B typed in between does nothing. That is the stated limit of this phase, not a fault to fix here.)
3. **Pagination survives.** With a document long enough for two pages, A's edit on page 1 does not make B's page break disappear.
4. **Presence.** B's face appears in A's presence strip within a few seconds of B opening the page, and disappears within a few seconds of B closing the tab.
5. **Same account, two tabs.** Open the document twice as the same user: an edit in one appears in the other.
6. **Conflict.** Type in A and B at the same moment. One of them shows "Not saved — this document was changed elsewhere while you were typing. Do not reload: choose one." with both buttons. "Load theirs" shows the other's text; "Keep mine" saves this tab's text and the other tab then follows it.
7. **No silent overwrite.** After any of the above, reload both tabs: they show the same document.
8. **Access removed.** Remove B as a collaborator from A's share screen: within a few seconds B shows "You no longer have access to this document."
9. **A style change does not conflict with yourself.** With one tab open, change the document style from the picker, then type: the status returns to "Saved" and no conflict notice appears.
10. **Read-only stays read-only.** Not required to reproduce; confirm by reading `syncState()` that a fail-closed editor returns `'closed'`.
11. **Still following after the first exchange.** After A has saved at least once, and after B's presence strip has changed at least once (open the document in a third session and close it again), type in A and then in B: each still follows the other, and the browser's network panel shows the `/sync` requests still going out from both. The first exchange can pass while this fails, if `destroy()` stopped the engine on a re-render.
12. **The outline does not fall back.** A adds a new numbered heading; B follows it and shows its number. Then a third session C opens the document, so B's presence strip re-renders. B's heading numbers and table of contents do not change.
13. **Put it back.** Repeat case 6 and choose "Load theirs". The status strip shows "Your text was set aside." with a "Put it back" button. Press it: this tab's own text returns, the status goes back to "Saved", and the other tab follows it. The history then has a version holding the other person's text, added just now (open `/documents/{uuid}/history` as in case 14): Put it back replaced their version, so it was kept first, exactly as Keep mine does.
14. **The replaced version is in the history.** Repeat case 6 and choose "Keep mine". Open the document's version history (the `documents.history` page, `/documents/{uuid}/history`): the list has a version added just now, under your name, whose preview shows the other person's text. (The history page does not show a version's label; the label "Before <your name> kept their version" is in the `document_versions` row.)
15. **A style change by somebody else arrives whole.** A changes the document style from the picker. Within a few seconds B shows the new fonts and colours as well as the new numbering, without a refresh.
16. **A document that does not end in a paragraph.** With A and B both open on a throwaway document and nobody typing, replace the document with one that ends in a list. Either accept an AI suggestion whose text ends in a list, or, when the assistant is not configured locally, run this in a terminal with the document's uuid in place of `PUT-THE-UUID-HERE`:

    ```bash
    php artisan tinker --execute '$d = App\Models\Document::where("uuid", "PUT-THE-UUID-HERE")->firstOrFail(); app(App\Documents\DocumentStore::class)->save($d, ["type" => "doc", "content" => [["type" => "paragraph", "content" => [["type" => "text", "text" => "Ends in a list"]]], ["type" => "bulletList", "content" => [["type" => "listItem", "content" => [["type" => "paragraph", "content" => [["type" => "text", "text" => "Last item"]]]]]]]]], $d->owner);'
    ```

    Both tabs follow to the new document within a few seconds. Now check that neither tab saves anything by itself. Read the document's version, wait ten seconds without typing in either tab, and read it again:

    ```bash
    php artisan tinker --execute 'echo App\Models\Document::where("uuid", "PUT-THE-UUID-HERE")->value("version");'
    ```

    It prints the same number both times, neither tab has shown "Saving", and neither shows the conflict notice. The version moves only when somebody types. (Each tab keeps an empty paragraph of its own after the list, so there is somewhere to put the caret; it is saved with that tab's next edit. If the number goes up by itself, `skipTrailingNode` has found its way back into `applyRemote()`. Do not judge this by the `v` figure in the status strip: Livewire draws it, and a tab that only follows does not redraw it.)
17. **An edit undone at once.** In B, type a word and wait for "Saved". Then type one letter and delete it straight away, inside a second. Now type a sentence in A: B follows it within a few seconds and shows no conflict notice.
18. **Reloading instead of choosing.** Repeat case 6 and, in the tab that shows the notice, reload the page instead of pressing a button. The page opens on the other person's version and the status strip shows "Your text was set aside." with "Put it back". Press it: this tab's text returns and is saved, the other tab follows it, and the history has a version holding the other person's text.
19. **Saved does not show beside the notice.** In one tab, make a save fail and then succeed (the quickest way: in the browser console run `Livewire.getByName('documents.editor')[0].saveContent({ type: 'doc', content: [{ type: 'marquee' }] }, 1)`, which the schema rejects whatever the document's version is; see "Not saved" with the reason in the status strip, then type a word and wait for "Saved"). Then repeat case 6 in that tab: while the conflict notice is showing, the word "Saved" is NOT showing next to it.

Take a screenshot of case 1 and case 6 as proof.

- [ ] **Step 13: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/documents/editor.blade.php resources/js/editor/pagination/index.js tests/Feature/Documents/EditorSyncWiringTest.php
git commit -m "feat(editor): follow other people's saves without a refresh

The editor page starts the sync engine: saves state their base
version, one at a time; a newer document is applied to a clean tab as
a minimal change; and a tab with unsaved typing is shown a notice with
two choices instead of being overwritten or overwriting. Keep mine
keeps the replaced version in the history; Load theirs can be undone
with Put it back, which keeps the version it replaces in the same
way. A draft the document has moved past is offered back in the page
instead of being parked out of reach. Presence comes from the same
poll; the 60 second heartbeat and the unload Livewire call are gone.
A broadcast, when a socket exists, is only a poke.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 9: The service worker leaves non-GET requests alone and stops queueing Livewire saves

`public/sw.js` treats every same-origin request that is not a Livewire POST or a navigation as a static asset: cache-first, then cache the response. The sync poll and the unload beacon are POSTs that fall into that branch on every call. The Cache API cannot store a POST, so each one ends in a rejected `cache.put()`. They are taken out of the service worker's hands. This also takes POST navigations (form posts such as login and logout) out of the offline page fallback; that is intended, a form post must never be answered from the cache.

The second change is to what the service worker does with a Livewire request when the network is down. Today it stores the request in IndexedDB, answers the page with a made-up body (`{effects: [], components: []}`), and sends the stored request again when the connection returns. Neither half works. Livewire cannot read that body: it throws, never releases the request, and every later action on the page, saving included, stays pending until the page is reloaded. And the replay is a whole-document save from before the connection dropped, sent behind the page's back. From now on an offline Livewire request simply fails, as it would with no service worker, and nothing is queued. Text typed offline is protected by the offline draft (`resources/js/offline.js`), and the page sends it itself when it is back online (`resendIfOwed()` in Task 8).

**Files:**
- Modify: `public/sw.js` (the header comment, the constants, the `fetch` listener, `handleLivewirePost()`, and the queue-and-replay code and the IndexedDB helpers, which are deleted)
- Test: `tests/js/sw.test.js` (create)

**Interfaces:**
- Produces: nothing other tasks use.

- [ ] **Step 1: Write the failing test**

Create `tests/js/sw.test.js`:

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * Run public/sw.js in a sandbox that stands in for a service worker.
 *
 * @param {{fetchImpl?: Function}} options `fetchImpl` replaces the network;
 *        by default every request is answered 200.
 * @returns {{listeners: object, calls: {databaseOpens: number}}} the event
 *          listeners the worker registered, by event name, and a count of
 *          how often it opened IndexedDB.
 */
function loadServiceWorker({ fetchImpl } = {}) {
    const listeners = {};
    const calls = { databaseOpens: 0 };
    const sandbox = {
        URL,
        Response,
        Promise,
        console,
        indexedDB: {
            open: () => {
                calls.databaseOpens += 1;
                throw new Error('no IndexedDB in this sandbox');
            },
        },
        caches: {
            match: async () => undefined,
            open: async () => ({ put: async () => undefined, addAll: async () => undefined }),
            keys: async () => [],
            delete: async () => true,
        },
        fetch: fetchImpl ?? (async () => new Response('ok', { status: 200 })),
    };
    sandbox.self = {
        location: { origin: 'https://doc.test' },
        addEventListener: (type, fn) => {
            listeners[type] = fn;
        },
        skipWaiting: () => {},
        clients: { claim: async () => {} },
        registration: {},
    };

    vm.runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), sandbox);

    return { listeners, calls };
}

function fetchEvent(method, path, mode = 'cors') {
    const event = {
        responded: false,
        response: null,
        request: {
            method,
            mode,
            url: `https://doc.test${path}`,
            clone() {
                return this;
            },
            text: async () => '{}',
        },
        respondWith(promise) {
            event.responded = true;
            event.response = Promise.resolve(promise);
            event.response.catch(() => {});
        },
    };

    return event;
}

test('the sync poll is not intercepted', () => {
    const event = fetchEvent('POST', '/documents/abc/sync');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, false);
});

test('the unload beacon is not intercepted', () => {
    const event = fetchEvent('POST', '/documents/abc/autosave');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, false);
});

test('an offline Livewire POST fails as a network error, and nothing is queued for later', async () => {
    const worker = loadServiceWorker({
        fetchImpl: async () => {
            throw new TypeError('Failed to fetch');
        },
    });
    const event = fetchEvent('POST', '/livewire/update');
    worker.listeners.fetch(event);

    assert.equal(event.responded, true);

    // A network error, which makes the page's own fetch() reject - not a
    // made-up 200 that Livewire then chokes on.
    const response = await event.response;
    assert.equal(response.type, 'error');

    // Nothing was stored for a later replay, and nothing listens for one.
    assert.equal(worker.calls.databaseOpens, 0);
    assert.equal(worker.listeners.sync, undefined);
});

test('a static asset is still served through the cache', () => {
    const event = fetchEvent('GET', '/build/assets/app-abc.js');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, true);
});

test('a page navigation still gets the network-first handler with its offline fallback', () => {
    const event = fetchEvent('GET', '/documents', 'navigate');
    loadServiceWorker().listeners.fetch(event);

    assert.equal(event.responded, true);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/sw.test.js`
Expected: 3 fail, 2 pass. The two "not intercepted" tests fail (`responded` is true); the offline Livewire test fails on `response.type` (it is `'default'`: the made-up 200); the static-asset test and the navigation test pass.

- [ ] **Step 3: Add the guard**

In `public/sw.js`, inside the `fetch` listener, directly after the Livewire block's closing brace (the block that ends with `return;` after `event.respondWith(handleLivewirePost(request));`), add:

```js
    // Every other request that is not a GET goes to the network untouched.
    // The branches below are for pages and static files; the editor's sync
    // poll and its unload beacon are POSTs, the Cache API cannot store a
    // POST, and running them through "cache first" only produced a rejected
    // cache.put() on every call. A form post is not answered from the cache
    // either.
    if (request.method !== 'GET') return;
```

- [ ] **Step 3b: Fail an offline Livewire request, and delete the queue**

Five edits, all in `public/sw.js`.

1. Replace the comment at the top of the file with:

```js
/**
 * Dot.Docs Service Worker
 * - Caches static assets for offline shell
 * - Passes Livewire requests straight to the network. It does NOT keep a
 *   failed save for later: see handleLivewirePost().
 */
```

2. Delete two constants: `const OFFLINE_DB  = 'dotdocs-offline';` and `const SYNC_TAG    = 'dotdocs-sync-saves';`. Keep `CACHE_NAME`.

3. In the `fetch` listener, replace the comment `// Livewire AJAX / document save POSTs — queue offline` with:

```js
    // Livewire requests — to the network; offline they fail (see below)
```

4. Replace the whole section that begins with the comment `// ── Handle Livewire POSTs offline ──` and its `handleLivewirePost()` function, AND the whole section after it that begins `// ── Background sync: replay queued saves ──` (the `sync` listener and `replayQueuedSaves()`), with:

```js
// ── Livewire requests ─────────────────────────────────────────────────────────
// Straight to the network. When the network is down the page gets what a
// browser with no service worker would give it: a failed request.
//
// This used to store the request in IndexedDB, answer the page with a
// made-up `{effects: [], components: []}` body, and send the stored request
// again when the connection came back. Neither half worked. Livewire cannot
// read that body: it throws, never releases the request, and every later
// action on the page - saving included - stays pending until reload. And a
// replayed save is a whole-document copy from before the connection dropped,
// sent behind the page's back. Text typed offline is protected by the
// offline draft (resources/js/offline.js); the editor page sends it itself
// once it is back online.
async function handleLivewirePost(request) {
    try {
        return await fetch(request.clone());
    } catch (_) {
        return Response.error();
    }
}
```

5. At the end of the file, delete everything from the one-line comment that begins `// ── IndexedDB helpers` to the end of the file: the three functions `openDb()`, `enqueueOfflineSave()` and `dequeueAllOfflineSaves()`. Nothing calls any of them any more, and a function nobody calls cannot change anybody's database: `resources/js/offline.js` has its own `openDb()`, which creates both stores. In their place, as the last lines of the file, put only this comment:

```js
// ── IndexedDB ─────────────────────────────────────────────────────────────────
// This worker no longer uses IndexedDB. The `dotdocs-offline` database and its
// layout belong to resources/js/offline.js. Its `saves` store held the save
// queue that has been removed from this file; offline.js still creates it,
// only so that no browser needs a schema upgrade.
```

When the five edits are done, `grep -n "SYNC_TAG\|OFFLINE_DB\|openDb\|indexedDB\|enqueueOfflineSave\|dequeueAllOfflineSaves\|replayQueuedSaves\|addEventListener('sync'" public/sw.js` prints nothing.

Known leftover, not fixed here: a browser in which the OLD worker queued a save while offline still holds that request (a whole Livewire request body, the document included) in the `saves` store, and nothing reads or clears that store any more. Clearing it needs IndexedDB code in `resources/js/offline.js` that `npm test` cannot reach (the tests have no IndexedDB), so it is left for the owner to decide; it is listed under "Before this goes live".

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/sw.test.js`
Expected: PASS (5 tests).

Run: `node --check public/sw.js && npm test`
Expected: no syntax error, and every JavaScript test passes.

- [ ] **Step 4b: Check offline typing in a browser**

The test above runs the worker in a sandbox; only a browser runs it for real. With the dev server from Task 8 Step 12 running, open a document, then reload the page once so the new `sw.js` is the one in control (in the browser's developer tools, Application → Service Workers shows it activated). Then:

1. In the developer tools' Network panel choose "Offline". Type a sentence. The status strip shows "Offline".
2. Switch back to "No throttling". Within about half a minute the status returns to "Saved" without a reload. (The save that was sent while offline never answers; the page waits 15 seconds for it and then sends the text again on its next poll.)
3. Reload the page: the sentence is there, and no "unsaved offline draft" question appears.

If step 2 never reaches "Saved" and other actions on the page hang too, the old worker is still in control: unregister it in Application → Service Workers and repeat.

- [ ] **Step 5: Commit**

```bash
git add public/sw.js tests/js/sw.test.js
git commit -m "fix(sw): leave non-GET requests alone; stop queueing Livewire saves

The editor's sync poll and unload beacon are POSTs. The service worker
ran them through its cache-first branch for static files, which cannot
store a POST. They now go to the network untouched.

An offline Livewire request now fails as a network error. The worker
used to answer it with a made-up body that froze Livewire on the page
until reload, and to replay the stored request later behind the page's
back. Both are gone, and with them the worker's IndexedDB code; the
offline draft is what protects text typed offline, and the page
re-sends it when it is back online.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Task 10: Bring the standing rules up to date and verify everything

**Files:**
- Modify: `.ai/rules/app.md`, `.ai/rules/editor.md`, `.ai/rules/livewire.md`, `.ai/rules/notifications.md`, `.ai/rules/views.md`, `.ai/rules/index.md`
- Modify: `wiki.md` (the events table in section 5)

**Interfaces:** none.

- [ ] **Step 1: `.ai/rules/app.md` — add two sections at the end**

```markdown

## A save states the version it was based on, and a stale one is refused
`DocumentStore::save()` takes `['expectedVersion' => int]`. When it is given and the stored document has moved on it throws `App\Documents\StaleDocumentException` (carrying `currentVersion`) and writes nothing. Both editor save paths state it: `Editor::saveContent($content, $baseVersion, $overwrite)` (returns `{ok, conflict, version}`; a null base is refused with a "reload" error, because it comes from a tab running old JavaScript) and the unload beacon (`base_version`, answers 409 when stale). Before this, two people with a document open each replaced the other's work with a stale whole-document copy on every autosave, silently. Writers that replace the document on purpose - restore, import, an accepted suggestion, a style change - state no base and always go through; do not add `expectedVersion` to those. The new version is `current + 1` where `current` is read from the database INSIDE the transaction (`lockForUpdate()`; SQLite's IMMEDIATE transaction mode takes the write lock on BEGIN), never the model's own copy, which was loaded when the request began. The `on_save` webhook fires after the transaction commits: it is an HTTP call to somebody else's server and must not hold the write lock or announce a save that rolls back. `$overwrite` is the editor page's "Keep mine" or "Put it back" choice - a writer knowingly saving over a newer version that their base is on. The person who wrote that version is not asked and is not told, so `saveContent()` passes `['keepReplacedAs' => 'Before <name> kept their version']` and `DocumentStore::save()` cuts a `named` version of the STORED document with that label INSIDE the save's transaction, after the stale check: the replaced text must stay in the history, because the save that wrote it may have cut no version of its own. It is cut there and nowhere else. Cutting it from the component before calling `save()` ran outside the transaction and left a stray version behind every overwrite that was then refused (content the schema rejects, or a document that moved on in between). Covered by `tests/Feature/Documents/DocumentStoreVersionGuardTest.php` and `EditorSaveBaseVersionTest.php`.

## Presence is one row per open tab, refreshed by the sync poll
`App\Services\PresenceService` reads and writes `document_presences` (query builder, no model): `touch()` at most once every 5 seconds per tab, `leave()` on the unload beacon, `members()` for the strip (distinct users seen in the last 30 seconds) and `others()` (other live TABS, which is what decides how fast a tab polls - another tab of the same account counts). `touch()` writes with ONE `upsert` statement; `updateOrInsert()` is two (exists, then insert), and two requests for the same new tab arriving together would collide on the unique index. It replaced a single cache entry per document rewritten read-modify-write on a 60 second Livewire heartbeat, which lost concurrent updates and could not tell two tabs of one account apart. `App\Http\Controllers\DocumentSyncController` is the only writer. A quiet poll there selects six small columns of `documents` and never the content - `DocumentSyncTest::test_a_quiet_poll_never_loads_the_document_content` fails if that changes. When the caller is behind, the same response carries the document, its outline and its canvas stylesheet (`css`), because the style may be what changed. The request's optional integer `protocol` is accepted and not yet acted on; it exists so a later phase can tell tabs running old JavaScript to reload. `Editor::heartbeat()` and `Editor::leaving()` are EMPTY public methods kept only because a tab opened before the sync poll shipped still calls them (every 60 seconds, and on unload), and a missing method answers with an error page; remove them one release later.
```

- [ ] **Step 2: `.ai/rules/editor.md` — replace the "Remote updates go through applyRemote()" section, and six smaller edits**

Replace the heading `## Remote updates go through applyRemote(), which VALIDATES BEFORE it cancels the pending save` and its paragraph with:

```markdown
## Other people's saves arrive through the sync engine, and applyRemote() is a minimal, non-destructive change
There is no websocket in production, so an open editor polls. `resources/js/editor/sync/engine.js` (no DOM, fetch or timers of its own; all injected, tested in `tests/js/sync.engine.test.js`) asks `POST /documents/{uuid}/sync` for the current version: every 1.5 s while somebody else has the document open, every 10 s alone, not at all while the tab is hidden. The Blade bridge gives it a host with four states - `clean`, `busy` (a save in flight), `dirty` (unsaved typing or an unresolved conflict) and `closed` (fail-closed) - and it hands a newer document to the editor ONLY in `clean`. In `dirty` it reports a conflict: saving is suspended and the writer chooses "Keep mine" (save over the newer version, with the base moved up to it; the server keeps the replaced version in the history) or "Load theirs" (park the local text as a `stale-` draft and hold it in the bridge's `setAside`, then apply). "Put it back" restores what `setAside` holds, and the save that follows goes as an overwrite exactly like Keep mine (`overwriteOwed`), because it too replaces a version on purpose: the one this tab loaded. Without the flag the replaced version was kept in the history only if `DocumentStore::shouldCut()` happened to cut one. One choice at a time: `keepMine()` and `loadTheirs()` do nothing while `syncState()` is `busy`, or Load theirs pressed while a Keep mine save is in the air leaves this tab showing one version and claiming the other as its base. Apart from `applySuggestion()` (a document this page itself just had stored), the engine is the only thing that applies a server document; an Echo `.document.updated` broadcast, when a socket exists, just calls `poke()`. The engine tracks `seen` (newest version downloaded), which is not the same as the bridge's `baseVersion` (what the editor's content is based on, sent with every save) - keeping them apart is what stops an unresolved conflict re-downloading the document on every poll; `refetch(version)` is how the bridge asks for a document the engine has already seen and no longer holds. The engine stops for good on 401, 419, 403 or 404 ONLY when the response has a JSON body, which is the application answering; the same status with no JSON body is a firewall or rate limiter in front of it and is retried with back-off. A document the editor cannot open is reported once through `onRefused` and polling goes on. A host callback that THROWS must not end the loop either (the next poll would never be armed, and the tab would stop following and drop out of presence with nothing on screen to say so): a throw from `applyRemote` is treated as a refusal, and anything thrown during a poll is swallowed before the next poll is armed.

The bridge decides `dirty` from its own `unsaved` flag, not only from `handle.pending`: the bundle stops reporting pending the moment it HANDS a document to `persist()`, long before the server has stored it, so a save that never answers (offline, a 500 and a 419 all leave the `$wire` promise pending for ever) or is rejected would otherwise read as `clean` and the next remote document would replace text that exists nowhere else. `unsaved` is set on every local `update` and cleared in three places only: when `clearDraftIfSettled()` confirms a save of exactly what the editor holds, when a server document has been applied, and when `syncState()` finds the editor back at exactly what the server last confirmed. That last one needs the bridge's `confirmed` (the document as a JSON string: as the page opened it, as the last accepted save stored it, or as the last server document left it): an edit undone again inside the bundle's 1.2 s debounce never reaches `persist()`, because the bundle finds nothing to send, so nothing else would ever clear the flag - the tab would stop following for good and raise a conflict over text it does not hold. `persist()` keeps ONE save in the air: Livewire sends a second call after the first with the arguments it was CALLED with, so a second autosave would state the old base and the tab would conflict with itself; a save asked for meanwhile is remembered in `resave` and sent by `resendIfOwed()`, which also re-sends a save that has not answered in 15 seconds and is what the `app-online` listener calls - it never saves a copy the writer has not touched. `adoptVersion()` (after this page's own style change) moves the base up only when the new version is exactly one past it; anything else means somebody else saved first, and adopting the version would make the next save erase their text.

`handle.applyRemote(json, {force})` replaces only the range that differs (`sync/narrow.js` `diffRange()`, built on `Fragment.findDiffStart/findDiffEnd`), in one transaction tagged `addToHistory: false` (a collaborator's paragraph is not something YOU can undo) and `preventUpdate: true` (so TipTap does not emit `update` and arm the autosave, which would send the document straight back). The transaction itself is built by `remoteTransaction(state, json, ReplaceStep)` in `sync/apply.js`, which imports nothing from the editor bundle so that `tests/js/sync.apply.test.js` runs every branch of it; `applyRemote()` keeps the guards, the metas, the dispatch and the autosave bookkeeping, and nothing under `tests/js` can load it - change what an apply DOES in `apply.js`, with a test. A document that does not end in a paragraph gets the editor's own empty trailing paragraph: TipTap's TrailingNode appends it inside the apply's own dispatch (where `preventUpdate` keeps it out of the autosave), `remoteTransaction()` carries it over on every later apply instead of removing and re-adding it, and it is saved only with the writer's next edit. Do NOT tag the transaction `skipTrailingNode`: that only postpones the paragraph to the next transaction of any kind (the heading-number plugin dispatches one after every apply), where it arrives with a fresh id, fires `update` and arms the autosave, so every following tab saves its own trailing paragraph and the tabs conflict although nobody typed. The range is applied as a `ReplaceStep`, NOT `tr.replace()`: `tr.replace()` runs ProseMirror's fitter, which re-shapes an open slice around `isolating` nodes (table cells, figures, columns) and returns a DIFFERENT document without throwing. The result is checked against the target and falls back to a whole-document replace if it differs. The caret, the undo history and the page-break decorations survive for everything outside the one replaced range; when a single apply carries two separate changes, the text between them is replaced too, a caret there moves to the end of the range and local edits there can no longer be undone. It returns false without touching anything when the content is invalid, when the editor is fail-closed, or - unless `force` - when there is unsaved local typing. True means "applied", not "identical": a local repair plugin (a ragged table, a figure with no image) may still adjust what arrived and a trailing paragraph may follow it, and neither is saved from there. Compare documents as ProseMirror NODES, never as JSON: `DocumentSchema::normalise()` strips `align` when it is null and the editor emits `align: null` on every paragraph, so the two JSON forms of one document never match. Parsed nodes still differ in two places, and `remoteTransaction()` levels both before it diffs: `toc.entries` and `crossRef.label`, which `Outline::apply()` stamps into the stored document (levelled with attribute steps, or a re-stamped contents list stretches the range from the top of the document to the real change), and an empty `sectionBreak.setup` or `doc.vars`, which PHP serialises as `[]` where the editor holds `{}`.
```

In the section `## Style-bearing attrs are whitelisted on BOTH sides, and normalised before they persist`, in the paragraph beginning "paragraph/heading.align, columns.count, image.src and textStyle.color", replace `An Echo payload goes straight to setContent() and NEVER passes through parseHTML` with `A document that arrives from the sync poll goes straight to schema.nodeFromJSON() in remoteTransaction() (sync/apply.js) and NEVER passes through parseHTML`. (The conclusion of that sentence, that parseHTML alone is not a guard, still holds; only the route a remote document takes has changed.)

In the section `## The pagehide/destroy flush goes by BEACON, never Livewire`, change `POSTs `{_token, content}`` to `POSTs `{_token, content, base_version}`` and add this sentence at the end of the paragraph: `The beacon states the version the page's copy was based on (`getBaseVersion` mount option); the server answers 409 and writes nothing when somebody has saved since.`

In the section `## Draft recency is decided by VERSION...`, change the first sentence's `returns `['ok' => bool, 'version' => int]`` to `returns `['ok' => bool, 'conflict' => bool, 'version' => int]``.

In the same section's second paragraph (it begins "Clearing a draft is equally strict."), add these sentences at the end of the paragraph: `` `applyFromSync()` also clears the draft, once a newer server document has been applied: the engine applies only to a tab whose `unsaved` flag is false, so nothing unsaved is ever behind that clear. A draft the writer restores at page load is NOT cleared by the restore itself - the save that follows can be refused as a conflict - but by `persist()` once that save has settled. ``

In the section `## A draft that cannot be restored is PARKED, and parked drafts expire after 7 days`, replace `Two paths park instead of deleting: a draft whose baseVersion is behind the document (somebody saved since), and a draft the writer DECLINED` with `Three paths park instead of deleting: a draft whose baseVersion is behind the document (somebody saved since), the editor's own text when the writer chooses Load theirs in the conflict notice (loadTheirs() also holds it in the bridge's setAside, which Put it back restores), and a draft the writer DECLINED`, and in the next sentence replace `Both call window.offlineDraft.parkStaleDraft(uuid, json, baseVersion)` with `All three call window.offlineDraft.parkStaleDraft(uuid, json, baseVersion)`. Then add these sentences at the end of that paragraph: `A draft parked at page load because the document has moved on is also held in the bridge's setAside, so the status strip offers Put it back - but only when DotDoc.documentsDiffer(draft, editor.getJSON()) is true. The unload beacon leaves a draft behind every time it succeeds (the page is gone before it could clear it), and that draft says exactly what the document already says: offering it would show Your text was set aside after every tab closed in mid-sentence.`

In the section `## Draft recency is decided by VERSION...`, first paragraph, replace `it is parked under a `stale-<uuid>` key with a `console.info` explaining why.` with `it is parked under a `stale-<uuid>` key with a `console.info` explaining why, and offered back through Put it back when it differs from the document (see the parked-draft section below).`

- [ ] **Step 3: `.ai/rules/livewire.md` — the fail-closed list, and two new sections**

In the paragraph beginning "When the content check refuses a document", replace `the Echo .document.updated listener` with `the sync engine's applyFromSync() (and syncState(), which reports 'closed'), resendIfOwed(), keepMine(), loadTheirs(), putBack()`.

Then add these two sections at the end of the file:

```markdown

## Alpine re-creates the editor's data object on every render that changes the x-data string
The editor's `x-data` string contains the document's version and its JSON, and `public Document $document` is re-read from the database on every Livewire request, so the string changes on the first render after anybody saves. When Livewire morphs a changed `x-data` onto the root element, Alpine calls `destroy()` on the CURRENT data object, builds a new one and calls its `init()`. Four things follow. (1) On first load `init()` runs twice on the same object (Alpine's own call plus `x-init="init()"`), which leaves `owns` false on it; do not remove `x-init`, because `owns` being false is what stops `destroy()` destroying the editor on every save. (2) `destroy()` runs on the old object at each re-init and must not tear down anything that lives on the ELEMENT - the editor handle, the sync engine parked in `__dotdocSync` - so it returns early while `this.$el.isConnected`; only a real removal tears them down. Without that guard the engine is stopped on the first render after a save and the tab never polls again. (3) Handlers bound at first load (`persist()`, the engine's host callbacks, the window listeners, the status strip's buttons) stay on the FIRST object; each later object only runs `init()` as far as `if (!this.owns) return;`. Anything `init()` does before that line therefore runs again on every re-render, which is why the outline is seeded from `data-outline` only when `this.owns`: that attribute sits on a `wire:ignore` element and still holds the page-load outline. (4) An element Livewire ADDS on a later render - a conditional branch that comes back, such as the Saved / Ready word after a rejected save - is initialised then, and binds to the NEWEST data object, whose state never changes. An Alpine binding that must keep working (`x-show`, `x-text`, `@click`) therefore goes on an element that is rendered the same on every render; that is why the Saved / Ready word's `x-show` sits on a wrapper span outside the conditional and not on the word.

## The editor's x-data object is ONE double-quoted HTML attribute: no double quote inside it
A double-quote character anywhere inside the `x-data="{ ... }"` component, a comment included, ends the attribute at that point: the component is cut off, the rest becomes stray attributes, `x-init` is lost and the editor never mounts, while the server renders the page without complaint. Use single quotes, and in comments write the words without quotation marks. `ContextualToolbarTest::test_the_editors_alpine_component_survives_being_an_html_attribute` and `EditorSyncWiringTest` read the attribute the way a browser does and fail when it is cut short.
```

- [ ] **Step 4: `.ai/rules/notifications.md` — the events sentence**

Replace `(all four in `app/Events` do; a queued `DocumentUpdated` would hand editors a minute-old document through `applyRemote()`)` with `(both events in `app/Events` do; a queued `DocumentUpdated` would poke editors a minute late)`.

- [ ] **Step 4b: `wiki.md` — the events table**

In section `## 5. Events Emitted`, delete this row of the table:

```markdown
| `UserJoinedDocument` / `UserLeftDocument` | `document.{id}` (presence) | Collaborator opens/leaves the editor | user identity / user id |
```

In the `DocumentUpdated` row above it, replace the third cell `Live content edit propagated to other active collaborators` with `A save happened; open editors treat it as a prompt to poll` (the event still carries the content, which Phase 1 does not change, but no editor applies it any more).

In the sentence below the table replace `wired to real Livewire components (`Editor`, `CommentThread`) and `PresenceService` — not stubs.` with `wired to real Livewire components (`Editor`, `CommentThread`) — not stubs.` (`task-list.md` also names the two events; it is a historical checklist and stays as it is.)

- [ ] **Step 4c: `.ai/rules/views.md` and `.ai/rules/index.md` — the service worker's standing rule**

Task 9's decision is recorded in no rule file, and no row of `.ai/rules/index.md` covers `public/sw.js`, so the next person to edit the worker would find nothing. Three edits.

1. In `.ai/rules/views.md`, add `public/sw.js` to the `paths:` list at the top of the file, so that it reads:

```markdown
---
paths:
  - 'resources/views/**'
  - 'resources/css/**'
  - 'resources/js/**'
  - 'public/sw.js'
---
```

2. Add this section at the end of `.ai/rules/views.md`:

```markdown

## The service worker never touches a non-GET request and never keeps a save for later
`public/sw.js` caches pages and static files, and that is all. Every same-origin request that is not a GET goes to the network untouched (the editor's sync poll and its unload beacon are POSTs, and the Cache API cannot store one); a Livewire request is passed straight to the network and, offline, FAILS as a network error, exactly as it would with no service worker. Do not queue a failed Livewire request, answer it with a made-up body, or replay it later: the worker used to do all three, the made-up `{effects: [], components: []}` body made Livewire throw and never release the request (every later action on the page, saving included, hung until reload), and a replayed save is a whole-document copy from before the connection dropped, sent behind the page's back over whatever anybody saved since. Text typed offline is protected by the offline draft (`resources/js/offline.js`), and the editor page sends it itself once it is back online (`resendIfOwed()`). The worker does not use IndexedDB at all; the `dotdocs-offline` database belongs to `offline.js`. Covered by `tests/js/sw.test.js`.
```

3. In `.ai/rules/index.md`, replace the row

```markdown
| resources/views/**, resources/css/**, resources/js/** | .ai/rules/views.md |
```

with

```markdown
| resources/views/**, resources/css/**, resources/js/**, public/sw.js | .ai/rules/views.md |
```

- [ ] **Step 5: Run everything**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
npm test
npm run build
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: every PHPUnit test passes (4 pre-existing skips), every JavaScript test passes, the build succeeds, PHPStan reports no errors.

- [ ] **Step 6: Commit and open the pull request**

```bash
git add .ai/rules wiki.md
git commit -m "docs(rules): saves state a base version; the sync engine follows other people's saves

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git push -u origin coediting-phase-1
```

Open the pull request with `gh pr create`. Its description must state, in this order: what the owner will see; the limits (simultaneous typing shows a notice with two choices until Phase 2; the person whose text is replaced by "Keep mine" or "Put it back" is not told, though the text is kept in the history; a save whose answer is lost on the way back shows the conflict notice against the writer's own save; words typed in the last second before closing a tab while a save is in the air are set aside and offered back on the next open, not saved; the service worker no longer queues saves made offline); the browser cases from Task 8 Step 12 and Task 9 Step 4b with which were run and their result; and the "Before this goes live" list below. End the description with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Do not merge it.

---

## Before this goes live

Not tasks: things the owner does or decides, in this order.

1. **Check that the host lets the poll through.** This is the roadmap's last Phase 0 server check, and nothing above does it: confirm the production host does not block one POST every 1.5 seconds per open tab from one address (ModSecurity, Imunify360 and LiteSpeed per-address limits are common on shared cPanel hosting, and two colleagues behind one office connection look like one address). If it does, raise `FAST_MS` in `resources/js/editor/sync/engine.js` before merging. A blocked poll does not stop the editor - the engine only stops when the application itself answers, and backs off otherwise - but following then runs at the back-off pace.
2. **Check the production broadcast setting.** On the server, in `/home/infodotc/doc.infodot.co.za/doc`: `grep -E '^(BROADCAST_CONNECTION|REVERB_HOST)=' .env`. If `BROADCAST_CONNECTION` is `reverb`, every save and every comment waits up to 2 seconds for a publish that cannot succeed, and from this release each such save also writes the failure to the log; it should be `null` until a socket exists (roadmap Phase 6).
3. **Merging deploys.** The deploy runs `php artisan migrate --force`, which creates `document_presences`.
4. **Tabs left open across the deploy** are running the old JavaScript: their saves state no base version and are refused with "This page is out of date. Reload it to keep editing." Their typing stays in the offline draft. Tell anyone mid-edit to reload. Those tabs also keep calling `heartbeat()` every 60 seconds and `leaving()` when they close; that is why both are kept as empty methods (Task 4), so the calls get an empty answer instead of an error page. Remove the two methods one release later.
5. **Test on production with two accounts**: cases 1, 4, 6 and 7 from Task 8 Step 12.
6. **Known limit until Phase 2:** two people typing at the same moment get the conflict notice. One person typing while others read is the case this phase makes good.
7. **A left-open tab stays signed in.** An editor tab left open and visible keeps its session alive for as long as it is open: the poll counts as activity, so the idle timeout now only applies to hidden tabs. If the idle timeout matters, say so, and Phase 5 can exclude the sync route from session renewal.
8. **Saves the old service worker queued stay in browsers.** A browser in which the old worker queued a save while offline still holds that request, the document's content included, in the `saves` store of its `dotdocs-offline` IndexedDB database. Nothing reads that store any more and nothing clears it. Decide whether a later change should empty it once (in `initOfflineSupport()` in `resources/js/offline.js`, next to `purgeStaleDrafts()`); this plan does not, because that code cannot be tested under `npm test`.
