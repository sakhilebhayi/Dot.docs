<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;

class InterceptLocalAuthTest extends DotSsoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stand-ins for a platform's own auth pages and machine doors, so the suite does not depend on the host's Fortify.
        Route::middleware('web')->group(function () {
            foreach (['login', 'register', 'forgot-password', 'reset-password', 'reset-password/{token}'] as $path) {
                Route::get('/'.$path, fn () => 'local '.$path);
                Route::post('/'.$path, fn () => 'posted '.$path);
            }
            Route::get('/signed-share', fn () => 'share')->name('t.signed')->middleware('signed');
            Route::get('/public/share/abc', fn () => 'public share');
            Route::get('/health-extra', fn () => 'extra');
        });
        Route::get('/api/ping', fn () => response()->json(['pong' => true]));
        Route::post('/webhooks/stripe', fn () => 'hook');
        Route::post('/api/login', fn () => 'token issued');
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    #[DataProvider('pages')]
    public function test_the_local_pages_go_to_central_sign_in(string $path, array $expectedQuery): void
    {
        $response = $this->get('https://platform.test'.$path)->assertRedirect();

        $this->assertSame(route('dotsso.login', $expectedQuery), $response->headers->get('Location'));
    }

    public static function pages(): array
    {
        return [
            'login' => ['/login', []],
            'register' => ['/register', ['screen' => 'register']],
            'forgot' => ['/forgot-password', []],
            'reset' => ['/reset-password', []],
            'reset with token' => ['/reset-password/abc123', []],
        ];
    }

    public function test_the_intended_page_is_not_copied_into_the_address(): void
    {
        $location = $this->withSession(['url.intended' => 'https://platform.test/tasks/4'])
            ->get('https://platform.test/login')->headers->get('Location');

        $this->assertStringNotContainsString('tasks', $location);
        $this->assertStringNotContainsString('intended', $location);
    }

    public function test_the_deep_link_survives_the_whole_trip(): void
    {
        $this->withSession(['url.intended' => 'https://platform.test/tasks/4'])->get('https://platform.test/login')->assertRedirect();
        $authorize = FakeIdpParams::from($this->get('https://platform.test/sso/login'));

        $this->finishLogin($authorize)->assertRedirect('/tasks/4');
    }

    public function test_credential_posts_to_the_old_forms_are_refused_so_they_cannot_be_used_directly(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password'] as $path) {
            $this->post('https://platform.test'.$path, ['email' => 'a@b.test', 'password' => 'x'])
                ->assertStatus(303)->assertRedirect(route('dotsso.login'));
        }
    }

    public function test_machine_paths_are_untouched(): void
    {
        $this->get('https://platform.test/api/ping')->assertOk()->assertJson(['pong' => true]);
        $this->post('https://platform.test/webhooks/stripe')->assertOk();
        $this->postJson('https://platform.test/api/login', [])->assertOk();
        $this->withHeader('Authorization', 'Bearer abc')->get('https://platform.test/api/ping')->assertOk();
        $this->get('https://platform.test/health-extra')->assertOk();
    }

    public function test_json_or_a_bearer_token_never_reopens_a_sign_in_page(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password'] as $path) {
            $this->getJson('https://platform.test'.$path)->assertRedirect();
            $this->postJson('https://platform.test'.$path, ['email' => 'a@b.test', 'password' => 'x'])
                ->assertStatus(303)->assertRedirect(route('dotsso.login'));
            $this->withHeader('Authorization', 'Bearer abc')
                ->post('https://platform.test'.$path, ['email' => 'a@b.test', 'password' => 'x'])
                ->assertStatus(303)->assertRedirect(route('dotsso.login'));
        }
    }

    public function test_signed_urls_and_public_share_links_are_untouched(): void
    {
        $this->get(URL::signedRoute('t.signed'))->assertOk();
        $this->get('https://platform.test/public/share/abc')->assertOk();
    }

    public function test_a_platform_can_list_paths_that_must_never_be_intercepted(): void
    {
        config(['dotsso.allow_paths' => ['reset-password/*']]);

        $this->get('https://platform.test/reset-password/abc')->assertOk()->assertSee('local reset-password/{token}');
        $this->get('https://platform.test/login')->assertRedirect();
    }

    public function test_break_glass_brings_the_local_pages_back(): void
    {
        config(['dotsso.allow_local_login' => true]);

        $this->get('https://platform.test/login')->assertOk()->assertSee('local login');
        $this->post('https://platform.test/login')->assertOk();
    }

    public function test_with_the_switch_off_nothing_is_intercepted(): void
    {
        config(['dotsso.enabled' => false]);

        $this->get('https://platform.test/login')->assertOk()->assertSee('local login');
        $this->get('https://platform.test/register')->assertOk();
        $this->post('https://platform.test/login')->assertOk();
    }

    public function test_other_paths_are_not_touched(): void
    {
        $this->get('https://platform.test/_dotsso/probe')->assertOk();
    }
}

/** Tiny helper: read the authorize query out of a redirect. */
final class FakeIdpParams
{
    public static function from($response): array
    {
        return FakeIdp::authorizeParams($response->assertRedirect()->headers->get('Location'));
    }
}
