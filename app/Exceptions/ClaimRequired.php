<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/** An InfoDot sign-in matched a local account that is unverified or not yet linked: the person must prove the address first. */
class ClaimRequired extends RuntimeException
{
    /** @param array{sub: string, email: string, name: string} $claims */
    public function __construct(public readonly User $user, public readonly array $claims)
    {
        parent::__construct('A local account holds this address; the claim flow is required.');
    }
}
