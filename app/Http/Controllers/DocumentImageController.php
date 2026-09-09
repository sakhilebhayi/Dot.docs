<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentImageController extends Controller
{
    public function store(Request $request, string $uuid): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        // Gate::authorize(), not $this->authorize(): the base Controller in
        // this application has no AuthorizesRequests trait, so $this->authorize()
        // is an undefined method and EVERY image upload 500'd. Same shape as
        // DocumentAutosaveController and DocumentImportController.
        Gate::authorize('update', $document);

        $request->validate([
            'image' => 'required|image|max:4096',
        ]);

        $filename = Str::uuid().'.webp';
        // The framework's own image manager. Intervention's facade binds the
        // same container key ('image') as Illuminate\Image, so `Image::read()`
        // resolved to Laravel's driver, which has no read() — the second half
        // of the 500 every upload was answering.
        $encoded = Image::fromUpload($request->file('image'))->quality(82)->toWebp()->toBytes();

        Storage::disk('public')->put('document-images/'.$filename, $encoded);

        return response()->json([
            'url' => asset('storage/document-images/'.$filename),
        ]);
    }
}
