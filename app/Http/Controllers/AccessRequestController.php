<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\AccessRequest;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Access\AccessRequested;
use App\Notifications\Access\AccessRequestedBell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * "Ask for access" on the page a person gets when a link opens a document they cannot read (R2-3b / U-06).
 *
 * The page and this answer say nothing about the document - not its title, not whose it is - so a link cannot be
 * used to learn what is behind it. The owner hears about it (bell and email) and decides on the Sharing page. One
 * open request per person per document; a person may send at most PER_HOUR requests an hour, whatever documents.
 */
class AccessRequestController extends Controller
{
    public const PER_HOUR = 5;

    /** After a decline, the same person may not ask for the same document again for this long. */
    public const COOLDOWN_DAYS = 7;

    public function store(Request $request, string $uuid): RedirectResponse
    {
        abort_unless(Str::isUuid($uuid), 404);
        $document = Document::where('uuid', $uuid)->first();
        abort_if($document === null, 404);

        /** @var User $user */
        $user = $request->user();

        // Already able to read it (granted since, or joined the company): just open it.
        if ($user->can('view', $document)) {
            return redirect()->route('documents.open', $uuid);
        }

        $data = $request->validate(['message' => ['nullable', 'string', 'max:500']]);

        // One open request per person per document, and a declined one is not re-asked for a week: the check and
        // the create sit under one lock, so a double submit cannot make two rows (or two emails).
        $lock = Cache::lock('access-request:'.$user->id.':'.$document->id, 10);
        if (! $lock->get()) {
            return redirect()->route('documents.open', $uuid)->with('access_request', 'already');
        }

        try {
            $open = AccessRequest::where('document_id', $document->id)->where('requester_id', $user->id)
                ->where('status', AccessRequest::PENDING)->exists();
            if ($open) {
                return redirect()->route('documents.open', $uuid)->with('access_request', 'already');
            }

            $declined = AccessRequest::where('document_id', $document->id)->where('requester_id', $user->id)
                ->where('status', AccessRequest::DECLINED)->where('decided_at', '>', now()->subDays(self::COOLDOWN_DAYS))
                ->latest('decided_at')->first();
            if ($declined !== null) {
                return redirect()->route('documents.open', $uuid)->withInput()
                    ->withErrors(['message' => 'The owner has already decided on your request. You can ask again from '.($declined->decided_at ?? now())->copy()->addDays(self::COOLDOWN_DAYS)->format('j M Y').'.']);
            }

            $key = 'access-request:'.$user->id;
            if (! RateLimiter::attempt($key, self::PER_HOUR, fn () => true, 3600)) {
                return redirect()->route('documents.open', $uuid)->withInput()
                    ->withErrors(['message' => 'You have asked for access several times this hour. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
            }

            $accessRequest = AccessRequest::create([
                'document_id' => $document->id,
                'requester_id' => $user->id,
                'message' => filled($data['message'] ?? null) ? trim((string) $data['message']) : null,
                'status' => AccessRequest::PENDING,
            ]);
        } finally {
            $lock->release();
        }

        app(AuditLogger::class)->record('document.access_requested', $document, ['request_id' => $accessRequest->id], $user);

        foreach ($this->deciders($document) as $decider) {
            $decider->notify(new AccessRequestedBell($accessRequest));
            $decider->notify(new AccessRequested($accessRequest));
        }

        return redirect()->route('documents.open', $uuid)->with('access_request', 'sent');
    }

    /**
     * Who hears about it: the owner; with no owner (their account was deleted), the owner of the document's company.
     *
     * @return list<User>
     */
    private function deciders(Document $document): array
    {
        $owner = $document->owner;
        if ($owner instanceof User) {
            return [$owner];
        }

        $team = $document->team;
        $teamOwner = $team instanceof Team ? User::find($team->user_id) : null;

        return $teamOwner instanceof User ? [$teamOwner] : [];
    }
}
