<?php

namespace App\Http\Requests;

use App\Models\Files\Obj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A file uploaded into a Files folder (FileUploadController). The folder is looked up (404 when it is not a folder),
 * the policy asked (403), and only then the file checked (422) - the order the controller had (R2-7 / Q-06).
 */
class FileUploadRequest extends FormRequest
{
    /** 20 MB, the same ceiling DocumentImportController accepts. */
    public const MAX_KILOBYTES = 20480;

    private ?Obj $folder = null;

    public function folder(): Obj
    {
        if ($this->folder === null) {
            $node = Obj::where('uuid', (string) $this->route('parent'))->firstOrFail();
            abort_unless($node->isFolder(), 404);
            $this->folder = $node;
        }

        return $this->folder;
    }

    public function authorize(): bool
    {
        Gate::authorize('create', [Obj::class, $this->folder()]);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
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
        ];
    }
}
