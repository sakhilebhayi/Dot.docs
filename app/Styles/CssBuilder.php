<?php

namespace App\Styles;

use App\Models\DocumentStyle;
use Database\Seeders\DocumentStyleSeeder;

/**
 * Turns one DocumentStyle's token array into a CSS string for one of three
 * surfaces: 'canvas' (editor, a fixed A4-shaped .paper), 'print'
 * (export/PDF, with an at-page rule) or 'share' (the published page at
 * /d/{slug}). Every selector renders from CSS custom properties set on
 * .paper so a style switch is a single stylesheet swap.
 *
 * 'share' is canvas with the editor's own chrome taken off: the reader gets
 * the same A4-shaped paper, but a page break is a plain rule that actually
 * breaks the page when the print button is pressed, not the editor's dashed
 * line labelled "page break".
 *
 * Callouts always get a full hairline border plus a tone-coloured title
 * (never a thick single-side border) - a design constraint, not a token.
 *
 * A team-owned DocumentStyle's tokens are arbitrary JSON a team member can
 * set (unlike the fourteen system styles, which are literal PHP arrays
 * this codebase controls) - every free-form value (colours, font names,
 * lengths, the leading number, the font-import URL) is read through
 * TokenGuard before it is interpolated into CSS, falling back to the
 * 'report' style's equivalent token when a value doesn't pass.
 */
class CssBuilder
{
    /** @var array<string,mixed> */
    private array $tokens;

    /** @var array<string,mixed> The 'report' style's tokens - the fallback for every guarded value. */
    private array $fallback;

    /** Modes that lay the content out on a fixed A4-shaped sheet. */
    private const PAPER_MODES = ['canvas', 'share'];

    public function __construct(private DocumentStyle $style, private string $mode)
    {
        $this->tokens = $style->tokens;
        $this->fallback = DocumentStyleSeeder::STYLES['report']['tokens'];
    }

    public function build(): string
    {
        $parts = [];

        $import = TokenGuard::fontImport($this->tokens['fonts']['import'] ?? null, $this->fallback['fonts']['import']);
        if ($import !== '') {
            $parts[] = "@import url({$import});";
        }

        $parts[] = $this->paperRule();
        $parts[] = $this->headingRules();
        $parts[] = $this->paragraphRule();
        $parts[] = $this->tableRules();
        $parts[] = $this->figureRules();
        $parts[] = $this->tocRules();
        $parts[] = $this->calloutRules();
        $parts[] = $this->numRules();
        $parts[] = $this->columnsRule();
        $parts[] = $this->pageBreakRule();
        $parts[] = $this->paginationRule();

        if ($this->mode === 'print') {
            $parts[] = $this->pageRule();
            $parts[] = '.paper .toc-empty{display:none}';
        }

        return implode("\n", array_filter($parts));
    }

    private function fontStack(string $name): string
    {
        $fallback = match (true) {
            str_contains($name, 'Mono') => 'monospace',
            str_contains($name, 'Serif') => 'serif',
            default => 'sans-serif',
        };

        return "'{$name}', {$fallback}";
    }

