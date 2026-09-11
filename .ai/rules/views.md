---
paths:
  - 'resources/views/**'
---

# Views

## The shell is "Fair Copy": the token table, and colour never appears as a literal
The shell is **Fair Copy** (docs/superpowers/specs/2026-09-11-dot-doc-fair-copy-redesign-design.md, design note docs/design/2026-09-11-fair-copy-design-note.md). It REPLACED "Two Inks on a Desk" for Dot.Doc in Tasks 1-3 of that phase; Two Inks is not retracted as an ecosystem pattern (it is right for control-room products like Dot.Memory) but nothing in this repo should reach for it again. `--desk`, `--desk-raised`, `--rule`, `--text`, `--text-2`, `--signal`, `--good`, `.lamp`, `.readout`, `.ledger*`, `.status-line`, `.status-item`, `.desk`, `.rail-initial` and `<x-shell.lamp>` no longer exist — a view that names one is a view written against the retired system.

Chrome colour NEVER appears as a literal in a view: every one is a CSS custom property declared once in resources/css/shell.css. **`:root` is DAY** (a writing tool opens on paper), night is `html.dark`. The names are exactly:

| Token | Role |
|---|---|
| `--ground` | app background behind the page |
| `--surface` | panels, dock, rail, top bar |
| `--surface-raised` | raised chrome: sheets, menus, the palette, fields, buttons |
| `--ink` / `--ink-soft` | primary text / secondary text and placeholders |
| `--line` | the ONLY rule colour, used sparingly, never as a grid |
| `--accent` / `--accent-soft` | links, active states, selected rows — the signature ink-green |
| `--danger` | errors and destructive actions |
| `--paper` / `--paper-ink` / `--paper-shadow` | the document sheet; these do NOT invert (see below) |
| `--marker` / `--marker-bg` / `--marker-chrome` | the machine's ink in the document / in the chrome |
| `--font-display` / `--font-chrome` / `--font-mono` | Fraunces / Work Sans / IBM Plex Mono |
| `--t-micro|body|lede|title|display` | 12/15/19/25/33px |
| `--s1..--s6` | 4/8/12/20/32/56px |
| `--r-control` / `--r-canvas` | 8px on anything you click, 12px on the canvas |
| `--rail-w` / `--dock-w` / `--topbar-h` | 268 / 340 / 52px |

`--paper` and `--paper-ink` do not invert on purpose: `App\Styles\CssBuilder` writes `.paper{background:#fff}` and colours every document element from `--doc-ink`, and Document Styles are out of scope for chrome work — a night `--surface-raised` on the sheet puts light chrome ink on a white page. `--marker` is the machine's ink IN the document (on white, both modes); `--marker-chrome` is the same ink in the chrome, and it is the one that flips.

Night/day is a `theme` cookie (dark|light) read SERVER-SIDE in layouts/app.blade.php so there is no flash; it is written by resources/js/shell.js in plain text and is therefore listed in the encryptCookies exception in bootstrap/app.php — encrypt it and the server reads null. An EXPLICIT day choice is stamped `<html class="light">` so it beats the `@media (prefers-color-scheme: dark) { html:not(.light) }` guard, and the guest/marketing/auth pages are pinned `class="light"` because they have no night mode. **The two night guards are duplicates and must stay identical**; `FairCopyTest::test_both_night_guards_declare_the_same_tokens` compares them declaration by declaration.

## Structural rules (these replaced Two Inks' rules 1:1)

