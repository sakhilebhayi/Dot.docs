<?php

namespace Dot\SsoClient\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a platform must answer for the package. The package decides the order (stored InfoDot user id first, then
 * verified email on both sides, then the claim flow, then auto-provision); the platform only reads and writes its
 * own users table. Nothing here may replace a password or detach data.
 */
interface UserProvisioner
{
    /** The local account already linked to this InfoDot user id (OIDC sub), or null. */
    public function findByUuid(string $uuid): ?Authenticatable;

    /** The local account with this email address (case-insensitive), or null. */
    public function findByEmail(string $email): ?Authenticatable;

    /** The InfoDot user id this local account is linked to, or null when it is not linked. */
    public function uuidOf(Authenticatable $user): ?string;

    /** Whether the local account's email address is verified on the platform. */
    public function hasVerifiedEmail(Authenticatable $user): bool;

    /** Store the link. Must not touch the password, memberships, tokens or two-factor settings. */
    public function link(Authenticatable $user, string $uuid): void;

    /** Mark the local email verified (called only after the emailed code proved the address). */
    public function confirmEmail(Authenticatable $user): void;

    /**
     * Create a local account for a person InfoDot has verified. Called only when `dotsso.auto_provision` is true.
     * Store the InfoDot user id. Return null to refuse.
     *
     * @param  array{sub: string, email: string, name: string}  $claims
     */
    public function create(array $claims): ?Authenticatable;

    /**
     * Runs after every successful sign-in (for example to refresh a display name). Must not throw for ordinary
     * conditions.
     *
     * @param  array<string, mixed>  $claims
     */
    public function afterSignIn(Authenticatable $user, array $claims): void;

    /** Send the one-time numeric code to the email address on file, through the platform's own mailer. */
    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void;
}
