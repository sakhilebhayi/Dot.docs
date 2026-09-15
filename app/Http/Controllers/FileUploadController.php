<?php

namespace App\Http\Controllers;

use App\Files\FilesService;
use App\Models\Files\Obj;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Put a file into a folder of the shared tree.
 *
 * Authorisation is on the PARENT node, through ObjPolicy, exactly as
 * FilesService::createFile does internally - a uuid in the URL therefore
 * reaches nothing outside the uploader's own teams. The validation
 * discipline follows DocumentImageController: a hard size cap and an
 * explicit mime allow-list rather than trusting the sent filename, and the
 * stored path is composed by FilesService from the team id and a random
 * name, never from what was uploaded.
 *
 * Gate::authorize(), not $this->authorize(): the base Controller in this
 * application carries no AuthorizesRequests trait.
 */
class FileUploadController extends Controller
{
    /** 20 MB, the same ceiling DocumentImportController accepts. */
    private const MAX_KILOBYTES = 20480;

    public function store(Request $request, string $parent): RedirectResponse
    {
        $node = Obj::where('uuid', $parent)->firstOrFail();

        abort_unless($node->isFolder(), 404);
        Gate::authorize('create', [Obj::class, $node]);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                'mimetypes:'.implode(',', [
                    // NO image/svg+xml. An SVG is a script that renders as a
                    // picture, and FileViewController serves stored bytes
                    // back: one carrying a <script> would execute in the
                    // Dot.Doc origin with the session of whichever team
                    // member opened it. DocumentImageController escapes this
                    // by re-encoding every image to raster WebP; this
                    // controller stores bytes verbatim, so the type is
                    // refused at the door instead. See .ai/rules/files.md.
                    'image/png', 'image/jpeg', 'image/gif', 'image/webp',
                    'application/pdf',
                    'text/plain', 'text/markdown', 'text/csv',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/zip',
                ]),
            ],
        ]);

        $upload = $request->file('file');

        app(FilesService::class)->createFile(
            $node,
            (string) $upload->getClientOriginalName(),
            (string) $upload->get(),
            $upload->getMimeType(),
            $request->user(),
        );

        return back()->with('status', 'The file is filed here now.');
    }
}
