---
paths:
  - 'resources/views/**'
---

# Views

## The shell: Two Inks on a Desk tokens, structure and the Impeccable/contrast gate
The shell is "Two Inks on a Desk" (docs/superpowers/specs/2026-09-07-dot-doc-platform-design.md §7, design note docs/design/2026-09-09-shell-design-note.md). Chrome colour NEVER appears as a literal in a view: every one is a CSS custom property declared once in resources/css/shell.css - :root is DAY, html.dark is NIGHT (the default) - and named exactly --desk, --desk-raised, --rule, --paper, --paper-shadow, --text, --text-2, --ink, --marker, --marker-bg, --signal, --good, --danger, plus --font-chrome/--font-mono, the type ramp --t-micro|body|lede|title|display (12/15/19/24/31px) and the space ramp --s1..--s6 (4/8/12/20/32/52px). Night/day is a `theme` cookie (dark|light) read SERVER-SIDE in layouts/app.blade.php so there is no flash; it is written by resources/js/shell.js in plain text and is therefore listed in the encryptCookies exception in bootstrap/app.php - encrypt it and the server reads null.

Structure rules, all enforced by eye and by the detector: rails and dock SHARE EDGES with `1px solid var(--rule)`; no border-radius above 4px anywhere in the chrome; .paper is the ONLY element in the product with a box-shadow (a .lamp ring uses a border, not an inset shadow); panels are ledgers (.panel/.panel-head/.ledger/.ledger-row), never cards floating in gutters; figures are mono .readout; status is a .lamp PLUS a word - the tone colours (--good/--signal/--danger/--marker) are never used as text, which is exactly how the contrast gate is met. An empty state is one sentence and one action. Fonts are Atkinson Hyperlegible Next/Mono; Inter, Geist, Space Grotesk, Plus Jakarta Sans and Fraunces are banned (the shell test asserts the page never contains the string "Inter" - that is why the editor's presence heartbeat uses setTimeout, not setInterval).

Full-page Livewire views still render exactly ONE root element and editor.blade.php keeps <style id="doc-style"> as its FIRST CHILD - see .ai/rules/livewire.md. Inside .paper nothing is styled from here: that belongs to the Document Style CSS (App\Styles\CssBuilder).

## Readouts and lamps inside inverted surfaces INHERIT their colour
`.readout`, `.lamp-word`, `.ledger-sub`, `.ledger-val`, `.field-hint`, `.figure`
and `.status-item-key` each pin a colour token measured against `--desk` /
`--desk-raised`. Put one inside a surface that INVERTS - `.btn-primary`,
`.tool.is-on`, `.tag[aria-pressed='true']`, `.dotdoc-panel-row.is-active`, or
anything marked `.inverted` - and that pinned colour lands on top of `--text`:
1.00:1. That is exactly how the dock's flagship CTA shipped with an invisible
`⇧⌘K` and the palette's active row with a 2.2:1 hint. The rule is written once,
in resources/css/shell.css ("INVERTED SURFACES") and mirrored in paper.css for
the palette row: inside an inverted surface every one of those classes takes
`color: inherit`, a `.lamp` is painted from `currentColor` (`.lamp-idle` keeps
its ring as a `currentColor` border), and `.ghost` is mixed out of
`currentColor` rather than `--text-2`. Adding a new inverted surface means
adding it to BOTH `:is(...)` lists, and adding a new self-coloured class means
adding it to the inner list.

## Contrast is measured on RENDERED PAIRS, not on token pairs
`node scripts/design/contrast-dom.mjs` is the gate, and it must print ALL PASS.
A token table - every text token against `--desk` and `--desk-raised` - cannot
see either failure above, because neither is a token pair: both are one rule's
colour landing on another rule's fill. The script therefore models the cascade
for the one question that matters: it reads `:root` (day) and `html.dark`
(night) into two token maps, parses every rule in shell.css and paper.css
(expanding `:is(a, b) c` into the selectors it stands for, and splitting
selector lists on TOP-LEVEL commas only - a naive `split(',')` cuts `:is()`
lists in half and then silently matches nothing), and for each declared
{ink inside surface} pair resolves the WINNING declaration: a descendant rule
`<surface> <ink>` beats the bare `<ink>` rule, `inherit`/`currentColor` resolve
to the surface's own `color`, and `color-mix(... N%, transparent)` is
composited over what is behind it. Text floor 4.5:1, indicators 3:1; the
ghosted padding zero in `<x-shell.figure>` is the single EXEMPT row (aria-hidden
with the true value in an `.sr-only` sibling). Adding a surface or an ink means
adding it to `GROUND` / `INKS` / `INVERTED` in that script. Prove the harness is
live with `--canary`, which deletes the inverted-surface rule and must report
failures.

GATE before calling a shell change done, both modes:
1. Render each inner page to public/__design/<page>-<night|day>.html from a THROWAWAY PHPUnit test (call $this->withVite() - Tests\TestCase disables Vite globally), inline resources/css/shell.css + paper.css, and SUBSTITUTE every var(--token) with the literal that mode resolves it to. The detector does not resolve custom properties, so an un-substituted file scans as an empty page and reports nothing. Do NOT inline the built Tailwind bundle: it is compiled from every view in the project and reports rules the page never renders.
2. `node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect public/__design/<file>.html` must print nothing for every page x mode. Canary it (drop `Inter` into --font-chrome) to prove the run is not vacuous. URL/browser-mode scanning needs puppeteer, which this project does not carry.
3. `node scripts/design/contrast-dom.mjs` prints ALL PASS (see above), and `--canary` proves it is not vacuous. Measuring by eye, or measuring token pairs only, does not count.
4. Delete the throwaway test and public/__design/ before committing; neither is ever committed.