    private function paperRule(): string
    {
        $fonts = $this->tokens['fonts'] ?? [];
        $sizes = $this->tokens['sizes'] ?? [];
        $spacing = $this->tokens['spacing'] ?? [];
        $colours = $this->tokens['colours'] ?? [];
        $fb = $this->fallback;

        $vars = [
            '--doc-font-body' => $this->fontStack(TokenGuard::fontName($fonts['body'] ?? null, $fb['fonts']['body'])),
            '--doc-font-heading' => $this->fontStack(TokenGuard::fontName($fonts['heading'] ?? null, $fb['fonts']['heading'])),
            '--doc-font-mono' => $this->fontStack(TokenGuard::fontName($fonts['mono'] ?? null, $fb['fonts']['mono'])),
            '--doc-size-body' => TokenGuard::length($sizes['body'] ?? null, $fb['sizes']['body']),
            '--doc-size-h1' => TokenGuard::length($sizes['h1'] ?? null, $fb['sizes']['h1']),
            '--doc-size-h2' => TokenGuard::length($sizes['h2'] ?? null, $fb['sizes']['h2']),
            '--doc-size-h3' => TokenGuard::length($sizes['h3'] ?? null, $fb['sizes']['h3']),
            '--doc-size-small' => TokenGuard::length($sizes['small'] ?? null, $fb['sizes']['small']),
            '--doc-leading' => (string) TokenGuard::number($this->tokens['leading'] ?? null, (float) $fb['leading']),
            '--doc-space-paragraph' => TokenGuard::length($spacing['paragraph'] ?? null, $fb['spacing']['paragraph']),
            '--doc-space-heading-top' => TokenGuard::length($spacing['headingTop'] ?? null, $fb['spacing']['headingTop']),
            '--doc-space-heading-bottom' => TokenGuard::length($spacing['headingBottom'] ?? null, $fb['spacing']['headingBottom']),
            '--doc-ink' => TokenGuard::colour($colours['ink'] ?? null, $fb['colours']['ink']),
            '--doc-heading' => TokenGuard::colour($colours['heading'] ?? null, $fb['colours']['heading']),
            '--doc-accent' => TokenGuard::colour($colours['accent'] ?? null, $fb['colours']['accent']),
            '--doc-rule' => TokenGuard::colour($colours['rule'] ?? null, $fb['colours']['rule']),
            '--doc-muted' => TokenGuard::colour($colours['muted'] ?? null, $fb['colours']['muted']),
        ];

        if (in_array($this->mode, self::PAPER_MODES, true)) {
            $vars['width'] = '210mm';
            $vars['min-height'] = '297mm';
            $vars['padding'] = $this->margins();
        }

        $decls = implode(';', array_map(fn ($prop, $value) => "{$prop}:{$value}", array_keys($vars), $vars));

        return ".paper{background:#fff;{$decls}}";
    }

    private function margins(): string
    {
        $m = $this->tokens['page']['margins'] ?? [];
        $fb = $this->fallback['page']['margins'];

        $top = TokenGuard::length($m['top'] ?? null, $fb['top']);
        $right = TokenGuard::length($m['right'] ?? null, $fb['right']);
        $bottom = TokenGuard::length($m['bottom'] ?? null, $fb['bottom']);
        $left = TokenGuard::length($m['left'] ?? null, $fb['left']);

        return "{$top} {$right} {$bottom} {$left}";
    }

    /**
     * Weight per level defaults to 700/600/500; a style may set
     * fonts.headingWeight to override ALL levels uniformly (marketing
     * wants its bold weight at every heading level, not the gradient).
     */
    private function headingRules(): string
    {
        $upper = ($this->tokens['headingCase'] ?? 'none') === 'upper';
        $rawWeight = $this->tokens['fonts']['headingWeight'] ?? null;

        $rules = '';
        foreach (['h1' => 700, 'h2' => 600, 'h3' => 500] as $tag => $default) {
            $weight = $rawWeight === null ? $default : (int) TokenGuard::number($rawWeight, (float) $default, 100, 900);
            $transform = $tag === 'h1' && $upper ? 'uppercase' : 'none';
            $rules .= ".paper {$tag}{font-family:var(--doc-font-heading);font-size:var(--doc-size-{$tag});".
                "font-weight:{$weight};color:var(--doc-heading);text-transform:{$transform};".
                'margin:var(--doc-space-heading-top) 0 var(--doc-space-heading-bottom)}';
        }

        return $rules;
    }

    private function paragraphRule(): string
    {
        // 'align' is a two-value enum (brief §token shape); any other
        // value collapses to the safe default rather than reaching CSS.
        $align = ($this->tokens['align'] ?? 'left') === 'justify' ? 'justify' : 'left';

        return '.paper p{font-family:var(--doc-font-body);font-size:var(--doc-size-body);'.
            'line-height:var(--doc-leading);color:var(--doc-ink);text-align:'.$align.';'.
            'margin:0 0 var(--doc-space-paragraph)}';
    }

