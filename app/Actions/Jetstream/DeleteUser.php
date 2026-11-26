<?php

namespace App\Actions\Jetstream;

use App\Audit\AuditLogger;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

/**
 * Deleting an account (EA-2; enterprise audit S-03; owner decisions 2026-11-26).
 *
 * 1. A person who owns a team that still has other members cannot delete
 *    their account: team ownership has to move to another member first, so a
 *    company and everyone's access never disappear with one person.
 * 2. Documents they own in a team that stays (one they belonged to but did
 *    not own) pass to that team's owner, in place, each handover audited.
 * 3. Every other document they own - no team, or a team that goes with them
 *    because nobody else is in it - is soft-deleted, taken out of the files
 *    tree, and purged 30 days later by doc:purge-deleted-accounts.
 * 4. Their comments and the versions they wrote on anyone's documents stay;
 *    the foreign keys set the author to null and the pages show "Former
 *    member" (migration 2026_11_26_000001).
 *
 * Before EA-2 the database cascade hard-deleted all of it, other companies'
 * copies included, with no audit row.
 */
class DeleteUser implements DeletesUsers
{
    /** How long a deleted account's own documents stay recoverable. */
    public const RECOVERABLE_DAYS = 30;

    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        $this->refuseWhileOwningASharedTeam($user);

        DB::transaction(function () use ($user) {
            $leavingTeamIds = $user->ownedTeams()->pluck('id');

            $this->handOverCompanyDocuments($user, $leavingTeamIds->all());
            $this->setAsidePersonalDocuments($user);

            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Owner decision 3: block, do not choose a new owner for them.
     *
     * Thrown under `password`, the only field Jetstream's delete form shows
     * an error for.
     */
    protected function refuseWhileOwningASharedTeam(User $user): void
    {
        $shared = $user->ownedTeams()->whereHas('users')->pluck('name');

        if ($shared->isNotEmpty()) {
            throw ValidationException::withMessages([
                'password' => __('You own :teams, which other people still use. Make another member its owner before you delete your account.', [
                    'teams' => $shared->map(fn (string $name) => '"'.$name.'"')->join(', ', ' and '),
                ]),
            ]);
        }
    }

    /**
     * Owner decision 1: a document in a team that stays passes to that team's
     * owner and stays exactly where it is - same team, same folder, same
     * collaborators. One audit row per document, written by the leaver while
     * the account still exists.
     *
     * @param  list<int>  $leavingTeamIds  teams the leaver owns (they go with the account)
     */
    protected function handOverCompanyDocuments(User $user, array $leavingTeamIds): void
    {
        $audit = app(AuditLogger::class);

        // withTrashed: a company document already in the bin is still the
        // company's, and must not fall to the 30-day purge of personal ones.
        Document::withTrashed()
            ->where('owner_id', $user->id)
            ->whereNotNull('team_id')
            ->whereNotIn('team_id', $leavingTeamIds)
            ->with('team')
            ->chunkById(200, function ($documents) use ($user, $audit) {
                foreach ($documents as $document) {
                    $team = $document->team;

                    if (! $team instanceof Team || (int) $team->user_id === $user->id) {
                        continue;
                    }

                    Document::withTrashed()->whereKey($document->id)->update(['owner_id' => $team->user_id]);

                    $audit->record('document.owner_transferred', $document, [
                        'reason' => 'account_deleted',
                        'from_user_id' => $user->id,
                        'to_user_id' => (int) $team->user_id,
                    ], $user);
                }
            });
    }

    /**
     * Owner decision 2: everything else the leaver owns is soft-deleted now
     * and purged after RECOVERABLE_DAYS. Its tree row goes first: a node
     * whose document is trashed is invisible in every listing yet still
     * counts as "something is filed in here" (see .ai/rules/files.md).
     */
    protected function setAsidePersonalDocuments(User $user): void
    {
        Obj::where('objectable_type', 'document')
            ->whereIn('objectable_id', Document::withTrashed()->where('owner_id', $user->id)->select('id'))
            ->delete();

        Document::query()
            ->where('owner_id', $user->id)
            ->chunkById(200, function ($documents) {
                foreach ($documents as $document) {
                    $document->delete();
                }
            });
    }

    /**
     * Delete the teams and team associations attached to the user. Only
     * teams nobody else is in reach this point (refuseWhileOwningASharedTeam).
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
