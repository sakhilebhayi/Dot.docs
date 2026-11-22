<?php

namespace Dot\SsoClient\Tests;

use Dot\SsoClient\DotSsoServiceProvider;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Dot\SsoClient\Tests\Support\TestProvisioner;
use Dot\SsoClient\Tests\Support\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plumbing for the package's own suite. It runs inside whichever host holds the package (InfoDot, a platform): the host
 * supplies the framework and a database, the test supplies a users table, a provisioner and a fake provider. The
 * package's service provider is registered here so the suite does not depend on how the host loads it.
 */
abstract class DotSsoTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        TestProvisioner::$mails = [];
        TestProvisioner::$refuseCreate = false;

        $this->app->register(DotSsoServiceProvider::class);
        Route::middleware('web')->get('/_dotsso/probe', fn () => 'ok')->name('dotsso.test.probe');
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();

        // The package's own tables: created here when the host's migration run happened before the provider was registered.
        if (! Schema::hasTable('dotsso_claims') || ! Schema::hasTable('dotsso_revocations')) {
            $this->artisan('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true, '--no-interaction' => true]);
        }

        // The users table is the test's own.
        Schema::dropIfExists('dotsso_test_users');
        Schema::create('dotsso_test_users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('infodot_user_uuid', 64)->nullable()->unique();
            $t->rememberToken();
            $t->timestamps();
        });

        config(['auth.providers.users.model' => TestUser::class, 'auth.guards.web.provider' => 'users']);
        $this->app['auth']->forgetGuards();

        $this->idp = (new FakeIdp)->install();
        RateLimiter::clear('x');
    }

    protected function localUser(array $attributes = []): TestUser
    {
        return TestUser::create(array_merge([
            'name' => 'Ada Local',
            'email' => 'ada@example.test',
            'password' => bcrypt('local-password-1'),
        ], $attributes));
    }

    /** Start a sign-in the way a browser would and return the authorize parameters the package sent. @return array<string, string> */
    protected function startLogin(array $query = [], array $session = []): array
    {
        $response = $this->withSession($session)->get('https://platform.test/sso/login'.($query ? '?'.http_build_query($query) : ''));
        $response->assertRedirect();

        return FakeIdp::authorizeParams($response->headers->get('Location'));
    }

    /** Finish a sign-in as the provider would: approve, then hit the callback. */
    protected function finishLogin(array $authorize, array $claims = [], ?string $state = null)
    {
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('https://platform.test/sso/callback?'.http_build_query(['code' => $code, 'state' => $state ?? $authorize['state']]));
    }
}
