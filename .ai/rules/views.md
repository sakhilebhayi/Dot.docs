---
paths:
  - 'resources/views/**'
---

# Views

## The shell: Two Inks on a Desk tokens, structure and the Impeccable/contrast gate
The shell is "Two Inks on a Desk" (docs/superpowers/specs/2026-09-07-dot-doc-platform-design.md §7, design note docs/design/2026-09-09-shell-design-note.md). Chrome colour NEVER appears as a literal in a view: every one is a CSS custom property declared once in resources/css/shell.css - :root is DAY, html.dark is NIGHT (the default) - and named exactly --desk, --desk-raised, --rule, --paper, --paper-shadow, --text, --text-2, --ink, --marker, --marker-bg, --signal, --good, --danger, plus --font-chrome/--font-mono, the type ramp --t-micro|body|lede|title|display (12/15/19/24/31px) and the space ramp --s1..--s6 (4/8/12/20/32/52px). Night/day is a `theme` cookie (dark|light) read SERVER-SIDE in layouts/app.blade.php so there is no flash; it is written by resources/js/shell.js in plain text and is therefore listed in the encryptCookies exception in bootstrap/app.php - encrypt it and the server reads null.

Structure rules, all enforced by eye and by the detector: rails and dock SHARE EDGES with `1px solid var(--rule)`; no border-radius above 4px anywhere in the chrome; .paper is the ONLY element in the product with a box-shadow (a .lamp ring uses a border, not an inset shadow); panels are ledgers (.panel/.panel-head/.ledger/.ledger-row), never cards floating in gutters; figures are mono .readout; status is a .lamp PLUS a word - the tone colours (--good/--signal/--danger/--marker) are never used as text, which is exactly how the contrast gate is met. An empty state is one sentence and one action. Fonts are Atkinson Hyperlegible Next/Mono; Inter, Geist, Space Grotesk, Plus Jakarta Sans and Fraunces are banned (the shell test asserts the page never contains the string "Inter" - that is why the editor's presence heartbeat uses setTimeout, not setInterval).

Full-page Livewire views still render exactly ONE root element and editor.blade.php keeps <style id="doc-style"> as its FIRST CHILD - see .ai/rules/livewire.md. Inside .paper nothing is styled from here: that belongs to the Document Style CSS (App\Styles\CssBuilder).

GATE before calling a shell change done, both modes:
1. Render each inner page to public/__design/<page>-<night|day>.html from a THROWAWAY PHPUnit test (call $this->withVite() - Tests\TestCase disables Vite globally), inline resources/css/shell.css + paper.css, and SUBSTITUTE every var(--token) with the literal that mode resolves it to. The detector does not resolve custom properties, so an un-substituted file scans as an empty page and reports nothing. Do NOT inline the built Tailwind bundle: it is compiled from every view in the project and reports rules the page never renders.
2. `node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect public/__design/<file>.html` must print nothing for every page x mode. Canary it (drop `Inter` into --font-chrome) to prove the run is not vacuous. URL/browser-mode scanning needs puppeteer, which this project does not carry.
3. Measure contrast with a script, not by eye: every text token >= 4.5:1 against --desk and --desk-raised in BOTH modes, every lamp tone >= 3:1. The one allowed exception is the ghosted padding zero in <x-shell.figure>, which is aria-hidden with the real value in .sr-only.
4. Delete the throwaway test and public/__design/ before committing; neither is ever committed.
