<?php

namespace App\Print;

use App\Documents\DocumentStore;
use App\Documents\Outline\Outline;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Models\Document;
use App\Styles\StyleEngine;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a Document to the print/PDF surface: PageSetup's @page rule
 * (overriding the style's own, see StyleEngine::css('print')), fixed
 * header/footer bands, and the body via HtmlRenderer with a 'print'
 * RenderContext (same numbering/TOC/variables pipeline as the editor -
 * see App\Documents\DocumentStore::fill()).
 *
 * Header/footer templates support {{ title }}, {{ date }}, {{ team }} and
 * every $doc->variables key, substituted server-side with htmlspecialchars.
 * {{ page }}/{{ pages }} cannot be resolved at HTML-build time (dompdf only
 * knows the page number while it paginates the PDF), so they are left for
 * dompdf: any header/footer template containing them renders as an empty
 * DOM band, and the real text - with {{ page }}/{{ pages }} rewritten to
 * dompdf's own {PAGE_NUM}/{PAGE_COUNT} placeholders - is instead drawn onto
 * every page's canvas by a <script type="text/php"> block calling
 * Canvas::page_text(), dompdf's page-numbering convention (see
 * vendor/dompdf/dompdf/src/Adapter/CPDF.php::page_text()).
 */
class PrintRenderer
{
    public function __construct(
        private DocumentStore $store,
        private StyleEngine $engine,
        private Outline $outline,
        private HtmlRenderer $renderer,
        private HeaderFooterBands $bands,
    ) {}

    public function html(Document $doc): string
    {
        $style = $this->engine->resolve($doc);
        $setup = PageSetup::fromDocument($doc, $style);

        $json = $this->store->json($doc);
        $rules = $style->tokens['numbering'] ?? [];
        $result = $this->outline->build($json, $rules);
        $json = $this->outline->apply($json, $result);

        $ctx = RenderContext::print();
        $ctx->numbers = $result->numbers;
        $ctx->kinds = $result->kinds;
        $ctx->toc = $result->toc;
        $ctx->vars = $doc->variables ?? [];

        $body = $this->renderer->render($json, $ctx);
        $css = $this->engine->css($style, 'print');

        $vars = array_merge($doc->variables ?? [], [
            'title' => $doc->title,
            'date' => now()->format('Y-m-d'),
            'team' => $doc->team?->name ?? '',
        ]);

        $header = $this->band($setup->header, $vars);
        $footer = $this->band($setup->footer, $vars);

        // page_text() draws on the raw PDF canvas, outside the normal CSS
        // box model, so its x/y offsets are computed here in points rather
        // than left as guesswork pixels - x sits at the left margin, y
        // inside the top/bottom margin band (see toPoints() below).
        $leftPt = self::toPoints($setup->margins['left']);
        $topPt = self::toPoints($setup->margins['top']);
        $bottomPt = self::toPoints($setup->margins['bottom']);

        return view('print.document', [
            'title' => $doc->title,
            'css' => $css,
            'setup' => $setup,
            'body' => $body,
            'headerHtml' => $header['html'],
            'footerHtml' => $footer['html'],
            'headerPageTextLiteral' => $header['pageText'] === null ? null : self::phpStringLiteral($header['pageText']),
            'footerPageTextLiteral' => $footer['pageText'] === null ? null : self::phpStringLiteral($footer['pageText']),
            'pageTextX' => round($leftPt, 2),
            'headerPageTextY' => round(max($topPt - 14, 4), 2),
            'footerPageTextYFromBottom' => round(max($bottomPt - 14, 4), 2),
        ])->render();
    }

    public function pdf(Document $doc): string
    {
        $style = $this->engine->resolve($doc);
        $setup = PageSetup::fromDocument($doc, $style);
        $html = $this->html($doc);

        // Only grant dompdf's eval()-backed PHP evaluator (a security
        // surface - see .ai/rules/print.md) when html() actually emitted a
        // <script type="text/php"> block, i.e. a header/footer template
        // used {{ page }}/{{ pages }}. Most documents render with it off.
        $needsPhpEval = str_contains($html, 'type="text/php"');

        return Pdf::setOption(['isPhpEnabled' => $needsPhpEval])
            ->loadHTML($html)
            ->setPaper(strtolower($setup->size), $setup->orientation)
            ->output();
    }

    /**
     * Turns a header/footer template into either a fully-substituted HTML
     * string, or — when it contains {{ page }}/{{ pages }} — the raw text
     * dompdf's page_text() canvas draw needs (with those two tokens
     * rewritten to dompdf's own {PAGE_NUM}/{PAGE_COUNT} placeholders).
     * HeaderFooterBands::segments() is the shared, field-injection-safe
     * split; this method only reassembles it into PrintRenderer's own
     * {html, pageText} shape, so a header/footer that mixes literal text
     * with a page number renders ENTIRELY via page_text() (dompdf only
     * knows the page number while it paginates the PDF, so the whole band
     * has to wait for that, not just the number itself) — exactly the
     * binary split this method already made before the extraction.
     *
     * @param  array<string,string>  $vars
     * @return array{html:string,pageText:?string}
     */
    private function band(string $template, array $vars): array
    {
        $segments = $this->bands->segments($template, $vars);

        if ($segments === []) {
            return ['html' => '', 'pageText' => null];
        }

        $hasField = collect($segments)->contains(fn (array $s) => $s['type'] === 'field');

        if ($hasField) {
            $pageText = implode('', array_map(
                fn (array $s) => $s['type'] === 'field'
                    ? ($s['value'] === 'PAGE' ? '{PAGE_NUM}' : '{PAGE_COUNT}')
                    : $s['value'],
                $segments,
            ));

            return ['html' => '', 'pageText' => $pageText];
        }

        $html = implode('', array_map(
            fn (array $s) => htmlspecialchars($s['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $segments,
        ));

        return ['html' => $html, 'pageText' => null];
    }

    /**
     * Turns arbitrary text (header/footer templates are user-editable via
     * DocumentSettings::savePageSetup(), not a trusted literal) into a safe
     * single-quoted PHP string literal, for embedding into the
     * <script type="text/php"> block dompdf eval()-uates while rendering
     * (see PhpEvaluator::evaluate()). A single-quoted PHP literal only
     * treats \ and ' specially, so escaping those two is sufficient to stop
     * the text from breaking out of the string into arbitrary PHP.
     * "</script" is additionally neutralised so the text can't also break
     * out of the HTML <script> element itself and open a second
     * text/php block dompdf would then also execute.
     */
    private static function phpStringLiteral(string $text): string
    {
        $text = str_ireplace('</script', '<\\/script', $text);

        return "'".addcslashes($text, "\\'")."'";
    }

    /**
     * Converts a PageSetup margin length (already restricted to
     * pt|px|mm|cm|em|rem|% by App\Styles\TokenGuard::length()) to PDF
     * points for page_text()'s x/y offsets: 1mm = 2.8346pt,
     * 1cm = 28.346pt, 1in = 72pt. A value already in points (or any other
     * unit TokenGuard allows) passes through as its leading numeric value -
     * points is what page_text() expects either way.
     */
    private static function toPoints(string $length): float
    {
        if (! preg_match('/^(\d+(?:\.\d+)?)(mm|cm|pt|in)$/', $length, $m)) {
            return (float) $length;
        }

        $value = (float) $m[1];

        return match ($m[2]) {
            'mm' => $value * 2.8346,
            'cm' => $value * 28.346,
            'in' => $value * 72,
            default => $value,
        };
    }
}
