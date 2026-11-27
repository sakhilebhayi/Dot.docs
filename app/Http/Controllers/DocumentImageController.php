<?php

namespace App\Http\Controllers;

use App\Documents\DocumentMedia;
use App\Http\Requests\DocumentImageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Str;

class DocumentImageController extends Controller
{
    public function store(DocumentImageRequest $request, string $uuid): JsonResponse
    {
        // Looked up, authorised (update) and checked by DocumentImageRequest, in that order (R2-7 / Q-06).
        $document = $request->document();

        $filename = Str::uuid().'.webp';
        // The framework's own image manager. Intervention's facade binds the
        // same container key ('image') as Illuminate\Image, so `Image::read()`
        // resolved to Laravel's driver, which has no read() — the second half
        // of the 500 every upload was answering.
        $encoded = Image::fromUpload($request->file('image'))->quality(82)->toWebp()->toBytes();

        // Private, under the document, served only to its readers (R2-1 / S-13; DocumentMedia).
        return response()->json([
            'url' => DocumentMedia::put((string) $document->uuid, $filename, $encoded),
        ]);
    }
}
