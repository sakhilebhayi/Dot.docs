# A4 Pagination Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Dot.Doc's editor a real A4 page experience — computed page breaks, header/footer bands with live page numbers, and six page-view modes — while the underlying ProseMirror document stays one continuous, always-editable flow.

**Architecture:** A debounced measurement pass walks the top-level blocks of `.paper` in the live DOM, a pure algorithm (`measure.js`) decides where pages break (block-level only — headings/figures/tables/callouts never orphaned; tables and lists split between rows/items with header-row repetition; a single stranded paragraph line is possible in v1), and a ProseMirror plugin renders the result as non-editable **widget decorations** (gap, shadow, header/footer bands) inserted at computed positions — never written into the document JSON. Header/footer template substitution is extracted from `PrintRenderer` into a shared `HeaderFooterBands` class so the live view and the PDF export use the exact same field-injection-safe logic.

**Tech Stack:** ProseMirror decorations (via `@tiptap/core`'s underlying `prosemirror-view`/`prosemirror-state`), the existing TipTap editor bundle, Laravel/Livewire (`Editor::outline()`), dompdf (unchanged).

## Global Constraints

- **Print engine stays dompdf.** The live view and the exported PDF are two different rendering engines; this phase does not attempt pixel parity (design spec, "Three decisions" #1).
- **v1 is block-level pagination only.** No sub-paragraph line-pairing (widow/orphan) guarantee; no mid-list-item or mid-columns splitting; no reorder/duplicate/delete of a computed page (design spec, "Three decisions" #2–3, §7).
- **No schema changes.** `pageBreak` and `sectionBreak` already exist in `App\Documents\Schema\DocumentSchema`; this phase reads and renders them, it does not add node types.
- **No new npm/composer dependencies.**
- Every TipTap node name this phase touches must already exist in `App\Documents\Schema\DocumentSchema` (.ai/rules/editor.md) — this plan adds no new node types, so that rule is inherited, not exercised.
- Every JS module that must run under `node --test` (measure.js) is dependency-free, mirroring `resources/js/editor/ui/toolbarVariant.js`, `attrs.js`, `guards.js`, `validation.js` (.ai/rules/editor.md).
- All document content writes still go through `App\Documents\DocumentStore` (.ai/rules/app.md) — this phase writes no content at all; decorations are display-only and never serialised.
- Chrome colour never appears as a literal; the pagination gap/shadow CSS uses `--ground`/`--line`/existing tokens only (.ai/rules/views.md).
- `npm test` runs `node --test 'tests/js/*.test.js'`; PHP tests run via `php artisan test --compact`.

---

## 1. File structure

| File | Responsibility |
|---|---|
| `app/Print/HeaderFooterBands.php` (new) | Pure template→segments splitter, extracted from `PrintRenderer::band()`. The one place `{{ page }}`/`{{ pages }}` are recognised as fields; every other `{{ key }}` is always literal text. |
| `app/Print/PrintRenderer.php` (modified) | `band()` rewritten to call `HeaderFooterBands::segments()` and reassemble its existing `{html, pageText}` shape — behaviour unchanged, verified by the existing `PrintRendererTest` suite. |
| `app/Livewire/Documents/Editor.php` (modified) | `outline()` response gains `pageSetup`, `headerSegments`, `footerSegments`. |
| `resources/js/editor/pagination/measure.js` (new) | Pure block-level pagination algorithm. Zero DOM access. |
| `resources/js/editor/pagination/decorations.js` (new) | Measures the live DOM into `measure.js`'s input shape, maps its output (`{blockIndex, offset}`) to real ProseMirror positions, and owns the `DecorationSet`/ProseMirror `Plugin`. |
| `resources/js/editor/pagination/bands.js` (new) | Renders one header or footer band's DOM from server-supplied segments plus a live `{page, pages}` pair. `textContent` only — never `innerHTML` — so no escaping contract has to be re-derived client-side. |
| `resources/js/editor/pagination/viewModes.js` (new) | CSS class + scroll-behaviour switching for the six view modes; owns the scaled-clone thumbnail renderer shared by the rail panel and Multi-Page mode. |
| `resources/js/editor/pagination/index.js` (new) | Orchestrator: debounce timer, `editor.on('update')` wiring, `pageSetup`/segments store, exposes `window.DotDoc.pagination`. |
| `resources/js/editor/index.js` (modified) | Wires `pagination/index.js` into `mount()`/`destroy()`. |
| `resources/views/livewire/documents/editor.blade.php` (modified) | View-mode `<select>`, thumbnails toggle + panel, `refreshOutline()` feeds pagination, `pdfPreviewUrl` passed to `mount()`. |
| `app/Styles/CssBuilder.php` (modified) | `canvas` mode gains the gap/shadow/page-rectangle CSS the decorations render into, and hides `pageBreak`/`sectionBreak`'s own divider styling while pagination is active. |
| `tests/js/pagination.measure.test.js` (new) | `node --test` coverage for `measure.js`. |
| `tests/Feature/Print/HeaderFooterBandsTest.php` (new) | Coverage for the extracted segment splitter. |
| `tests/Feature/Documents/EditorOutlineTest.php` (new, or extended if it already exists) | Feature test for `outline()`'s new response fields. |

---

## Task 1: `HeaderFooterBands` — extract the segment splitter

**Files:**
- Create: `app/Print/HeaderFooterBands.php`
- Modify: `app/Print/PrintRenderer.php:33-38` (constructor), `app/Print/PrintRenderer.php:118-145` (`band()`)
- Test: `tests/Feature/Print/HeaderFooterBandsTest.php` (new), `tests/Feature/Print/PrintRendererTest.php` (unchanged — this is the regression baseline)

**Interfaces:**
- Produces: `HeaderFooterBands::segments(string $template, array $vars): array<int, array{type: 'text'|'field', value: string}>`. A `'field'` segment's `value` is always exactly `'PAGE'` or `'NUMPAGES'`. A `'text'` segment's `value` is the **raw, unescaped** substituted string (every non-scalar `$vars` value coerces to `''`, exactly as `PrintRenderer::band()` does today) — escaping is the caller's job, applied at the point of output, not here. Adjacent text runs are coalesced into one segment.
- Consumed by: Task 2's `Editor::outline()` (client-facing segments, rendered via `textContent` — see `bands.js` in Task 5) and this task's own rewritten `PrintRenderer::band()`.

- [ ] **Step 1: Write the failing test for `HeaderFooterBands::segments()`**

```php
<?php
// tests/Feature/Print/HeaderFooterBandsTest.php

namespace Tests\Feature\Print;

use App\Print\HeaderFooterBands;
use Tests\TestCase;

class HeaderFooterBandsTest extends TestCase
{
    private function bands(): HeaderFooterBands
    {
        return new HeaderFooterBands;
    }

    public function test_plain_text_with_no_tokens_is_one_text_segment(): void
    {
        $segments = $this->bands()->segments('Confidential', []);

        $this->assertSame([['type' => 'text', 'value' => 'Confidential']], $segments);
    }

    public function test_empty_template_produces_no_segments(): void
    {
        $this->assertSame([], $this->bands()->segments('', ['title' => 'x']));
    }

    public function test_variable_tokens_substitute_and_coalesce_with_surrounding_text(): void
    {
        $segments = $this->bands()->segments('{{ title }} — {{ team }}', ['title' => 'Monthly report', 'team' => 'Acme']);

        $this->assertSame([['type' => 'text', 'value' => 'Monthly report — Acme']], $segments);
    }

    public function test_page_and_pages_become_field_segments(): void
    {
        $segments = $this->bands()->segments('Page {{ page }} of {{ pages }}', []);

        $this->assertSame([
            ['type' => 'text', 'value' => 'Page '],
            ['type' => 'field', 'value' => 'PAGE'],
            ['type' => 'text', 'value' => ' of '],
            ['type' => 'field', 'value' => 'NUMPAGES'],
        ], $segments);
    }

    public function test_mixed_variables_and_page_fields(): void
    {
        $segments = $this->bands()->segments('{{ title }} · {{ page }}/{{ pages }}', ['title' => 'Report']);

        $this->assertSame([
            ['type' => 'text', 'value' => 'Report · '],
            ['type' => 'field', 'value' => 'PAGE'],
            ['type' => 'text', 'value' => '/'],
            ['type' => 'field', 'value' => 'NUMPAGES'],
        ], $segments);
    }

    public function test_unknown_variable_is_blank_not_left_as_a_token(): void
    {
        $segments = $this->bands()->segments('{{ nope }}', []);

        $this->assertSame([], $segments);
    }

    public function test_non_scalar_variable_value_coerces_to_empty_string(): void
    {
        $segments = $this->bands()->segments('Period: {{ period }}', ['period' => ['August', '2026']]);

        $this->assertSame([['type' => 'text', 'value' => 'Period: ']], $segments);
    }

    public function test_only_page_and_pages_are_ever_recognised_as_fields(): void
    {
        // A key that merely CONTAINS "page" must not be treated as a field —
        // the whitelist is exact-match, not a substring test.
        $segments = $this->bands()->segments('{{ pageTitle }}', ['pageTitle' => 'Q3']);

        $this->assertSame([['type' => 'text', 'value' => 'Q3']], $segments);
    }

    public function test_raw_text_is_not_html_escaped_here(): void
    {
        // Escaping is the CALLER's job (HTML-escape for PrintRenderer's html
        // output, textContent for the live JS view) — segments() must hand
        // back the literal substituted text, unescaped, or a caller that
        // needs the raw value (dompdf's page_text canvas draw) would get a
        // double-escaped string instead.
        $segments = $this->bands()->segments('{{ title }}', ['title' => 'A & B <em>'])[0];

        $this->assertSame('A & B <em>', $segments['value']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Print/HeaderFooterBandsTest.php`
Expected: FAIL — class `App\Print\HeaderFooterBands` not found.

- [ ] **Step 3: Write `HeaderFooterBands`**

```php
<?php

namespace App\Print;

/**
 * Splits a header/footer template into an ordered list of segments — the
 * ONE place `{{ page }}`/`{{ pages }}` are recognised as live page-number
 * fields; every other `{{ key }}` is always substituted-then-literal text,
 * never a field. That whitelist is the actual security boundary Task 10's
 * field-injection fix depends on (see PrintRenderer::phpStringLiteral()),
 * so this class must stay the ONLY place the distinction is made — both
 * PrintRenderer (server PDF) and Editor::outline() (live view) call this,
 * never re-parsing `{{ }}` tokens themselves.
 *
 * segments() returns RAW, unescaped text in every 'text' segment on
 * purpose: PrintRenderer's dompdf page_text() canvas draw needs the raw
 * string, its HTML band needs htmlspecialchars(), and the live JS view
 * renders every segment via `textContent` (inherently escape-safe). Each
 * consumer picks the escaping appropriate to where the text lands —
 * escaping once, here, for only one of those three destinations would be
 * wrong for the other two.
 */
class HeaderFooterBands
{
    /**
     * @param  array<string,mixed>  $vars
     * @return list<array{type: 'text'|'field', value: string}>
     */
    public function segments(string $template, array $vars): array
    {
        if ($template === '') {
            return [];
        }

        $pieces = preg_split('/(\{\{\s*\w+\s*\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];

        foreach ($pieces as $piece) {
            if (preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $piece, $m)) {
                $key = $m[1];

                if ($key === 'page') {
                    $segments[] = ['type' => 'field', 'value' => 'PAGE'];

                    continue;
                }

                if ($key === 'pages') {
                    $segments[] = ['type' => 'field', 'value' => 'NUMPAGES'];

                    continue;
                }

                $value = $vars[$key] ?? '';
                $text = is_scalar($value) ? (string) $value : '';
            } else {
                $text = $piece;
            }

            if ($text === '') {
                continue;
            }

            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last]['type'] === 'text') {
                $segments[$last]['value'] .= $text;
            } else {
                $segments[] = ['type' => 'text', 'value' => $text];
            }
        }

        return $segments;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Print/HeaderFooterBandsTest.php`
Expected: PASS (all 9 cases)

- [ ] **Step 5: Rewrite `PrintRenderer::band()` to use it, preserving exact behaviour**

In `app/Print/PrintRenderer.php`, add the dependency to the constructor:

```php
    public function __construct(
        private DocumentStore $store,
        private StyleEngine $engine,
        private Outline $outline,
        private HtmlRenderer $renderer,
        private HeaderFooterBands $bands,
    ) {}
```

(add `use App\Print\HeaderFooterBands;` — it is already in the same namespace, `App\Print`, so no `use` import is actually needed; omit it.)

Replace the `band()` method body:

```php
    /**
     * Turns a header/footer template into either a fully-substituted HTML
     * string, or — when it contains {{ page }}/{{ pages }} — the raw text
     * dompdf's page_text() canvas draw needs (with those two tokens
     * rewritten to dompdf's own {PAGE_NUM}/{PAGE_COUNT} placeholders).
     * HeaderFooterBands::segments() is the shared, field-injection-safe
     * split; this method only reassembles it into PrintRenderer's own
     * {html, pageText} shape, so a header/footer that mixes literal text
     * with a page number renders ENTIRELY via page_text() (dompdf only
     * knows the page number while it paginates the PDF, so the whole band
     * has to wait for that, not just the number itself) — exactly the
     * binary split this method already made before the extraction.
     *
     * @param  array<string,string>  $vars
     * @return array{html:string,pageText:?string}
     */
    private function band(string $template, array $vars): array
    {
        $segments = $this->bands->segments($template, $vars);

        if ($segments === []) {
            return ['html' => '', 'pageText' => null];
        }

        $hasField = collect($segments)->contains(fn (array $s) => $s['type'] === 'field');

        if ($hasField) {
            $pageText = implode('', array_map(
                fn (array $s) => $s['type'] === 'field'
                    ? ($s['value'] === 'PAGE' ? '{PAGE_NUM}' : '{PAGE_COUNT}')
                    : $s['value'],
                $segments,
            ));

            return ['html' => '', 'pageText' => $pageText];
        }

        $html = implode('', array_map(
            fn (array $s) => htmlspecialchars($s['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $segments,
        ));

        return ['html' => $html, 'pageText' => null];
    }
```

- [ ] **Step 6: Run the full PrintRenderer regression suite**

Run: `php artisan test --compact tests/Feature/Print/PrintRendererTest.php`
Expected: PASS — all 8 pre-existing cases, unchanged. This is the "Task 10's field-injection coverage applies unchanged" the design spec calls for: the escaping in `phpStringLiteral()` was never touched, and every test that exercises the `{{ page }}`/`{{ pages }}` → `page_text()` path still goes through the same code path, now via `HeaderFooterBands` underneath.

- [ ] **Step 7: Run Pint and the static analyser**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no new findings (PrintRenderer's constructor now has 5 promoted properties — Larastan does not flag constructor arity).

- [ ] **Step 8: Commit**

```bash
git add app/Print/HeaderFooterBands.php app/Print/PrintRenderer.php tests/Feature/Print/HeaderFooterBandsTest.php
git commit -m "feat(print): extract HeaderFooterBands, the shared header/footer segment splitter

PrintRenderer::band() now builds on HeaderFooterBands::segments() instead
of parsing {{ }} tokens itself. Behaviour is unchanged (PrintRendererTest
passes unmodified) - this is groundwork for Editor::outline() to expose
the same field-injection-safe segments to the live pagination view."
```

---

## Task 2: `Editor::outline()` — expose page setup and header/footer segments

**Files:**
- Modify: `app/Livewire/Documents/Editor.php:144-159` (`outline()`)
- Test: `tests/Feature/Documents/EditorOutlineTest.php` (new)

**Interfaces:**
- Consumes: `App\Print\PageSetup::fromDocument(Document, DocumentStyle): PageSetup` (unchanged), `App\Print\HeaderFooterBands::segments()` (Task 1), `App\Styles\StyleEngine::resolve(Document): DocumentStyle` (unchanged, already used by `render()` two lines below).
- Produces: `outline()`'s return array gains `pageSetup: array{size:string,orientation:string,margins:array,header:string,footer:string}` (i.e. `PageSetup::toArray()`), `headerSegments: list<array{type,value}>`, `footerSegments: list<array{type,value}>`. Consumed by Task 7's Blade bridge and by `pagination/index.js` (Task 7).

- [ ] **Step 1: Write the failing feature test**

```php
<?php
// tests/Feature/Documents/EditorOutlineTest.php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditorOutlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_outline_response_includes_page_setup_and_header_footer_segments(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, [
            'page_setup' => [
                'orientation' => 'landscape',
                'header' => '{{ title }}',
                'footer' => 'Page {{ page }} of {{ pages }}',
            ],
        ]);

        $result = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->instance()->outline();

        $this->assertSame('landscape', $result['pageSetup']['orientation']);
        $this->assertSame('A4', $result['pageSetup']['size']);
        $this->assertSame([['type' => 'text', 'value' => 'Monthly report']], $result['headerSegments']);
        $this->assertSame([
            ['type' => 'text', 'value' => 'Page '],
            ['type' => 'field', 'value' => 'PAGE'],
            ['type' => 'text', 'value' => ' of '],
            ['type' => 'field', 'value' => 'NUMPAGES'],
        ], $result['footerSegments']);
    }

    public function test_outline_still_includes_the_pre_existing_fields(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        $result = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->instance()->outline();

        $this->assertArrayHasKey('numbers', $result);
        $this->assertArrayHasKey('toc', $result);
        $this->assertArrayHasKey('figures', $result);
        $this->assertArrayHasKey('tables', $result);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Documents/EditorOutlineTest.php`
Expected: FAIL — `pageSetup` undefined array key.

- [ ] **Step 3: Extend `outline()`**

In `app/Livewire/Documents/Editor.php`, update the docblock and body:

```php
    /**
     * Heading/figure numbers, the table of contents, and the resolved page
     * shape for the document as it is currently stored. Numbering and page
     * setup are both authoritative on the server (numbering depends on the
     * style's numbering tokens, see .ai/rules/styles.md; page setup merges
     * the document's own override over its style, see App\Print\PageSetup),
     * so the editor asks for both after every save instead of computing
     * either on its own.
     *
     * `headerSegments`/`footerSegments` are pre-split by
     * App\Print\HeaderFooterBands — the SAME class PrintRenderer uses for
     * the PDF export — so the live pagination view (resources/js/editor/
     * pagination/bands.js) never re-parses a `{{ }}` template itself. Each
     * segment is either literal text (already fully substituted) or one of
     * the two live fields ('PAGE'/'NUMPAGES'), which the client fills in
     * per page from its own computed page index and total.
     *
     * `figures` and `tables` are what the cross-reference picker offers
     * besides headings - a figure or a table is referenced by its number and
     * found by its caption, so both travel together.
     *
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
    public function outline(): array
    {
        // Called straight from JS on every save round trip, so it carries its
        // own authorisation rather than trusting mount()'s.
        $this->authorize('view', $this->document);

        $style = $this->document->resolvedStyle() ?? DocumentStyle::resolve('report');
        $result = app(Outline::class)->build($this->document->content_json ?? [], $style?->tokens['numbering'] ?? []);

        // PageSetup::fromDocument() requires a non-null DocumentStyle;
        // StyleEngine::resolve() is the guaranteed-non-null resolver
        // render() already uses two lines below in this same class, so
        // page setup and CSS are resolved from the same style either way.
        $resolvedStyle = app(StyleEngine::class)->resolve($this->document);
        $setup = PageSetup::fromDocument($this->document, $resolvedStyle);

        $vars = array_merge($this->document->variables ?? [], [
            'title' => $this->document->title,
            'date' => now()->format('Y-m-d'),
            'team' => $this->document->team?->name ?? '',
        ]);
        $bands = app(HeaderFooterBands::class);

        return [
            'numbers' => $result->numbers,
            'toc' => $result->toc,
            'figures' => $result->figures,
            'tables' => $result->tables,
            'pageSetup' => $setup->toArray(),
            'headerSegments' => $bands->segments($setup->header, $vars),
            'footerSegments' => $bands->segments($setup->footer, $vars),
        ];
    }
```

Add the two new `use` imports at the top of the file:

```php
use App\Print\HeaderFooterBands;
use App\Print\PageSetup;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Documents/EditorOutlineTest.php`
Expected: PASS

- [ ] **Step 5: Run the broader Editor test file to check for regressions**

Run: `php artisan test --compact --filter=Editor`
Expected: PASS (the `render()` call to `$this->outline()` now returns more keys; nothing in the existing suite asserts the response is a closed set of keys, so this is additive).

- [ ] **Step 6: Pint + static analysis**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no new findings.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Documents/Editor.php tests/Feature/Documents/EditorOutlineTest.php
git commit -m "feat(editor): Editor::outline() returns pageSetup and header/footer segments

Reuses App\Print\PageSetup and the new App\Print\HeaderFooterBands so the
live pagination view (Task 3+) renders header/footer bands from exactly
the same resolved page shape and field-injection-safe segments the PDF
export uses."
```

---

## Task 3: `measure.js` — the pure pagination algorithm

**Files:**
- Create: `resources/js/editor/pagination/measure.js`
- Test: `tests/js/pagination.measure.test.js` (new)

**Interfaces:**
- Produces: `computeBreaks(blocks: MeasuredBlock[], pageHeight: number): Break[]` — see the JSDoc typedefs in Step 3. This is the ONLY export Task 4 (`decorations.js`) consumes from this module.
- Zero DOM access; every number the algorithm needs is passed in, mirroring `ui/toolbarVariant.js`'s "pure decision, dependency-free" shape (.ai/rules/editor.md), so `node --test` can load it directly with no `moduleLoader.js` harness.

- [ ] **Step 1: Write the failing tests**

```js
// tests/js/pagination.measure.test.js
import assert from 'node:assert/strict';
import test from 'node:test';

import { computeBreaks } from '../../resources/js/editor/pagination/measure.js';

const PAGE = 1000;

test('a document shorter than one page produces no breaks', () => {
    const blocks = [{ type: 'paragraph', height: 100, lines: 2, lineHeight: 50 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), []);
});

test('two atomic blocks that together overflow the page break between them', () => {
    const blocks = [
        { type: 'figure', height: 600 },
        { type: 'figure', height: 600 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }]);
});

test('an atomic block bigger than a whole page is placed whole and overflows silently', () => {
    const blocks = [{ type: 'figure', height: 1400 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [], 'nothing to break AROUND when it is the only block');
});

test('a paragraph splits at a line boundary when it runs past the page', () => {
    // 12 lines of 100px = 1200px, page is 1000px -> 10 lines fit, 2 carry over.
    const blocks = [{ type: 'paragraph', height: 1200, lines: 12, lineHeight: 100 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 0, offset: 10 }]);
});

test('a paragraph split can span more than two pages', () => {
    const blocks = [{ type: 'paragraph', height: 2500, lines: 25, lineHeight: 100 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [
        { blockIndex: 0, offset: 10 },
        { blockIndex: 0, offset: 20 },
    ]);
});

test('a single line taller than a whole page is placed whole and overflows rather than looping forever', () => {
    const blocks = [{ type: 'paragraph', height: 1500, lines: 1, lineHeight: 1500 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), []);
});

test('keep-with-next: a heading with no room for one line of the next paragraph moves down', () => {
    // 900px used already; heading is 80px (fits, 20px left); the next
    // paragraph's line is 50px, which does not fit in the 20px left over ->
    // the heading itself must move to the next page.
    const blocks = [
        { type: 'paragraph', height: 900, lines: 9, lineHeight: 100 },
        { type: 'heading', height: 80 },
        { type: 'paragraph', height: 300, lines: 3, lineHeight: 100 },
    ];
    const breaks = computeBreaks(blocks, PAGE);
    assert.deepEqual(breaks, [{ blockIndex: 1, offset: 0 }]);
});

test('keep-with-next does not apply when the heading is the last block in the document', () => {
    const blocks = [
        { type: 'paragraph', height: 950, lines: 1, lineHeight: 950 },
        { type: 'heading', height: 80 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }], 'the heading itself still does not fit, so it still moves - but for overflow, not keep-with-next');
});

test('keep-with-next is skipped when a forced break immediately follows the heading', () => {
    // Heading fits with only 10px left over, and the very next block is a
    // pageBreak - the writer chose that break on purpose, so the heading is
    // not moved down to protect a line of content that was never going to
    // share the page with it anyway.
    const blocks = [
        { type: 'paragraph', height: 910, lines: 1, lineHeight: 910 },
        { type: 'heading', height: 80 },
        { type: 'pageBreak', height: 0 },
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 3, offset: 0 }]);
});

test('a table splits between rows and repeats the header on the continuation', () => {
    // header 50 + 12 rows of 100 = 1250; page is 1000 -> header(50) + 9
    // rows(900) = 950 fits, 10th row does not (would be 1050) -> breaks
    // before row index 9, continuation repeats the header.
    const blocks = [{
        type: 'table',
        height: 1250,
        headerHeight: 50,
        rowHeights: Array(12).fill(100),
    }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 0, offset: 9 }]);
});

