<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Jetstream;

/**
 * Applies a membership change InfoDot has just made, so access ends in seconds rather than at
 * the person's next sign-in (which still catches anything this misses).
 *
 * - member.removed: detach the person from the linked company and, if it was their current
 *   team, clear it so no scoped query keeps using it. A team's local owner is never detached.
 * - member.role_changed: set the person's role here (same mapping as sign-in).
 * - team.deleted: unlink the company and detach everyone but its local owner. The files and
 *   documents themselves stay, with the owner; deleting data is never a side effect of this.
 *
 * Unknown teams and people are ignored without a word: the caller learns nothing about who
 * exists here. Every event is idempotent.
 */
class ApplyMembershipChange
{
    /**
     * @param  array{event: string, team_uuid: string, email?: string|null, role?: string|null}  $change
     */
    public function __invoke(array $change): void
    {
        $team = Team::where('infodot_team_uuid', $change['team_uuid'])->first();

        if (! $team) {
            return;
        }

        DB::transaction(function () use ($team, $change) {
            match ($change['event']) {
                'member.removed' => $this->remove($team, $this->person($change)),
                'member.role_changed' => $this->changeRole($team, $this->person($change), (string) ($change['role'] ?? '')),
                'team.deleted' => $this->unlink($team),
                default => null,
            };
        });
    }

    /** @param  array<string, mixed>  $change */
    private function person(array $change): ?User
    {
        return filled($change['email'] ?? null) ? User::whereRaw('lower(email) = ?', [strtolower((string) $change['email'])])->first() : null;
    }

    private function remove(Team $team, ?User $user): void
    {
        if (! $user) {
            return;
        }

        if ($team->user_id === $user->id) {
            Log::warning('InfoDot removed the local owner of a company; left in place.', ['team_id' => $team->id, 'user_id' => $user->id]);

            return;
        }

        $team->users()->detach($user->id);

        if ($user->current_team_id === $team->id) {
            $user->forceFill(['current_team_id' => null])->save();
        }
    }

    private function changeRole(Team $team, ?User $user, string $infodotRole): void
    {
        if (! $user || $team->user_id === $user->id || ! $team->users()->where('users.id', $user->id)->exists()) {
            return;
        }

        $team->users()->updateExistingPivot($user->id, ['role' => $this->localRole($infodotRole)]);
    }

    private function unlink(Team $team): void
    {
        foreach ($team->users()->get() as $member) {
            if ($member instanceof User) {
                $this->remove($team, $member);
            }
        }

        $team->forceFill(['infodot_team_uuid' => null])->save();
    }

    private function localRole(string $infodotRole): string
    {
        $wanted = in_array($infodotRole, ['owner', 'admin'], true) ? 'admin' : ($infodotRole === 'editor' ? 'editor' : 'viewer');

        return Jetstream::findRole($wanted) ? $wanted : 'editor';
    }
}
