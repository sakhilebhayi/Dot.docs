# Dot.Doc — "Fair Copy" Redesign (Phase 1 of the Unified Experience)

**Status:** approved direction (owner brief 2026-09-11)
**Parent request:** "Dot.Docs + Dot.Files — Unified Document & File Experience" (39-section owner brief, 2026-09-11)
**Supersedes, for Dot.Doc only:** the "Two Inks on a Desk" chrome from `docs/superpowers/specs/2026-09-07-dot-doc-platform-design.md` §7, shipped in Task 9 (PR #2). Two Inks is not retracted as an ecosystem pattern — it is the right fit for control-room products like Dot.Memory. It is wrong for Dot.Doc, which the owner has now said explicitly.

## Why this document exists

The owner's brief describes three largely independent bodies of work: a visual redesign away from control-room language, a real A4 pagination engine, and deep Dot.Doc ↔ Dot.Files UX (embedded browser, drag-and-drop, unified search, real-time sync, command palette). Building all three as one plan would hide failure in any one piece behind the other two. This spec covers **only the first**: the chrome redesign. Pagination and deep Files UX are named in §9 below as explicitly deferred, with the interface seams this phase must leave open for them.

Three decisions were confirmed with the owner before writing this:
1. Full replacement of the Dot.Doc chrome, not a selective evolution of Two Inks.
2. Pagination fidelity target (for Phase 2, not this phase): debounced repagination, matching how Word and Google Docs actually behave, not per-keystroke recalculation.
3. Delivery shape: phased, build-and-review one piece at a time, matching how Tasks 1–15 were executed.

Two additional scope boundaries were stated as routine calls, not asked as questions, because reversing either would be free to correct later and low-risk either way:
- **Task 6's fourteen Document Styles are untouched.** They govern the page's own typography (`app/Styles/StyleEngine.php`, `database/seeders/DocumentStyleSeeder.php`) and have nothing to do with the control-room critique, which is about the app's chrome.
- **The rail / canvas / dock structural skeleton stays** (`resources/views/components/shell/{rail,dock}.blade.php`), restyled rather than rebuilt. Section 13 of the owner's brief describes exactly this structure — a document canvas with an optional collapsible left panel and an optional collapsible right panel.

## 1. What exists today (read before implementing)

- `resources/css/shell.css` (~1600 lines) — Two Inks tokens (`--desk`, `--desk-raised`, `--rule`, `--paper`, `--marker`, `--signal`, lamp/readout/ledger/status-line/dock/rail rules), night-default with a day override.
- `resources/css/paper.css` — the canvas/editor chrome (toolbar, palette, bubble menu, figure/caption styling, xref/toc/callout rendering) — this is where the "3 ragged toolbar rows at 1440px" defect Task 9's review found lives.
- `resources/views/layouts/app.blade.php` — the shell skeleton: skip link, status line, rail, `<main class="desk">`, dock. Reads a `theme` cookie server-side.
- `resources/views/components/shell/{rail,dock,status-line,lamp,figure}.blade.php` — the Two Inks primitives.
- `resources/js/shell.js` — theme toggle, rail collapse, dock tabs (APG pattern), outline `MutationObserver`, save-state event listener.
- `resources/views/livewire/documents/{index,editor,version-history,share-manager,document-settings,template-gallery,comment-thread,webhook-manager}.blade.php` — every inner page, all restyled onto Two Inks tokens in Task 9.
- `resources/views/livewire/files/navigator.blade.php`, `app/Livewire/Files/Navigator.php`, `app/Livewire/Files/BrowsesTheTree.php` — the Task 14 file-tree browser embedded in `Index`, already reading from the shared `objects`/`folders`/`files` tables (Tasks 14–15, PR #2 and Dot.Files PR #74).
- `resources/js/editor/{index.js,commands/registry.js,ui/{palette,slash,bubble}.js}` — the `⌘K` palette, slash menu, and selection bubble. These are functionally sound (Task 8, four review rounds) and are **not** being rewritten — only restyled.
- `scripts/design/contrast-dom.mjs` — the rendered-pair contrast checker built in Task 9, reused as-is.
- `.ai/rules/views.md` — the current Two Inks rule file; this phase replaces its content for Dot.Doc.
- The guest-facing pages (`resources/views/layouts/guest.blade.php`, `welcome.blade.php`, auth cards) already use **Fraunces / Work Sans / IBM Plex Mono** with a warm identity, per Task 9's own review, which flagged the mismatch between this identity and the (then） Two Inks authenticated shell as a follow-up. This phase resolves that flagged inconsistency by design, not by accident.

## 2. Design language: "Fair Copy"

A fair copy is the clean final draft a writer produces once the messy work is done. The name states the product's job and carries no dashboard connotation.

### 2.1 Principles

- **The document is the object in the room.** Chrome recedes; the page has visible presence (generous surrounding space, a soft distinguishing shadow) without competing with it.
- **Whitespace is the primary structural device**, not hairlines. Two Inks used shared edges and ruled grids deliberately, because that is correct for an instrument panel. Fair Copy inverts this: sections are separated by space first, a rule only where content would otherwise be ambiguous.
- **Contextual, not exhaustive.** Controls appear when relevant (selection-driven toolbar, expand-on-demand panels) rather than being permanently on screen.
- **One signature accent, used rarely.** Not violet (generic AI default), not amber (already the Dot ecosystem's general signal colour and Two Inks' own accent), not the cream-paper-plus-serif combination — that specific look has already been rejected elsewhere in this ecosystem as a recognisable AI-default aesthetic and must not reappear here under a different name.

### 2.2 Typography

| Role | Family | Notes |
|---|---|---|
| Chrome display (page titles, empty-state headlines, section labels in nav) | **Fraunces**, optical size `opsz` 24–72, weight 400–600 | Already loaded on the guest pages; add the authenticated layout's `<link>` |
| UI body, labels, buttons, form fields | **Work Sans**, weight 400–600 | Replaces Atkinson Hyperlegible Next entirely in chrome |
| Figures that must be tabular (word count, version number) shown as plain numerals, not instrument-panel readouts | Work Sans tabular-figure variant, or a light **IBM Plex Mono** only where genuinely tabular alignment matters (never as a design motif) | No padded zeros, no `aria-hidden` ghost-digit trick — that device belongs to the control-room language being retired |
| Document body/headings | **Unchanged** — the 14 `DocumentStyle` records (Source Serif 4, Source Sans 3, etc.) | Out of scope; do not touch `CssBuilder`/`DocumentStyleSeeder` |

No Inter, Geist, Space Grotesk, or Plus Jakarta Sans — same ban as before, unchanged.

### 2.3 Colour

Light-first (the previous system defaulted to night; a writing tool defaults to day), with a genuine, non-inverted dark mode.

| Token | Day | Night | Role |
|---|---|---|---|
| `--ground` | `#f5f3ee` | `#1c1a17` | App background behind the page |
| `--surface` | `#fbfaf7` | `#242119` | Panels, dock, rail |
| `--surface-raised` | `#ffffff` | `#2b2820` | The page canvas itself |
| `--ink` | `#23211d` | `#eeece6` | Primary text |
| `--ink-soft` | `#6b675f` | `#a39d90` | Secondary text, placeholders |
| `--line` | `#e5e1d8` | `#38352c` | The only rule colour — used sparingly, never as a grid |
| `--accent` | `#2f5233` | `#7fae7a` | Links, active states, the signature ink-green |
| `--accent-soft` | `#2f523314` | `#7fae7a1f` | Accent backgrounds (selected row, active tab) |
| `--danger` | `#a63b2c` | `#d97c68` | Errors, destructive actions |

`--ground` is a warm neutral measured to stay clear of both the near-black-plus-bright-accent pattern and the classic cream-plus-terracotta pattern: cooler and lower-chroma than a true cream, and paired with green rather than a warm accent, so the two rejected looks are not reachable by drifting either token alone.

Contrast is re-verified with `scripts/design/contrast-dom.mjs` against the new tokens (script is reused; its `INVERTED` surface list and token map are updated for the new variable names) before this phase is called done — every text/background pairing ≥ 4.5:1 in both modes, exactly as Task 9 required.

### 2.4 Structural rules (replacing Two Inks' rules 1:1)

| Two Inks rule (retired for Dot.Doc) | Fair Copy rule |
|---|---|
| Panels share edges over a ruled grid | Panels separated by generous padding; a rule appears only where two surfaces of different elevation actually touch (e.g. rail against ground) |
| Status is a lamp (coloured nib) + word | Status is a word with a small dot in `--accent`/`--danger`/`--ink-soft`, no bordered "lamp" compartment |
| Figures are mono, zero-padded readouts | Figures are plain numerals in the UI font; no padding, no ghost digits |
| No border-radius above 4px in chrome | Radius is allowed and used deliberately — 8px on interactive surfaces (buttons, fields, the palette), 12px on the page canvas itself, still never the "rounded card floating in a gutter" pattern (surfaces sit flush against `--ground`/`--surface`, not as isolated tiles with visible gaps on all sides) |
| One shadow only, on the paper | Still true — the page canvas keeps the only shadow in the interface; panels/rail/dock remain flat |
| Rails/dock collapse but default open | Rail and dock **default to collapsed** on first load of a document; the canvas gets the full width. They expand on click or on a relevant action (opening the outline, invoking AI, opening comments) |

## 3. Layout

Same three regions as today, restyled and rebalanced:

- **Top bar** (`resources/views/components/shell/topbar.blade.php`, new — replaces `status-line.blade.php` for Dot.Doc; the component name and file are new, the old one is not reused under a new skin because its DOM shape is status-line-specific): document title (editable inline), save status as a word + dot, undo/redo, share, export, collaborator avatars, profile menu. No platform name/version/word-count readout row — those move into the (collapsed-by-default) left panel as a quiet footer line.
- **Left panel** (rail, collapsed by default): outline, pages (a stub list for now — real page thumbnails are Phase 2), the Task 14 file navigator (folders/documents in the current location), recent documents. Expands via a single toggle in the top bar or by pressing `⌘\`.
- **Canvas**: the page, centred, with visibly more surrounding space than Two Inks' `.paper` treatment gave it. Canvas background is `--ground`, page is `--surface-raised` with the one permitted shadow.
- **Right panel** (dock, collapsed by default): Intelligence (AI) and Files (attachments/data), same Task 8/12 backing logic, restyled. Expands automatically when the user invokes AI (`⌘K` → an AI command, or the floating toolbar's "Ask" action) or attaches a file, per §14 of the owner's brief ("show relevant tools when relevant").

## 4. Contextual toolbar

Retires the persistent multi-row toolbar (`paper.css`'s `.doc-tools`) that Task 9's own review flagged as wrapping into three ragged rows at realistic widths. Replaces it with:

- A **slim persistent bar** directly under the top bar: document-style picker, `⌘K`, and nothing else that needs constant visibility.
- A **floating contextual toolbar** anchored to the current selection (reusing the existing bubble-menu plumbing in `resources/js/editor/ui/bubble.js` — that JS is sound, only its CSS/markup skin changes): text marks when text is selected, image tools when an image/figure is selected, table tools inside a table, heading/outline tools when a heading is the selection anchor. This is exactly the mechanism `bubble.js` already implements for the inline mark toolbar; this phase extends its trigger conditions (image, table, heading) rather than building a new system.

The `⌘K` palette (`resources/js/editor/commands/registry.js`) is restyled onto Fair Copy tokens; commands named in the owner's brief §31 are added to the registry only where the underlying action already exists (search, export, share, AI operations, insert table/chart). No command is added that has no implementation behind it yet — a palette entry that does nothing is worse than an absent one.

## 5. Empty states and document creation

Rewritten in the plain language of §17 of the owner's brief. The documents-index empty state and the blank-new-document state both get: one sentence, then up to three actions (blank document, use a template, create with AI) — no illustration, no "No documents found."

**Smart save / create flow** (§18): a single lightweight sheet — name, a document-type/template picker (existing `TemplateGallery` data), and a location field. The location field defaults to the folder the user is currently viewing (read from `BrowsesTheTree`'s current node, the same context Task 14 already tracks) and lets them pick a different folder from the same tree the Navigator already renders. This phase does **not** add recents/favourites/starred to the picker — that depth is Phase 3 (§19–20 of the owner's brief). The seam: the location field is a small Livewire component (`app/Livewire/Documents/LocationPicker.php`, new) with one public method, `resolve(): Obj`, so Phase 3 can extend what it offers without changing how `SmartSaveSheet` calls it.

## 6. Responsive posture (principles only, not a full mobile redesign this phase)

- The rail and dock become full-screen overlays below ~900px rather than persistent columns — they already collapse-to-icon at that width in the current CSS; this phase changes the collapsed *behaviour* from "narrow rail" to "hidden, opened by a single control" to match a phone-sized canvas-first layout.
- The floating contextual toolbar becomes a bottom sheet on touch, matching the existing selection-bubble's mobile fallback pattern already scaffolded in Task 9's CSS.
- Full mobile workflows (comments, AI, sharing tuned for touch) are not re-verified this phase beyond "does not break" — that is out of scope until the owner asks for it explicitly.

## 7. What does not change in this phase

- The editor's JS behaviour (`resources/js/editor/**`) — autosave, applyRemote, block-insert guards, offline drafts, the entire Task 8 hardening. Only CSS classes/markup this JS renders into are restyled.
- The Document Styles engine, print rendering, DOCX/Markdown import-export, audit log, search, AI transport (Tasks 6, 7, 10, 12).
- The shared file-tree data model and `FilesService` (Tasks 14–15) — this phase is a consumer of `Navigator`/`BrowsesTheTree`, not a modifier of them.
- Guest/marketing pages — already on Fraunces/Work Sans; left as-is, now consistent with the authenticated shell instead of clashing with it.

## 8. Verification

Same discipline as Task 9, reapplied to the new tokens:
1. Invoke `frontend-design` and `ui-ux-pro-max` before layout work; record the decisions and rejected alternatives in a short design note under `docs/design/`.
2. Impeccable (`node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect`) on every page this phase touches (dashboard, documents index, editor, version history, share manager, document settings, template gallery), night and day — zero findings.
3. `scripts/design/contrast-dom.mjs`, updated for the new token names and surfaces, all pairs ≥ 4.5:1, with the same canary-deletion self-check Task 9 used to prove the harness isn't vacuous.
4. Browser verification: rail/dock default collapsed and expand correctly; contextual toolbar appears for text/image/table/heading selections and nowhere else; `⌘K` and the existing keyboard paths (Task 8) still work unmodified; the smart-save sheet defaults its location to the current folder; every dialog keeps the established Escape-closes-and-returns-focus pattern.
5. Full existing suite (346 tests as of Task 15) stays green — this is a CSS/markup/one-new-component phase, not a data or business-logic change, so no existing test should need to change beyond selector updates in `ShellTest`-style assertions that check for specific class names/strings tied to the old system (e.g. `assertSee('class="status-line"')`) — update those assertions to the new equivalents rather than deleting the coverage they provide.

## 9. Explicit deferral (for the next two specs, not built now)

- **Phase 2 — A4 pagination engine.** Real page boundaries, headers/footers, orphan/widow control, keep-with-next, table-splitting, multiple page views (single/continuous/two-page/multi/focus/print-preview), page thumbnails. Debounced repagination, matching Word/Google Docs' own idle-recalculation behaviour, per the owner's confirmed fidelity target. Gets its own spec once this phase ships.
- **Phase 3 — Deep Dot.Doc ↔ Dot.Files UX.** Full embedded file browser (recent/starred/shared/trash), drag-and-drop, unified content search across both products, real-time broadcast sync (rename/move/delete reflected immediately without refresh), sharing/permission unification, version-history AI summaries, document attachments from Dot.Files, AI-powered organisation suggestions, the cross-ecosystem "find and build a report" capability. Builds on the One Tree model (Tasks 14–15) and this phase's `LocationPicker` seam. Gets its own spec once Phase 2 ships.

---

## Self-review

**Placeholder scan:** no TBD/TODO; every section states a concrete decision (tokens, fonts, component names, deferred boundaries).

**Internal consistency:** the "what does not change" section (§7) and the deferred section (§9) do not overlap with what §3–5 claim to build; the token table (§2.3) is referenced consistently by the structural-rules table (§2.4) and the verification section (§8).

**Scope check:** focused enough for one implementation plan — one repo, CSS/Blade/one new Livewire component, no schema changes, no new dependencies.

**Ambiguity check:** "collapsed by default" is stated with its exact expansion triggers (§3) so it isn't reinterpretable; the palette's command list is bounded to "only where the action already exists" so no one over- or under-builds it.
