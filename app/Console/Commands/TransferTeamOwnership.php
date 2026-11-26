<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes another member a team's owner (EA-2). The owner of a team that others
 * use cannot delete their account until somebody else owns it (owner decision
 * 2026-11-26), and Jetstream has no screen for this, so support runs:
 *
 *   php artisan doc:transfer-team-ownership {team id} {new owner's email}
 *
 * The new owner must already be a member. The old owner stays in the team as
 * an admin. InfoDot's team sync sets the owner only when it CREATES a team,
 * so the change is not undone by the next sign-in.
 */
class TransferTeamOwnership extends Command
{
    protected $signature = 'doc:transfer-team-ownership {team : Team id} {email : Email of the member who becomes the owner}';

    protected $description = "Make another member a team's owner (the old owner stays as an admin)";

    public function handle(AuditLogger $audit): int
    {
        $team = Team::find($this->argument('team'));
        $newOwner = User::where('email', $this->argument('email'))->first();

        if (! $team instanceof Team || ! $newOwner instanceof User) {
            $this->error('No such team, or no account with that email.');

            return self::FAILURE;
        }

        if ((int) $team->user_id === $newOwner->id) {
            $this->info('That person already owns the team.');

            return self::SUCCESS;
        }

        if (! $team->users()->whereKey($newOwner->id)->exists()) {
            $this->error('That person is not a member of the team. Invite them first.');

            return self::FAILURE;
        }

        $oldOwnerId = (int) $team->user_id;

        DB::transaction(function () use ($team, $newOwner, $oldOwnerId, $audit) {
            $team->users()->detach($newOwner->id);
            $team->forceFill(['user_id' => $newOwner->id])->save();

            if ($oldOwnerId && ! $team->users()->whereKey($oldOwnerId)->exists()) {
                $team->users()->attach($oldOwnerId, ['role' => 'admin']);
            }

            $audit->record('team.owner_transferred', $team, [
                'from_user_id' => $oldOwnerId,
                'to_user_id' => $newOwner->id,
            ], $newOwner);
        });

        $this->info("{$newOwner->email} now owns \"{$team->name}\"; the previous owner stays as an admin.");

        return self::SUCCESS;
    }
}
