# Dot.Doc "Fair Copy" Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Dot.Doc's "Two Inks on a Desk" chrome (control-room language: lamps, readouts, ledgers, status line) with "Fair Copy" — a calm, document-first visual language — without touching document typography, editor JS behaviour, print/export, or the shared file-tree data model.

**Architecture:** A token and structural-rule rewrite in `resources/css/shell.css`, a new topbar replacing the status line, a `status-word` component replacing every `<x-shell.lamp>` call site, a contextual (selection-driven) toolbar replacing the always-visible multi-row editor toolbar, and one new `LocationPicker` Livewire component backing a smart document-creation flow. Every existing Livewire component's PHP logic is untouched; this is a CSS/Blade/one-new-component phase.

**Tech Stack:** Laravel 13 Blade components, Tailwind utility classes plus the existing hand-written token CSS pattern, Alpine (via Livewire), the existing TipTap editor bundle (JS untouched, only its rendered markup/CSS skin changes).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-11-dot-doc-fair-copy-redesign-design.md`. Read it before starting any task.
- Fonts: Fraunces (chrome display/headings), Work Sans (UI body/labels). Never Inter, Geist, Space Grotesk, Plus Jakarta Sans. Document body typography (the 14 `DocumentStyle` records) is untouched.
- Colour tokens exactly per spec §2.3 (`--ground`, `--surface`, `--surface-raised`, `--ink`, `--ink-soft`, `--line`, `--accent`, `--accent-soft`, `--danger`), day-first with a real night mode (inverted from Two Inks' night-first convention).
- No lamps, no mono-readout ledgers, no status-line bordered compartments — replaced per spec §2.4's table.
- `resources/js/editor/**` behaviour is unchanged. `editor.blade.php` stays a single root element with `<style id="doc-style">` as its first child (`.ai/rules/livewire.md`).
- No new composer/npm dependencies.
- Every dialog keeps the established Escape-closes-and-returns-focus pattern (`.ai/rules/views.md`'s existing convention — the pattern is right, only the visual skin retires).
- 346 tests (as of Task 15) must stay green; update selector-based assertions tied to retired class names/strings rather than deleting their coverage.
- Verification bar: `frontend-design` + `ui-ux-pro-max` skills invoked before layout work; Impeccable detector prints nothing on every touched page, night and day; `scripts/design/contrast-dom.mjs` (updated for new tokens) reports ALL PASS with its canary check proving the harness isn't vacuous — same discipline as Task 9.

---

### Task 1: Tokens, layout skeleton, and full page restyle

**Files:**
- Modify: `resources/css/shell.css` (token block + every structural rule — full rewrite per spec §2.3–2.4), `resources/css/paper.css` (only the editor-chrome skin: status colours, palette/bubble colours — NOT the JS-driven behaviour), `resources/views/layouts/app.blade.php` (Fraunces/Work Sans font links, day-first `<html>` class default, skip link/landmarks preserved)
- Create: `resources/views/components/shell/topbar.blade.php`, `resources/views/components/shell/status-word.blade.php`
- Delete (functionally retired — remove the file and every reference): `resources/views/components/shell/{status-line,lamp}.blade.php`
- Modify: `resources/js/shell.js` (rail/dock default to collapsed on document load; expand triggers: outline click, AI invocation, comment-sidebar toggle, attaching a file; theme toggle now flips a day-first default)
- Modify every page that currently renders `<x-shell.lamp>` or the old status-line: `resources/views/dashboard.blade.php`, `resources/views/livewire/documents/{index,editor,version-history,share-manager,document-settings,template-gallery,comment-thread,webhook-manager}.blade.php`, `resources/views/livewire/notification-bell.blade.php`, `resources/views/components/shell/{rail,dock}.blade.php` (internal skin only — same slots/props, no signature change)
- Test: `tests/Feature/Shell/ShellTest.php` (update every assertion tied to a retired string/class to its Fair Copy equivalent — do not delete coverage, retarget it), `tests/Feature/Shell/FairCopyTest.php` (new — asserts no `<x-shell.lamp` / `class="status-line"` remains anywhere rendered, asserts the new topbar/status-word markup is present, asserts rail/dock start collapsed on first load of a document)

**Interfaces:**
- Produces: `<x-shell.topbar :title :saveState :actions>` (slots: `title`, `actions` for undo/redo/share/export/avatars/profile; `saveState` prop is `['word' => string, 'tone' => 'idle'|'saving'|'saved'|'error']`, rendered as a word + dot, never a bordered lamp).
- Produces: `<x-shell.status-word tone="idle|good|danger" word="…">` — the direct replacement for every prior `<x-shell.lamp>` call (same two meaningful props, no `variant`/padding/border-right overrides needed since it carries no compartment styling).
- Consumes: nothing new from later tasks; `resources/js/editor/**`'s public bridge (`window.DotDoc.mount`, `outline`, `applyRemote`, etc.) is unchanged and this task must not alter its contract.

- [ ] **Step 1: Invoke the design skills and write the design note**

Use the `frontend-design` and `ui-ux-pro-max` skills (via the Skill tool) before writing any CSS. Apply their guidance to: the topbar's proportions, the collapsed-by-default panel treatment, and how the page canvas reads as an object with presence against `--ground`. Write `docs/design/2026-09-11-fair-copy-design-note.md` (≤ 60 lines) recording what you took from each skill and what you rejected, mirroring the note format from Task 9's `docs/design/2026-09-09-shell-design-note.md`.

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Feature\Shell;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FairCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_retired_chrome_survives_on_any_touched_page(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Fair Copy check');

        foreach ([
            route('dashboard'),
            route('documents.index'),
            route('documents.edit', $doc->uuid),
            route('documents.history', $doc->uuid),
            route('documents.share', $doc->uuid),
            route('documents.settings', $doc->uuid),
        ] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertDontSee('<x-shell.lamp', false)
                ->assertDontSee('class="status-line"', false)
                ->assertDontSee('class="lamp"', false)
                ->assertDontSee('Inter', false);
        }
    }

    public function test_the_editor_ships_a_topbar_and_starts_with_panels_collapsed(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Panels');

        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertSee('class="topbar"', false)
            ->assertSee('data-panel-state="collapsed"', false)
            ->assertSee('Fraunces', false)
            ->assertSee('Work+Sans', false);
    }

    public function test_theme_defaults_to_day(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $res = $this->actingAs($user)->get(route('dashboard'));
        $res->assertOk()->assertDontSee('class="dark"', false);
    }
}
```

- [ ] **Step 3: Run the tests to confirm they fail**

Run: `php artisan test --compact --filter=FairCopyTest`
Expected: FAIL — no topbar, no `status-word`, lamps/status-line still present.

- [ ] **Step 4: Implement the token and structural rewrite**

Rewrite `resources/css/shell.css`'s `:root`/`.dark` blocks to the spec §2.3 token table (day values on bare `:root`, night values under `:root:not([data-theme="light"])`'s `prefers-color-scheme: dark` guard and under `:root[data-theme="dark"]`, matching the existing dual-guard convention this file already established in Task 9 — only the token *values* and *which mode is bare-`:root`* change, not the guard mechanism). Replace every `.lamp`/`.lamp-word`/`.readout`/`.ledger`/`.status-line`/`.status-item` selector with the spec §2.4 replacements: `.status-word` (word + `::before` dot sized ~6px, colour from `tone`), `.topbar` (flex row, `--surface` background, one `border-bottom: 1px solid var(--line)`), panels (`.rail`, `.dock`) using padding-based separation from `--ground` instead of shared-edge hairlines, `border-radius: 8px` on interactive surfaces and `12px` on `.paper`, and `[data-panel-state="collapsed"]`/`[data-panel-state="expanded"]` width rules driving the rail/dock's collapsed-vs-expanded state (0 or an icon-rail width when collapsed, full width when expanded).

- [ ] **Step 5: Build the topbar and status-word components**

`resources/views/components/shell/topbar.blade.php`:

```blade
@props(['title' => null, 'saveState' => ['word' => 'Ready', 'tone' => 'idle']])

<header {{ $attributes->merge(['class' => 'topbar']) }}>
    <div class="topbar-title">{{ $title }}</div>
    <x-shell.status-word :tone="$saveState['tone']" :word="$saveState['word']" />
    <div class="topbar-actions">{{ $actions ?? '' }}</div>
</header>
```

`resources/views/components/shell/status-word.blade.php`:

```blade
@props(['tone' => 'idle', 'word' => ''])

<span {{ $attributes->merge(['class' => 'status-word status-word-'.$tone]) }}>
    <span class="status-word-dot" aria-hidden="true"></span>
    <span>{{ $word }}</span>
</span>
```

Update `layouts/app.blade.php`'s head with the Fraunces/Work Sans Google Fonts link (`family=Fraunces:opsz,wght@9..144,400..600&family=Work+Sans:wght@400..600`), remove the Atkinson links, default `<html>` to no `dark` class unless the `theme` cookie is explicitly `dark` (day-first — inverse of Task 9's night-first default).

- [ ] **Step 6: Replace every `<x-shell.lamp>` call site with `<x-shell.status-word>`**

Grep every page listed in Files above for `<x-shell.lamp`; replace each with `<x-shell.status-word :tone="…" word="…">` using the same two meaningful values the old call passed (drop any `style="padding:0;border-right:0"` override — `status-word` never needs it). Delete `status-line.blade.php` and `lamp.blade.php` once no reference remains (`grep -rn "x-shell.lamp\|x-shell.status-line" resources/views` returns nothing).

- [ ] **Step 7: Wire rail/dock collapsed-by-default and expand triggers**

`resources/js/shell.js`: on document-editor page load, set `data-panel-state="collapsed"` on both `.rail` and `.dock`; add expand triggers — clicking the outline toggle in the topbar expands the rail, invoking an AI command or opening comments expands the dock. Dashboard/index pages keep the rail expanded by default (there is no document canvas competing for space there) — only the editor route defaults both to collapsed.

- [ ] **Step 8: Rewrite empty-state copy**

`resources/views/livewire/documents/index.blade.php`'s empty state becomes: one sentence ("Start with an idea.") plus up to three actions (Blank document, Use a template, Create with AI — wire "Create with AI" to the existing `AiAssistant`/`freePrompt` path if a document-generation entry point exists; if it does not yet exist as a one-click action, link it to the template gallery's AI-adjacent option instead and note this in your report rather than inventing a new AI generation feature this task doesn't own).

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --compact --filter=FairCopyTest` then `php artisan test --compact --filter=ShellTest` (update its assertions to Fair Copy strings first).
Expected: PASS.

- [ ] **Step 10: Impeccable and contrast verification**

Update `scripts/design/contrast-dom.mjs`'s token map and `INVERTED` surface list for the new variable names and the day-first default; run it — ALL PASS, canary still fails when the inverted-surface rule is deleted. Render every page in Files above into `public/__design/*.html` via a throwaway test (delete both after), run Impeccable night and day on each — zero findings.

- [ ] **Step 11: Run the full suite and commit**

Run: `php artisan test --compact` (346+ must stay green), `npm run build`, `vendor/bin/pint --dirty --format agent`.

```bash
git add -A
git commit -m "feat(shell): Fair Copy tokens, topbar, status-word, and full page restyle

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Contextual toolbar and command palette

**Files:**
- Modify: `resources/js/editor/ui/bubble.js` (extend trigger conditions to image/figure, table, heading selections — not just text marks), `resources/css/paper.css` (retire the always-visible multi-row `.doc-tools` in favour of a slim persistent bar plus the floating contextual toolbar's Fair Copy skin), `resources/views/livewire/documents/editor.blade.php` (slim bar markup: document-style picker + `⌘K` trigger only in the persistent row; every block/insert/format action moves to either the contextual toolbar or stays reachable via `⌘K`/slash)
- Modify: `resources/js/editor/commands/registry.js` (add palette entries per spec §31 only where the backing action already exists: search, export, share, add comment, find/replace, summarize/rewrite/analyze, insert chart/table, open recent — using the exact existing Livewire/JS calls those features already expose, never a stub)
- Test: `tests/Feature/Documents/ContextualToolbarTest.php` (asserts the persistent bar's markup no longer contains the retired block-insert buttons), `tests/js/toolbar.test.js` (node --test — asserts `bubble.js`'s trigger-condition function returns the correct toolbar variant for a text/image/table/heading selection shape)

**Interfaces:**
- Consumes: Task 1's `.topbar`/`status-word` CSS classes (this task's slim bar sits directly under the topbar, same `--surface`/`--line` tokens).
- Produces: `bubble.js` exports `toolbarVariantFor(selectionShape): 'text'|'image'|'table'|'heading'|null` — a pure function Task 3's tests (if any touch the toolbar) can rely on by name.

- [ ] **Step 1: Write the failing JS test**

```js
const test = require('node:test');
const assert = require('node:assert/strict');
const { toolbarVariantFor } = require('../../resources/js/editor/ui/bubble.js');

test('text selection maps to the text toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'text' }), 'text');
});

test('an image or figure selection maps to the image toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'image' }), 'image');
    assert.equal(toolbarVariantFor({ type: 'figure' }), 'image');
});

test('a cell selection inside a table maps to the table toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'tableCell' }), 'table');
});

test('a heading selection maps to the heading toolbar variant', () => {
    assert.equal(toolbarVariantFor({ type: 'heading' }), 'heading');
});

test('an unrecognised or empty selection maps to no toolbar', () => {
    assert.equal(toolbarVariantFor({ type: 'paragraph' }), null);
    assert.equal(toolbarVariantFor(null), null);
});
```

- [ ] **Step 2: Run to confirm it fails**

Run: `node --test tests/js/toolbar.test.js`
Expected: FAIL — `toolbarVariantFor` not exported.

- [ ] **Step 3: Implement `toolbarVariantFor` and wire it into `bubble.js`'s existing show/hide logic**

Add the pure function and export it (`module.exports.toolbarVariantFor` alongside the existing bubble-menu init export), then use it inside the existing selection-update handler to pick which toolbar button group to render — text marks (bold/italic/link/highlight/comment, already built), image tools (replace, alt text, caption toggle), table tools (add/remove row/column, header toggle), heading tools (level, numbered toggle — call the existing `setHeadingNumbered` command from Task 8's registry).

- [ ] **Step 4: Rebuild the persistent bar markup and retire the multi-row toolbar**

In `editor.blade.php`, the persistent row becomes: document-style picker, `⌘K` trigger, save status (`<x-shell.status-word>`), version readout, presence avatars — nothing that inserts a block. Every block-insert action (heading, list, table, image, page break, callout, etc.) moves to the `/` slash menu (already built, Task 8) and to `⌘K` — neither needs new code, only the persistent-row buttons for them are removed.

- [ ] **Step 5: Add palette entries with real backing**

In `registry.js`, add entries for: `search` (dispatches to the existing `DocumentSearch`-backed search box), `export.*` (existing per-format export routes), `share` (opens `ShareManager`), `comment.add` (existing comment flow), `find`/`replace` (native browser find is already the documented fallback from Task 8 — if a real in-app find/replace doesn't exist yet, do not add this entry; note the gap in your report rather than stubbing it), `ai.summarize`/`ai.rewrite`/`ai.analyze` (existing `AiAssistant` quick passes), `insert.table`, `insert.chart` (only if a chart-insert command already exists from an earlier task — if not, omit and note it), `recent.open` (link to `documents.index` sorted by recency, which already exists).

- [ ] **Step 6: Run tests, build, commit**

Run: `node --test tests/js/*.test.js`, `php artisan test --compact --filter=ContextualToolbarTest`, then the full suite and `npm run build`.

```bash
git add -A
git commit -m "feat(editor): contextual toolbar by selection type, slim persistent bar, palette entries with real backing

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Smart Save / LocationPicker, responsive collapse, and final verification sweep

**Files:**
- Create: `app/Livewire/Documents/LocationPicker.php` + `resources/views/livewire/documents/location-picker.blade.php`
- Modify: `app/Livewire/Documents/Index.php` (the "New document" action opens the smart-save sheet instead of the current folder-first flow), `resources/views/livewire/documents/index.blade.php` (the sheet markup: name, template/type picker reusing `TemplateGallery` data, embedded `<livewire:documents.location-picker>`)
- Modify: `resources/css/shell.css` (the `<900px` overlay behaviour for rail/dock per spec §6)
- Modify: `.ai/rules/views.md` (replace its Two Inks content with the Fair Copy tokens, structural rules, and the collapsed-by-default/expand-trigger convention)
- Test: `tests/Feature/Documents/LocationPickerTest.php`, `tests/Feature/Documents/SmartSaveTest.php`

**Interfaces:**
- Consumes: `App\Files\FilesService` (Task 14 — `children()`, `createFolder()`), `App\Livewire\Files\BrowsesTheTree` trait (Task 14 — `folderOrRoot()`, `workspaceTeam()`, `guarded()`).
- Produces: `LocationPicker::resolve(): \App\Models\Files\Obj` — the single public method a later phase (Phase 3) will extend with recents/favourites without changing its call sites. `Index`'s new-document flow calls `app(DocumentStore::class)->create($user, $name, $templateJson, $attrs, $picker->resolve())` (the 5th `$parent` argument `DocumentStore::create()` already accepts, from Task 14).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Documents;

use App\Files\FilesService;
use App\Livewire\Documents\LocationPicker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocationPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_the_current_folder_and_resolves_to_its_obj(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $root = app(FilesService::class)->root($team);
        $reports = app(FilesService::class)->createFolder($root, 'Reports', $user);

        $component = Livewire::actingAs($user)->test(LocationPicker::class, ['currentFolderId' => $reports->id]);
        $component->assertSet('selectedId', $reports->id);

        $this->assertSame($reports->id, $component->instance()->resolve()->id);
    }

    public function test_switching_folders_updates_the_resolved_location(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $root = app(FilesService::class)->root($team);
        $drafts = app(FilesService::class)->createFolder($root, 'Drafts', $user);

        $component = Livewire::actingAs($user)->test(LocationPicker::class, ['currentFolderId' => $root->id]);
        $component->call('selectFolder', $drafts->id);

        $this->assertSame($drafts->id, $component->instance()->resolve()->id);
    }

    public function test_a_folder_outside_the_users_team_cannot_be_selected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $strangersRoot = app(FilesService::class)->root($stranger->currentTeam);

        Livewire::actingAs($user)->test(LocationPicker::class, ['currentFolderId' => null])
            ->call('selectFolder', $strangersRoot->id)
            ->assertForbidden();
    }
}
```

```php
<?php

namespace Tests\Feature\Documents;

use App\Files\FilesService;
use App\Livewire\Documents\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmartSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_document_from_the_smart_save_sheet_files_it_at_the_chosen_location(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->currentTeam);
        $reports = app(FilesService::class)->createFolder($root, 'Reports', $user);

        Livewire::actingAs($user)->test(Index::class)
            ->set('newDocumentName', 'Q3 Update')
            ->call('openSmartSave')
            ->set('newDocumentFolderId', $reports->id)
            ->call('createDocumentAtLocation')
            ->assertRedirect();

        $doc = \App\Models\Document::where('title', 'Q3 Update')->firstOrFail();
        $this->assertSame($reports->id, $doc->node()->parent_id);
    }

    public function test_the_smart_save_sheet_defaults_to_the_folder_the_user_is_currently_viewing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->currentTeam);
        $drafts = app(FilesService::class)->createFolder($root, 'Drafts', $user);

        Livewire::actingAs($user)->test(Index::class, ['folderId' => $drafts->id])
            ->call('openSmartSave')
            ->assertSet('newDocumentFolderId', $drafts->id);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --compact --filter=LocationPickerTest` and `--filter=SmartSaveTest`
Expected: FAIL — class/methods don't exist.

- [ ] **Step 3: Implement `LocationPicker`**

```php
<?php

namespace App\Livewire\Documents;

use App\Files\FilesService;
use App\Models\Files\Obj;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

class LocationPicker extends Component
{
    use AuthorizesRequests;

    public ?int $currentFolderId = null;

    public ?int $selectedId = null;

    public function mount(?int $currentFolderId = null): void
    {
        $this->currentFolderId = $currentFolderId;
        $this->selectedId = $currentFolderId ?? app(FilesService::class)
            ->root(auth()->user()->currentTeam)->id;
    }

    public function selectFolder(int $objId): void
    {
        $obj = Obj::findOrFail($objId);
        $this->authorize('view', $obj);
        $this->selectedId = $obj->id;
    }

    public function resolve(): Obj
    {
        return Obj::findOrFail($this->selectedId);
    }

    public function render()
    {
        $current = Obj::find($this->selectedId);
        $children = $current
            ? app(FilesService::class)->children($current)->filter(fn ($o) => $o->isFolder())
            : collect();

        return view('livewire.documents.location-picker', [
            'current' => $current,
            'folders' => $children,
        ]);
    }
}
```

Wire `Index::openSmartSave()`/`createDocumentAtLocation()`: the former sets `showSmartSave = true` and reads the current `folderOrRoot()` (from `BrowsesTheTree`) into a public `newDocumentFolderId`; the latter validates a name, resolves the `Obj` via a nested `LocationPicker` instance's `resolve()` (or re-derives it directly with `Obj::findOrFail($this->newDocumentFolderId)` guarded by the same `ObjPolicy::view` check), and calls `DocumentStore::create($user, $name, null, [], $obj)`, then redirects to the new document's edit route.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=LocationPickerTest --filter=SmartSaveTest`
Expected: PASS.

- [ ] **Step 5: Responsive collapse**

In `shell.css`, add a `@media (max-width: 900px)` block turning `.rail`/`.dock` into full-screen overlays (`position: fixed; inset: 0`) triggered by the same expand controls from Task 1, with a close affordance; the floating contextual toolbar (Task 2) becomes a bottom sheet at the same breakpoint, reusing its existing mobile fallback scaffolding from Task 9's CSS.

- [ ] **Step 6: Rewrite `.ai/rules/views.md`**

Replace its Two Inks content with: the Fair Copy token table, the structural-rules table (§2.4), the collapsed-by-default/expand-trigger rule, the `status-word` replacement-for-`lamp` note, and the same rendered-pair contrast verification method (method itself is unchanged from Task 9, only the token names it checks are updated).

- [ ] **Step 7: Full-suite Impeccable/contrast sweep across every page touched by Tasks 1–3**

Re-run the Task 1 Step 10 procedure across the full page list plus `location-picker.blade.php`'s rendered sheet, both breakpoints (desktop and the new <900px overlay), night and day. Zero findings, all contrast pairs pass.

- [ ] **Step 8: Full suite, build, commit**

```bash
php artisan test --compact
npm run build
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(files): smart save with a location picker, responsive panel overlays, Fair Copy rule file

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review

**Spec coverage:** §2 (tokens/typography/structural rules) → Task 1. §3 (layout, topbar, collapsed panels) → Task 1. §4 (contextual toolbar) → Task 2. §5 (empty states, smart save, LocationPicker seam) → Task 1 (empty states) + Task 3 (smart save/LocationPicker). §6 (responsive) → Task 3. §7 (what doesn't change) → honoured by every task's Files list (editor JS, DocumentStyle engine, print, One Tree model all absent from every task's Modify list). §8 (verification) → each task's final steps plus Task 3's full sweep. §9 (deferral) → not built here, correctly out of every task.

**Placeholder scan:** none of the banned phrases; every code step has real code or an explicit "if the backing action doesn't exist, omit and note it" instruction rather than a silent stub.

**Type consistency:** `LocationPicker::resolve(): Obj` used identically in its own class and in `Index`'s wiring description; `DocumentStore::create()`'s 5th parameter matches its Task 14 signature exactly; `toolbarVariantFor()` name and return shape match between its test and its implementation step.