test('a table never starts a page with only its header row visible', () => {
    // 960px already used, 40px left. A table with a 50px header would place
    // the header alone with no data row visible under it - the WHOLE table
    // must move to the next page instead.
    const blocks = [
        { type: 'paragraph', height: 960, lines: 1, lineHeight: 960 },
        { type: 'table', height: 350, headerHeight: 50, rowHeights: [100, 100, 100] },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }]);
});

test('a single table row taller than a fresh page (minus header) is placed whole rather than looping forever', () => {
    const blocks = [{ type: 'table', height: 1050, headerHeight: 50, rowHeights: [1000] }];
    assert.deepEqual(computeBreaks(blocks, PAGE), []);
});

test('a list splits between items; an individual item is atomic', () => {
    const blocks = [{
        type: 'bulletList',
        height: 1100,
        itemHeights: [200, 300, 200, 400],
    }];
    // 200+300+200 = 700 fits (300 left), the 400 item does not (would be
    // 1100) -> breaks before item index 3.
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 0, offset: 3 }]);
});

test('forced break: a pageBreak node always ends the current page, wherever it sits', () => {
    const blocks = [
        { type: 'paragraph', height: 200, lines: 2, lineHeight: 100 },
        { type: 'pageBreak', height: 0 },
        { type: 'paragraph', height: 200, lines: 2, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 2, offset: 0 }]);
});

