<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Documents\Export\MarkdownExporter;
use App\Documents\Render\NumberedRender;
use App\Documents\Render\RenderContext;
use App\Files\FilesService;
use App\Models\Document;
use App\Print\PrintRenderer;
use App\Services\WebhookService;
use App\Styles\StyleEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
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

        app(AuditLogger::class)->record('document.exported', $document, ['format' => $format]);

        // Fire on_export webhooks (best-effort, after response is built)
        app(WebhookService::class)->fire($document, 'on_export', ['format' => $format]);

        return $response;
    }

    /**
     * The same PDF `export('pdf')` produces, streamed for the pagination
     * editor's Print Preview mode to embed in an <iframe> instead of
     * downloaded. This is a SEPARATE route from `export()`, not that route
     * with its disposition flipped — `export()` answers "Export → PDF" for
     * every caller of it, and changing what that route DOES would be a much
     * bigger change than adding a second way to read the same bytes (see
     * .ai/rules/documents-io.md).
     *
     * Two differences from `export('pdf')`, both deliberate:
     *   - `inline` disposition, not `attachment` — an `attachment`
     *     disposition makes every browser abort an <iframe>'s navigation
     *     outright rather than render it, which is exactly what left Print
     *     Preview mode permanently blank (found live in Task 8's browser
     *     verification: `net::ERR_ABORTED` on a 200 OK response).
     *   - its OWN rate-limit budget, `preview-pdf:<user>`, not the export
     *     budget `export:<user>` — a preview isn't a real export (no
     *     `document.exported` audit row, no `on_export` webhook fires
     *     here), and switching view modes back and forth must not spend
     *     down the same 10/hour budget a writer needs for real downloads.
     *     Kept generous (30/hour) since it is the SAME PDF render cost as a
     *     real export and this route is reachable only by an authorized
     *     viewer of the document, not a public endpoint.
     */
    public function previewPdf(string $uuid): SymfonyResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $document);

        $key = 'preview-pdf:'.auth()->id();
        if (! RateLimiter::attempt($key, 30, fn () => true, 3600)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Preview limit reached. Try again in {$seconds} seconds.");
        }

        $pdf = app(PrintRenderer::class)->pdf($document);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * The same export, filed in the shared Dot.Files tree instead of
     * downloaded.
     *
     * It lands beside the document - in the folder the document's tree node
     * sits in - so "save the PDF" puts it where a person would look for it,
     * and a document at the workspace root puts its export at the root too.
     * The 10/hour bucket is the SAME one `export()` spends from: this runs
     * the identical render, so it has to cost the same.
     */
    public function saveToFiles(string $uuid, string $format): RedirectResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $document);

        $key = 'export:'.auth()->id();
        if (! RateLimiter::attempt($key, 10, fn () => true, 3600)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Export limit reached. Try again in {$seconds} seconds.");
        }

        $files = app(FilesService::class);
        $node = $document->node()->first();
        $parent = $node?->parent;

        if ($parent === null) {
            $team = $document->team ?? $document->owner?->personalTeam();
            abort_if($team === null, 409, 'This document has no workspace to file an export in.');
            $parent = $files->root($team);
        }

        [$contents, $mime, $extension] = $this->payload($document, $format);

        $safeTitle = Str::slug($document->title ?: 'document') ?: 'document';

        $files->createFile($parent, $safeTitle.'.'.$extension, $contents, $mime, auth()->user());

        app(AuditLogger::class)->record('document.exported', $document, ['format' => $format, 'to' => 'files']);
        app(WebhookService::class)->fire($document, 'on_export', ['format' => $format]);

        return back()->with('status', 'Saved to Dot.Files.');
    }

    /**
     * The bytes an export format produces, with the mime type and extension
     * to file them under.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function payload(Document $document, string $format): array
    {
        return match ($format) {
            'pdf' => [app(PrintRenderer::class)->pdf($document), 'application/pdf', 'pdf'],
            'word' => [$this->wordBytes($document), self::WORD_MIME, 'docx'],
            'html' => [$this->htmlBody($document), 'text/html', 'html'],
            'markdown' => [$this->markdownBody($document), 'text/markdown', 'md'],
            default => abort(404, 'Unknown export format.'),
        };
    }

    /** PhpWord's writer only saves to a path, so the temp file is read back and removed. */
    private function wordBytes(Document $document): string
    {
        $path = $this->docx->export(
            $this->store->json($document),
            $document,
            $this->styles->resolve($document),
        );

        try {
            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
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
        return response($this->htmlBody($document), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.html\"",
        ]);
    }

    private function htmlBody(Document $document): string
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

        return $html;
    }

    private function exportMarkdown(Document $document, string $safeTitle): Response
    {
        return response($this->markdownBody($document), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.md\"",
        ]);
    }

    private function markdownBody(Document $document): string
    {
        [$json] = $this->numbered->prepare($document, RenderContext::share());

        $body = $this->markdown->export($json);

        return '# '.$document->title."\n".($body === '' ? '' : "\n".$body);
    }
}