    private function tableRules(): string
    {
        $table = $this->tokens['table'];
        $header = $table['header'] === 'band'
            ? 'background:var(--doc-rule);color:var(--doc-ink);font-weight:600'
            : 'background:transparent;border-bottom:2px solid var(--doc-ink);font-weight:600';

        $border = match ($table['border']) {
            'grid' => '.paper .doc-table td,.paper .doc-table th{border:1px solid var(--doc-rule)}',
            'hairline' => '.paper .doc-table td,.paper .doc-table th{border-bottom:1px solid var(--doc-rule)}',
            default => '.paper .doc-table td,.paper .doc-table th{border:none}',
        };

        // #f2f0ea is the spec palette's light "Desk, raised" tone - a
        // subtle neutral tint that stays print-safe (no color-mix()).
        $zebra = $table['zebra']
            ? '.paper .doc-table tr:nth-child(even) td{background:#f2f0ea}'
            : '';

        return '.paper .doc-table{width:100%;border-collapse:collapse;font-size:var(--doc-size-small);font-family:var(--doc-font-body)}'.
            ".paper .doc-table th{{$header};text-align:left;padding:.5em .6em}".
            '.paper .doc-table td{padding:.5em .6em}'.
            $border.$zebra.
            '.paper .doc-table .num-cell{font-family:var(--doc-font-mono);text-align:right}';
    }

    private function figureRules(): string
    {
        $position = $this->tokens['caption']['position'] ?? 'below';
        $italic = ($this->tokens['caption']['style'] ?? 'italic') === 'italic' ? 'italic' : 'normal';

        $figure = $position === 'above'
            ? '.paper figure{display:flex;flex-direction:column-reverse;margin:1.5em 0}'
            : '.paper figure{margin:1.5em 0}';

        $captionMargin = $position === 'above' ? 'margin:0 0 .4em' : 'margin:.4em 0 0';

        return $figure.
            ".paper figcaption{font-style:{$italic};color:var(--doc-muted);font-size:var(--doc-size-small);{$captionMargin}}";
    }

    private function tocRules(): string
    {
        return '.paper .toc{margin:1.5em 0}'.
            '.paper .toc ol{list-style:none;padding-left:0;margin:0}'.
            '.paper .toc li{margin:.3em 0}'.
            '.paper .toc .num{color:var(--doc-muted)}';
    }

    private function calloutRules(): string
    {
        $tones = ['note' => ['#0f766e', 'Note'], 'warning' => ['#8a6d05', 'Warning'], 'success' => ['#2f7043', 'Success'], 'danger' => ['#a9352d', 'Danger']];
        $rules = '.paper .callout{border:1px solid var(--doc-rule);border-radius:2px;padding:.8em 1em;margin:1em 0;background:transparent}'.
            '.paper .callout::before{display:block;font-weight:600;font-size:var(--doc-size-small);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4em}';
        foreach ($tones as $tone => [$colour, $label]) {
            $rules .= ".paper .callout-{$tone}::before{content:\"{$label}\";color:{$colour}}";
        }

        return $rules;
    }

    private function numRules(): string
    {
        return '.paper .num{margin-right:.6em;color:var(--doc-muted)}'.
            '.paper h1 .num{color:var(--doc-accent)}';
    }

    private function columnsRule(): string
    {
        return '.paper .columns{display:grid;grid-template-columns:repeat(var(--cols,2),1fr);gap:1.5em}';
    }

