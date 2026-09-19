# Dot.Doc — A4 Pagination Engine (Phase 2 of the Unified Experience)

**Status:** approved direction (owner brief 2026-09-11, confirmed 2026-09-15)
**Parent request:** "Dot.Docs + Dot.Files — Unified Document & File Experience" §5–8, §16
**Follows:** the "Fair Copy" chrome redesign (`docs/superpowers/specs/2026-09-11-dot-doc-fair-copy-redesign-design.md`), merged.

## Why this document exists

Sections 5–8 of the owner's brief ask for a genuine word-processor page experience: real A4 pages with margins, headers, footers, page numbers, automatic and manual page breaks, keep-with-next, table splitting, and several page-view modes. Nothing in the editor does this today — `.paper` is one fixed-width, unbounded-height sheet (`app/Styles/CssBuilder.php`), and `pageBreak`/`sectionBreak` nodes render as decorative dividers, not real page boundaries.

Three decisions were confirmed with the owner before writing this:
1. **Print engine stays dompdf.** The live view and the exported PDF are two different rendering engines and will not be pixel-identical on every edge case. This is accepted, not fixed this phase.
2. **v1 targets block-level pagination only.** Headings, figures, tables, and callouts are never orphaned from their content. A single stranded line of a long paragraph at a page edge is possible; full widow/orphan control is a later refinement.
3. **Page thumbnails are navigation-only.** No reorder/duplicate/delete — those don't have a clean meaning when pages are a computed result of prose, not objects the user owns.

## 1. What exists today (read before implementing)

