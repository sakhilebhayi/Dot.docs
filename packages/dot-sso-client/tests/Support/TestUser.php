<?php

namespace Dot\SsoClient\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A stand-in for a platform's users table: it exists only inside the package's own tests. */
class TestUser extends Authenticatable
{
    protected $table = 'dotsso_test_users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime'];
}
