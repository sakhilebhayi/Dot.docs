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
