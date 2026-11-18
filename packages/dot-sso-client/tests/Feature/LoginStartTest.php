<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\FakeIdp;
use PHPUnit\Framework\Attributes\DataProvider;

class LoginStartTest extends DotSsoTestCase
{
    public function test_it_starts_an_authorisation_code_flow_with_pkce_state_and_nonce(): void
    {
        $q = $this->startLogin();

        $this->assertSame('code', $q['response_type']);
        $this->assertSame(FakeIdp::CLIENT_ID, $q['client_id']);
        $this->assertSame(FakeIdp::REDIRECT, $q['redirect_uri']);
        $this->assertSame('openid profile email', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertGreaterThanOrEqual(43, strlen($q['code_challenge']));
        $this->assertGreaterThanOrEqual(16, strlen($q['state']));
        $this->assertGreaterThanOrEqual(8, strlen($q['nonce']));
        $this->assertArrayNotHasKey('prompt', $q);
        $this->assertArrayNotHasKey('code_verifier', $q, 'the verifier never leaves the server');
    }

    public function test_every_start_has_its_own_state_nonce_and_challenge(): void
    {
        $a = $this->startLogin();
        $b = $this->startLogin();

        $this->assertNotSame($a['state'], $b['state']);
        $this->assertNotSame($a['nonce'], $b['nonce']);
        $this->assertNotSame($a['code_challenge'], $b['code_challenge']);
    }

    public function test_the_register_screen_is_a_fixed_value_that_asks_for_prompt_create(): void
    {
        $this->assertSame('create', $this->startLogin(['screen' => 'register'])['prompt']);
    }

    #[DataProvider('hostileScreens')]
    public function test_any_other_screen_value_is_ignored_and_never_becomes_an_address(string $screen): void
    {
        $location = $this->get('https://platform.test/sso/login?'.http_build_query(['screen' => $screen]))->headers->get('Location');

        $this->assertStringStartsWith(FakeIdp::ISSUER.'/oauth/authorize?', $location);
        $this->assertArrayNotHasKey('prompt', FakeIdp::authorizeParams($location));
        $this->assertStringNotContainsString('evil', $location);
    }

    public static function hostileScreens(): array
    {
        return [['https://evil.example/'], ['//evil.example'], ['login'], ['REGISTER'], ['register&redirect_uri=https://evil.example']];
    }

    public function test_the_deep_link_is_kept_server_side_and_never_put_in_the_url(): void
    {
        $location = $this->withSession(['url.intended' => 'https://platform.test/zzmarker/deep-path?tab=files'])
            ->get('https://platform.test/sso/login')->headers->get('Location');

        $this->assertStringNotContainsString('zzmarker', $location);
        $this->assertStringNotContainsString('deep-path', $location);
    }

    #[DataProvider('hostileDeepLinks')]
    public function test_a_hostile_deep_link_is_dropped_and_the_person_lands_on_the_safe_page(string $intended): void
    {
        $authorize = $this->startLogin([], ['url.intended' => $intended]);

        $this->finishLogin($authorize, ['sub' => 'uuid-9'])->assertRedirect('/dashboard');
    }

    public static function hostileDeepLinks(): array
    {
        return [
            'other host' => ['https://evil.example/steal'],
            'lookalike host' => ['https://platform.test.evil.example/x'],
            'protocol relative' => ['//evil.example/x'],
            'backslash' => ['/\\evil.example'],
            'encoded slashes' => ['/%2f%2fevil.example'],
            'double encoded' => ['/%252f%252fevil.example'],
            'encoded backslash' => ['/%5cevil.example'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,x'],
            'credentials' => ['https://platform.test@evil.example/'],
            'newline' => ["/ok\r\nLocation: https://evil.example"],
            'traversal' => ['/a/../../etc/passwd'],
            'encoded traversal' => ['/a/%2e%2e/b'],
            'loop to login' => ['/login'],
            'loop to register' => ['/register'],
            'loop to sso' => ['/sso/callback?code=x'],
            'oauth' => ['/oauth/authorize'],
            'different port' => ['https://platform.test:8443/x'],
            'wrong scheme' => ['http://platform.test/x'],
            'empty' => [''],
            'too long' => ['/'.str_repeat('a', 2100)],
        ];
    }

    public function test_a_good_deep_link_comes_back_exactly(): void
    {
        $authorize = $this->startLogin([], ['url.intended' => 'https://platform.test/tasks/42?tab=files']);

        $this->finishLogin($authorize, ['sub' => 'uuid-9'])->assertRedirect('/tasks/42?tab=files');
    }

    public function test_a_platform_can_narrow_the_deep_link_allowlist(): void
    {
        config(['dotsso.deep_link.allow' => ['#^/tasks(/|$)#']]);

        $this->finishLogin($this->startLogin([], ['url.intended' => '/settings']), ['sub' => 'a1'])->assertRedirect('/dashboard');
        auth()->logout();
        $this->flushSession();
        $this->finishLogin($this->startLogin([], ['url.intended' => '/tasks/7']), ['sub' => 'a2', 'email' => 'bo@example.test'])->assertRedirect('/tasks/7');
    }

    public function test_missing_client_credentials_show_a_clear_page_not_a_redirect(): void
    {
        config(['dotsso.client_secret' => null]);

        $this->get('https://platform.test/sso/login')->assertStatus(503)->assertSee('not set up correctly')->assertSee('misconfigured');
    }

    public function test_an_unreachable_provider_shows_a_clear_page_not_a_loop(): void
    {
        $this->idp->down = true;

        $this->get('https://platform.test/sso/login')->assertStatus(503)->assertSee('not reachable')->assertDontSee('Location');
    }

    public function test_an_insecure_issuer_is_refused(): void
    {
        config(['dotsso.issuer' => 'http://infodot.co.za']);

        $this->get('https://platform.test/sso/login')->assertStatus(503);
    }

    public function test_a_signed_in_person_is_sent_on_without_a_round_trip(): void
    {
        $this->actingAs($this->localUser())
            ->withSession(['url.intended' => '/tasks/3'])
            ->get('https://platform.test/sso/login')->assertRedirect('/tasks/3');
    }
}
