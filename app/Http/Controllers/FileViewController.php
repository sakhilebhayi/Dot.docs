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
    public function show(Request $request, string $file): BinaryFileResponse
    {
        $record = File::where('uuid', $file)->firstOrFail();

        abort_unless($record->team && $request->user()?->belongsToTeam($record->team), 403);

        $path = $this->containedPath($record->path);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => $record->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($record->name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
