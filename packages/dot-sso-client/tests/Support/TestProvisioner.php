<?php

namespace Dot\SsoClient\Tests\Support;

use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Authenticatable;

class TestProvisioner implements UserProvisioner
{
    /** @var list<array{email: string, code: string, minutes: int}> */
    public static array $mails = [];

    public static bool $refuseCreate = false;

    public function findByUuid(string $uuid): ?Authenticatable
    {
        return TestUser::where('infodot_user_uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?Authenticatable
    {
        return TestUser::whereRaw('lower(email) = ?', [strtolower($email)])->first();
    }

    public function uuidOf(Authenticatable $user): ?string
    {
        return $user->infodot_user_uuid;
    }

    public function hasVerifiedEmail(Authenticatable $user): bool
    {
        return $user->email_verified_at !== null;
    }

    public function link(Authenticatable $user, string $uuid): void
    {
        $user->forceFill(['infodot_user_uuid' => $uuid])->save();
    }

    public function confirmEmail(Authenticatable $user): void
    {
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function create(array $claims): ?Authenticatable
    {
        if (self::$refuseCreate) {
            return null;
        }

        return TestUser::create([
            'name' => $claims['name'],
            'email' => $claims['email'],
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'email_verified_at' => now(),
            'infodot_user_uuid' => $claims['sub'],
        ]);
    }

    public function afterSignIn(Authenticatable $user, array $claims): void {}

    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void
    {
        self::$mails[] = ['email' => $user->email, 'code' => $code, 'minutes' => $minutes];
    }
}
