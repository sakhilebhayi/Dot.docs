<?php

namespace App\Actions\Jetstream;

use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $this->forgetTreeNodes($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }

    /**
     * Take this account's documents OUT of the shared Dot.Files tree before
     * the database takes the documents themselves.
     *
     * `documents.owner_id` cascades on delete at the FK level, so deleting an
     * account hard-deletes every document it owned - including documents
     * filed in OTHER teams the person merely belonged to. That cascade never
     * passes through DocumentObserver or FilesService, so each of those
     * documents would leave its `objects` row behind in a tree this account
     * had nothing to do with: invisible in every listing (FilesService::
     * children() rejects a node whose objectable is gone) yet still counted
     * by deleteObject()'s "is anything filed in here" check, which would
     * make the containing folder permanently undeletable.
     *
     * KNOWN LIMITATION, deliberately not addressed here: the documents
     * themselves still go with the account, another team's copies included.
     * Reassigning them to a team admin instead is the right answer, and it
     * is a product decision about a core column (`documents.owner_id` is NOT
     * NULL and is the primary predicate in DocumentPolicy and
     * DocumentSearch), not a files-tree fix. Recorded in .ai/rules/files.md.
     *
     * A subquery, not a pluck: an account can own an unbounded number of
     * documents and none of them need to be loaded to forget their nodes.
     */
    protected function forgetTreeNodes(User $user): void
    {
        Obj::where('objectable_type', 'document')
            ->whereIn(
                'objectable_id',
                Document::withTrashed()->where('owner_id', $user->id)->select('id'),
            )
            ->delete();
    }
}
