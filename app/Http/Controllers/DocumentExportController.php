<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Documents\Export\MarkdownExporter;
use App\Documents\Render\NumberedRender;
use App\Documents\Render\RenderContext;
use App\Models\Document;
use App\Print\PrintRenderer;
use App\Services\WebhookService;
use App\Styles\StyleEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Downloads a document as PDF, DOCX, standalone HTML or Markdown.
 *
 * Every format renders the SAME pipeline the editor and the PDF use — the
 * stored JSON through Outline::build()/apply() so headings carry their
 * numbers and cross-references their labels — and then differs only in the
 * writer. See .ai/rules/documents-io.md for the node mapping each writer
 * implements.
 */
class DocumentExportController extends Controller
{
    use AuthorizesRequests;

    private const WORD_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private DocumentStore $store,
        private StyleEngine $styles,
        private NumberedRender $numbered,
        private DocxExporter $docx,
        private MarkdownExporter $markdown,
    ) {}

    public function export(string $uuid, string $format): SymfonyResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $document);

        // Rate limit: 10 exports per user per hour
        $key = 'export:'.auth()->id();
        if (! RateLimiter::attempt($key, 10, fn () => true, 3600)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Export limit reached. Try again in {$seconds} seconds.");
        }

        $safeTitle = Str::slug($document->title ?: 'document') ?: 'document';

        $response = match ($format) {
            'pdf' => $this->exportPdf($document, $safeTitle),
            'word' => $this->exportWord($document, $safeTitle),
            'html' => $this->exportHtml($document, $safeTitle),
            'markdown' => $this->exportMarkdown($document, $safeTitle),
            default => abort(404, 'Unknown export format.'),
        };

        // Fire on_export webhooks (best-effort, after response is built)
        app(WebhookService::class)->fire($document, 'on_export', ['format' => $format]);

        return $response;
    }

    private function exportPdf(Document $document, string $safeTitle): SymfonyResponse
    {
        $pdf = app(PrintRenderer::class)->pdf($document);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.pdf\"",
        ]);
    }

    /**
     * DocxExporter writes a temp file rather than a string (PhpWord's writer
     * only saves to a path), so the response streams it and deletes it once
     * it has been sent.
     */
    private function exportWord(Document $document, string $safeTitle): SymfonyResponse
    {
        $path = $this->docx->export(
            $this->store->json($document),
            $document,
            $this->styles->resolve($document),
        );

        return response()
            ->download($path, $safeTitle.'.docx', ['Content-Type' => self::WORD_MIME])
            ->deleteFileAfterSend(true);
    }

    private function exportHtml(Document $document, string $safeTitle): Response
    {
        $style = $this->styles->resolve($document);
        $body = $this->numbered->html($document, RenderContext::share());
        $css = $this->styles->css($style, 'canvas');
        $title = e($document->title);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <style>
        body{background:#f5f3ee;margin:0;padding:32px 16px}
        .paper{margin:0 auto;box-shadow:0 1px 3px rgba(0,0,0,.12)}
        {$css}
        </style>
        </head>
        <body>
        <div class="paper">
        <h1>{$title}</h1>
        {$body}
        </div>
        </body>
        </html>
        HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.html\"",
        ]);
    }

    private function exportMarkdown(Document $document, string $safeTitle): Response
    {
        [$json] = $this->numbered->prepare($document, RenderContext::share());

        $body = $this->markdown->export($json);
        $markdown = '# '.$document->title."\n".($body === '' ? '' : "\n".$body);

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.md\"",
        ]);
    }
}
