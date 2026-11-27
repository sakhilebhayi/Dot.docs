<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\Import\DocxImporter;
use App\Documents\Import\HtmlToJson;
use App\Documents\Import\MarkdownImporter;
use App\Documents\Import\PlainTextImporter;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    private const UNREADABLE = 'That file could not be read, so nothing was imported. Check it opens in Word or a text editor, then try again.';

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
            $wait = 'Import limit reached. Try again in '.RateLimiter::availableIn($key).' seconds.';
            if (! $request->expectsJson()) {
                return redirect()->route('documents.edit', $document->uuid)->withErrors(['file' => $wait]);
            }
            abort(429, $wait);
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

        try {
            $json = $this->parse($file->getRealPath(), strtolower($file->getClientOriginalExtension()), (string) $document->uuid, $docx, $markdown, $html, $plainText);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable) {
            // A file whose bytes sniff as a .docx but whose OOXML is broken
            // reaches PhpWord as anything from its own Exception to a
            // DOMDocument warning promoted to an ErrorException. None of
            // that is an application fault: it is the same "this file could
            // not be read" the unreadable case already answers 422 with.
            // From the page (not JSON) the writer goes back to the editor with
            // the reason in the banner instead of a bare 422 (EA-4 / F-06).
            if (! $request->expectsJson()) {
                return redirect()
                    ->route('documents.edit', $document->uuid)
                    ->withErrors(['file' => self::UNREADABLE]);
            }

            abort(422, 'This file could not be read.');
        }

        // An import states no base and replaces whatever is stored, so the
        // store is told to keep that first (see DocumentStore::save()). The
        // label column holds 120 characters and the name is the uploader's.
        $store->save($document, $json, Auth::user(), [
            'version' => 'named',
            'label' => 'Imported '.$file->getClientOriginalName(),
            'keepReplacedAs' => 'Before import of '.Str::limit($file->getClientOriginalName(), 100, ''),
        ]);

        return redirect()
            ->route('documents.edit', $document->uuid)
            ->with('status', 'File imported successfully.');
    }

    /**
     * Files > Import a document (R2-3 / F-05): a NEW document, filed in that folder, titled after the file, holding
     * the file's contents. Before this the button made an empty "Imported document" and asked for the file again in
     * the editor; anyone who stopped there was left with a stray empty document. The document is created and filled
     * in one transaction, so a file that cannot be read leaves nothing behind.
     */
    public function intoFolder(
        Request $request,
        string $parent,
        DocumentStore $store,
        DocxImporter $docx,
        MarkdownImporter $markdown,
        HtmlToJson $html,
        PlainTextImporter $plainText,
    ): RedirectResponse {
        $node = Obj::where('uuid', $parent)->firstOrFail();
        abort_unless($node->isFolder(), 404);
        Gate::authorize('create', [Obj::class, $node]);

        $back = redirect()->route('files.index', ['folder' => $node->uuid]);

        $key = 'import:'.Auth::id();
        if (! RateLimiter::attempt($key, self::RATE_LIMIT, fn () => true, 3600)) {
            return $back->withErrors(['file' => 'Import limit reached. Try again in '.RateLimiter::availableIn($key).' seconds.'], 'import');
        }

        $request->validateWithBag('import', [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'mimes:'.implode(',', self::EXTENSIONS)],
        ]);

        $file = $request->file('file');
        $title = Str::limit(trim((string) pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'Imported document', 200, '');
        /** @var User $user */
        $user = Auth::user();

        $mediaKey = null;

        try {
            $document = DB::transaction(function () use ($store, $user, $title, $node, $file, $docx, $markdown, $html, $plainText, &$mediaKey) {
                $document = $store->create($user, $title, null, ['team_id' => $node->team_id, 'is_public' => false], $node);
                $mediaKey = (string) $document->uuid;
                $json = $this->parse($file->getRealPath(), strtolower($file->getClientOriginalExtension()), (string) $document->uuid, $docx, $markdown, $html, $plainText);

                return $store->save($document, $json, $user, ['version' => 'named', 'label' => 'Imported '.Str::limit($file->getClientOriginalName(), 100, '')]);
            });
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable) {
            // The rollback took the document away; pictures the .docx importer already wrote for it go too.
            if ($mediaKey !== null) {
                Storage::disk('local')->deleteDirectory('document-media/'.$mediaKey);
            }

            return $back->withErrors(['file' => self::UNREADABLE], 'import');
        }

        return redirect()->route('documents.edit', $document->uuid)->with('status', 'Imported '.$file->getClientOriginalName().'.');
    }

    /**
     * The importer for the file's type, keyed (for .docx pictures) by the document they belong to.
     *
     * @return array<mixed>
     */
    private function parse(string|false $path, string $extension, string $mediaKey, DocxImporter $docx, MarkdownImporter $markdown, HtmlToJson $html, PlainTextImporter $plainText): array
    {
        $path = (string) $path;

        return match ($extension) {
            // The document's own uuid keys the media folder, so pictures pulled out of the .docx land beside the
            // document that owns them (DocumentMedia).
            'docx' => $docx->import($path, $mediaKey),
            'md', 'markdown' => $markdown->import($this->read($path)),
            'html', 'htm' => $html->convert($this->read($path)),
            'txt' => $plainText->import($this->read($path)),
            default => throw new \InvalidArgumentException('Unsupported file type.'),
        };
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        abort_if($contents === false, 422, 'The uploaded file could not be read.');

        return $contents;
    }
}
