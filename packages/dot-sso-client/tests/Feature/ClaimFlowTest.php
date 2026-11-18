<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\TestProvisioner;
use Dot\SsoClient\Tests\Support\TestUser;
use Illuminate\Support\Facades\DB;

class ClaimFlowTest extends DotSsoTestCase
{
    private TestUser $local;

    protected function setUp(): void
    {
        parent::setUp();

        $this->local = $this->localUser();
    }

    /** Sign in as the person whose email matches the unverified local account, landing on the claim page. */
    private function startClaim(array $session = []): string
    {
        $this->finishLogin($this->startLogin([], $session))->assertRedirect(route('dotsso.claim'));

        return TestProvisioner::$mails[array_key_last(TestProvisioner::$mails)]['code'];
    }

    public function test_the_claim_page_names_the_address_masked_and_does_not_leak_the_code(): void
    {
        $code = $this->startClaim();

        $this->get('https://platform.test/sso/claim')->assertOk()
            ->assertSee('a**@example.test', false)
            ->assertDontSee($code);
    }

    public function test_the_code_is_numeric_six_digits_mailed_to_the_address_on_file_and_stored_only_as_a_hash(): void
    {
        $code = $this->startClaim();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame(10, TestProvisioner::$mails[0]['minutes']);
        $row = DB::table('dotsso_claims')->first();
        $this->assertStringNotContainsString($code, json_encode((array) $row));
        $this->assertSame(64, strlen($row->code_hash));
    }