| Retired | Fair Copy |
|---|---|
| Panels share edges over a ruled grid | **Whitespace divides.** A rule appears only where two surfaces of different elevation actually touch (rail against ground, top bar against canvas) |
| Status is a `.lamp` (bordered nib) + word | Status is `<x-shell.status-word>`: a word in `--ink` plus a 6px dot in `--accent`/`--danger`/`--ink-soft`. **The tone colours are never text** — that is how the contrast gate is met by construction |
| Figures are mono, zero-padded readouts | Figures are plain numerals in the UI font (`.numeral`, `<x-shell.figure>`). No padding, no `aria-hidden` ghost digits, no exemption rows |
| `.ledger` / `.ledger-row` / `.ledger-val` | `.list` / `.list-row` / `.list-key` / `.list-sub` / `.list-val` |
| No border-radius above 4px | Radius is deliberate: **8px on interactive surfaces**, **12px on the canvas and on floating panels**. Still never the "rounded card floating in a gutter" pattern — surfaces sit flush against `--ground`/`--surface` |
| One shadow only, on the paper | **Still true.** `.paper` is the only element in the product with a box-shadow. The rail, dock, sheets, menus and the contextual toolbar are all flat |
| Rails/dock collapse but default open | **Collapsed by default on the editor**, open everywhere else |
| An empty state is one sentence and one action | One sentence, then up to three actions — and the sentence NAMES what is filtering the list, so every active filter has a way out of it |

Fonts are **Fraunces** (display), **Work Sans** (everything functional) and **IBM Plex Mono** (only where a column of figures genuinely has to line up — never as a motif). Inter, Geist, Space Grotesk, Plus Jakarta Sans and Atkinson Hyperlegible are banned; `FairCopyTest::assertBannedFontsAreAbsent` measures the face in a FONT STACK or a WEBFONT REQUEST, not as a bare substring ("Inter" lives inside `IntersectionObserver`, "Roboto" inside a filename). Fraunces is flagged by Impeccable's `overused-font` detector and carries a **value-scoped** ignore in `.impeccable/config.json`; Inter and the rest still fail, which the canary proves.

Full-page Livewire views still render exactly ONE root element and editor.blade.php keeps `<style id="doc-style">` as its FIRST CHILD — see .ai/rules/livewire.md. Inside `.paper` nothing is styled from here: that belongs to the Document Style CSS (`App\Styles\CssBuilder`).

## Collapsed by default, and what is allowed to expand a panel
The rail and dock are `data-panel-state="collapsed"` on the editor and expanded everywhere else, rendered SERVER-SIDE by layouts/app.blade.php so neither flashes open before resources/js/shell.js runs. A collapsed panel is `display: none`, which takes it out of the tab order — not a zero-width strip of two-letter initials.

