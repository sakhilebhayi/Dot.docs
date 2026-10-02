<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\Outline\EditorOutline;
use App\Models\Document;
use App\Services\PresenceService;
use App\Styles\StyleEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What an open editor polls to stay in step with everybody else.
 *
 * There is no websocket to push a change to a browser - production is shared
 * hosting that cannot run one - so each open editor asks, every second or
 * two while somebody else is present: what version is the document at, who
 * is here, and (only when my copy is behind) what does it say now.
 *
 * A plain controller rather than a Livewire action for the same reason as
 * DocumentAutosaveController: a Livewire request drags the component's whole
 * snapshot - which includes the document - up and down on every call, and
 * the unload path cannot use Livewire at all.
 *
 * It is called constantly, so the quiet case has to stay cheap: it selects
 * six small columns and never the content.
 */
class DocumentSyncController extends Controller
{
    public function store(
        Request $request,
        string $uuid,
        DocumentStore $store,
        PresenceService $presence,
        EditorOutline $outline,
        StyleEngine $styles,
    ): JsonResponse {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'tab' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'leaving' => ['sometimes', 'boolean'],
            // Which version of this request format the caller speaks. Not
            // acted on yet: it is accepted now so that a later phase can
            // tell a tab still running old JavaScript to reload.
            'protocol' => ['sometimes', 'integer'],
        ]);

        $document = Document::query()
            ->select(['id', 'uuid', 'owner_id', 'team_id', 'is_public', 'version'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        // On every poll, not once at page load: somebody removed from the
        // document stops receiving it on their very next request.
        Gate::authorize('view', $document);

        $user = $request->user();

        if ($request->boolean('leaving')) {
            $presence->leave($document->id, $data['tab']);

            return response()->json(['left' => true]);
        }

        $presence->touch($document->id, $user, $data['tab']);

        $payload = [
            'version' => (int) $document->version,
            'changed' => false,
            'members' => $presence->members($document->id),
            'others' => $presence->others($document->id, $data['tab']),
        ];

        if ($payload['version'] > (int) $data['version']) {
            // Only now is the whole row worth reading.
            $full = Document::query()->whereKey($document->id)->firstOrFail();

            $payload['version'] = (int) $full->version;
            $payload['changed'] = true;
            $payload['json'] = $store->json($full);
            $payload['outline'] = $outline->of($full);
            // The style may be what changed. The outline carries its
            // numbering and page setup; this carries its fonts and colours.
            $payload['css'] = $styles->css($styles->resolve($full), 'canvas');
        }

        return response()->json($payload);
    }
}
