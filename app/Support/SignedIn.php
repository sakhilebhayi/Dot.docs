<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in person, for code that only ever runs behind the `auth` middleware (R2-10): every app Livewire component
 * and the signed-in controllers. Typed as a User, not User|null, so a missing session is one clear 401 here rather than
 * a null dereference further on - which is what `auth()->user()` everywhere used to leave possible (PHPStan level 8).
 */
trait SignedIn
{
    protected function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
