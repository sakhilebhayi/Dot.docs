<?php

namespace Dot\SsoClient\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Anything that can finish a verified sign-in (the controller does). Lets the claim flow stay free of the controller. */
interface SsoCompletion
{
    /** @param array{sub: string, email: string, name: string} $claims */
    public function finish(Request $request, Authenticatable $user, array $claims, ?string $deepLink, string $idToken, string $how): RedirectResponse;
}
