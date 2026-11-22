<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Support\ClaimService;
use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Dot\SsoClient\Tests\Support\TestProvisioner;
use Dot\SsoClient\Tests\Support\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

class CallbackTest extends DotSsoTestCase
{
    public function test_a_clean_round_trip_signs_the_person_in_and_provisions_the_account_with_the_infodot_id(): void
    {
        $authorize = $this->startLogin();
        $response = $this->finishLogin($authorize);

        $response->assertRedirect('/dashboard');
        $user = TestUser::where('email', 'ada@example.test')->firstOrFail();
        $this->assertSame('uuid-1111', $user->infodot_user_uuid);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_session_id_is_regenerated_at_sign_in(): void
    {
        $authorize = $this->startLogin();
        $before = session()->getId();

        $this->finishLogin($authorize);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_token_exchange_carries_the_verifier_secret_and_exact_redirect_server_to_server(): void
    {
        $authorize = $this->startLogin();
        $this->finishLogin($authorize);

        $sent = $this->idp->tokenRequests[0];
        $this->assertSame(FakeIdp::SECRET, $sent['client_secret']);
        $this->assertSame(FakeIdp::REDIRECT, $sent['redirect_uri']);
        $this->assertSame(FakeIdp::b64(hash('sha256', $sent['code_verifier'], true)), $authorize['code_challenge']);
    }

    public function test_no_token_code_or_secret_reaches_the_browser_or_the_log(): void
    {
        Log::spy();
        $authorize = $this->startLogin();
        $response = $this->finishLogin($authorize);

        $everything = $response->headers->get('Location').$response->getContent().json_encode($response->headers->all());
        foreach (['at-secret', 'rt-secret', FakeIdp::SECRET, 'id_token', 'code_verifier'] as $needle) {
            $this->assertStringNotContainsString($needle, $everything);
        }

        Log::shouldHaveReceived('log')->atLeast()->once();
        Log::shouldNotHaveReceived('log', fn ($level, $message, $context = []) => str_contains(json_encode($context), 'at-secret') || str_contains(json_encode($context), FakeIdp::SECRET));
    }

    public function test_a_second_visit_to_the_callback_with_the_same_state_is_refused(): void
    {
        $authorize = $this->startLogin();
        $code = $this->idp->approve($authorize);
        $url = 'https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]);

        $this->get($url)->assertRedirect('/dashboard');
        auth()->logout();

        $this->get($url)->assertStatus(400)->assertSee('state_unknown');
        $this->assertGuest();
    }

    public function test_a_state_the_server_never_issued_is_refused(): void
    {
        $authorize = $this->startLogin();
        $code = $this->idp->approve($authorize);

        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => 'x'.$authorize['state']]))
            ->assertStatus(400)->assertSee('state_unknown');
        $this->assertGuest();
    }

    public function test_a_callback_with_no_state_or_no_code_is_refused(): void
    {
        $authorize = $this->startLogin();
        $this->get('https://platform.test/sso/callback?code=abc')->assertStatus(400)->assertSee('state_missing');
        $this->get('https://platform.test/sso/callback?state='.$authorize['state'])->assertStatus(400)->assertSee('state_missing');
        $this->assertGuest();
    }

    public function test_a_state_from_another_browser_session_is_refused(): void
    {
        $authorize = $this->startLogin();
        $code = $this->idp->approve($authorize);

        $this->flushSession();

        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]))
            ->assertStatus(400)->assertSee('state_unknown');
        $this->assertGuest();
    }

    public function test_a_state_older_than_ten_minutes_is_refused(): void
    {
        $authorize = $this->startLogin();
        $code = $this->idp->approve($authorize);

        $this->travel(11)->minutes();

        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]))
            ->assertStatus(400)->assertSee('state_expired');
        $this->assertGuest();
    }

    public function test_an_error_from_the_provider_ends_on_a_page_and_spends_the_state(): void
    {
        $authorize = $this->startLogin();

        $this->get('https://platform.test/sso/callback?'.http_build_query(['error' => 'access_denied', 'state' => $authorize['state']]))
            ->assertStatus(403)->assertSee('denied');
        $this->get('https://platform.test/sso/callback?'.http_build_query(['error' => 'access_denied', 'state' => $authorize['state']]))
            ->assertStatus(400)->assertSee('state_unknown');
    }

    public function test_pkce_tampering_is_caught_by_the_provider_and_ends_on_a_page(): void
    {
        $authorize = $this->startLogin();
        $code = $this->idp->approve($authorize);

        // Someone swaps the stored verifier: the provider's own S256 check no longer matches the challenge.
        $records = session('dotsso.pending');
        $records[$authorize['state']]['verifier'] = str_repeat('a', 64);
        session(['dotsso.pending' => $records]);

        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]))
            ->assertStatus(502)->assertSee('token_exchange_failed');
        $this->assertGuest();
    }

    public function test_a_code_replayed_at_the_provider_gets_nothing(): void
    {
        $authorize = $this->startLogin();
        $code = $this->idp->approve($authorize);
        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]))->assertRedirect();
        auth()->logout();

        $second = $this->startLogin();
        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $second['state']]))
            ->assertStatus(502);
        $this->assertGuest();
    }

    public function test_the_token_endpoint_being_down_ends_on_a_page(): void
    {
        $authorize = $this->startLogin();
        $this->idp->tokenStatus = 500;

        $this->finishLogin($authorize)->assertStatus(502)->assertSee('token_exchange_failed');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badTokens(): array
    {
        $other = ['__other_key__' => true];

        return [
            'wrong nonce' => [['nonce' => 'not-the-nonce'], 'nonce mismatch'],
            'wrong audience' => [['aud' => 'someone-else'], 'audience mismatch'],
            'audience list without us' => [['aud' => ['a', 'b']], 'audience mismatch'],
            'wrong issuer' => [['iss' => 'https://evil.example'], 'issuer mismatch'],
            'lookalike issuer' => [['iss' => 'https://idp.test.evil.example'], 'issuer mismatch'],
            'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'expired'],
            'issued in the future' => [['iat' => time() + 3600, 'exp' => time() + 7200], 'future'],
            'too old for this exchange' => [['iat' => time() - 3000, 'exp' => time() + 600], 'too old'],
            'lifetime too long' => [['exp' => time() + 86400 * 30], 'longer than allowed'],
            'no exp' => [['exp' => '__unset__'], 'expired'],
            'no sub' => [['sub' => '__unset__'], 'subject'],
            'empty sub' => [['sub' => ''], 'subject'],
            'azp mismatch' => [['azp' => 'other-client'], 'azp'],
            'no nonce' => [['nonce' => '__unset__'], 'nonce'],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_a_token_that_fails_any_check_is_refused_and_nothing_is_created(array $override, string $reason): void
    {
        $this->idp->claimOverrides = $override;
        $authorize = $this->startLogin();

        $this->finishLogin($authorize)->assertStatus(403)->assertSee('token_invalid');

        $this->assertGuest();
        $this->assertSame(0, TestUser::count());
    }

    public function test_a_token_signed_by_a_different_key_is_refused(): void
    {
        $this->idp->signWith = FakeIdp::otherKey();
        $authorize = $this->startLogin();

        $this->finishLogin($authorize)->assertStatus(403)->assertSee('token_invalid');
        $this->assertGuest();
    }

    public function test_an_unsigned_token_and_an_hmac_key_confusion_token_are_refused(): void
    {
        foreach (['none', 'HS256'] as $alg) {
            $this->idp->forceAlg = $alg;
            $authorize = $this->startLogin();

            $this->finishLogin($authorize)->assertStatus(403)->assertSee('token_invalid');
            $this->assertGuest();
        }
    }

    public function test_a_token_whose_payload_was_edited_after_signing_is_refused(): void
    {
        $authorize = $this->startLogin();
        $good = $this->idp->mint($this->idp->defaultClaims(['nonce' => $authorize['nonce']]));
        [$h, $p, $s] = explode('.', $good);
        $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $payload['email'] = 'admin@example.test';
        $evil = $h.'.'.FakeIdp::b64(json_encode($payload)).'.'.$s;

        $this->idp->tokenBody = ['id_token' => $evil];

        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => 'x', 'state' => $authorize['state']]))
            ->assertStatus(403)->assertSee('token_invalid');
        $this->assertGuest();
        $this->assertSame(0, TestUser::count());
    }

    public function test_a_response_with_no_id_token_is_refused(): void
    {
        $authorize = $this->startLogin();
        $this->idp->tokenBody = ['access_token' => 'x'];

        $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => 'x', 'state' => $authorize['state']]))
            ->assertStatus(502);
        $this->assertGuest();
    }

    public function test_an_email_the_provider_does_not_vouch_for_is_refused(): void
    {
        $this->idp->claimOverrides = ['email_verified' => false];

        $this->finishLogin($this->startLogin())->assertStatus(403)->assertSee('email_unverified');
        $this->assertGuest();
        $this->assertSame(0, TestUser::count());
    }

    public function test_a_stolen_deep_link_cannot_be_smuggled_through_the_callback(): void
    {
        $authorize = $this->startLogin([], ['url.intended' => '/tasks/5']);
        $code = $this->idp->approve($authorize);

        $this->get('https://platform.test/sso/callback?'.http_build_query([
            'code' => $code, 'state' => $authorize['state'], 'redirect' => 'https://evil.example', 'next' => 'https://evil.example', 'return_to' => '//evil.example',
        ]))->assertRedirect('/tasks/5');
    }

    public function test_the_routes_do_not_exist_while_the_switch_is_off(): void
    {
        config(['dotsso.enabled' => false]);

        $this->get('https://platform.test/sso/login')->assertNotFound();
        $this->get('https://platform.test/sso/callback')->assertNotFound();
        $this->get('https://platform.test/sso/claim')->assertNotFound();
        $this->post('https://platform.test/sso/claim', ['code' => '123456'])->assertNotFound();
        $this->post('https://platform.test/sso/backchannel-logout')->assertNotFound();
    }

    public function test_a_claim_started_by_the_platforms_fallback_can_be_finished_while_the_switch_is_off(): void
    {
        $local = $this->localUser();
        $request = Request::create('https://platform.test/auth/ecosystem', 'POST');
        $request->setLaravelSession(app('session.store'));
        app(ClaimService::class)->begin($request, $local, ['sub' => 'uuid-1111', 'email' => 'ada@example.test', 'name' => 'Ada'], '/tasks/2', '');
        $code = TestProvisioner::$mails[0]['code'];
        config(['dotsso.enabled' => false]);

        $this->post('https://platform.test/sso/claim', ['code' => $code])->assertRedirect('/tasks/2');
        $this->assertSame('uuid-1111', $local->fresh()->infodot_user_uuid);
    }
}
