<?php

namespace App\Search;

use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Documents of ONE company, the one the person is standing in.
 *
 * The scoping rule is the arrival page's (App\Http\Controllers\LandingController): a document belongs to its team, and a
 * document with no team is only ever shown to its owner, and only while they stand in no company at all. Nothing from
 * another company is read, however public or shared the document is there; DocumentPolicy::view() is then checked row
 * by row on what is left, so the rule can only narrow.
 *
 * The company predicate for search (App\Search\DocumentSearch, both searches since R2-7), the documents list and its
 * tag filter (through Document::scopeVisibleTo(), R2-12), the arrival page, the Bin and the API.
 *
 * Company FIRST is also what keeps these queries fast: `team_id` (and `owner_id` for the no-company case) leads an
 * index on `documents`, so SQLite reads one company's rows instead of every document on the platform (R2-11, the
 * documents list scanned 60k rows for one page). tests/Feature/Documents/DocumentVisibilityQueryPlanTest holds it.
 */
class CompanyDocuments
{
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
        // Qualified, so the predicate stays the documents' own when the query joins another table (search's ranking).
        return $teamId === null
            ? $query->whereNull($query->qualifyColumn('team_id'))->where($query->qualifyColumn('owner_id'), $userId)
            : $query->where($query->qualifyColumn('team_id'), $teamId);
    }

    /**
     * The company a person is standing in, for the visibility rule: their current team, but only while they still
     * belong to it. A stale `current_team_id` (a team they have just been removed from) counts as no company, so the
     * rule falls back to their own un-filed documents rather than listing a team they can no longer open.
     */
    public static function companyOf(User $user): ?Team
    {
        $team = $user->currentTeam;

        return $team instanceof Team && $user->belongsToTeam($team) ? $team : null;
    }

    /**
     * THE visibility rule for listing and finding documents: the person's company (companyOf()), nothing else.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        return self::scope($query, self::companyOf($user)?->id, $user->id);
    }

    /**
     * One document by uuid, only if it belongs to the company the person is standing in; 404 otherwise. A uuid from
     * another company is never confirmed to exist.
     */
    public static function find(User $user, string $uuid): Document
    {
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;

        return self::scope(Document::query(), $team?->id, $user->id)->where('uuid', $uuid)->firstOrFail();
    }
}
