<?php

namespace App\Actions\Ecosystem;

use App\Exceptions\ClaimRequired;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an InfoDot identity (already proven by the redeem endpoint) into a local user. This is the FALLBACK receiver's
 * matching (the /auth/ecosystem handoff); with DOTSSO_ENABLED the shared package does it, by the same rules.
 *
 * Order: the stored InfoDot user id; else the local account with this verified email (linked now); else, when a local
 * account with that email exists but is unverified, the CLAIM FLOW (ClaimRequired): nothing is replaced, nothing is
 * detached, the person proves the address with a code first. A person InfoDot vouches for who has no account here gets
 * one, with a random password and a personal team.
 *
 * R-54: the old behaviour took an unverified account over by replacing its password. That is gone: passwords, team
 * memberships, documents, two-factor settings and API tokens are never touched here. Documents and comments belong to
 * users.id, so a second account is never created for a person who already has one.
 */
class ProvisionUserFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity
     *
     * @throws ClaimRequired when an unverified or unlinked local account holds the address
     */
    public function __invoke(array $identity): ?User
    {
        $email = Str::lower(trim((string) ($identity['user']['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($identity['user']['email_verified'] ?? false) !== true) {
            return null;
        }

        $uuid = trim((string) ($identity['user']['uuid'] ?? '')) ?: null;
        $name = trim((string) ($identity['user']['name'] ?? '')) ?: Str::before($email, '@');

        if ($uuid !== null && ($linked = User::query()->where('infodot_user_uuid', $uuid)->first())) {
            return $linked;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            if ($user->infodot_user_uuid !== null && ($uuid === null || ! hash_equals($user->infodot_user_uuid, $uuid))) {
                Log::warning('InfoDot sign-in refused: the local account is linked to a different InfoDot identity.', ['user_id' => $user->id]);

                return null;
            }

            if ($user->email_verified_at !== null) {
                if ($uuid !== null && $user->infodot_user_uuid === null) {
                    $user->forceFill(['infodot_user_uuid' => $uuid])->save();
                }

                return $user;
            }

            if ($uuid === null) {
                // Without InfoDot's user id there is nothing a claim could link to; refuse rather than guess.
                Log::notice('InfoDot sign-in refused: unverified local account and no InfoDot user id in the answer.', ['user_id' => $user->id]);

                return null;
            }

            throw new ClaimRequired($user, ['sub' => $uuid, 'email' => $email, 'name' => Str::limit($name, 255, '')]);
        }

        if (! config('ecosystem.infodot.provision', true)) {
            return null;
        }

        return $this->create($email, $name, $uuid);
    }

    /** A new, verified account with a random password and its personal team. */
    public function create(string $email, string $name, ?string $uuid): User
    {
        return DB::transaction(function () use ($email, $name, $uuid) {
            $user = new User(['name' => Str::limit($name, 255, ''), 'email' => $email, 'password' => Hash::make(Str::random(48))]);
            $user->forceFill(['email_verified_at' => now(), 'infodot_user_uuid' => $uuid])->save();

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]);
            $user->ownedTeams()->save($team);
            $user->switchTeam($team);

            return $user;
        });
    }
}
