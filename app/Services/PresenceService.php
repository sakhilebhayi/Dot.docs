<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who has a document open right now.
 *
 * One row per open editor TAB in `document_presences`, refreshed by the
 * editor's sync poll (App\Http\Controllers\DocumentSyncController). It used
 * to be one cache entry per document holding an array of users, rewritten
 * read-modify-write: two requests arriving together lost each other's
 * update, two tabs of one account were indistinguishable, and somebody who
 * left lingered for five minutes. A row per tab has none of those problems,
 * and "is anyone ELSE here?" - which decides how fast a tab polls - is one
 * indexed count.
 *
 * Query builder, not a model: these rows are bookkeeping with a lifetime of
 * seconds, and nothing else in the app has a reason to load one.
 */
class PresenceService
{
    /**
     * A tab not heard from for this long is gone. Comfortably longer than
     * the slowest poll (10 seconds, when a tab is alone in the document).
     */
    public const TTL_SECONDS = 30;

    /**
     * The poll runs as often as every 1.5 seconds. Refreshing the row on
     * every one would be a database write per poll per tab for no benefit:
     * a row younger than this is left alone.
     */
    private const TOUCH_EVERY_SECONDS = 5;

    private const TABLE = 'document_presences';

    /** Record that this tab has the document open now. */
    public function touch(int $documentId, User $user, string $tabId): void
    {
        $now = now();

        $row = DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('tab_id', $tabId)
            ->first(['user_id', 'last_seen_at']);

        $fresh = $row !== null
            && (int) $row->user_id === $user->id
            && Carbon::parse($row->last_seen_at)->gt($now->copy()->subSeconds(self::TOUCH_EVERY_SECONDS));

        if ($fresh) {
            return;
        }

        // ONE statement. updateOrInsert() is two (does a row exist? then
        // insert), so two requests for the same new tab arriving together
        // would both see no row, and the second insert would hit the unique
        // index on (document_id, tab_id) and answer 500.
        DB::table(self::TABLE)->upsert(
            [['document_id' => $documentId, 'tab_id' => $tabId, 'user_id' => $user->id, 'last_seen_at' => $now]],
            ['document_id', 'tab_id'],
            ['user_id', 'last_seen_at'],
        );

        // Sweep this document's dead rows while we are writing anyway, so
        // the table never needs a scheduled prune to stay small.
        DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('last_seen_at', '<', $now->copy()->subSeconds(self::TTL_SECONDS))
            ->delete();
    }

    /** The tab is closing. */
    public function leave(int $documentId, string $tabId): void
    {
        DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('tab_id', $tabId)
            ->delete();
    }

    /**
     * The people here now, one entry each however many tabs they have open,
     * in order of arrival.
     *
     * @return list<array{id:int,name:string,avatar:string}>
     */
    public function members(int $documentId): array
    {
        $userIds = $this->live($documentId)->orderBy('id')->pluck('user_id')->unique()->values();

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        return $userIds
            ->map(fn ($id) => $users->get($id))
            ->filter()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->profile_photo_url,
            ])
            ->values()
            ->all();
    }

    /**
     * How many OTHER live tabs have this document open. Another tab of the
     * same account counts: it is somebody to stay in step with.
     */
    public function others(int $documentId, string $tabId): int
    {
        return $this->live($documentId)->where('tab_id', '!=', $tabId)->count();
    }

    private function live(int $documentId): Builder
    {
        return DB::table(self::TABLE)
            ->where('document_id', $documentId)
            ->where('last_seen_at', '>=', now()->subSeconds(self::TTL_SECONDS));
    }
}
