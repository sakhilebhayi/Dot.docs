<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\SyncMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Where the editor page flushes its co-editing counters: about 5 s after it
 * opened (the session it counts under `editor_open`), then once a minute, and
 * when it is left.
 *
 * `{"counts": {"editor_open": 1, "merge_live_clean": 2, "peers_1_secs": 41}}`, nothing else is
 * read: the kinds are a closed list (SyncMetrics::kinds(); unknown ones are
 * dropped, the known ones in the same body kept), each count a whole
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

        // The page declares its beacon as JSON, which is what the CSRF check and Request::input() read. A body that
        // arrives as text/plain anyway (a browser or a proxy that rewrites the type) is the same JSON: read it, so a
        // session is not lost to a content type. Whatever is not a JSON object stays a bad request below.
        if (! $request->isJson()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded) && ! array_is_list($decoded)) {
                $request->merge($decoded);
            }
        }

        $document = Document::query()->select(['id', 'uuid', 'owner_id', 'team_id', 'is_public'])->where('uuid', $uuid)->firstOrFail();
        Gate::authorize('view', $document);

        // The shape first: an object of kind => count. A list, a string or nothing is not what the page sends.
        $counts = $request->input('counts');
        if (! is_array($counts) || $counts === [] || array_is_list($counts)) {
            throw ValidationException::withMessages(['counts' => 'Counts must be an object of kind and number.']);
        }

        // An unknown kind is DROPPED, whatever its value: a page a deploy ahead may send a kind this server does not
        // know yet, and that must not cost the known ones sent with it. Nothing outside the closed list is stored.
        $known = array_intersect_key($counts, array_flip(SyncMetrics::kinds()));
        if ($known === []) {
            return response()->noContent();
        }

        // A known kind with a bad count is a bad request, as before.
        $data = Validator::make(['counts' => $known], [
            'counts.*' => ['required', 'integer:strict', 'min:1', 'max:'.SyncMetrics::MAX_COUNT],
        ])->validate();

        $metrics->record($document->id, (int) $request->user()->getAuthIdentifier(), $data['counts']);

        return response()->noContent();
    }
}
