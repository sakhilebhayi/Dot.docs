<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Documents\Outline\SyncDerived;
use App\Http\Requests\DocumentSyncRequest;
use App\Models\Document;
use App\Services\PresenceService;
use Illuminate\Http\JsonResponse;
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
        DocumentSyncRequest $request,
        string $uuid,
        DocumentStore $store,
        PresenceService $presence,
        SyncDerived $derived,
    ): JsonResponse {
        $data = $request->validated();

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
            // Whether this person may still change the document, asked of the
            // same gate as Editor::canEdit() (the update policy, never
            // cached). It rides on every poll so that a downgrade is noticed
            // by an open page on its next poll, not only on its next save or
            // render. Old JavaScript ignores the field.
            'can_edit' => $user->can('update', $document),
        ];

        if ($payload['version'] > (int) $data['version']) {
            // Only now is the whole row worth reading.
            $full = Document::query()->whereKey($document->id)->firstOrFail();

            $payload['version'] = (int) $full->version;
            $payload['changed'] = true;
            $payload['json'] = $store->json($full);
            // The outline and the canvas CSS (the style may be what changed),
            // built once per state of the document and shared by every
            // co-editor's poll for it: see SyncDerived.
            $shared = $derived->of($full);
            $payload['outline'] = $shared['outline'];
            $payload['css'] = $shared['css'];
        }

        return response()->json($payload);
    }
}