    private function pageBreakRule(): string
    {
        if ($this->mode === 'print') {
            return '.paper .page-break{page-break-after:always;border:none;height:0;margin:0}';
        }

        // A reader is not editing, so the break is a hairline rather than a
        // labelled one - and it still breaks the page on the way to paper.
        if ($this->mode === 'share') {
            return '.paper .page-break{border-top:1px solid var(--doc-rule);height:0;margin:2em 0}'.
                '@media print{.paper .page-break{page-break-after:always;border:none;margin:0}}';
        }

        return '.paper .page-break{border-top:1px dashed var(--doc-rule);text-align:center;margin:2em 0;position:relative}'.
            '.paper .page-break::after{content:"page break";position:relative;top:-.6em;background:#fff;padding:0 .6em;font-size:var(--doc-size-small);color:var(--doc-muted)}';
    }

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
     * actually run - `.dotdoc-paginated` is added to `.editor-main` (the
     * tight wrapper around `.paper` - NOT `.canvas-region`, the whole
     * page's <main> content region also shared with `.doc-bar` and the
     * comments sidebar) by viewModes.js's applyMode(), so a page that has
     * not mounted the pagination bundle at all (or has JS disabled) sees
     * the plain unbounded `.paper` exactly as it did before this phase.
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

        return '.editor-main.dotdoc-paginated .paper{background:transparent;box-shadow:none}'.
            '.editor-main.dotdoc-paginated .paper>*{background:#fff}'.
            // A thumbnail clone (viewModes.js's renderThumbnail()) reuses
            // the `paper` class so Document Style CSS applies to it - but
            // that means it ALSO matches the transparent rule immediately
            // above whenever the clone lives inside `.editor-main.
            // dotdoc-paginated` (Multi-Page mode's grid), and it is a
            // paper-shaped element with no other rule reaching it at all
            // when it lives in the thumbnails RAIL (outside `.editor-main`
            // entirely). Found live in Task 8's browser verification, made
            // visible only once the off-screen Multi-Page bug elsewhere in
            // this file was fixed: `--paper`/`--paper-ink` are the same
            // never-inverting tokens `.canvas .paper` itself uses
            // (paper.css) - a thumbnail is a miniature real page and must
            // look like one regardless of which container it is mounted
            // in. `.dotdoc-thumbnail .dotdoc-thumbnail-inner.paper` ties
            // the transparent rule above on specificity (three classes
            // each) and wins on source order since it comes later in this
            // same string - deliberately NOT scoped under `.editor-main`,
            // so the identical rule also reaches the rail's thumbnails,
            // which sit outside `.editor-main` and have no other paper
            // background/ink rule reaching them at all.
            '.dotdoc-thumbnail .dotdoc-thumbnail-inner.paper{background:var(--paper);color:var(--paper-ink)}'.
            '.dotdoc-page-boundary{contain:layout;pointer-events:none}'.
            // The two document-EDGE bands (page 1's header, the last
            // page's footer - pagination/decorations.js's repaginate(),
            // Task 8): fixed widgets outside the boundary mechanism, so
            // they get their own (much smaller) wrapper rule rather than
            // `.dotdoc-page-boundary`'s, which also owns gap/shadow layout
            // neither edge band has.
            '.dotdoc-page-edge{contain:layout;pointer-events:none}'.
            '.dotdoc-page-gap{height:2.5em;background:var(--ground)}'.
            '.dotdoc-page-shadow{height:.5em;background:linear-gradient(to bottom,rgba(0,0,0,.08),transparent)}'.
            '.dotdoc-page-shadow-below{transform:rotate(180deg)}'.
            // A 4-value padding shorthand (top right bottom left): a small
            // fixed vertical padding appropriate for a one-line band, and
            // the page's REAL left/right margin so the band's text aligns
            // with the body text above/below it.
            ".dotdoc-page-band{background:#fff;padding:.4em {$h['right']} .4em {$h['left']};font-size:var(--doc-size-small);color:var(--doc-muted);pointer-events:auto}".
            // design spec §2.2: a table repeats its header row at the top
            // of each continuation page. `decorations.js`'s
            // `cloneTableHeaderRow()` builds this as a `display:flex` row
            // of plain `<div>` cells, deliberately never a `<table>` (a
            // real `<table>` nested inside the ORIGINAL table's own
            // `<tbody>` was found live to feed back into that table's own
            // auto-layout column-width computation, growing it wider on
            // every repagination pass) - `flex` lays the per-cell fixed
            // widths `cloneTableHeaderRow()` sets inline out in a single
            // row without needing any table layout algorithm at all.
            '.dotdoc-table-header-repeat{display:flex;background:#fff}'.
            // A mid-table page-boundary widget (decorations.js's
            // `renderBoundaryWidget()`, called for a break whose offset
            // lands INSIDE a table rather than between top-level blocks)
            // is a direct child of that table's own `<tbody>` - a wide
            // foreign block there was found live to measurably distort an
            // auto-layout table's own column-width computation, worse on
            // every repagination pass, with no stable fixed point
            // (.ai/rules/editor.md's "wide foreign block child of
            // `<tbody>`" rule). `position:absolute` removes it from the
            // table's layout algorithm entirely (CSS 2.1 §17.2.1: an
            // out-of-flow child of a table-row-group never gets wrapped
            // in the anonymous row/cell that would otherwise fold it into
            // column-width computation) - confirmed live, restoring the
            // table's true, un-distorted columns the instant this rule
            // applies. A normal (non-table) boundary is an in-flow CHILD
            // of `.paper` (paper.css's `.canvas .paper{position:relative}`),
            // so it inherits `.paper`'s own `padding: {$this->margins()}`
            // for free, confining it to the content column - but CSS
            // positions an ABSOLUTELY positioned descendant relative to
            // its containing block's PADDING BOX (CSS 2.1 §10.3.7: the
            // containing block is the padding edge of the nearest
            // positioned ancestor), which spans `.paper`'s full width
            // INCLUDING that padding. `left/right:0` therefore bled the
            // widget across the page's margins entirely (confirmed live:
            // its header/footer band measured full paper width, edge to
            // edge, instead of the ~560px content column every normal
            // boundary's band measures) - `$h['left']`/`$h['right']` (the
            // same page-margin values `.dotdoc-page-band`'s own padding
            // below already uses) put it back at the content column's
            // actual edges. `top` is set inline per instance
            // (`decorations.js`'s `tableSplitOverlayTopPx()`), since it
            // depends on exactly where in that specific table the split
            // falls.
            ".dotdoc-page-boundary.dotdoc-page-boundary-in-table{position:absolute;left:{$h['left']};right:{$h['right']}}".
            // Taking the widget out of flow means nothing reserves the
            // vertical space it used to occupy by simply sitting there -
            // without this, the table's own next row renders directly
            // under the (now purely visual) widget instead of after it.
            // `decorations.js`'s `buildTableSplitGapDecorations()` reserves
            // it instead, via a REAL `Decoration.node()` on the row
            // immediately before the split (never a raw DOM write - see
            // that function's own comment for why one survives ProseMirror's
            // reconciliation and the other does not). `padding-bottom`,
            // not `margin-bottom`: padding is what table row layout
            // actually honours on a cell; a margin here would be ignored.
            // The amount travels as a CSS custom property, not a literal
            // value, so `decorations.js`'s `rowSplitGapPx()` can read the
            // SAME number back out on the next pass and subtract it back
            // out of this row's own measured height.
            '.dotdoc-table-split-gap-cell{padding-bottom:var(--dotdoc-split-gap,0px)}'.
            '.editor-main.dotdoc-paginated .page-break,.editor-main.dotdoc-paginated .section-break{display:none}'.
            '.editor-main.dotdoc-mode-focus .dotdoc-page-boundary,.editor-main.dotdoc-mode-focus .dotdoc-page-edge,.editor-main.dotdoc-mode-focus .dotdoc-page-band{display:none}'.
            '.editor-main.dotdoc-mode-focus .dotdoc-page-gap{background:transparent;height:0}'.
            // Design spec §3: Focus mode hides "all chrome (rail/dock/
            // topbar) - a single continuous scroll of prose", not just the
            // page-break decorations above. `.editor-main` cannot reach any
            // of the three with an ordinary descendant selector - they are
            // its OWN ancestor's siblings inside `.shell` (layouts/
            // app.blade.php's grid: topbar/rail/canvas/dock), several
            // levels above this rule's own injection point (editor.blade.
            // php's `<style id="doc-style">`, itself nested inside
            // `#canvas`). `:has()` is the only pure-CSS way to react, from
            // an ancestor, to a class living on a DESCENDANT of a
            // different branch of that ancestor's subtree.
            //
            // This deliberately does NOT touch `data-panel-state`/
            // `data-panel-user` (.ai/rules/views.md's rail/dock state
            // machine) at all - it only ever hides, never opens or closes,
            // a panel. A panel the writer had open before switching to
            // Focus mode keeps recording itself as open the whole time;
            // leaving Focus mode (viewModes.js's applyMode() removing the
            // class on any other mode) needs no restore step, because
            // nothing here ever changed what was being restored - the
            // panel reappears with whatever state it already had, exactly
            // per "a trigger never closes a panel somebody opened on
            // purpose" (this just isn't a trigger).
            '.shell:has(.editor-main.dotdoc-mode-focus) .topbar,'.
            '.shell:has(.editor-main.dotdoc-mode-focus) .rail,'.
            '.shell:has(.editor-main.dotdoc-mode-focus) .dock{display:none}'.
            // `.rail`/`.dock` sit in `auto`-sized grid columns (shell.css'
            // own comment on `.shell`: "A collapsed panel is display:none,
            // which empties its column, which collapses the track to zero"),
            // so hiding them above is already enough - no separate rule
            // needed. `.topbar`'s ROW is not `auto` though: `grid-template-
            // rows: var(--topbar-h) auto minmax(0, 1fr)` fixes it at 52px
            // regardless of what is inside it, so `.topbar{display:none}`
            // alone leaves an empty 52px band of bare `--ground` at the top
            // instead of the "single continuous scroll" design spec §3
            // promises - confirmed live (`getComputedStyle(shell).
            // gridTemplateRows` still read "52px ..." with the topbar
            // hidden). Collapsing the row itself here is scoped to the SAME
            // `:has()` condition, so it only ever fires alongside the
            // content hide immediately above.
            '.shell:has(.editor-main.dotdoc-mode-focus){grid-template-rows:0 auto minmax(0,1fr)}'.
            '.editor-main.dotdoc-mode-single{scroll-snap-type:y mandatory;overflow-y:auto}'.
            '.editor-main.dotdoc-mode-single .paper{scroll-snap-align:start}'.
            // Two Page mode was removed from v1 (Task 8, design spec §7):
            // `.paper` is one continuous element for the WHOLE document
            // (design spec §2), so a two-column grid here would always
            // place that single element in column 1 and leave column 2
            // permanently, visibly empty - it cannot show two DIFFERENT
            // pages side by side without the live Range-based extraction
            // technique the thumbnails use (viewModes.js), which is a
            // materially bigger feature than a CSS fix. No CSS rule
            // exists for `dotdoc-mode-two-page` because `two-page` is no
            // longer a selectable view mode (viewModes.js's MODES, the
            // Blade `<select>`) - do not re-add a grid rule here without
            // also re-adding that live-extraction rendering.
            '.dotdoc-multi-page-grid,.dotdoc-thumbnail-rail{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:var(--s3, 12px)}'.
            // `.canvas` (paper.css) is a FLEX container, so without an
            // explicit width `.dotdoc-multi-page-grid` (mounted inside it
            // as `.paper`'s replacement, viewModes.js's applyMode()) sizes
            // to its own min-content instead of the canvas's available
            // width - collapsing `repeat(auto-fill, minmax(120px,1fr))`
            // to a single column of 120px boxes rather than the "zoomed-
            // out GRID of scaled page previews" design spec §3 promises.
            // `.dotdoc-thumbnail-rail` needs no equivalent rule: it lives
            // in the rail panel's own block-layout container, not inside
            // this flex row.
            '.dotdoc-multi-page-grid{width:100%}'.
            '.dotdoc-thumbnail{border:1px solid var(--line);border-radius:var(--r-control, 8px);overflow:hidden;cursor:pointer;position:relative;aspect-ratio:210/297}'.
            '.dotdoc-thumbnail.is-current{outline:2px solid var(--accent);outline-offset:2px}'.
            '.dotdoc-thumbnail-inner{transform-origin:top left;pointer-events:none}'.
            '.dotdoc-thumbnail-number{position:absolute;bottom:4px;right:4px;font-size:var(--doc-size-small, 11px);background:var(--surface);color:var(--ink-soft);padding:0 4px;border-radius:4px}'.
            // Multi-Page mode REPLACES the canvas with "a zoomed-out grid
            // of scaled page previews" (design spec §3), exactly the same
            // claim Print Preview makes below about the PDF iframe - and
            // the same bug Task 8's browser verification caught here:
            // viewModes.js's applyMode() APPENDS `.dotdoc-multi-page-grid`
            // as a sibling of `.paper` rather than replacing it, so without
            // this rule the live continuous canvas kept rendering ABOVE the
            // grid instead of being replaced by it. Mirrors the
            // print-preview rule below exactly, including
            // pagination/index.js's matching skip-while-hidden guard in
            // `runRepaginate()` (measuring a `display:none` subtree
            // reports zero-height rects and would wipe every decoration).
            //
            // `.canvas>.paper`, NOT a bare `.paper` descendant selector:
            // `.paper` and `#doc-paper`/`.canvas` are NOT the same element -
            // TipTap mounts a genuinely separate child div (class
            // `tiptap ProseMirror paper`) INSIDE the `#doc-paper.canvas`
            // host, confirmed live via getComputedStyle after this bug was
            // found. A thumbnail clone (viewModes.js's renderThumbnail())
            // ALSO carries the `paper` class and sits nested several levels
            // inside this same `.editor-main.dotdoc-mode-multi-page`
            // subtree (inside `.dotdoc-multi-page-grid`, itself inside
            // `.canvas` too) - a bare `.paper` descendant selector matches
            // it as well as the real canvas, silently hiding every
            // thumbnail's content and leaving Multi-Page mode showing
            // empty bordered boxes. The real editable canvas is always a
            // DIRECT child of `.canvas`; no clone ever is - `>` is what
            // makes this rule hide only the one element it is meant to.
            '.editor-main.dotdoc-mode-multi-page .canvas>.paper{display:none}'.
            // Print Preview REPLACES the canvas with the real exported PDF
            // (design spec §3: "not computed live at all") - viewModes.js's
            // applyMode() appends the iframe as a SIBLING of .paper rather
            // than removing .paper from the DOM, so without this rule both
            // would render at once. `.canvas>.paper`, not a bare `.paper`
            // descendant selector, for the same reason as the multi-page
            // rule above - the iframe itself carries no `paper` class today,
            // so this specific mode has no active collision yet, but the
            // scoped selector costs nothing and stays correct if that ever
            // changes. Hiding the real canvas does not affect ProseMirror's
            // document state, so switching back to any other mode restores
            // it with nothing lost.
            '.editor-main.dotdoc-mode-print-preview .canvas>.paper{display:none}'.
            '.dotdoc-print-preview-frame{width:100%;height:80vh;border:none}';
    }

    private function pageRule(): string
    {
        $size = $this->tokens['page']['size'] ?? null;
        $size = (is_string($size) && preg_match('/^[A-Za-z0-9]{1,20}$/', $size)) ? $size : $this->fallback['page']['size'];

        return "@page{size:{$size} portrait;margin:{$this->margins()}}";
    }
}
