<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\StaleDocumentException;
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

        $request->validate([
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],
            'content.content' => ['sometimes', 'array'],
            'content.attrs' => ['sometimes', 'array'],
            // The version the page's copy was based on. A beacon that states
            // none comes from a tab running JavaScript from before saves
            // carried one, and is refused: it would overwrite blind.
            'base_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            // The RAW input, never `validated()`. validate() returns only the
            // keys it was given rules for, so saving the validated array threw
            // `content.attrs` (schema/style/vars) away and ensureIds() then
            // re-stamped its defaults on every navigation away — the document's
            // style and variables reset themselves behind the writer's back.
            // Rules here are a shape check; DocumentSchema::validate(), run
            // inside DocumentStore::save(), is what actually vets the content.
            $document = $store->save($document, $request->input('content'), Auth::user(), [
                'version' => 'none',
                'expectedVersion' => $request->integer('base_version'),
            ]);
        } catch (StaleDocumentException $e) {
            // Somebody saved after this page last synced. The page is already
            // gone, so nobody reads this; what matters is that nothing was
            // overwritten. The offline draft in the browser still holds the
            // text, and the editor page offers it back the next time the
            // document is opened there (Put it back).
            return response()->json(['conflict' => true, 'version' => $e->currentVersion], 409);
        } catch (InvalidArgumentException $e) {
            // DocumentSchema::validate() refused it — an unknown node type, or
            // a block with no valid id. Report it as a validation failure so
            // the caller gets a 422 rather than a 500.
            throw ValidationException::withMessages(['content' => $e->getMessage()]);
        }

        return response()->json(['version' => $document->version]);
    }
}
