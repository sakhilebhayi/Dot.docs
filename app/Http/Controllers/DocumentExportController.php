<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Print\PrintRenderer;
use App\Services\WebhookService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\HtmlConverter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class DocumentExportController extends Controller
{
    use AuthorizesRequests;

    public function export(string $uuid, string $format): Response|\Symfony\Component\HttpFoundation\Response
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $document);

        // Rate limit: 10 exports per user per hour
        $key = 'export:'.auth()->id();
        if (! RateLimiter::attempt($key, 10, fn () => true, 3600)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Export limit reached. Try again in {$seconds} seconds.");
        }

        $safeTitle = Str::slug($document->title ?: 'document');

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

    private function exportPdf(Document $document, string $safeTitle): \Symfony\Component\HttpFoundation\Response
    {
        $pdf = app(PrintRenderer::class)->pdf($document);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.pdf\"",
        ]);
    }

    private function exportWord(Document $document, string $safeTitle): Response
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(12);

        $section = $phpWord->addSection();

        // Title
        $section->addText(
            $document->title,
            ['bold' => true, 'size' => 20],
            ['alignment' => 'center', 'spaceAfter' => 240]
        );

        // Strip HTML and split into paragraphs
        $plain = strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $document->content ?? ''));
        foreach (explode("\n", $plain) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $section->addText(htmlspecialchars($line));
            } else {
                $section->addTextBreak();
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx_');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tmpFile);

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.docx\"",
        ]);
    }

    private function exportHtml(Document $document, string $safeTitle): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$document->title}</title>
<style>body{font-family:Georgia,serif;max-width:800px;margin:40px auto;padding:0 20px;line-height:1.6;}</style>
</head>
<body>
<h1>{$document->title}</h1>
{$document->content}
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
        $converter = new HtmlConverter([
            'strip_tags' => false,
            'header_style' => 'atx',
        ]);

        $markdown = "# {$document->title}\n\n".$converter->convert($document->content ?? '');

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$safeTitle}.md\"",
        ]);
    }
}
