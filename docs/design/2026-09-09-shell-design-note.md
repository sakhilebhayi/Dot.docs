# Shell design note — "Two Inks on a Desk" (Task 9, 2026-09-09)

Skills consulted before layout: `frontend-design`, `ui-ux-pro-max`
(`--design-system --variance 6 --motion 3 --density 8`, `--domain ux` on
focus/keyboard/contrast, `--stack laravel`).

## Taken

- *frontend-design — ground it in the subject.* The subject is paper on a desk,
  so the rules between panels are the desk's own seams, not decoration.
- *frontend-design — spend boldness in one place.* One signature: the 36px mono
  **status line**, `DOT.DOC │ ▪ SAVED │ v0007 │ 01,204 WORDS │ ▪ NIGHT`, with
  ghosted leading zeros. Everything else stays quiet.
- *ui-ux-pro-max (ux).* Visible `:focus-visible` ring on every control (2px,
  2px offset); skip link; tab order matches visual order; 4.5:1 on body text;
  labels always visible, never placeholder-only; errors beside their field;
  140–300 ms transitions behind a reduced-motion guard; 11px floor on
  functional text.
- *ui-ux-pro-max (laravel).* Alpine for local UI state only; `@props` on every
  anonymous component so the shell API is declared, not implied.

## Hierarchy and scales

Status line (facts) → rail (where am I) → paper (the work) → dock (what the
machine and the data say). One shadow in the product, on `.paper`. Chrome radius
never above 4px; rails and dock share edges with `1px solid var(--rule)`.

- Type, ratio ≥ 1.25 a step: 12 / 15 / 19 / 24 / 31 px. Chrome sans = Atkinson
  Hyperlegible Next; every figure and state word = Atkinson Hyperlegible Mono.
  Body line-height 1.55.
- Space, deliberately uneven so grouping reads: 4 / 8 / 12 / 20 / 32 / 52 px.
- Panels are ledgers: a mono micro-heading, then rows split by hairlines. No
  card, no gutter, no nesting.

## States, empty states, motion

A lamp plus a word, never colour alone: an 8px square nib in `--good` /
`--signal` / `--danger` / `--marker` beside a mono word set in `--text`. Tone
colours are therefore never text, which is how the contrast gate is met by
construction. Hover is a `--desk` wash; active is a 2px inked left edge on rail
items only. An empty state is one sentence in `--text-2` and one action — no
illustration, no icon: "No documents here yet." + *New document*.
AI ink fades in over 180 ms (`--ai-in`); accepted ink settles to graphite over
240 ms (`--ink-settle`). Chrome hover 140 ms. Paper never animates. All of it is
off under `prefers-reduced-motion: reduce`.

## Rejected

- The `--design-system` answer in full: Plus Jakarta Sans (owner's banned list
  and Impeccable's `overused-font`), the #2563EB scan-blue on #F8FAFC, and
  "Soft UI Evolution"'s improved shadows — one shadow exists and the paper owns
  it. Kept only its accessibility and motion-duration guidance.
- The AI-dashboard defaults the brief names: four equal metric tiles, rounded
  cards in gutters, a one-side colour stripe, a radial bloom behind the mark.
  The dashboard is a ledger of readouts instead.
- Material Symbols and emoji as icons — words, or a hairline inline SVG.
- The pulsing live dot: colour-plus-animation with no word, and a detector
  anti-pattern. A static nib and the word replace it.

---

## Fix round 1 (2026-09-09) — the bench, the ruled column, the filled rail

`frontend-design` was re-invoked on two questions the review opened: the
editor toolbar (finding 15) and the dead desk / rail void (finding 25).

### The bench — one row that never reflows

*Structure is information.* The toolbar was three ragged rows because it held
two different kinds of thing in one undifferentiated strip. They are separated
now, and the separation is the design:

- **`.doc-head`** — the document's own slug: title, style, who else is here,
  the state lamp, the version. Facts about the document.
- **`.doc-tools`** — the bench: the twelve controls a writer reaches for
  *mid-sentence*, milled into compartments by the same `1px var(--rule)`
  hairline that separates every other region of the shell. B / I │ H1 H2 H3
  List 1. Quote │ Table Image │ ⌘K │ Undo Redo.
- **More** — everything that acts on the *whole document* (assistant,
  suggesting mode, comments, inline code, voice, export, import, template),
  pushed to the end of the bench behind one hairline.

The split is what makes the row fit. At 1280px the desk is only ~660px wide —
the rail takes 260, the dock 360 — so a bench holding all nineteen controls
could not physically hold one line. Rather than fold clusters at runtime (a
`ResizeObserver` moving DOM nodes fights Livewire's morph, and duplicating
buttons into the menu is worse), the bench is a **curated set** and everything
structural lives in the ⌘K palette, which already lists 35 commands. A single
container query tightens the compartments below 780px of desk; measured, the
row is one line with zero overflow at both 1280 and 1440.

Two smaller corrections came with it: the style picker was the one *boxed*
control among borderless tools and is a `.tool-select` now (it draws its edge
only on hover/focus), and the orphan presence chip moved up into the slug row
where it belongs, beside the people-facing facts.

*Boldness is already spent on the status line, so the bench stays deliberately
unmemorable.* No icons were introduced; the words stayed.

### The page column is ruled, not centred

Centring the 1080px column would have left two dead margins instead of one, and
"centre it" is the answer any brief would get. The column stays **left-anchored
— a ledger is read from its left edge, and the rail's hairline is what anchors
it — and the desk to its right is RULED**: `.page` ends in the same
`1px var(--rule)` seam every other region of the shell ends in, running the full
height of the desk. The empty area then reads as the desk's margin rather than
as an unfinished layout. The rule is drawn by a container query on the desk
(not the viewport, because the rail and the dock both eat into it) so it only
appears once there is actually desk to the right of it, and never doubles up
against the dock's own edge.

### The rail ends where its list ends

The 190px void came from `margin-top: auto` on `.rail-foot` pushing the account
block to the bottom of a column whose content stopped short. The groups now sit
in a `.rail-scroll` region that absorbs the leftover and scrolls; the account
block is a pinned `.rail-foot`. The groups are seam to seam, and the rail ends
where the list ends. Notifications, which were a 320px dropdown clipped on both
axes inside that 260px scroll container, open as a sheet.
