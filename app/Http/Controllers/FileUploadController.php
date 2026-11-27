<?php

namespace App\Http\Controllers;

use App\Files\FilesService;
use App\Http\Requests\FileUploadRequest;
use Illuminate\Http\RedirectResponse;
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
    /** The folder is found, the upload authorised and the file checked by FileUploadRequest (R2-7 / Q-06). */
    public function store(FileUploadRequest $request, string $parent): RedirectResponse
    {
        $node = $request->folder();

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
