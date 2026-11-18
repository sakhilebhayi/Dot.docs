<?php

namespace Dot\SsoClient\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who is this InfoDot person here? In this order, and never any other:
 *  1. the local account already linked to their InfoDot user id;
 *  2. a local account with their email, linked ONLY when InfoDot says the email is verified AND the local account is
 *     email-verified (and not already linked to someone else);
 *  3. that same account when it is unverified or unlinked: the claim flow (a code to the address on file). Its password,
 *     memberships and two-factor settings are not touched;
 *  4. a new account, only when the platform's auto_provision policy allows it.
 */
class Resolver
{
    /**
     * @param  array{sub: string, email: string, name: string}  $claims
     * @return array{type: 'signed_in', user: Authenticatable, how: string}|array{type: 'claim', user: Authenticatable}|array{type: 'error', code: string}
     */
    public static function resolve(array $claims): array
    {
        $provisioner = Sso::provisioner();

        if ($user = $provisioner->findByUuid($claims['sub'])) {
            return ['type' => 'signed_in', 'user' => $user, 'how' => 'uuid'];
        }

        if ($local = $provisioner->findByEmail($claims['email'])) {
            $linked = $provisioner->uuidOf($local);

            if ($linked !== null && $linked !== '' && ! hash_equals($linked, $claims['sub'])) {
                return ['type' => 'error', 'code' => 'account_linked_elsewhere'];
            }

            if (config('dotsso.link_verified_email') && $provisioner->hasVerifiedEmail($local)) {
                $provisioner->link($local, $claims['sub']);

                return ['type' => 'signed_in', 'user' => $local, 'how' => 'verified_email'];
            }

            return ['type' => 'claim', 'user' => $local];
        }

        if (! config('dotsso.auto_provision')) {
            return ['type' => 'error', 'code' => 'not_provisioned'];
        }

        $created = $provisioner->create($claims);

        return $created
            ? ['type' => 'signed_in', 'user' => $created, 'how' => 'provisioned']
            : ['type' => 'error', 'code' => 'provision_refused'];
    }
}
