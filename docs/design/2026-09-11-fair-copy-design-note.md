# Shell design note — "Fair Copy" (Task 1, 2026-09-11)

Replaces "Two Inks on a Desk" for Dot.Doc (`docs/design/2026-09-09-shell-design-note.md`).
Skills consulted before any CSS: `frontend-design`, `ui-ux-pro-max`
(`--design-system --variance 3 --motion 2 --density 3`, `--domain ux`, `--stack laravel`).

## The subject, named before designing

A fair copy is the clean final draft a writer produces once the messy work is done.
The subject is the **sheet**, not the instrument that reads it. The shell's one job:
put the page in the middle of the room and get out of the way.

## Taken

- *frontend-design — ground it in the subject.* The page is the only object with
  elevation; space, not hairlines, does the dividing. A rule survives only where two
  surfaces of different elevation actually touch (rail/dock against the canvas).
- *frontend-design — spend boldness in one place.* One signature: the **topbar title
  in Fraunces**, on a 52px bar carrying nothing else but a status word and two panel
  toggles. Everything else is Work Sans at one of five sizes.
- *frontend-design — structure is information.* Panels default **collapsed** on the
  editor and **expanded** on the listing routes, because the canvas only competes for
  width where there is a canvas. The state is server-rendered (`data-panel-state`), so
  it never flashes.
- *ui-ux-pro-max (ux).* Visible `:focus-visible` ring on every control; skip link;
  labels always visible; errors beside their field; 4.5:1 text and 3:1 indicators;
  140–300 ms transitions behind a `prefers-reduced-motion` guard; an empty state is a
  sentence plus an action; loading gets a word, not a spinner.
- *ui-ux-pro-max (laravel).* Alpine for local UI state only; `@props` on every
  anonymous component; no `wire:click` for a purely visual toggle.

## Tokens

Day is bare `:root`; night is `html.dark`, plus a `prefers-color-scheme: dark` guard
for readers who never touch the switch. A writing tool defaults to day.

| Token | Day | Night |
|---|---|---|
| `--ground` / `--surface` / `--surface-raised` | `#f5f3ee` / `#fbfaf7` / `#ffffff` | `#1c1a17` / `#242119` / `#2b2820` |
| `--ink` / `--ink-soft` / `--line` | `#23211d` / `#6b675f` / `#e5e1d8` | `#eeece6` / `#a39d90` / `#38352c` |
| `--accent` / `--accent-soft` / `--danger` | `#2f5233` / `#2f523314` / `#a63b2c` | `#7fae7a` / `#7fae7a1f` / `#d97c68` |

Fraunces (display, `opsz 9..144`), Work Sans (everything functional), IBM Plex Mono
only where a column of figures has to align. Ramp 12/15/19/25/33px; space
4/8/12/20/32/56px — the top steps grew, because whitespace is the structural device.
Radius 8px on anything you click, 12px on the canvas, 0 elsewhere. One shadow, on the
page. **The document canvas does not invert:** `CssBuilder` writes
`.paper{background:#fff}` and Document Styles are out of scope (spec §7), so `--paper`
/ `--paper-ink` are a separate, non-inverting pair the contrast gate measures on their own.

## States, empty states, motion

Status is a **word plus a 6px dot** — three tones (`idle`/`good`/`danger`), no bordered
compartment, no amber. Figures are plain numerals with tabular figures: no zero padding,
no ghost digits, nothing `aria-hidden` to exempt from the contrast floor. Hover is an
`--accent-soft` wash; the current nav item is `--accent-soft` plus weight, never colour
alone. Empty state: "Start with an idea." and up to three actions.

## Rejected

- The `--design-system` answer almost entire: the "Product Review/Ratings" pattern
  (wrong product), "Exaggerated Minimalism" with `clamp(3rem,10vw,12rem)` and weight 900
  (a writing tool must not shout over the writing), teal `#0D9488` + orange `#EA580C`
  (two accents, and orange is the ecosystem's signal colour), Lora/Raleway, and the GSAP
  scroll-reveal — nothing in this shell scrolls into view. Kept its accessibility,
  contrast and motion-duration guidance and its pre-delivery checklist.
- The three AI-default looks `frontend-design` names: cream + high-contrast serif +
  terracotta (banned by the spec), near-black + acid accent, and the hairline-ruled
  broadsheet — which is exactly what Two Inks was. `--ground` is cooler and lower-chroma
  than a true cream and pairs with green, so neither look is one token's drift away.
- Rounded cards floating in gutters. Panels are unbordered fields of `--surface` sitting
  flush in the page column, separated by 32px of `--ground`.
- An icon-only collapsed rail: a 56px strip of initials is worse than no rail. Collapsed
  is width zero plus one labelled toggle.
- Material Symbols, emoji, and a spinner for "Saving". Words, still.
