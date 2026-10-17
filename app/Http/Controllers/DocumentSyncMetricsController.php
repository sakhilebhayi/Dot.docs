<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\SyncMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Where the editor page flushes its co-editing counters.
 *
 * `{"counts": {"merge_live_clean": 2, "peers_1_secs": 41}}`, nothing else is
 * read: the kinds are a closed list (SyncMetrics::KINDS), each count a whole
 * number from 1 to SyncMetrics::MAX_COUNT, and a body over MAX_BODY_BYTES is
 * refused before it is parsed. A plain controller like DocumentSyncController
 * and for the same reason as DocumentAutosaveController: the page leaves by
 * `navigator.sendBeacon()`, which can only POST to a plain endpoint (the
 * CSRF token then rides in the body as `_token`, and is ignored here).
 *
 * Anybody who may VIEW the document may send: a read only person is present
 * too, and their downgrade is one of the things counted. The rows are keyed
 * by person, so nobody can write into somebody else's.
 *
 * The page treats every failure of this endpoint as nothing (it drops the
 * batch, one retry a minute later at most), and the answer is 204 either
 * way a flush is wanted: this must never be the reason an editor shows an
 * error.
 */
class DocumentSyncMetricsController extends Controller
{
    public function __invoke(Request $request, string $uuid, SyncMetrics $metrics): Response
    {
        // Switched off (config/docs.php): collect nothing, whoever still sends.
        if (! config('docs.sync_metrics')) {
            return response()->noContent();
        }

        if (strlen($request->getContent()) > SyncMetrics::MAX_BODY_BYTES) {
            abort(413);
        }

        $document = Document::query()->select(['id', 'uuid', 'owner_id', 'team_id', 'is_public'])->where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $document);

        $counts = $request->input('counts');
        $data = $request->validate([
            'counts' => ['required', 'array', 'min:1', 'max:'.count(SyncMetrics::KINDS)],
            'counts.*' => ['required', 'integer:strict', 'min:1', 'max:'.SyncMetrics::MAX_COUNT],
        ]);

        // The kind is the array KEY, which a rule on `counts.*` does not see.
        $unknown = is_array($counts) ? array_diff(array_map('strval', array_keys($counts)), SyncMetrics::KINDS) : [];
        if ($unknown !== []) {
            throw ValidationException::withMessages(['counts' => 'Unknown kind.']);
        }

        $metrics->record($document->id, (int) $request->user()->getAuthIdentifier(), $data['counts']);

        return response()->noContent();
    }
}
