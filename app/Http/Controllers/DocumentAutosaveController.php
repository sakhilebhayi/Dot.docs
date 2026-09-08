<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The editor's last-chance autosave.
 *
 * The bundle debounces autosave by 1200 ms and normally sends it through
 * Livewire ($wire.saveContent). On `pagehide` that is not possible: Livewire's
 * CommitBus defers every call on a 5 ms timer the unloading page never runs, so
 * the request is never even created. `navigator.sendBeacon()` is the only send
 * the browser promises to deliver during unload, and it can only POST a body to
 * a plain endpoint — this one. It writes through DocumentStore like every other
 * content writer (see .ai/rules/app.md) and cuts no version snapshot: navigating
 * away is not a point in the document's history.
 */
class DocumentAutosaveController extends Controller
{
    public function store(Request $request, string $uuid, DocumentStore $store): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->firstOrFail();
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],
            'content.content' => ['sometimes', 'array'],
        ]);

        try {
            $document = $store->save($document, $validated['content'], Auth::user(), ['version' => 'none']);
        } catch (InvalidArgumentException $e) {
            // DocumentSchema::validate() refused it — an unknown node type, or
            // a block with no valid id. Report it as a validation failure so
            // the caller gets a 422 rather than a 500.
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }

        return response()->json(['version' => $document->version]);
    }
}
