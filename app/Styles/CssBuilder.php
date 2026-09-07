<?php

namespace App\Styles;

use App\Models\DocumentStyle;

/**
 * Turns one DocumentStyle's token array into a CSS string for either the
 * 'canvas' (editor, a fixed A4-shaped .paper) or 'print' (export/PDF, with
 * an at-page rule) surface. Every selector renders from CSS custom
 * properties set on .paper so a style switch is a single stylesheet swap.
 *
 * Callouts always get a full hairline border plus a tone-coloured title
 * (never a thick single-side border) - a design constraint, not a token.
 */
class CssBuilder
{
    /** @var array<string,mixed> */
    private array $tokens;

    public function __construct(private DocumentStyle $style, private string $mode)
    {
        $this->tokens = $style->tokens;
    }

    public function build(): string
    {
        $parts = [];

        $import = $this->tokens['fonts']['import'] ?? '';
        if (is_string($import) && str_starts_with($import, 'https://fonts.googleapis.com/')) {
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
        $fonts = $this->tokens['fonts'];
        $sizes = $this->tokens['sizes'];
        $spacing = $this->tokens['spacing'];
        $colours = $this->tokens['colours'];

        $vars = [
            '--doc-font-body' => $this->fontStack($fonts['body']),
            '--doc-font-heading' => $this->fontStack($fonts['heading']),
            '--doc-font-mono' => $this->fontStack($fonts['mono']),
            '--doc-size-body' => $sizes['body'],
            '--doc-size-h1' => $sizes['h1'],
            '--doc-size-h2' => $sizes['h2'],
            '--doc-size-h3' => $sizes['h3'],
            '--doc-size-small' => $sizes['small'],
            '--doc-leading' => (string) $this->tokens['leading'],
            '--doc-space-paragraph' => $spacing['paragraph'],
            '--doc-space-heading-top' => $spacing['headingTop'],
            '--doc-space-heading-bottom' => $spacing['headingBottom'],
            '--doc-ink' => $colours['ink'],
            '--doc-heading' => $colours['heading'],
            '--doc-accent' => $colours['accent'],
            '--doc-rule' => $colours['rule'],
            '--doc-muted' => $colours['muted'],
        ];

        if ($this->mode === 'canvas') {
            $vars['width'] = '210mm';
            $vars['min-height'] = '297mm';
            $vars['padding'] = $this->margins();
        }

        $decls = implode(';', array_map(fn ($prop, $value) => "{$prop}:{$value}", array_keys($vars), $vars));

        return ".paper{background:#fff;{$decls}}";
    }

    private function margins(): string
    {
        $m = $this->tokens['page']['margins'];

        return "{$m['top']} {$m['right']} {$m['bottom']} {$m['left']}";
    }

    private function headingRules(): string
    {
        $upper = ($this->tokens['headingCase'] ?? 'none') === 'upper';
        $rules = '';
        foreach (['h1' => 700, 'h2' => 600, 'h3' => 500] as $tag => $weight) {
            $transform = $tag === 'h1' && $upper ? 'uppercase' : 'none';
            $rules .= ".paper {$tag}{font-family:var(--doc-font-heading);font-size:var(--doc-size-{$tag});".
                "font-weight:{$weight};color:var(--doc-heading);text-transform:{$transform};".
                'margin:var(--doc-space-heading-top) 0 var(--doc-space-heading-bottom)}';
        }

        return $rules;
    }

    private function paragraphRule(): string
    {
        $align = $this->tokens['align'] ?? 'left';

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

        return '.paper .page-break{border-top:1px dashed var(--doc-rule);text-align:center;margin:2em 0;position:relative}'.
            '.paper .page-break::after{content:"page break";position:relative;top:-.6em;background:#fff;padding:0 .6em;font-size:var(--doc-size-small);color:var(--doc-muted)}';
    }

    private function pageRule(): string
    {
        $page = $this->tokens['page'];

        return "@page{size:{$page['size']} portrait;margin:{$this->margins()}}";
    }
}
