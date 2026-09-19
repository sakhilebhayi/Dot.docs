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
            '.dotdoc-page-boundary{contain:layout;pointer-events:none}'.
            '.dotdoc-page-gap{height:2.5em;background:var(--ground)}'.
            '.dotdoc-page-shadow{height:.5em;background:linear-gradient(to bottom,rgba(0,0,0,.08),transparent)}'.
            '.dotdoc-page-shadow-below{transform:rotate(180deg)}'.
            // A 4-value padding shorthand (top right bottom left): a small
            // fixed vertical padding appropriate for a one-line band, and
            // the page's REAL left/right margin so the band's text aligns
            // with the body text above/below it.
            ".dotdoc-page-band{background:#fff;padding:.4em {$h['right']} .4em {$h['left']};font-size:var(--doc-size-small);color:var(--doc-muted);pointer-events:auto}".
            '.editor-main.dotdoc-paginated .page-break,.editor-main.dotdoc-paginated .section-break{display:none}'.
            '.editor-main.dotdoc-mode-focus .dotdoc-page-boundary,.editor-main.dotdoc-mode-focus .dotdoc-page-band{display:none}'.
            '.editor-main.dotdoc-mode-focus .dotdoc-page-gap{background:transparent;height:0}'.
            '.editor-main.dotdoc-mode-single{scroll-snap-type:y mandatory;overflow-y:auto}'.
            '.editor-main.dotdoc-mode-single .paper{scroll-snap-align:start}'.
            '.editor-main.dotdoc-mode-two-page{display:grid;grid-template-columns:repeat(2,210mm);gap:1em;justify-content:center}'.
            '.dotdoc-multi-page-grid,.dotdoc-thumbnail-rail{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:var(--s3, 12px)}'.
            '.dotdoc-thumbnail{border:1px solid var(--line);border-radius:var(--r-control, 8px);overflow:hidden;cursor:pointer;position:relative;aspect-ratio:210/297}'.
            '.dotdoc-thumbnail.is-current{outline:2px solid var(--accent);outline-offset:2px}'.
            '.dotdoc-thumbnail-inner{transform-origin:top left;pointer-events:none}'.
            '.dotdoc-thumbnail-number{position:absolute;bottom:4px;right:4px;font-size:var(--doc-size-small, 11px);background:var(--surface);color:var(--ink-soft);padding:0 4px;border-radius:4px}'.
            '.dotdoc-print-preview-frame{width:100%;height:80vh;border:none}';
    }

    private function pageRule(): string
    {
        $size = $this->tokens['page']['size'] ?? null;
        $size = (is_string($size) && preg_match('/^[A-Za-z0-9]{1,20}$/', $size)) ? $size : $this->fallback['page']['size'];

        return "@page{size:{$size} portrait;margin:{$this->margins()}}";
    }
}