test('a forced break with nothing left on the current page is a no-op', () => {
    const blocks = [
        { type: 'pageBreak', height: 0 },
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [], 'a break as the very first block starts nothing new');
});

test('a trailing forced break with no content after it produces no page', () => {
    const blocks = [
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
        { type: 'pageBreak', height: 0 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [], 'nothing follows the break, so no new page is needed');
});

test('a sectionBreak forces a break AND changes the usable height for what follows', () => {
    const blocks = [
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
        { type: 'sectionBreak', height: 0, newPageHeight: 400 },
        // 500 tall on a 400-tall page: breaks after 4 lines of 100.
        { type: 'paragraph', height: 500, lines: 5, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [
        { blockIndex: 2, offset: 0 },
        { blockIndex: 2, offset: 4 },
    ]);
});

test('an unrecognised node type is treated as atomic', () => {
    const blocks = [
        { type: 'paragraph', height: 900, lines: 9, lineHeight: 100 },
        { type: 'someFutureNode', height: 200 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }]);
});

test('an empty document produces no breaks', () => {
    assert.deepEqual(computeBreaks([], PAGE), []);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/js/pagination.measure.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Write `measure.js`**

```js
/**
 * Pure pagination measurement.
 *
 * Given the blocks of one document (already measured in the live DOM by
 * pagination/decorations.js) and the page's usable content height, decide
 * where each page boundary falls. Zero DOM access - every number the walk
 * needs comes in on `blocks`, the same "pure decision, dependency-free,
 * `node --test`-able" shape as ui/toolbarVariant.js (.ai/rules/editor.md).
 *
 * v1 is block-level pagination only (design spec §"Three decisions", #2):
 * a single stranded line of a long paragraph at a page edge is possible.
 * Full widow/orphan control is a named deferral (design spec §7).
 *
 * @typedef {Object} MeasuredBlock
 * @property {string} type - ProseMirror top-level node type name
 * @property {number} height - total rendered height in px
 * @property {number} [lines] - paragraph/blockquote only: wrapped line count
 * @property {number} [lineHeight] - paragraph/blockquote only: px per line
 *   (uniform within one block - a paragraph's CSS line-height is uniform by
 *   construction; per-line variation is out of scope, see design spec §7)
 * @property {number} [headerHeight] - table only: header row height in px
 * @property {number[]} [rowHeights] - table only: one entry per DATA row
 *   (the header is not in this array - it always repeats on a continuation)
 * @property {number[]} [itemHeights] - list types only: one entry per item
 * @property {number} [newPageHeight] - sectionBreak only: the usable page
 *   height every subsequent block should be measured against
 *
 * @typedef {Object} Break
 * @property {number} blockIndex - index into `blocks` where the new page begins
 * @property {number} offset - 0 for a break BEFORE `blocks[blockIndex]`; for
 *   a split paragraph/blockquote, the 0-based line at which the new page's
 *   content resumes; for a split table, the 0-based DATA row (the header
 *   always repeats at the top, so it is never counted in `offset`); for a
 *   split list, the 0-based item.
 */

const ATOMIC_TYPES = new Set(['figure', 'image', 'callout', 'horizontalRule', 'toc', 'columns', 'heading']);
const LINE_SPLIT_TYPES = new Set(['paragraph', 'blockquote']);
const LIST_TYPES = new Set(['bulletList', 'orderedList', 'taskList']);
const FORCED_BREAK_TYPES = new Set(['pageBreak', 'sectionBreak']);

/**
 * How much of `block`, starting fresh at the top of an empty page, is
 * needed before ANY of it may begin on the page above instead. Used only by
 * the keep-with-next check: it is never correct to place a heading with,
 * say, half a table's header row visible beneath it.
 *
 * @param {MeasuredBlock} block
 * @returns {number}
 */
function minimumFirstChunk(block) {
    if (block.type === 'table') {
        return block.headerHeight + (block.rowHeights[0] ?? 0);
    }
    if (LINE_SPLIT_TYPES.has(block.type)) {
        return block.lineHeight;
    }
    if (LIST_TYPES.has(block.type)) {
        return block.itemHeights[0] ?? 0;
    }

    // Atomic (including another heading, a figure, etc.): the whole thing
    // or nothing - there is no partial unit smaller than the whole block.
    return block.height;
}

/**
 * @param {MeasuredBlock[]} blocks
 * @param {number} pageHeight
 * @returns {import('./measure').Break[]}
 */
export function computeBreaks(blocks, pageHeight) {
    const breaks = [];
    let usable = pageHeight;
    let used = 0;

    const startNewPage = (blockIndex, offset, newUsable) => {
        breaks.push({ blockIndex, offset });
        used = 0;
        if (typeof newUsable === 'number') {
            usable = newUsable;
        }
    };

    for (let i = 0; i < blocks.length; i++) {
        const block = blocks[i];

        if (FORCED_BREAK_TYPES.has(block.type)) {
            // The node itself is never shown - the page-boundary decoration
            // takes its place visually (pagination/bands.js) - so its own
            // height never enters `used`. A break with nothing accumulated
            // yet (the very first block, or right after a previous break)
            // is a no-op: there is nothing on this page to separate from.
            // Likewise a break with nothing left AFTER it produces no page.
            const hasFollowingContent = i + 1 < blocks.length;
            if (used > 0 && hasFollowingContent) {
                startNewPage(i + 1, 0, block.newPageHeight);
            } else if (typeof block.newPageHeight === 'number') {
                usable = block.newPageHeight;
            }
            continue;
        }

        if (block.type === 'heading') {
            const remaining = usable - used;
            const doesNotFit = block.height > remaining;

            const next = blocks[i + 1];
            const nextIsForced = next && FORCED_BREAK_TYPES.has(next.type);
            const keepWithNextViolated = Boolean(next) && !nextIsForced
                && (remaining - block.height) < minimumFirstChunk(next);

            if (doesNotFit || keepWithNextViolated) {
                startNewPage(i, 0);
            }

            used += block.height;
            continue;
        }

        if (ATOMIC_TYPES.has(block.type)) {
            if (block.height > usable - used) {
                startNewPage(i, 0);
            }
            used += block.height;
            continue;
        }

        if (LINE_SPLIT_TYPES.has(block.type)) {
            if (block.lineHeight > usable) {
                // Pathological: even a fresh, empty page can't fit one
                // line. No break could ever help - place the whole block
                // and let it overflow, the same fallback an oversized
                // atomic block gets above.
                if (block.height > usable - used) {
                    startNewPage(i, 0);
                }
                used += block.height;
                continue;
            }

            let remaining = usable - used;
            let lineStart = 0;
            const totalLines = block.lines;

            while (lineStart < totalLines) {
                const linesFit = Math.floor(remaining / block.lineHeight);

                if (linesFit <= 0) {
                    startNewPage(i, lineStart);
                    remaining = usable;
                    continue;
                }

                const linesPlaced = Math.min(linesFit, totalLines - lineStart);
                used += linesPlaced * block.lineHeight;
                lineStart += linesPlaced;
                remaining = usable - used;

                if (lineStart < totalLines) {
                    startNewPage(i, lineStart);
                    remaining = usable;
                }
            }
            continue;
        }

        if (block.type === 'table') {
            const rows = block.rowHeights.length;
            const firstRow = rows > 0 ? block.rowHeights[0] : 0;
            const freshPageFirstChunk = block.headerHeight + firstRow;

            let remaining = usable - used;
            if (freshPageFirstChunk > remaining && freshPageFirstChunk <= usable) {
                startNewPage(i, 0);
                remaining = usable;
            }
            used += block.headerHeight;
            remaining = usable - used;

            const freshRowBudget = usable - block.headerHeight;
            let rowStart = 0;

            while (rowStart < rows) {
                const rowHeight = block.rowHeights[rowStart];

                if (rowHeight > remaining && rowHeight <= freshRowBudget) {
                    startNewPage(i, rowStart);
                    used += block.headerHeight;
                    remaining = usable - used;
                    continue;
                }

                used += rowHeight;
                remaining -= rowHeight;
                rowStart += 1;
            }
            continue;
        }

        if (LIST_TYPES.has(block.type)) {
            const items = block.itemHeights.length;
            let remaining = usable - used;
            let itemStart = 0;

            while (itemStart < items) {
                const itemHeight = block.itemHeights[itemStart];

                if (itemHeight > remaining && itemHeight <= usable) {
                    startNewPage(i, itemStart);
                    remaining = usable;
                    continue;
                }

                used += itemHeight;
                remaining -= itemHeight;
                itemStart += 1;
            }
            continue;
        }

        // An unrecognised type is treated as atomic - the safe default for
        // any node type this algorithm has not been taught about yet.
        if (block.height > usable - used) {
            startNewPage(i, 0);
        }
        used += block.height;
    }

    return breaks;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/pagination.measure.test.js`
Expected: PASS (all 19 cases)

- [ ] **Step 5: Run the full JS suite to check for regressions**

Run: `npm test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor/pagination/measure.js tests/js/pagination.measure.test.js
git commit -m "feat(pagination): pure block-level pagination algorithm (measure.js)

Zero-DOM computeBreaks(blocks, pageHeight) - atomic/line-split/row-split/
item-split classification per block type, keep-with-next for headings,
forced breaks at pageBreak/sectionBreak (the latter also changing usable
height for what follows), and termination guards for the pathological
cases (a single line/row/item taller than a whole fresh page)."
```

---

## Task 4: `decorations.js` — measure the live DOM and render breaks as widgets

**Files:**
- Create: `resources/js/editor/pagination/decorations.js`
- Test: manual/browser verification only (see Task 8) — this module is a thin, imperative DOM/ProseMirror-view layer around the already-tested `measure.js`, in the same spirit as `resources/js/editor/ui/bubble.js` (untested directly; the decision logic it calls is tested). Its one pure helper (`resolveSectionPageHeight`) gets a unit test here.

**Interfaces:**
- Consumes: `computeBreaks` from `measure.js` (Task 3).
- Produces: `PaginationExtension` — a TipTap `Extension` (added to `buildExtensions(opts)` in `resources/js/editor/index.js`, Task 7), following the **exact same shape `resources/js/editor/extensions/headingNumbered.js` already uses** for its own widget-decoration plugin (`Plugin`/`PluginKey` from `@tiptap/pm/state`, `Decoration`/`DecorationSet` from `@tiptap/pm/view`, a plugin `state.apply()` that reads a dispatched meta) — pagination's plugin is always present once the editor is constructed, not registered/unregistered at runtime. Also produces `repaginate(view, getPageSetup, renderBands): number` (dispatches the freshly computed `DecorationSet` as plugin meta and returns the new page count) and the pure `mmToPx(length): number` / `resolveSectionPageHeight(baseSetup, sectionSetup): number` helpers.

- [ ] **Step 1: Write the failing test for the one pure helper**

```js
// tests/js/pagination.decorations.test.js
import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveSectionPageHeight, mmToPx } from '../../resources/js/editor/pagination/decorations.js';

test('mmToPx converts at 96dpi (1in = 25.4mm = 96px)', () => {
    assert.equal(Math.round(mmToPx('25.4mm')), 96);
    assert.equal(Math.round(mmToPx('297mm')), 1122);
});

test('mmToPx passes through a value already in px', () => {
    assert.equal(mmToPx('500px'), 500);
});

test('resolveSectionPageHeight falls back to the base setup for anything the section does not override', () => {
    const base = { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    const height = resolveSectionPageHeight(base, {});
    // A4 portrait is 297mm tall; margins top+bottom = 50mm.
    assert.equal(Math.round(height), Math.round(mmToPx('297mm') - mmToPx('50mm')));
});

test('resolveSectionPageHeight honours an orientation override', () => {
    const base = { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    const landscape = resolveSectionPageHeight(base, { orientation: 'landscape' });
    // A4 landscape swaps to 210mm tall.
    assert.equal(Math.round(landscape), Math.round(mmToPx('210mm') - mmToPx('50mm')));
});

test('resolveSectionPageHeight honours a margin override merged over the base', () => {
    const base = { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    const height = resolveSectionPageHeight(base, { margins: { top: '10mm' } });
    // Only top changes (10mm instead of 25mm); bottom stays 25mm.
    assert.equal(Math.round(height), Math.round(mmToPx('297mm') - mmToPx('35mm')));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/pagination.decorations.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Write `decorations.js`**

```js
import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

import { computeBreaks } from './measure';

/** A4/A3/Letter portrait dimensions in mm - the same table PageSetup::SIZES
 *  in app/Print/PageSetup.php validates against. Landscape swaps width/height. */
const PAGE_SIZES_MM = {
    A4: [210, 297],
    A3: [297, 420],
    Letter: [215.9, 279.4],
};

/** 96 CSS px per inch, 25.4mm per inch - the standard CSS px/physical-unit ratio every browser uses. */
export function mmToPx(length) {
    const match = /^(\d+(?:\.\d+)?)(mm|cm|in|px)$/.exec(length);
    if (!match) {
        return parseFloat(length) || 0;
    }
    const value = parseFloat(match[1]);

    switch (match[2]) {
        case 'mm':
            return (value / 25.4) * 96;
        case 'cm':
            return (value * 10 / 25.4) * 96;
        case 'in':
            return value * 96;
        default:
            return value;
    }
}

/**
 * The usable page CONTENT height in px for a page setup: the page's own
 * height (from its size + orientation) minus its top and bottom margins.
 * Header/footer band heights are subtracted separately by the caller, once
 * per repagination pass, since they depend on live-rendered band DOM the
 * pure helper here has no access to.
 */
export function resolveSectionPageHeight(baseSetup, sectionOverride = {}) {
    const size = sectionOverride.size ?? baseSetup.size;
    const orientation = sectionOverride.orientation ?? baseSetup.orientation;
    const margins = { ...baseSetup.margins, ...(sectionOverride.margins || {}) };

    const [wMm, hMm] = PAGE_SIZES_MM[size] || PAGE_SIZES_MM.A4;
    const heightMm = orientation === 'landscape' ? wMm : hMm;

    return mmToPx(`${heightMm}mm`) - mmToPx(margins.top) - mmToPx(margins.bottom);
}

export const paginationPluginKey = new PluginKey('dotdoc-pagination');

/**
 * Walk .paper's TOP-LEVEL nodes in document order, measuring each one's live
 * rendered height (and, for splittable types, the extra shape measure.js
 * needs) into the MeasuredBlock[] shape computeBreaks() consumes.
 *
 * Returns both the blocks and a parallel array of each block's starting
 * ProseMirror position, so a Break's {blockIndex, offset} can be resolved
 * back into a real position without re-walking the document.
 */
function measureBlocks(view, sectionSetups) {
    const { doc } = view.state;
    const blocks = [];
    const starts = [];

    let currentSetup = sectionSetups.base;

    doc.forEach((node, offset) => {
        const pos = offset;
        starts.push(pos);
        const dom = view.nodeDOM(pos);
        const rect = dom instanceof HTMLElement ? dom.getBoundingClientRect() : { height: 0 };

        if (node.type.name === 'sectionBreak') {
            const override = node.attrs.setup || {};
            const newPageHeight = resolveSectionPageHeight(currentSetup, override) - sectionSetups.bandHeight;
            currentSetup = { ...currentSetup, ...override, margins: { ...currentSetup.margins, ...(override.margins || {}) } };
            blocks.push({ type: 'sectionBreak', height: 0, newPageHeight });
            return;
        }

        if (node.type.name === 'pageBreak') {
            blocks.push({ type: 'pageBreak', height: 0 });
            return;
        }

        if (node.type.name === 'paragraph' || node.type.name === 'blockquote') {
            const { lines, lineHeight } = measureLines(dom, rect.height);
            blocks.push({ type: node.type.name, height: rect.height, lines, lineHeight });
            return;
        }

        if (node.type.name === 'table') {
            const rowEls = dom instanceof HTMLElement ? Array.from(dom.querySelectorAll('tr')) : [];
            const headerEl = rowEls.find((r) => r.querySelector('th'));
            const headerHeight = headerEl ? headerEl.getBoundingClientRect().height : 0;
            const rowHeights = rowEls
                .filter((r) => r !== headerEl)
                .map((r) => r.getBoundingClientRect().height);
            blocks.push({ type: 'table', height: rect.height, headerHeight, rowHeights });
            return;
        }

        if (node.type.name === 'bulletList' || node.type.name === 'orderedList' || node.type.name === 'taskList') {
            const itemEls = dom instanceof HTMLElement ? Array.from(dom.children) : [];
            const itemHeights = itemEls.map((el) => el.getBoundingClientRect().height);
            blocks.push({ type: node.type.name, height: rect.height, itemHeights });
            return;
        }

        blocks.push({ type: node.type.name, height: rect.height });
    });

    return { blocks, starts };
}

/**
 * Number of wrapped lines and the (uniform) height per line for a
 * paragraph/blockquote's rendered DOM: every distinct `top` a Range over
 * its full text reports is one visual line. Falls back to a single line
 * spanning the whole block when the element holds no measurable text
 * (an empty paragraph) - `getClientRects()` returns nothing for an empty
 * Range, and a zero-line block would divide by zero in measure.js.
 */
function measureLines(dom, height) {
    if (!(dom instanceof HTMLElement) || !dom.firstChild) {
        return { lines: 1, lineHeight: height || 1 };
    }

    const range = document.createRange();
    range.selectNodeContents(dom);
    const rects = Array.from(range.getClientRects());
    const tops = [...new Set(rects.map((r) => Math.round(r.top)))];
    const lines = tops.length || 1;

    return { lines, lineHeight: height / lines };
}

/**
 * Resolve a Break (from measure.js) into a real ProseMirror document
 * position. offset === 0 is always exact (the recorded block's own start
 * position). A non-zero offset for a table/list is ALSO exact - each row's
 * or item's own child position is looked up directly from the document,
 * never from screen coordinates. Only a non-zero paragraph/blockquote
 * offset (a mid-paragraph line split) needs `posAtCoords`, because a line
 * boundary is a VISUAL concept with no corresponding node boundary -
 * v1 accepts the small imprecision `posAtCoords` can have at a wrapped
 * line's exact start (this is the same "a single stranded line is
 * possible" trade-off named in the design spec's "Three decisions" #2.
 */
function resolveBreakPosition(view, breakInfo, starts, blockNode) {
    const blockStart = starts[breakInfo.blockIndex];

    if (breakInfo.offset === 0) {
        return blockStart;
    }

    if (blockNode.type.name === 'table') {
        // Exact, no coordinate math needed: ProseMirror already gives every
        // row's own child offset. +1 enters the table; a row's offset
        // (`rOffset`, relative to the table's own start) lands the position
        // at the start of that row's content.
        let rowOffset = 0;
        let dataRowsSeen = 0;
        blockNode.forEach((row, rOffset) => {
            const isHeaderRow = row.firstChild && row.firstChild.type.name === 'tableHeader';
            if (isHeaderRow) {
                return;
            }
            if (dataRowsSeen === breakInfo.offset) {
                rowOffset = rOffset;
            }
            dataRowsSeen += 1;
        });
        return blockStart + 1 + rowOffset;
    }

    if (blockNode.type.name === 'bulletList' || blockNode.type.name === 'orderedList' || blockNode.type.name === 'taskList') {
        let childOffset = 0;
        let itemIndex = 0;
        blockNode.forEach((item, iOffset) => {
            if (itemIndex === breakInfo.offset) {
                childOffset = iOffset;
            }
            itemIndex += 1;
        });
        return blockStart + 1 + childOffset;
    }

    // Paragraph/blockquote: resolve the visual line's DOM rect, then map it
    // to a document position. Falls back to the block's own start if the
    // view cannot resolve a position there (a defensive floor, never hit in
    // practice for an on-screen block).
    const dom = view.nodeDOM(blockStart);
    if (!(dom instanceof HTMLElement) || !dom.firstChild) {
        return blockStart;
    }
    const range = document.createRange();
    range.selectNodeContents(dom);
    const rects = Array.from(range.getClientRects());
    const tops = [...new Set(rects.map((r) => Math.round(r.top)))].sort((a, b) => a - b);
    const targetTop = tops[breakInfo.offset];
    if (targetTop === undefined) {
        return blockStart;
    }
    const paperRect = dom.closest('.paper')?.getBoundingClientRect();
    const left = paperRect ? paperRect.left + 1 : dom.getBoundingClientRect().left + 1;
    const coords = view.posAtCoords({ left, top: targetTop + 1 });

    return coords ? coords.pos : blockStart;
}

/** One page-boundary widget: the previous page's footer, a gap, a shadow on both edges, the next page's header. */
function renderBoundaryWidget(renderBands) {
    const el = document.createElement('div');
    el.className = 'dotdoc-page-boundary';
    el.contentEditable = 'false';

    const shadowAbove = document.createElement('div');
    shadowAbove.className = 'dotdoc-page-shadow dotdoc-page-shadow-above';
    const footer = document.createElement('div');
    footer.className = 'dotdoc-page-band dotdoc-page-footer';
    const gap = document.createElement('div');
    gap.className = 'dotdoc-page-gap';
    const header = document.createElement('div');
    header.className = 'dotdoc-page-band dotdoc-page-header';
    const shadowBelow = document.createElement('div');
    shadowBelow.className = 'dotdoc-page-shadow dotdoc-page-shadow-below';

    renderBands(footer, header);

    el.append(shadowAbove, footer, gap, header, shadowBelow);

    return el;
}

/**
 * A TipTap Extension, added to `buildExtensions(opts)` in resources/js/
 * editor/index.js (Task 7) - the SAME shape extensions/headingNumbered.js
 * already uses for its own widget-decoration plugin: a `Plugin` whose
 * `state.apply()` reads a dispatched meta and otherwise just maps the
 * existing DecorationSet through the transaction. Unlike a plugin added at
 * runtime via `editor.registerPlugin()`, this one is always present from
 * construction, has nothing to unregister, and needs no `view()` lifecycle
 * hook of its own - `pagination/index.js` (Task 7) owns the debounce timer
 * and calls `repaginate()` below directly, which dispatches the meta this
 * plugin's `apply()` picks up.
 */
export const PaginationExtension = Extension.create({
    name: 'dotdocPagination',

    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: paginationPluginKey,
                state: {
                    init: () => DecorationSet.empty,
                    apply(tr, value) {
                        const meta = tr.getMeta(paginationPluginKey);

                        return meta || value.map(tr.mapping, tr.doc);
                    },
                },
                props: {
                    decorations(state) {
                        return this.getState(state);
                    },
                },
            }),
        ];
    },
});

