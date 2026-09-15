<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\Import\DocxImporter;
use App\Documents\Import\HtmlToJson;
use App\Documents\Import\MarkdownImporter;
use App\Documents\Import\PlainTextImporter;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Replaces a document's content with an uploaded file.
 *
 * The controller only picks an importer and hands the JSON it returns to
 * DocumentStore::save() — every importer already returns schema-valid JSON
 * (ensureIds + normalise, see .ai/rules/documents-io.md), and the store is
 * the only writer of content/content_json/search_text/word_count
 * (.ai/rules/app.md).
 */
class DocumentImportController extends Controller
{
    /**
     * Accepted upload extensions. `mimes:` validates the file's sniffed MIME
     * against these, so an .exe renamed to .docx is rejected before any
     * importer opens it.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['docx', 'md', 'markdown', 'html', 'htm', 'txt'];

    /** 20 MB, in the kilobytes `max:` counts. */
    private const MAX_KILOBYTES = 20480;

    /**
     * Imports per user per hour — the same budget
     * DocumentExportController spends on the way out. An import parses a
     * stranger's file and writes a version, so it is the more expensive of
     * the two directions to leave unbounded.
     */
    private const RATE_LIMIT = 10;

    public function store(
        Request $request,
        string $uuid,
        DocumentStore $store,
        DocxImporter $docx,
        MarkdownImporter $markdown,
        HtmlToJson $html,
        PlainTextImporter $plainText,
    ): RedirectResponse {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $document);

        $key = 'import:'.Auth::id();
        if (! RateLimiter::attempt($key, self::RATE_LIMIT, fn () => true, 3600)) {
            abort(429, 'Import limit reached. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'mimes:'.implode(',', self::EXTENSIONS),
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = (string) $file->getRealPath();

        try {
            $json = match ($extension) {
                // The document's own uuid keys the media directory, so pictures
                // pulled out of the .docx land beside the document that owns
                // them (storage/app/public/documents/{uuid}/) rather than in a
                // directory nothing can attribute later.
                'docx' => $docx->import($path, $document->uuid),
                'md', 'markdown' => $markdown->import($this->read($path)),
                'html', 'htm' => $html->convert($this->read($path)),
                'txt' => $plainText->import($this->read($path)),
                default => abort(422, 'Unsupported file type.'),
            };
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable) {
            // A file whose bytes sniff as a .docx but whose OOXML is broken
            // reaches PhpWord as anything from its own Exception to a
            // DOMDocument warning promoted to an ErrorException. None of
            // that is an application fault: it is the same "this file could
            // not be read" the unreadable case already answers 422 with.
            abort(422, 'This file could not be read.');
        }

        $store->save($document, $json, Auth::user(), [
            'version' => 'named',
            'label' => 'Imported '.$file->getClientOriginalName(),
        ]);

        return redirect()
            ->route('documents.edit', $document->uuid)
            ->with('status', 'File imported successfully.');
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        abort_if($contents === false, 422, 'The uploaded file could not be read.');

        return $contents;
    }
}
