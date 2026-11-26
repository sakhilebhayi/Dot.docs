<?php

namespace App\Actions\Jetstream;

use App\Mail\QueuedTeamInvitation;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Events\InvitingTeamMember;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Rules\Role;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

class InviteTeamMember implements InvitesTeamMembers
{
    /**
     * Invitations one person may send in an hour. An invitation is mail sent
     * from this app's domain to any address the inviter types, so it is
     * bounded like every other mail a user can trigger (EA-1 / E-01).
     */
    public const PER_HOUR = 20;

    /**
     * Invite a new team member to the given team.
     */
    public function invite(User $user, Team $team, string $email, ?string $role = null): void
    {
        Gate::forUser($user)->authorize('addTeamMember', $team);

        $this->validate($team, $email, $role);

        if (! RateLimiter::attempt('team-invite:'.$user->id, self::PER_HOUR, fn () => true, 3600)) {
            throw ValidationException::withMessages([
                'email' => __('You have sent a lot of invitations in the last hour. Try again later.'),
            ])->errorBag('addTeamMember');
        }

        InvitingTeamMember::dispatch($team, $email, $role);

        $invitation = $team->teamInvitations()->create([
            'email' => $email,
            'role' => $role,
        ]);

        // Queued on the mail queue (EA-3 / E-05): the invitation row exists
        // either way, and a slow SMTP server no longer stalls the request.
        if ($invitation instanceof JetstreamTeamInvitation) {
            Mail::to($email)->queue((new QueuedTeamInvitation($invitation))->onQueue('mail'));
        }
    }

    /**
     * Validate the invite member operation.
     */
    protected function validate(Team $team, string $email, ?string $role): void
    {
        Validator::make([
            'email' => $email,
            'role' => $role,
        ], $this->rules($team), [
            'email.unique' => __('This user has already been invited to the team.'),
        ])->after(
            $this->ensureUserIsNotAlreadyOnTeam($team, $email)
        )->validateWithBag('addTeamMember');
    }

    /**
     * Get the validation rules for inviting a team member.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    protected function rules(Team $team): array
    {
        return array_filter([
            'email' => [
                'required', 'email',
                Rule::unique(Jetstream::teamInvitationModel())->where(function (Builder $query) use ($team) {
                    $query->where('team_id', $team->id);
                }),
            ],
            'role' => Jetstream::hasRoles()
                            ? ['required', 'string', new Role]
                            : null,
        ]);
    }

    /**
     * Ensure that the user is not already on the team.
     */
    protected function ensureUserIsNotAlreadyOnTeam(Team $team, string $email): Closure
    {
        return function ($validator) use ($team, $email) {
            $validator->errors()->addIf(
                $team->hasUserWithEmail($email),
                'email',
                __('This user already belongs to the team.')
            );
        };
    }
}