/**
 * Recompute page breaks against the live DOM and dispatch the resulting
 * DecorationSet as this plugin's meta. Called by pagination/index.js
 * (Task 7) after its debounce timer fires.
 *
 * @param {import('@tiptap/pm/view').EditorView} view
 * @param {() => {pageHeightPx: number, base: object, bandHeight: number}} getPageSetup
 * @param {(footerEl: HTMLElement, headerEl: HTMLElement, pageIndex: number) => void} renderBands
 * @returns {number} the new total page count
 */
export function repaginate(view, getPageSetup, renderBands) {
    const setup = getPageSetup();
    const { blocks, starts } = measureBlocks(view, { base: setup.base, bandHeight: setup.bandHeight });
    const usable = setup.pageHeightPx - setup.bandHeight;
    const breakList = computeBreaks(blocks, usable);

    let pageIndex = 0;
    const decorations = breakList.map((breakInfo) => {
        const blockNode = view.state.doc.child(breakInfo.blockIndex < blocks.length ? breakInfo.blockIndex : blocks.length - 1);
        const pos = resolveBreakPosition(view, breakInfo, starts, blockNode);
        pageIndex += 1;
        const thisPageIndex = pageIndex;

        return Decoration.widget(pos, () => renderBoundaryWidget(
            (footerEl, headerEl) => renderBands(footerEl, headerEl, thisPageIndex),
        ), { side: -1, key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}` });
    });

    const tr = view.state.tr.setMeta(paginationPluginKey, DecorationSet.create(view.state.doc, decorations));
    tr.setMeta('addToHistory', false);
    view.dispatch(tr);

    return breakList.length + 1;
}
```

- [ ] **Step 4: Run the pure-helper test to verify it passes**

Run: `node --test tests/js/pagination.decorations.test.js`
Expected: PASS (5 cases)

- [ ] **Step 5: Run the full JS suite**

Run: `npm test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor/pagination/decorations.js tests/js/pagination.decorations.test.js
git commit -m "feat(pagination): decorations.js - live DOM measurement and widget rendering

Walks .paper's top-level ProseMirror nodes into measure.js's MeasuredBlock
shape, resolves computeBreaks()'s {blockIndex, offset} results to real
document positions (exact for table/list splits, posAtCoords for a
mid-paragraph line split), and renders each boundary as a non-editable
widget decoration - never written into the document JSON. PaginationExtension
follows extensions/headingNumbered.js's own widget-decoration Plugin shape
exactly, added to buildExtensions() rather than registered at runtime."
```

---

## Task 5: `bands.js` — render header/footer bands from server segments

**Files:**
- Create: `resources/js/editor/pagination/bands.js`
- Test: `tests/js/pagination.bands.test.js` (new)

**Interfaces:**
- Consumes: the `headerSegments`/`footerSegments` shape from Task 1/2 (`{type: 'text'|'field', value: string}[]`).
- Produces: `renderBand(container: HTMLElement, segments: Segment[], page: number, pages: number): void` — clears `container` and appends the rendered band. Called by `decorations.js`'s `renderBands` callback (Task 4) once per boundary widget, and once more for the DOCUMENT'S OWN first-page header/last-page footer (Task 7 wires this at the top/bottom of `.paper` itself, not just at internal boundaries).

- [ ] **Step 1: Write the failing test**

```js
// tests/js/pagination.bands.test.js
import assert from 'node:assert/strict';
import test from 'node:test';

import { renderBand } from '../../resources/js/editor/pagination/bands.js';

// This module touches the DOM (container.textContent, appendChild) but
// needs no ProseMirror/TipTap import, so a minimal hand-rolled DOM stand-in
// is enough - no jsdom dependency required (none exists in package.json).
function fakeContainer() {
    const children = [];
    return {
        children,
        textContent: '',
        appendChild(node) { children.push(node); },
        get renderedText() {
            return children.map((c) => c.text ?? '').join('');
        },
    };
}

function textNode(text) {
    return { nodeType: 3, text };
}

// bands.js is expected to use `document.createTextNode`; under `node --test`
// there is no global `document`, so this suite stubs the minimal piece it
// needs rather than pulling in a DOM dependency the project does not carry.
global.document = { createTextNode: (t) => textNode(t) };

test('a text-only band renders its literal value', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: 'Confidential' }], 1, 5);
    assert.equal(el.renderedText, 'Confidential');
});

test('a PAGE field renders the current page number', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: 'Page ' }, { type: 'field', value: 'PAGE' }], 3, 10);
    assert.equal(el.renderedText, 'Page 3');
});

test('a NUMPAGES field renders the total page count', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'field', value: 'PAGE' }, { type: 'text', value: ' of ' }, { type: 'field', value: 'NUMPAGES' }], 3, 10);
    assert.equal(el.renderedText, '3 of 10');
});

test('an empty segment list renders nothing', () => {
    const el = fakeContainer();
    renderBand(el, [], 1, 1);
    assert.equal(el.children.length, 0);
});

test('every piece is appended as a TEXT NODE, never innerHTML - segments are never parsed as markup', () => {
    const el = fakeContainer();
    renderBand(el, [{ type: 'text', value: '<b>not markup</b>' }], 1, 1);
    assert.equal(el.renderedText, '<b>not markup</b>', 'the angle brackets must survive as literal text');
    assert.equal(el.children.every((c) => c.nodeType === 3), true);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/pagination.bands.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Write `bands.js`**

```js
/**
 * Renders one header or footer band from the segments App\Print\
 * HeaderFooterBands produces (see Editor::outline()'s headerSegments/
 * footerSegments). Every piece is appended as a TEXT NODE - never
 * `innerHTML` - so a segment's value can never be interpreted as markup,
 * whatever it contains. This is the client half of the same contract
 * PrintRenderer keeps server-side with htmlspecialchars(): each renderer
 * escapes for its OWN destination, and a DOM text node is inherently safe
 * without needing the segment to arrive pre-escaped (see HeaderFooterBands'
 * own docblock for why it deliberately does not escape).
 */

/**
 * @param {HTMLElement} container - cleared and re-filled on every call
 * @param {Array<{type: 'text'|'field', value: string}>} segments
 * @param {number} page - 1-based current page index
 * @param {number} pages - total page count
 */
export function renderBand(container, segments, page, pages) {
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    for (const segment of segments) {
        const text = segment.type === 'field'
            ? String(segment.value === 'PAGE' ? page : pages)
            : segment.value;

        container.appendChild(document.createTextNode(text));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/js/pagination.bands.test.js`
Expected: PASS (5 cases)

- [ ] **Step 5: Run the full JS suite**

Run: `npm test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor/pagination/bands.js tests/js/pagination.bands.test.js
git commit -m "feat(pagination): bands.js renders header/footer segments as text nodes

Mirrors HeaderFooterBands' segment contract on the client: PAGE/NUMPAGES
fields fill in from the live computed page index/total, every piece lands
via textContent-equivalent (createTextNode), never innerHTML."
```

---

## Task 6: `viewModes.js` — the six view modes and shared thumbnails

**Files:**
- Create: `resources/js/editor/pagination/viewModes.js`
- Test: `tests/js/pagination.viewModes.test.js` (new, covers the pure mode-to-class mapping only)

**Interfaces:**
- Produces: `MODES = ['continuous', 'single', 'two-page', 'multi-page', 'focus', 'print-preview']`, `classesForMode(mode: string): string[]` (pure — the only part this task unit-tests), `applyMode(canvasEl: HTMLElement, mode: string, opts): void` (DOM side effect: toggles classes, mounts/unmounts the print-preview iframe, mounts/unmounts the multi-page thumbnail grid), `renderThumbnail(pageEl: HTMLElement | null, scale: number): HTMLElement` (the scaled-clone technique shared by the rail panel and multi-page mode).

- [ ] **Step 1: Write the failing test for the pure mapping**

```js
// tests/js/pagination.viewModes.test.js
import assert from 'node:assert/strict';
import test from 'node:test';

import { MODES, classesForMode } from '../../resources/js/editor/pagination/viewModes.js';

test('every mode is a known, exact set of six', () => {
    assert.deepEqual(MODES, ['continuous', 'single', 'two-page', 'multi-page', 'focus', 'print-preview']);
});

test('continuous is the default: no special class beyond the base', () => {
    assert.deepEqual(classesForMode('continuous'), ['dotdoc-paginated']);
});

test('single page mode adds scroll-snap', () => {
    assert.deepEqual(classesForMode('single'), ['dotdoc-paginated', 'dotdoc-mode-single']);
});

test('two page mode adds the facing-pages grid class', () => {
    assert.deepEqual(classesForMode('two-page'), ['dotdoc-paginated', 'dotdoc-mode-two-page']);
});

test('multi page mode adds the overview grid class', () => {
    assert.deepEqual(classesForMode('multi-page'), ['dotdoc-paginated', 'dotdoc-mode-multi-page']);
});

test('focus mode hides page-break decorations and chrome', () => {
    assert.deepEqual(classesForMode('focus'), ['dotdoc-mode-focus']);
});

test('print preview mode has no pagination classes of its own - the iframe replaces the canvas entirely', () => {
    assert.deepEqual(classesForMode('print-preview'), ['dotdoc-mode-print-preview']);
});

test('an unknown mode falls back to continuous', () => {
    assert.deepEqual(classesForMode('nonsense'), ['dotdoc-paginated']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/pagination.viewModes.test.js`
Expected: FAIL — module not found.

- [ ] **Step 3: Write `viewModes.js`**

```js
/**
 * The six page-view modes (design spec §3). Five are CSS/scroll
 * arrangements of the SAME decoration set pagination/decorations.js
 * already computed - no separate pagination logic per mode. Print Preview
 * is the exception: it is not computed live at all, it embeds the real
 * exported PDF.
 */

export const MODES = ['continuous', 'single', 'two-page', 'multi-page', 'focus', 'print-preview'];

const CLASS_BY_MODE = {
    continuous: ['dotdoc-paginated'],
    single: ['dotdoc-paginated', 'dotdoc-mode-single'],
    'two-page': ['dotdoc-paginated', 'dotdoc-mode-two-page'],
    'multi-page': ['dotdoc-paginated', 'dotdoc-mode-multi-page'],
    focus: ['dotdoc-mode-focus'],
    'print-preview': ['dotdoc-mode-print-preview'],
};

/** @param {string} mode @returns {string[]} */
export function classesForMode(mode) {
    return CLASS_BY_MODE[mode] || CLASS_BY_MODE.continuous;
}

/**
 * Apply a view mode to the canvas region. `canvasEl` is `.canvas-region`
 * (the element wrapping `.paper`), not `.paper` itself, so print-preview's
 * iframe can fully replace the paginated DOM without pagination/index.js
 * having to tear anything down first.
 *
 * @param {HTMLElement} canvasEl
 * @param {string} mode
 * @param {{pdfPreviewUrl?: string, pageCount: () => number, currentPage: () => number, goToPage: (n: number) => void}} opts
 */
export function applyMode(canvasEl, mode, opts) {
    Object.values(CLASS_BY_MODE).flat().forEach((cls) => canvasEl.classList.remove(cls));
    classesForMode(mode).forEach((cls) => canvasEl.classList.add(cls));

    let iframe = canvasEl.querySelector('.dotdoc-print-preview-frame');
    if (mode === 'print-preview') {
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.className = 'dotdoc-print-preview-frame';
            iframe.title = 'Print preview';
            canvasEl.appendChild(iframe);
        }
        iframe.src = opts.pdfPreviewUrl || 'about:blank';
    } else if (iframe) {
        iframe.remove();
    }

    let grid = canvasEl.querySelector('.dotdoc-multi-page-grid');
    if (mode === 'multi-page') {
        if (!grid) {
            grid = document.createElement('div');
            grid.className = 'dotdoc-multi-page-grid';
            canvasEl.appendChild(grid);
        }
        renderThumbnailGrid(grid, canvasEl, opts, 0.35);
    } else if (grid) {
        grid.remove();
    }
}

/**
 * A scaled, non-editable, non-interactive CLONE of the live page content -
 * not a screenshot (this project carries no rasteriser), and not a blank
 * placeholder either, so the thumbnail actually shows what the page holds.
 * `transform: scale()` rather than a `zoom` CSS property, because `zoom`
 * also rescales the element's own box for layout purposes in a way that
 * fights a fixed thumbnail size; `transform` leaves the box where the CSS
 * grid puts it and only rescales what is drawn inside.
 *
 * @param {HTMLElement | null} pageContentEl - the DOM slice for one page
 *   (a `.paper` clone bounded to that page's rendered content); null draws
 *   an empty placeholder rather than throwing, since a page whose content
 *   has not rendered yet (mid-repagination) must still get a thumbnail box.
 * @param {number} scale
 * @returns {HTMLElement}
 */
export function renderThumbnail(pageContentEl, scale) {
    const box = document.createElement('div');
    box.className = 'dotdoc-thumbnail';

    const inner = document.createElement('div');
    inner.className = 'dotdoc-thumbnail-inner';
    inner.style.transform = `scale(${scale})`;

    if (pageContentEl) {
        const clone = pageContentEl.cloneNode(true);
        clone.removeAttribute('contenteditable');
        clone.setAttribute('aria-hidden', 'true');
        inner.appendChild(clone);
    }

    box.appendChild(inner);

    return box;
}

/**
 * Populate a thumbnail grid/rail with one box per page. Shared by
 * Multi-Page mode (full canvas, larger scale) and the thumbnails rail
 * (design spec §4, navigation only - clicking scrolls the real page into
 * view; no reorder/duplicate/delete, since a page is a computed result of
 * where the prose broke, not an object with its own identity).
 *
 * @param {HTMLElement} container
 * @param {HTMLElement} canvasEl
 * @param {{pageCount: () => number, currentPage: () => number, goToPage: (n: number) => void}} opts
 * @param {number} scale
 */
export function renderThumbnailGrid(container, canvasEl, opts, scale) {
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    const pageEls = Array.from(canvasEl.querySelectorAll('[data-dotdoc-page]'));
    const total = opts.pageCount();
    const current = opts.currentPage();

    for (let i = 1; i <= total; i++) {
        const pageEl = pageEls[i - 1] || null;
        const thumb = renderThumbnail(pageEl, scale);
        thumb.classList.toggle('is-current', i === current);
        thumb.setAttribute('role', 'button');
        thumb.setAttribute('tabindex', '0');
        thumb.setAttribute('aria-label', `Page ${i} of ${total}`);

        const activate = () => opts.goToPage(i);
        thumb.addEventListener('click', activate);
        thumb.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });

        const label = document.createElement('span');
        label.className = 'dotdoc-thumbnail-number';
        label.textContent = String(i);
        thumb.appendChild(label);

        container.appendChild(thumb);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/js/pagination.viewModes.test.js`
Expected: PASS (8 cases)

- [ ] **Step 5: Run the full JS suite**

Run: `npm test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor/pagination/viewModes.js tests/js/pagination.viewModes.test.js
git commit -m "feat(pagination): viewModes.js - the six view modes and shared thumbnails

classesForMode() is pure and tested; applyMode() and the scaled-clone
thumbnail renderer are the DOM side effects Multi-Page mode and the
thumbnails rail share, per design spec §3-4."
```

---

## Task 7: Orchestrator, bundle wiring, and Blade/CSS integration

**Files:**
- Create: `resources/js/editor/pagination/index.js`
- Modify: `resources/js/editor/index.js` (wire pagination into `mount()`/`destroy()`)
- Modify: `resources/views/livewire/documents/editor.blade.php` (view-mode select, thumbnails toggle/panel, `refreshOutline()` feeds pagination, pass `pdfPreviewUrl`)
- Modify: `app/Styles/CssBuilder.php` (`canvas` mode gap/shadow/page CSS; hide the plain `pageBreak`/`sectionBreak` divider styling while pagination is active)

**Interfaces:**
- Consumes: `createPaginationPlugin`/`repaginate`/`resolveSectionPageHeight`/`mmToPx` (Task 4), `renderBand` (Task 5), `applyMode`/`MODES` (Task 6), `pageSetup`/`headerSegments`/`footerSegments` (Task 1/2, arriving via `outline()`'s response).
- Produces: `window.DotDoc.pagination = { mode, setMode(mode), pageCount, currentPage, goToPage(n) }` (per design spec §5) plus an internal `setPageSetup(pageSetup, headerSegments, footerSegments)` the Blade bridge calls.

- [ ] **Step 1: Write `pagination/index.js`**

```js
import { repaginate, mmToPx } from './decorations';
import { renderBand } from './bands';
import { applyMode, MODES, renderThumbnailGrid } from './viewModes';

const REPAGINATE_DEBOUNCE_MS = 300;

/**
 * Mount pagination for one editor instance. Called once from
 * resources/js/editor/index.js's mount(), alongside the ProseMirror editor
 * itself - there is exactly one document editor per page, so the returned
 * controller is also what `window.DotDoc.pagination` delegates to (the
 * same flat-global shape resources/js/editor/outline.js already uses for
 * this editor's single active document).
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {HTMLElement} canvasEl - `.canvas-region`, the element wrapping `.paper`
 * @param {{pageSetup?: object, headerSegments?: Array, footerSegments?: Array, pdfPreviewUrl?: string}} opts
 */
export function mountPagination(editor, canvasEl, opts = {}) {
    let pageSetup = opts.pageSetup || { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    let headerSegments = opts.headerSegments || [];
    let footerSegments = opts.footerSegments || [];
    let mode = 'continuous';
    let currentPageIndex = 1;
    let pageCountValue = 1;
    let debounceTimer = null;

    /** Approximate band height from a throwaway render, so the usable page height accounts for it without a layout round trip per repagination. */
    function bandHeightPx() {
        // A single line of body text at the document's own font size is
        // the same floor PrintRenderer's own bands render at in practice;
        // an empty header/footer measures as 0, matching PageSetup's own
        // "" default meaning "no band reserved".
        if (headerSegments.length === 0 && footerSegments.length === 0) {
            return 0;
        }
        const probe = canvasEl.querySelector('.paper');
        const lineHeight = probe ? parseFloat(getComputedStyle(probe).lineHeight) || 24 : 24;

        return (headerSegments.length > 0 ? lineHeight : 0) + (footerSegments.length > 0 ? lineHeight : 0);
    }

    function getPageSetupForMeasurement() {
        const heightMm = pageSetup.orientation === 'landscape'
            ? { A4: 210, A3: 297, Letter: 215.9 }[pageSetup.size] || 210
            : { A4: 297, A3: 420, Letter: 279.4 }[pageSetup.size] || 297;
        const pageHeightPx = mmToPx(`${heightMm}mm`) - mmToPx(pageSetup.margins.top) - mmToPx(pageSetup.margins.bottom);

        return { pageHeightPx, base: pageSetup, bandHeight: bandHeightPx() };
    }

    function renderBandsForBoundary(footerEl, headerEl, pageIndexAfterBoundary) {
        renderBand(footerEl, footerSegments, pageIndexAfterBoundary - 1, pageCountValue);
        renderBand(headerEl, headerSegments, pageIndexAfterBoundary, pageCountValue);
    }

    function scheduleRepaginate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(runRepaginate, REPAGINATE_DEBOUNCE_MS);
    }

    function runRepaginate() {
        if (editor.isDestroyed) {
            return;
        }
        pageCountValue = repaginate(editor.view, getPageSetupForMeasurement, renderBandsForBoundary);
        currentPageIndex = Math.min(currentPageIndex, pageCountValue);
        const modeOpts = {
            pdfPreviewUrl: opts.pdfPreviewUrl,
            pageCount: () => pageCountValue,
            currentPage: () => currentPageIndex,
            goToPage,
        };
        applyMode(canvasEl, mode, modeOpts);

        // The thumbnails RAIL (design spec §4) lives outside `.canvas-region`
        // (see the Blade bridge in Step 4) and is populated whenever it is
        // present, independent of the current view mode - unlike Multi-Page
        // mode's grid, which applyMode() only mounts inside the canvas
        // itself while that mode is active.
        const rail = document.querySelector('.dotdoc-thumbnail-rail');
        if (rail) {
            renderThumbnailGrid(rail, canvasEl, modeOpts, 0.18);
        }
    }

    function goToPage(n) {
        currentPageIndex = Math.max(1, Math.min(n, pageCountValue));
        const target = canvasEl.querySelectorAll('.dotdoc-page-boundary')[currentPageIndex - 2];
        (target || canvasEl.querySelector('.paper'))?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    // Triggers, per design spec §2.1:
    //  - a debounced idle pause after any edit — covers local typing AND a
    //    remote update applied via applyRemote(), since TipTap's onUpdate
    //    fires on any transaction with docChanged regardless of its meta.
    //  - a document style change / page-setup change — both already flow
    //    through the Blade bridge's refreshOutline(), which calls
    //    setPageSetup() below with the fresh values before the next
    //    scheduled pass; no separate event wiring is needed for either.
    editor.on('update', scheduleRepaginate);

    scheduleRepaginate();

    return {
        get mode() {
            return mode;
        },
        setMode(next) {
            mode = MODES.includes(next) ? next : 'continuous';
            applyMode(canvasEl, mode, {
                pdfPreviewUrl: opts.pdfPreviewUrl,
                pageCount: () => pageCountValue,
                currentPage: () => currentPageIndex,
                goToPage,
            });
        },
        get pageCount() {
            return pageCountValue;
        },
        get currentPage() {
            return currentPageIndex;
        },
        goToPage,
        /** Called by the Blade bridge's refreshOutline() after every save and after a style change. */
        setPageSetup(nextPageSetup, nextHeaderSegments, nextFooterSegments) {
            pageSetup = nextPageSetup || pageSetup;
            headerSegments = nextHeaderSegments || headerSegments;
            footerSegments = nextFooterSegments || footerSegments;
            scheduleRepaginate();
        },
        destroy() {
            clearTimeout(debounceTimer);
            editor.off('update', scheduleRepaginate);
        },
    };
}
```

- [ ] **Step 2: Wire it into `resources/js/editor/index.js`**

Add the imports near the top, alongside the other extension/pagination-adjacent imports:

```js
import { PaginationExtension } from './pagination/decorations';
import { mountPagination } from './pagination/index';
```

Register the extension in `buildExtensions()`, alongside the other behavioural (non-node) extensions — add it right after `SlashMenu,`:

```js
        SlashMenu,
        PaginationExtension,
        // Last, so its global `id` attribute is registered over every node
        // type the extensions above contributed.
        BlockId,
```

Inside `mount()`, after `const editor = new Editor({...})` finishes constructing but before the `handle` object is built (i.e., right after the existing `if (contentError || ...) failClosed(...)` block, so pagination never starts against a fail-closed, read-only document), add:

```js
    const canvasRegion = element.closest('.canvas-region') || element.parentElement || element;
    const pagination = mountPagination(editor, canvasRegion, {
        pageSetup: opts.pageSetup,
        headerSegments: opts.headerSegments,
        footerSegments: opts.footerSegments,
        pdfPreviewUrl: opts.pdfPreviewUrl,
    });
```

Add `pagination` to the returned `handle` object's `destroy()` method (so it tears down when the editor does):

```js
        destroy: () => {
            flushSave({ beacon: true });
            clearTimeout(saveTimer);
            window.removeEventListener('pagehide', onPageHide);
            closeList();
            teardownPalette();
            teardownBubble();
            pagination.destroy();
            editor.destroy();
            if (element.__dotdoc === handle) {
                element.__dotdoc = null;
            }
        },
```

Add `pagination` as a property on `handle` itself, right after `editor,` at the top of the returned object:

```js
    const handle = {
        editor,
        pagination,

        run: (name, params = {}) => runCommand(editor, name, params),
```

Finally, expose it as the flat global the design spec names, at the bottom of the file where `window.DotDoc = DotDoc;` is set — add a getter-backed property so `window.DotDoc.pagination` always reflects whichever editor is currently mounted (there is exactly one per page):

```js
export const DotDoc = {
    mount,
    get,
    setOutline,
    outline,
    commands,
    run: runCommand,
    openPalette,
    base62,
    stripDerived,
    documentsDiffer,
    /** The single active editor's pagination controller, or a safe no-op stand-in before mount(). */
    get pagination() {
        const el = document.querySelector('[wire\\:ignore].canvas, #doc-paper');
        return el?.__dotdoc?.pagination || {
            mode: 'continuous', setMode() {}, pageCount: 1, currentPage: 1, goToPage() {}, setPageSetup() {},
        };
    },
};
```

- [ ] **Step 3: Add the `canvas` mode gap/shadow/page CSS to `CssBuilder`**

In `app/Styles/CssBuilder.php`, add a new private method and call it from `build()`. Insert the call right after `$parts[] = $this->pageBreakRule();`:

```php
        $parts[] = $this->pageBreakRule();
        $parts[] = $this->paginationRule();
```

Add both methods (placed after `pageBreakRule()`):

```php
    /**
     * Just the left/right margin, TokenGuard-validated the same way
     * margins() validates all four - a header/footer BAND needs its text
     * to align with the page's own left/right margin, but must NOT take
     * the page's full top/bottom margin as its own padding (that would
     * make a one-line band as tall as the page margin itself).
     *
     * @return array{left:string,right:string}
     */
    private function horizontalMargins(): array
    {
        $m = $this->tokens['page']['margins'] ?? [];
        $fb = $this->fallback['page']['margins'];

        return [
            'left' => TokenGuard::length($m['left'] ?? null, $fb['left']),
            'right' => TokenGuard::length($m['right'] ?? null, $fb['right']),
        ];
    }

    /**
     * The decoration-based pagination boundary (resources/js/editor/
     * pagination/decorations.js inserts one `.dotdoc-page-boundary` widget
     * per computed break). This CSS never appears unless pagination has
     * actually run - `.dotdoc-paginated` is added to `.canvas-region` by
     * viewModes.js's applyMode(), so a page that has not mounted the
     * pagination bundle at all (or has JS disabled) sees the plain
     * unbounded `.paper` exactly as it did before this phase.
     *
     * `--ground` is the Fair Copy app-background token (.ai/rules/views.md)
     * - the gap between two pages shows the desk behind the paper, not a
     * colour invented for this feature. The shadow is a soft edge on BOTH
     * sides of the gap, echoing the single box-shadow `.paper` itself
     * already carries (Fair Copy's "one shadow only" rule) rather than
     * adding a second, differently-styled shadow convention.
     *
     * `.page-break`/`.section-break` (the plain node markers) hide their
     * own decorative line while pagination is active: the real page-
     * boundary widget now renders exactly where a forced break falls, so
     * showing both would be two markers for one break.
     */
    private function paginationRule(): string
    {
        if ($this->mode !== 'canvas') {
            return '';
        }

        $h = $this->horizontalMargins();

        return '.canvas-region.dotdoc-paginated .paper{background:transparent;box-shadow:none}'.
            '.canvas-region.dotdoc-paginated .paper>*{background:#fff}'.
            '.dotdoc-page-boundary{contain:layout;pointer-events:none}'.
            '.dotdoc-page-gap{height:2.5em;background:var(--ground)}'.
            '.dotdoc-page-shadow{height:.5em;background:linear-gradient(to bottom,rgba(0,0,0,.08),transparent)}'.
            '.dotdoc-page-shadow-below{transform:rotate(180deg)}'.
            // A 4-value padding shorthand (top right bottom left): a small
            // fixed vertical padding appropriate for a one-line band, and
            // the page's REAL left/right margin so the band's text aligns
            // with the body text above/below it.
            ".dotdoc-page-band{background:#fff;padding:.4em {$h['right']} .4em {$h['left']};font-size:var(--doc-size-small);color:var(--doc-muted);pointer-events:auto}".
            '.canvas-region.dotdoc-paginated .page-break,.canvas-region.dotdoc-paginated .section-break{display:none}'.
            '.canvas-region.dotdoc-mode-focus .dotdoc-page-boundary,.canvas-region.dotdoc-mode-focus .dotdoc-page-band{display:none}'.
            '.canvas-region.dotdoc-mode-focus .dotdoc-page-gap{background:transparent;height:0}'.
            '.canvas-region.dotdoc-mode-single{scroll-snap-type:y mandatory;overflow-y:auto}'.
            '.canvas-region.dotdoc-mode-single .paper{scroll-snap-align:start}'.
            '.canvas-region.dotdoc-mode-two-page{display:grid;grid-template-columns:repeat(2,210mm);gap:1em;justify-content:center}'.
            '.dotdoc-multi-page-grid,.dotdoc-thumbnail-rail{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:var(--s3, 12px)}'.
            '.dotdoc-thumbnail{border:1px solid var(--line);border-radius:var(--r-control, 8px);overflow:hidden;cursor:pointer;position:relative;aspect-ratio:210/297}'.
            '.dotdoc-thumbnail.is-current{outline:2px solid var(--accent);outline-offset:2px}'.
            '.dotdoc-thumbnail-inner{transform-origin:top left;pointer-events:none}'.
            '.dotdoc-thumbnail-number{position:absolute;bottom:4px;right:4px;font-size:var(--doc-size-small, 11px);background:var(--surface);color:var(--ink-soft);padding:0 4px;border-radius:4px}'.
            '.dotdoc-print-preview-frame{width:100%;height:80vh;border:none}';
    }
```

- [ ] **Step 4: Wire the Blade bridge**

In `resources/views/livewire/documents/editor.blade.php`:

Add two properties to the top-level `x-data` object, near `showMoveSheet`:

```js
        showMoveSheet: false,
        thumbnailsOpen: false,
        viewMode: 'continuous',
```

Pass the new options into `window.DotDoc.mount(...)`:

```js
            const handle = window.DotDoc.mount(host, {
                content: @js($contentJson),
                vars: @js($document->variables ?? []),
                pageSetup: @js($outline['pageSetup']),
                headerSegments: @js($outline['headerSegments']),
                footerSegments: @js($outline['footerSegments']),
                pdfPreviewUrl: '{{ route('documents.export', [$document->uuid, 'pdf']) }}',
                uploadUrl: '{{ route('documents.images.store', $document->uuid) }}',
                autosaveUrl: '{{ route('documents.autosave', $document->uuid) }}',
                csrfToken: document.querySelector('meta[name=csrf-token]').content,
                onChange: (json) => this.persist(json),
                onSelection: (s) => { this.selection = s; this.tick++; },
                onCommand: (name, params) => this.hostCommand(name, params),
            });
```

Extend `refreshOutline()` to feed pagination on every round trip (covers a normal save, and — combined with the existing `@style-changed.window="...; refreshOutline()"` listener already on the root element — a style/page-setup change too):

```js
        refreshOutline() {
            return @this.outline().then((outline) => {
                if (!outline) return;
                window.DotDoc.setOutline(outline);
                window.DotDoc.pagination.setPageSetup(outline.pageSetup, outline.headerSegments, outline.footerSegments);
            });
        },
```

Add a view-mode `<select>` beside the existing style picker in `.doc-bar` (after the `@enderror` that closes the style picker's error slot):

```blade
        <label class="sr-only" for="doc-view-mode">Page view</label>
        <select id="doc-view-mode" class="tool-select"
                x-model="viewMode" @change="window.DotDoc.pagination.setMode(viewMode)">
            <option value="continuous">Continuous</option>
            <option value="single">Single page</option>
            <option value="two-page">Two page</option>
            <option value="multi-page">Multi-page</option>
            <option value="focus">Focus</option>
            <option value="print-preview">Print preview</option>
        </select>

        <button type="button" class="tool tool-mono" aria-pressed="{{ 'false' }}"
                x-bind:aria-pressed="thumbnailsOpen ? 'true' : 'false'"
                @click="thumbnailsOpen = !thumbnailsOpen">Pages</button>
```

Add the thumbnails rail as a sibling of `.editor-side`, inside `.editor-row` (thumbnails and comments can both be open at once — they serve different purposes and neither excludes the other):

```blade
        <div class="editor-thumbnails" x-show="thumbnailsOpen" x-cloak
             aria-label="Page thumbnails">
            <div class="dotdoc-thumbnail-rail" x-init="$nextTick(() => window.DotDoc.pagination.setMode(window.DotDoc.pagination.mode))"></div>
        </div>
```

The rail's own thumbnail population is already wired in Step 1's `runRepaginate()` above (it populates `.dotdoc-thumbnail-rail` whenever the element is present, independent of view mode) — no further change is needed here.

- [ ] **Step 5: Manual browser check (no automated test — this step is pure wiring)**

Run: `npm run build` (or confirm `npm run dev`/`composer run dev` is already running)
Open an existing document in the editor. Verify in the browser console: `window.DotDoc.pagination.pageCount` is a number, `window.DotDoc.pagination.setMode('single')` visibly changes the canvas layout, and the "Pages" button toggles the thumbnails rail.

- [ ] **Step 6: Run the full test suite**

Run: `npm test`
Run: `php artisan test --compact`
Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: all PASS, no new static-analysis findings.

- [ ] **Step 7: Commit**

```bash
git add resources/js/editor/pagination/index.js resources/js/editor/index.js \
        resources/views/livewire/documents/editor.blade.php app/Styles/CssBuilder.php
git commit -m "feat(pagination): orchestrator + editor bundle, Blade, and CssBuilder wiring

window.DotDoc.pagination exposes {mode, setMode, pageCount, currentPage,
goToPage} per design spec §5. Debounced repagination on every editor
update (covers local edits and applyRemote alike, since TipTap's onUpdate
fires on any doc-changing transaction); style/page-setup changes flow
through the existing refreshOutline() round trip. A view-mode <select>
and a thumbnails rail toggle land in .doc-bar; CssBuilder's canvas mode
gains the gap/shadow/band CSS the decorations render into, scoped behind
.dotdoc-paginated so a page that never mounts pagination is unaffected."
```

---

## Task 8: Final verification sweep — browser behaviour and the design gate

**Files:** none created; this task verifies Tasks 1-7 together and fixes anything the sweep finds. Touches whichever files a finding points to.

**Interfaces:** none new.

- [ ] **Step 1: Full regression run**

Run: `php artisan test --compact`
Run: `npm test`
Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: all green, matching every task's own gate above — this is the whole-feature confirmation, not a new bar.

- [ ] **Step 2: Browser verification, per design spec §6**

Using a real document with a long paragraph, a table with more rows than fit one page, and a manually inserted page break:

1. Confirm the page count matches the content's expected length and visible page-boundary decorations (gap + shadow + header/footer bands) appear where expected.
2. Insert a manual page break (`Mod-Enter`) and confirm it forces a boundary regardless of remaining space on the current page.
3. Confirm the table splits between rows with the header row repeated on the continuation, and never starts a page with only its header row visible.
4. Switch through all six view modes (`Continuous`, `Single page`, `Two page`, `Multi-page`, `Focus`, `Print preview`) and confirm each renders correctly in both `html.dark` and default (day) mode.
5. Open the thumbnails rail and confirm clicking a thumbnail scrolls the corresponding page into view.
6. Confirm Focus mode hides page-break decorations, headers/footers, and the rail/dock/topbar chrome.
7. Confirm Print Preview's iframe shows the actual exported PDF (same content the "Export → PDF" menu item downloads).

- [ ] **Step 3: Impeccable + contrast gate on the editor page, per .ai/rules/views.md's GATE**

Follow the exact procedure `.ai/rules/views.md` "GATE before calling a shell change done" already documents, applied to the editor route with pagination active (a document long enough to show at least 2 page boundaries and the thumbnails rail open):

1. Render the editor page to `public/__design/editor-pagination-{night,day}.html` from a throwaway PHPUnit test (`$this->withVite()`), inline `resources/css/shell.css` + `paper.css`, substitute every `var(--token)` for its literal per mode, strip CSS comments before parsing the token block.
2. `node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect public/__design/<file>.html` must print nothing for both files. Canary it first (drop `Inter` into `--font-chrome`) to prove the run is live.
3. Render a second pass with the `<900px`/`<1180px` media blocks flattened, and scan those too.
4. `node scripts/design/contrast-dom.mjs` must print ALL PASS; `--canary` (deleting the inverted-surface rule) must report failures, proving the harness is live.
5. Delete the throwaway test and `public/__design/` before committing — neither is ever committed.

If any of the above surfaces an issue, fix it in the relevant Task's file (most likely `CssBuilder::paginationRule()` from Task 7) and re-run the whole gate from Step 1.

- [ ] **Step 4: Record any durable rule this task discovered**

If the sweep uncovers a non-obvious trap (e.g. an interaction between `dotdoc-mode-focus` and the existing collapsed-rail/dock breakpoints, or a `posAtCoords` edge case at a page's very last line), record it via Laravel Boost's `record-rule` under the `resources/js/editor/**` or `resources/views/**` glob as appropriate, following the existing `.ai/rules/editor.md`/`views.md` pattern.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "fix(pagination): whole-feature verification sweep — <describe what was fixed, if anything>"
```

(If the sweep finds nothing to fix, skip this commit — there is nothing to record beyond the passing gate itself.)

---

## Self-review

**Spec coverage:**
- §1 (what exists today) — read and reused: `PageSetup` unchanged, `PrintRenderer::band()` refactored not reimplemented (Task 1), `Editor::outline()` extended not replaced (Task 2), `CssBuilder`'s canvas mode extended (Task 7), the Fair Copy toolbarVariant.js "pure decision" shape followed by `measure.js` (Task 3).
- §2.1 (triggers) — covered by Task 7's `editor.on('update')` (debounced edit + remote update, since TipTap's `onUpdate` fires on any doc-changing transaction) and `refreshOutline()`→`setPageSetup()` (style/page-setup change, reusing the already-existing round trip rather than new event plumbing).
- §2.2 (block classification, keep-with-next, forced breaks) — `measure.js`'s `ATOMIC_TYPES`/`LINE_SPLIT_TYPES`/`LIST_TYPES`/`FORCED_BREAK_TYPES` and the heading branch (Task 3), with 19 unit tests covering every row of the table plus the two termination-guard edge cases the table doesn't explicitly name but the algorithm must still not hang on.
- §2.3 (decorations, not content) — Task 4's widget decorations, `addToHistory: false`, never serialised.
- §2.4 (header/footer bands, shared logic) — Task 1 (`HeaderFooterBands`) + Task 5 (`bands.js`).
- §2.5 (section breaks) — `resolveSectionPageHeight`/`newPageHeight` threading through Task 3's `computeBreaks` and Task 4's `measureBlocks`; exporters explicitly left untouched (stretch goal, not required — confirmed nowhere in this plan touches `PrintRenderer`'s PDF path or `DocxExporter`).
- §3 (six view modes) — Task 6 (`viewModes.js`) + Task 7 (the `<select>` and CSS).
- §4 (thumbnails, navigation only) — Task 6's `renderThumbnailGrid`; no reorder/duplicate/delete affordance exists anywhere in this plan.
- §5 (component boundaries) — every file in that table has a corresponding task; `Editor::outline()`'s response shape matches exactly.
- §6 (testing) — `measure.js` unit tests (Task 3), `HeaderFooterBands` PHP tests (Task 1) reusing the existing `PrintRendererTest` regression baseline, `Editor::outline()` feature test (Task 2), browser verification and the design gate (Task 8).
- §7 (deferrals) — none of them appear as a requirement in any task above; Gotenberg, incremental repagination, first/odd-even headers, mid-item/mid-columns splitting, and full widow/orphan control are absent by design.

**Placeholder scan:** every step carries real, complete code — no "add appropriate handling," no "similar to Task N," no bare prose describing what a function should do without showing it.

**Type/signature consistency:** `MeasuredBlock`/`Break` (Task 3) are consumed with the identical shape in Task 4's `measureBlocks`/`resolveBreakPosition`. `HeaderFooterBands::segments()`'s `{type, value}` shape (Task 1) is consumed identically by `Editor::outline()` (Task 2, PHP array) and `bands.js`'s `renderBand()` (Task 5, JS object) — the same field names on both sides of the wire. `window.DotDoc.pagination`'s five members (`mode`, `setMode`, `pageCount`, `currentPage`, `goToPage`) are defined once in Task 7's `mountPagination()` return value and never renamed elsewhere.

**Ambiguity check:** "usable page height" is always page-size-minus-margins-minus-band-height, computed once per repagination pass (`getPageSetupForMeasurement`), never left as an unspecified "the available space." The keep-with-next "less than one line" test is the exact `minimumFirstChunk()` comparison, not a vague "reasonable space" — mirroring the design spec's own ambiguity-check note.
