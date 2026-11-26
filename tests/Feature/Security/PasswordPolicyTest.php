<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/** EA-1 / S-08: new passwords are at least 12 characters (breach check in production). */
class PasswordPolicyTest extends TestCase
{
    public function test_a_password_shorter_than_twelve_characters_is_refused(): void
    {
        $this->assertTrue(Validator::make(['p' => 'elevenchars'], ['p' => Password::defaults()])->fails());
        $this->assertFalse(Validator::make(['p' => 'twelve-chars'], ['p' => Password::defaults()])->fails());
    }

    public function test_pdf_rendering_never_fetches_remote_resources(): void
    {
        // An image src in a document must not make the server fetch an internal address (SSRF).
        $this->assertFalse((bool) config('dompdf.options.enable_remote'));
    }
}
