<?php

namespace App\Http\Controllers;

use App\Documents\DocumentMedia;
use App\Models\Document;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /media/{uuid}/{file}: a picture inside a document, to the people who can read that document (DocumentMedia).
 * Anyone else gets the same 404 as a file that does not exist, so the route cannot be used to find documents.
 */
class MediaController extends Controller
{
    private const TYPES = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    public function __invoke(Request $request, string $uuid, string $file): BinaryFileResponse
    {
        $document = Document::where('uuid', $uuid)->first();
        $path = $document ? DocumentMedia::file($uuid, $file) : null;

        abort_if($document === null || $path === null || ! DocumentMedia::canSee($request, $document), 404);

        $response = response()->file($path, [
            'Content-Type' => self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline',
        ]);

        // Private: a shared cache must never hand one reader's picture to the next. Set on the object, because a
        // BinaryFileResponse marks itself public by default and that would win over a header passed in above.
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
