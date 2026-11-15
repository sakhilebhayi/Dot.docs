<?php

namespace App\Search;

use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Documents of ONE company, the one the person is standing in.
 *
 * The scoping rule is the arrival page's (App\Http\Controllers\LandingController): a document belongs to its team, and a
 * document with no team is only ever shown to its owner, and only while they stand in no company at all. Nothing from
 * another company is read, however public or shared the document is there; DocumentPolicy::view() is then checked row
 * by row on what is left, so the rule can only narrow.
 *
 * Used by the header search. The documents page keeps its own search (App\Search\DocumentSearch) untouched.
 */
class CompanyDocuments
{
    /** Shorter than this is noise, not a search. */
    public const MIN_LENGTH = 2;

    /**
     * Narrow a document query to a company (or, with none, to the owner's un-filed documents).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, ?int $teamId, int $userId): Builder
    {
        return $teamId === null
            ? $query->whereNull('team_id')->where('owner_id', $userId)
            : $query->where('team_id', $teamId);
    }

    /** @return Collection<int, Document> */
    public function search(User $user, string $query, int $limit = 8): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return collect();
        }

        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($query)).'%';

        return self::scope(Document::query(), $team?->id, $user->id)
            ->select(['id', 'uuid', 'title', 'owner_id', 'team_id', 'updated_at', 'is_public'])
            ->where(function (Builder $q) use ($like) {
                $q->whereRaw('LOWER(title) LIKE ? ESCAPE \'\\\'', [$like])
                    ->orWhereRaw('LOWER(search_text) LIKE ? ESCAPE \'\\\'', [$like]);
            })
            ->latest('updated_at')
            ->limit($limit * 3)
            ->get()
            ->filter(fn (Document $document) => $user->can('view', $document))
            ->take($limit)
            ->values();
    }
}