- `app/Documents/Schema/DocumentSchema.php` — `pageBreak` and `sectionBreak` are already real block node types (`sectionBreak.attrs.setup` can carry landscape/page-size overrides, currently inert everywhere it's read).
- `app/Print/PageSetup.php` — resolves page size, orientation, margins, and header/footer templates from the document's `DocumentStyle` plus any per-document override. This is the single source of truth for page dimensions and does not change in this phase.
- `app/Print/PrintRenderer.php` — the PDF pipeline. `band()` (its private header/footer template method) splits a template into `{type: 'text'|'field', value}` segments, substituting every variable except the two page-number fields, which become dompdf's `addField('PAGE'/'NUMPAGES')`. This segment-splitting logic is exactly what closed the Word field-injection vulnerability found in Task 10's review — it must be reused, not reimplemented, for the live view's header/footer bands.
- `app/Styles/CssBuilder.php` — `'canvas'` mode renders `.paper` at a fixed `210mm` width with `min-height: 297mm`, the paper growing taller than one page as content is added. No page-break decoration exists.
- `resources/js/editor/index.js`, `commands/registry.js`, `ui/bubble.js` — the Task 8/Fair Copy editor bundle. Its autosave, `applyRemote`, block-insert guards, and offline-draft behaviour are unchanged by this phase.
- `app/Livewire/Documents/Editor.php::outline()` — an existing Livewire method (Task 8) returning heading numbers and TOC data after every save. This phase extends its response; no new endpoint is introduced.
- `resources/js/editor/ui/toolbarVariant.js` — the Fair Copy pattern for a pure, dependency-free, `node --test`-able decision function feeding a ProseMirror-facing consumer. This phase's measurement algorithm follows the same shape.

## 2. Architecture: decoration, not division

ProseMirror requires one continuous editable document. No production editor — including Word and Google Docs under the hood — splits live typing across multiple page-sized DOM roots. Pagination therefore works by **decoration**: the document stays one continuous ProseMirror doc and DOM tree; after the user pauses, a measurement pass computes where page boundaries fall and inserts non-editable widget decorations there — a gap, a shadow, the previous page's footer, the next page's header. Typing continues through a page boundary exactly as in Word.

### 2.1 What triggers repagination

- A debounce timer (short — a few hundred milliseconds of idle time after the last edit; faster than the 1200ms autosave debounce, since this is a local visual computation with no network cost).
- A document style change (margins or page size changed).
- A remote update arriving via `applyRemote` (a collaborator's edit changed content).
- A manual page break, section break, or page-setup change saved by the current user.

Repagination is **not** triggered by browser resize or zoom — page dimensions are fixed physical sizes (`210mm` etc.), independent of viewport width, matching the existing `.paper` behaviour.

### 2.2 Measurement algorithm

Given the usable page height (page height in px at 96dpi, minus top/bottom margins, minus header/footer band heights, minus any section's landscape override — see §2.5), walk the top-level children of `.paper` in document order, accumulating rendered height (`getBoundingClientRect()`), and decide where each page ends.

**Block classification:**

| Behaviour | Node types |
|---|---|
| **Atomic** — moves whole to the next page if it doesn't fit; never splits | `figure`, `image`, `callout`, `horizontalRule`, `toc`, `columns` (mid-columns splitting is a named deferral, §7) |
| **Splits at a line boundary** wherever the accumulated height runs out (normal paragraph reflow) | `paragraph`, `blockquote` |
| **Atomic** (a heading is a keep-with-next anchor, not a splittable block — see below) | `heading` |
| **Splits between rows**, reserving space for the header row (`tableHeader`) at the top of the continuation | `table` |
| **Splits between items**; an individual item is atomic for v1 (mid-item splitting for a very long list item is a named deferral, §7) | `bulletList`, `orderedList`, `taskList` |

**Keep-with-next:** a `heading` is never the last visible content on a page. When the walk reaches a heading and less than one line of the following block would fit in the remaining space, the heading itself moves to the start of the next page instead of being placed at the bottom of the current one. The same rule applies to a table's header row in isolation: a table never begins a page with only its header row and zero data rows visible before the next break.

`measure.js`'s break-position math *reserves* the header row's height on every continuation page, and `decorations.js`'s `cloneTableHeaderRow()`/`buildTableHeaderClone()` clone the header row's own DOM onto a mid-table split's continuation — a `display:flex` row of plain `<div>` cells, deliberately not a `<table>` or `<th>`/`<td>` elements, since a real `<table>` (or anything the UA stylesheet defaults to `display:table-cell`) nested inside the ORIGINAL table's own `<tbody>` was found to feed back into that table's own auto-layout column-width computation under repeated reflows - see `.ai/rules/editor.md`.

**Forced breaks:** when the walk encounters a `pageBreak` node, a break is forced there regardless of remaining space. A `sectionBreak` is also a forced break and, per §2.5, may change the usable page height for everything after it.

**What v1 does not attempt** (see §7 for the full deferred list): guaranteeing at least two lines of a paragraph stay together (true widow/orphan control); reordering, duplicating, or deleting a computed page as an object; mid-list-item or mid-columns splitting; different first-page or odd/even-page header layouts.

### 2.3 Decorations, not document content

Breaks are rendered as ProseMirror **widget decorations** — DOM nodes inserted at computed positions that are not part of the document's JSON and are never saved. Each decoration renders: the previous page's footer band, a visual gap (background matching `--ground`, the token from the Fair Copy redesign), the paper-edge shadow on both adjacent page rectangles, and the next page's header band. This is what makes "Continuous Pages" mode look like genuinely separate sheets while the underlying document remains one flow.

This boundary mechanism only ever describes a break *between* two pages, which structurally cannot reach either end of the document: nothing renders page 1's header (no boundary exists before it) or the last page's footer (no boundary exists after it). Two further widget decorations close that gap (Task 8), fixed at the document's start and end positions rather than at a computed break — each showing only its own single band (no gap, no shadow, since there is no adjoining page to shadow or gap against): one renders page 1's header at position `0`, the other the final page's footer at the document's end position. Both are always present whenever pagination has computed at least one page (which is always).

### 2.4 Header and footer bands

`Editor::outline()` is extended to also return the resolved `PageSetup` (size, orientation, margins) and the header/footer templates pre-split into segments using the **same** `band()` logic `PrintRenderer` already uses server-side — extracted into a small shared class both consumers call, so the field-injection-safe substitution exists in exactly one place. Every segment is either literal text (already fully substituted — title, date, team, document variables) or one of the two live tokens (`PAGE`, `NUMPAGES`), which the client fills in per page from its own computed page index and total page count. No template substitution logic is reimplemented in JavaScript.

### 2.5 Section breaks and per-section page setup

A `sectionBreak` node's `attrs.setup` can carry a page-size or orientation override (already part of the schema, currently inert in every renderer). This phase makes it meaningful for the **live view**: when the measurement walk crosses a `sectionBreak`, it recomputes the usable page height and width from that section's own setup (falling back to the document's page setup for anything the section doesn't override) for every subsequent page until the next `sectionBreak`. Making `sectionBreak.attrs.setup` equally meaningful in the PDF/DOCX exporters (currently inert there too, a pre-existing cross-cutting gap) is a **stretch goal, not a requirement** of this phase — closing it would mean reopening Task 7's `PrintRenderer` and Task 10's `DocxExporter`, a separately-scoped effort.

## 3. View modes

Once page boundaries are computed, four of the six modes the brief asks for are different arrangements of the same decoration set — no separate pagination logic per mode. (Two Page shipped as a fifth mode was rejected during implementation and deferred, §7; Print Preview is the sixth and needs none of this section's engine at all.)

| Mode | Implementation |
|---|---|
| **Continuous** (default) | Pages stacked vertically with the gap decorations; normal scroll. |
| **Single Page** | Same DOM; CSS `scroll-snap-type: y mandatory` with one `scroll-snap-align` per page, plus a page-N-of-total control. |
| **Two Page** | **Deferred out of v1 (§7).** Not present in the view-mode `<select>`; a two-column grid was drafted during implementation and rejected before shipping because `.paper` is one continuous element for the whole document (§2), so it would put that single element in column 1 and leave column 2 permanently, visibly empty. |
| **Multi-Page** | A zoomed-out grid of scaled page previews. **Shares its implementation with the page-thumbnails panel** (§4) — one CSS/rendering approach, presented either as a full-canvas overview or a persistent rail. |
| **Focus** | Hides page-break decorations, headers/footers, and all chrome (rail/dock/topbar) — a single continuous scroll of prose, closest to the pre-pagination canvas. The chrome-hiding half reaches the rail/dock/topbar via a `:has()` selector on `.shell` (`App\Styles\CssBuilder::paginationRule()`), since `.editor-main`'s Focus-mode class cannot reach them with an ordinary descendant selector — they are its own ancestor's siblings, several levels higher in `.shell`'s DOM. It deliberately never touches `data-panel-state`/`data-panel-user`, only visibility: a panel the writer had open before Focus mode stays recorded as open the whole time and simply reappears on the way out, with no restore step needed. |
| **Print Preview** | **Not computed live at all.** Embeds the real, already-generated PDF from the existing export route in an `<iframe>`. Preview and output are identical by construction — zero new rendering risk, and this mode needs no work from §2's engine. |

## 4. Page thumbnails (navigation only)

A rail panel (or the Multi-Page view's full-canvas form) listing every computed page: page number, a scaled-down preview, and a highlight on whichever page is currently in view. Clicking a thumbnail scrolls that page into view (or jumps to it directly in Single Page mode). No reorder, duplicate, or delete action exists — a page is a computed consequence of where the prose broke, not an object with independent identity, so those actions from the owner's brief are intentionally not built (confirmed with the owner, §"Three decisions" above).

## 5. Component boundaries

| File | Responsibility |
|---|---|
| `resources/js/editor/pagination/measure.js` | Pure functions: given block descriptors (`{type, height}` in document order) and a usable page height, produce break positions. Zero DOM mutation — the same "pure decision, dependency-free, `node --test`-able" shape as `toolbarVariant.js`. |
| `resources/js/editor/pagination/decorations.js` | Turns break positions into a ProseMirror `DecorationSet`; owns widget creation/teardown. |
| `resources/js/editor/pagination/bands.js` | Renders header/footer band DOM from the server-supplied segments plus the live page index/total. |
| `resources/js/editor/pagination/viewModes.js` | CSS class toggling and scroll behaviour for the five shipped modes (`MODES`, §7 on Two Page); owns the Multi-Page/thumbnails shared rendering. |
| `resources/js/editor/pagination/index.js` | Orchestrator: debounce timer, wiring into `editor.on('update')` and `applyRemote`, exposes `window.DotDoc.pagination = { mode, setMode, pageCount, currentPage, goToPage }`. |
| `app/Print/HeaderFooterBands.php` (new, extracted from `PrintRenderer`) | The shared, security-hardened template-to-segments logic both `PrintRenderer` and `Editor::outline()`'s new payload call. |
| `app/Livewire/Documents/Editor.php::outline()` (modified) | Response gains `pageSetup` and `headerSegments`/`footerSegments` alongside the existing `numbers`/`toc`. |

## 6. Testing

- `measure.js`'s core algorithm is pure and unit-tested with `node --test`: given synthetic block-height arrays and a page height, assert break positions, keep-with-next behaviour (a heading with insufficient trailing room moves down), table splitting with header-row repetition, and forced breaks at `pageBreak`/`sectionBreak`.
- `HeaderFooterBands` gets the same PHP test coverage `PrintRenderer::band()` already has (Task 10's field-injection regression test applies unchanged, since the logic is extracted, not rewritten).
- `Editor::outline()`'s new response fields get a feature test.
- Browser verification: a long document produces the expected page count and visible breaks; a manual page break forces a boundary regardless of remaining space; a table splits between rows with space correctly reserved for the header row, and the header row itself is visually repeated on the continuation (confirmed live with a 30-row table forced to split mid-table, including that the repeat's own text updates when the header cell is edited, and that unrelated edits elsewhere in the document leave the repeat's measured width stable rather than drifting — see `.ai/rules/editor.md`'s note on the underlying auto-layout instability this had to work around); switching each of the five shipped view modes renders correctly in both night and day (Two Page deferred, §7) — confirmed live in Task 8's browser verification, along with a dark-mode thumbnail legibility bug found by that same pass and fixed before this plan's whole-branch review, and Focus mode's chrome-hiding gap found by the same pass and fixed afterward (rail/dock open-or-collapsed state confirmed unchanged across a full Focus-mode enter/exit cycle); Impeccable and the rendered-pair contrast script are re-run on the editor page with pagination active, matching the Fair Copy bar.

## 7. Explicit deferral

Named now so it is not silently dropped later:

- **Full widow/orphan control** (guaranteeing at least two lines of a paragraph stay together) — needs sub-paragraph line measurement via `Range.getClientRects()`; a real, separable refinement once block-level pagination is proven.
- **Mid-list-item and mid-columns splitting** — both treated as atomic for v1.
- **Different first-page and odd/even-page header/footer layouts** — the brief names this, but it is a book-typesetting convention with limited value for this product's actual documents (mining production reports, board memos, safety incidents); revisit if a real use case appears.
- **Closing the `sectionBreak.attrs.setup` gap in the PDF/DOCX exporters** — a stretch goal (§2.5), not required this phase.
- **Adopting Gotenberg (headless Chromium)** as the print engine to close the live-view/PDF divergence gap — explicitly declined for now (§"Three decisions").
- **Incremental repagination** (re-measuring only from the edited point onward, reusing earlier pages' cached breaks) — v1 recomputes the whole document on each debounced pass; only worth building if a long real document proves this too slow in practice (YAGNI).
- **Two Page mode showing two DIFFERENT pages side by side** (Task 8, confirmed during Task 7's review) — `.paper` is one continuous DOM element for the whole document (§2: decoration, not division), so it structurally cannot place two different pages in two grid columns without the same live Range-based content-extraction technique the page thumbnails use (§4, `viewModes.js`), applied to the editable canvas instead of a read-only clone. That is a materially bigger feature — it would mean this one mode shows read-only clones rather than the live editable canvas, unlike every other mode — not a v1 fix. Task 7 drafted a `grid-template-columns: repeat(2, <page width>)` rule that put the single `.paper` in column 1 and left column 2 permanently, visibly empty; rather than ship that, or a purely cosmetic "wider gutters" approximation that doesn't actually show two pages, `two-page` was removed from the view-mode `<select>` and from `viewModes.js`'s `MODES` for v1.

---

## Self-review

**Placeholder scan:** no TBD/TODO; every section states a concrete mechanism (measurement algorithm, decoration approach, band reuse, view-mode implementation).

**Internal consistency:** §7's deferrals do not silently reappear as requirements elsewhere; §3's "Print Preview needs no work from §2's engine" is consistent with §2 only describing the live on-screen canvas, not the export pipeline (unchanged, per Task 7/10/12).

**Scope check:** one repo, no schema changes (the node types already exist), no new dependencies, a bounded set of new JS/PHP files with single responsibilities each.

**Ambiguity check:** "atomic" is defined per node type in the §2.2 table rather than left as a general concept; the keep-with-next rule states its exact trigger ("less than one line of the following block would fit") rather than "reasonable space."
