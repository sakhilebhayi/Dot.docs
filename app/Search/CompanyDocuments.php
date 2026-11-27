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
 * The company predicate for search (App\Search\DocumentSearch, both searches since R2-7), the arrival page, the Bin
 * and the API.
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
        return $teamId === null
            ? $query->whereNull('team_id')->where('owner_id', $userId)
            : $query->where('team_id', $teamId);
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
