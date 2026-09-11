<?php

namespace App\Http\Controllers;

use App\Models\Files\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read one file out of the shared Dot.Files blob store.
 *
 * TWO gates, and both are needed. The `signed` middleware on the route
 * proves the link was minted by this application and has not expired (ten
 * minutes - see App\Livewire\Files\Navigator::fileUrl), which is what stops
 * a link pasted into a chat from being a permanent public URL. That alone
 * would still let ANY signed-in person open a link meant for someone else,
 * so team membership is checked here as well, off the file's OWN team_id
 * and never the session's current team.
 *
 * The path is resolved with the same realpath() containment discipline
 * DocxExporter::publicDiskPath() uses (.ai/rules/documents-io.md): the
 * stored `path` is composed by FilesService and is not user input, but a
 * row on a SHARED database was not necessarily written by this app, so what
 * it resolves to is proved to be under the disk root before anything is
 * read. The `files` disk is private and is not exposed under
 * public/storage, so this route is the only way in.
 */
class FileViewController extends Controller
{
    /**
     * The only types rendered IN this origin. Everything else is handed over
     * as a download, so a stored file can never run as a document with the
     * viewer's session: SVG above all (a script that renders as a picture),
     * and HTML by the same argument. Uploads refuse both
     * (FileUploadController), but `files` is a SHARED table - a row written
     * by a Dot.Files instance can name any type at all - so the inline
     * decision is made here against an allow-list rather than from the
     * stored string.
     */
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/markdown', 'text/csv',
    ];

    public function show(Request $request, string $file): BinaryFileResponse
    {
        $record = File::where('uuid', $file)->firstOrFail();

        abort_unless($record->team && $request->user()?->belongsToTeam($record->team), 403);

        $path = $this->containedPath($record->path);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => $record->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $this->disposition($record->mime_type).'; filename="'.addslashes($record->name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** `inline` only for a type on the allow-list; `attachment` for anything else. */
    private function disposition(?string $mime): string
    {
        $type = strtolower(trim(explode(';', (string) $mime, 2)[0]));

        return in_array($type, self::INLINE_TYPES, true) ? 'inline' : 'attachment';
    }

    /**
     * The absolute path a stored `files.path` names inside the `files` disk,
     * or null when it names anything else - an absolute path, a path with a
     * `.`/`..` segment, or one that resolves outside the disk root.
     */
    private function containedPath(string $stored): ?string
    {
        if ($stored === '' || str_starts_with($stored, '/') || preg_match('#(^|/)\.\.?(/|$)#', $stored) === 1) {
            return null;
        }

        $root = realpath(Storage::disk('files')->path(''));
        $path = realpath(Storage::disk('files')->path($stored));

        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? $path : null;
    }
}