Three ways a panel opens, and no fourth:
1. **Its toggle in the top bar** (`data-shell-panel-toggle="rail|dock"`), which TOGGLES and writes the choice to `localStorage` keyed by panel AND by context (`dotdoc.panel.<editor|page>.<rail|dock>`) — a single key carried an editor collapse onto the dashboard, defeating the default.
2. **`⌘\` / `Ctrl+\`**, the rail's own chord (`isRailShortcut`), which also toggles. It deliberately does not bow out inside a field: a chord with Cmd/Ctrl held types nothing, and the writer with a caret in the paper is exactly the person reaching for the panel.
3. **An expand trigger** — `data-shell-expand="dock"` on a control, or the `open-ai-palette` / `shell:reveal-dock` window events. Revealing is ONE-WAY: a trigger never closes a panel somebody opened on purpose. Add a control that acts INTO a panel and you give it an expand trigger rather than letting it act into a panel nobody can see.

**Below 900px both panels stop being columns**: `position: fixed; inset: var(--topbar-h) 0 0 0` — full-screen overlays starting below the top bar, so the toggle that opened one is still on screen. They are shown by `data-panel-user="open"`, which is written ONLY by shell.js and never by the server, so a panel the server rendered expanded for a desktop layout does not land on a phone already covering the page. Each carries a `.panel-overlay-head` close control that is the SAME `data-shell-panel-toggle` hook as the top bar's — one source of truth, one keyboard path. The dock crosses over earlier, at 1180px, where there is still room for the rail. At the same 900px breakpoint the floating contextual toolbar becomes a bottom-anchored sheet; the `!important` on its `left`/`top` is load-bearing, because resources/js/editor/ui/bubble.js writes those two as INLINE styles and that file is out of scope for chrome work.

## Contrast is measured on RENDERED PAIRS, not on token pairs
`node scripts/design/contrast-dom.mjs` is the gate, and it must print ALL PASS.
A token table — every text token against `--ground` and `--surface` — cannot see
the failures that actually ship, because they are not token pairs: they are one
rule's colour landing on another rule's fill (`.micro {color: var(--ink-soft)}`
inside `.btn-primary {background: var(--accent)}` measured 1.00:1 in the first
pass of the retired system). The script therefore models the cascade: it reads
`:root` (day) and `html.dark` (night) into two token maps, parses every rule in
shell.css and paper.css (expanding `:is(a, b) c` into the selectors it stands
for, and splitting selector lists on TOP-LEVEL commas only — a naive
`split(',')` cuts `:is()` lists in half and then silently matches nothing), and
for each declared {ink inside surface} pair resolves the WINNING declaration: a
descendant rule `<surface> <ink>` beats the bare `<ink>` rule,
`inherit`/`currentColor` resolve to the surface's own `color`, and
`color-mix(... N%, transparent)` is composited over what is behind it. Text
floor 4.5:1, non-text marks 3:1. **Fair Copy has no exempt rows** — the ghosted
padding zero that was the single documented exemption went with the mono readout
it decorated. Adding a surface or an ink means adding it to `GROUND` / `INKS` /
`INVERTED` / `MARKS` in that script. Prove the harness is live with `--canary`,
which deletes the inverted-surface rule and must report failures.

## Self-coloured classes inside inverted surfaces INHERIT
`.micro`, `.status-word`, `.list-sub`, `.list-val`, `.field-hint`, `.field-label`,
`.numeral` and the rest each pin a colour token measured against `--ground` /
`--surface`. Put one inside a surface that INVERTS — `.btn-primary`,
`.tool.is-on`, `.tag[aria-pressed='true']`, `.dotdoc-panel-row.is-active`,
`.dotdoc-bubble-btn.is-active`, or anything marked `.inverted` — and that pinned
colour lands on top of the inverted fill at 1.00:1. That is exactly how the
dock's flagship CTA shipped with an invisible `⇧⌘K`. The rule is written once,
in resources/css/shell.css ("INVERTED SURFACES") and mirrored in paper.css for
the palette and bubble rows: inside an inverted surface every one of those
classes takes `color: inherit`, and a status dot is painted from `currentColor`.
**Adding a new inverted surface means adding it to BOTH `:is(...)` lists AND to
`INVERTED` in the contrast script**; adding a new self-coloured class means
adding it to the inner list and to `INKS`.

## GATE before calling a shell change done, both modes AND both breakpoints
1. Render each inner page to public/__design/&lt;page&gt;-&lt;night|day&gt;.html from a THROWAWAY PHPUnit test (call `$this->withVite()` — Tests\TestCase disables Vite globally), inline resources/css/shell.css + paper.css, and SUBSTITUTE every `var(--token)` with the literal that mode resolves it to. The detector does not resolve custom properties, so an un-substituted file scans as an empty page and reports nothing. Strip CSS comments before parsing the token block — a comment containing a `{` ends the block match early and leaves every later token unresolved. Do NOT inline the built Tailwind bundle: it is compiled from every view in the project and reports rules the page never renders.
2. `node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect public/__design/<file>.html` must print nothing for every page × mode. Canary it (drop `Inter` into `--font-chrome`) to prove the run is not vacuous. URL/browser-mode scanning needs puppeteer, which this project does not carry — which is also why there are no screenshot files in any report.
3. Render each page a SECOND time with the `@media (max-width: 900px)` and `(max-width: 1180px)` blocks flattened into unconditional rules, and scan those too. The overlay layout is a different page as far as the detector is concerned, and it is the layout most of the product's readers are on.
4. `node scripts/design/contrast-dom.mjs` prints ALL PASS, and `--canary` proves it is not vacuous. Measuring by eye, or measuring token pairs only, does not count.
5. Delete the throwaway test and public/__design/ before committing; neither is ever committed.