    public function test_the_right_code_links_the_account_signs_in_and_keeps_the_password_and_data(): void
    {
        $hash = $this->local->password;
        $code = $this->startClaim(['url.intended' => '/tasks/9']);

        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertRedirect('/tasks/9');

        $user = $this->local->fresh();
        $this->assertSame('uuid-1111', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at, 'the code proved the address');
        $this->assertSame($hash, $user->password, 'the password is not replaced');
        $this->assertSame('Ada Local', $user->name, 'nothing else is rewritten');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, TestUser::count());
    }

    public function test_a_wrong_code_counts_down_and_never_signs_in(): void
    {
        $this->startClaim();

        $this->from('https://platform.test/sso/claim')->post('https://platform.test/sso/claim', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertStringContainsString('4 tries left', session('errors')->first('code'));
        $this->assertGuest();
        $this->assertNull($this->local->fresh()->infodot_user_uuid);
    }

    public function test_five_wrong_codes_cancel_the_code_and_the_right_one_no_longer_works(): void
    {
        $code = $this->startClaim();
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 4; $i++) {
            $this->post('https://platform.test/sso/claim', ['code' => $wrong])->assertSessionHasErrors('code');
        }

        $this->post('https://platform.test/sso/claim', ['code' => $wrong])->assertStatus(429)->assertSee('claim_locked');
        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertStatus(400)->assertSee('claim_missing');

        $this->assertGuest();
        $this->assertNull($this->local->fresh()->infodot_user_uuid);
    }

    public function test_the_code_expires_after_ten_minutes(): void
    {
        $code = $this->startClaim();

        $this->travel(11)->minutes();

        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertStatus(400)->assertSee('claim_expired');
        $this->assertGuest();
    }

    public function test_a_code_works_once(): void
    {
        $code = $this->startClaim();
        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertRedirect();
        auth()->logout();

        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertStatus(400);
    }

    public function test_the_code_format_is_validated(): void
    {
        $this->startClaim();

        foreach (['', 'abcdef', '12345', '1234567', '12 345'] as $bad) {
            $this->post('https://platform.test/sso/claim', ['code' => $bad])->assertSessionHasErrors('code');
        }
    }

    public function test_resend_replaces_the_old_code(): void
    {
        $first = $this->startClaim();
        $this->post('https://platform.test/sso/claim/resend')->assertSessionHas('dotsso_status');
        $second = TestProvisioner::$mails[1]['code'];

        $this->assertCount(2, TestProvisioner::$mails);
        $this->assertSame(1, DB::table('dotsso_claims')->count(), 'one live code per claim');

        if ($first !== $second) {
            $this->post('https://platform.test/sso/claim', ['code' => $first])->assertSessionHasErrors('code');
        }
        $this->post('https://platform.test/sso/claim', ['code' => $second])->assertRedirect('/dashboard');
    }

    public function test_sending_is_limited_per_email_address(): void
    {
        $this->startClaim();                                   // send 1
        $this->post('https://platform.test/sso/claim/resend');  // send 2
        $this->post('https://platform.test/sso/claim/resend');  // send 3
        $this->post('https://platform.test/sso/claim/resend')->assertSessionHasErrors('code'); // refused

        $this->assertCount(3, TestProvisioner::$mails);
    }

    public function test_a_fourth_sign_in_for_the_same_address_cannot_send_another_code(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->startClaim();
            $this->flushSession();
        }

        $this->finishLogin($this->startLogin())->assertStatus(429)->assertSee('rate_limited');
        $this->assertCount(3, TestProvisioner::$mails);
    }

    public function test_sending_is_limited_per_ip_across_different_addresses(): void
    {
        config(['dotsso.claim.send_per_ip' => 2]);

        foreach (['a@example.test', 'b@example.test'] as $email) {
            $this->localUser(['email' => $email]);
            $this->finishLogin($this->startLogin(), ['email' => $email, 'sub' => 'u-'.$email])->assertRedirect(route('dotsso.claim'));
            $this->flushSession();
        }

        $this->localUser(['email' => 'c@example.test']);
        $this->finishLogin($this->startLogin(), ['email' => 'c@example.test', 'sub' => 'u-c'])->assertStatus(429);
    }

    public function test_busy_sign_in_routes_do_not_use_up_the_claim_forms_allowance(): void
    {
        $this->startClaim();

        for ($i = 0; $i < 25; $i++) {
            $this->get('https://platform.test/sso/callback?state=x&code=y');
        }

        $this->post('https://platform.test/sso/claim', ['code' => '000001'])->assertSessionHasErrors('code');
    }

    public function test_guessing_is_limited_per_ip_too(): void
    {
        config(['dotsso.claim.verify_per_ip' => 3]);
        $this->startClaim();

        for ($i = 0; $i < 3; $i++) {
            $this->post('https://platform.test/sso/claim', ['code' => '000001']);
        }

        $this->post('https://platform.test/sso/claim', ['code' => '000001'])->assertStatus(429)->assertSee('rate_limited');
    }

    public function test_a_claim_cannot_be_completed_from_a_browser_that_did_not_start_it(): void
    {
        $code = $this->startClaim();
        $this->flushSession();

        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertStatus(400)->assertSee('claim_missing');
        $this->get('https://platform.test/sso/claim')->assertStatus(400);
        $this->assertGuest();
    }

    public function test_if_the_account_is_linked_to_someone_else_before_the_code_is_used_the_claim_fails(): void
    {
        $code = $this->startClaim();
        $this->local->forceFill(['infodot_user_uuid' => 'another-person'])->save();

        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertStatus(403)->assertSee('account_linked_elsewhere');
        $this->assertGuest();
        $this->assertSame('another-person', $this->local->fresh()->infodot_user_uuid);
    }

    public function test_cancel_forgets_the_claim_and_changes_nothing(): void
    {
        $this->startClaim();

        $this->post('https://platform.test/sso/claim/cancel')->assertRedirect('/');
        $this->get('https://platform.test/sso/claim')->assertStatus(400);
        $this->assertNull($this->local->fresh()->infodot_user_uuid);
    }

    public function test_a_failing_mailer_does_not_break_the_page(): void
    {
        $this->app->bind(TestProvisioner::class, fn () => new class extends TestProvisioner
        {
            public function sendClaimCode($user, string $code, int $minutes): void
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->finishLogin($this->startLogin())->assertRedirect(route('dotsso.claim'));
        $this->get('https://platform.test/sso/claim')->assertOk();
    }
}
