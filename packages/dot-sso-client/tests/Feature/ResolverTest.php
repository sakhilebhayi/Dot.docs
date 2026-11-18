<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\TestProvisioner;
use Dot\SsoClient\Tests\Support\TestUser;

/** Matching: stored InfoDot id first, verified email on both sides second, the claim flow third, a new account last. */
class ResolverTest extends DotSsoTestCase
{
    public function test_the_stored_infodot_id_wins_even_when_the_email_has_changed(): void
    {
        $user = $this->localUser(['email' => 'old@example.test', 'infodot_user_uuid' => 'uuid-1111', 'email_verified_at' => now()]);

        $this->finishLogin($this->startLogin(), ['email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, TestUser::count(), 'no second account is created');
    }

    public function test_a_verified_local_account_with_the_same_verified_email_is_linked(): void
    {
        $user = $this->localUser(['email_verified_at' => now()]);
        $hash = $user->password;

        $this->finishLogin($this->startLogin())->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('uuid-1111', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password, 'the password is never replaced');
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_matching_ignores_case(): void
    {
        $user = $this->localUser(['email' => 'Ada@Example.TEST', 'email_verified_at' => now()]);

        $this->finishLogin($this->startLogin())->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_account_goes_to_the_claim_flow_and_is_left_exactly_as_it_was(): void
    {
        $user = $this->localUser();
        $before = $user->only(['password', 'email_verified_at', 'infodot_user_uuid', 'name']);

        $this->finishLogin($this->startLogin())->assertRedirect(route('dotsso.claim'));

        $this->assertGuest();
        $this->assertSame($before, $user->fresh()->only(['password', 'email_verified_at', 'infodot_user_uuid', 'name']));
        $this->assertCount(1, TestProvisioner::$mails);
        $this->assertSame('ada@example.test', TestProvisioner::$mails[0]['email']);
    }

    public function test_the_takeover_attempt_is_blocked(): void
    {
        // The victim registered locally and never verified. An attacker with an InfoDot account claiming that address
        // (InfoDot says unverified) must get nothing: no sign-in, no link, no password change, no new account.
        $victim = $this->localUser();
        $this->idp->claimOverrides = ['email_verified' => false, 'sub' => 'attacker-uuid'];

        $this->finishLogin($this->startLogin())->assertStatus(403)->assertSee('email_unverified');

        $this->assertGuest();
        $this->assertNull($victim->fresh()->infodot_user_uuid);
        $this->assertSame([], TestProvisioner::$mails);
        $this->assertSame(1, TestUser::count());
    }

    public function test_a_different_infodot_identity_cannot_take_over_an_account_that_is_already_linked(): void
    {
        $this->localUser(['email_verified_at' => now(), 'infodot_user_uuid' => 'someone-else']);

        $this->finishLogin($this->startLogin())->assertStatus(403)->assertSee('account_linked_elsewhere');

        $this->assertGuest();
    }

    public function test_when_linking_by_verified_email_is_switched_off_everything_goes_through_the_claim_flow(): void
    {
        config(['dotsso.link_verified_email' => false]);
        $this->localUser(['email_verified_at' => now()]);

        $this->finishLogin($this->startLogin())->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();
    }

    public function test_a_new_person_is_created_only_when_the_platform_allows_it(): void
    {
        config(['dotsso.auto_provision' => false]);

        $this->finishLogin($this->startLogin())->assertStatus(403)->assertSee('not_provisioned');

        $this->assertSame(0, TestUser::count());
        $this->assertGuest();
    }

    public function test_a_platform_can_refuse_to_create_an_account(): void
    {
        TestProvisioner::$refuseCreate = true;

        $this->finishLogin($this->startLogin())->assertStatus(403)->assertSee('provision_refused');
        $this->assertSame(0, TestUser::count());
    }

    public function test_a_second_sign_in_finds_the_same_account_by_its_id(): void
    {
        $this->finishLogin($this->startLogin());
        auth()->logout();
        $this->flushSession();

        $this->finishLogin($this->startLogin());

        $this->assertSame(1, TestUser::count());
    }
}
