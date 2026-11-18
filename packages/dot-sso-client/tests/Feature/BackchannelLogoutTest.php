<?php

namespace Dot\SsoClient\Tests\Feature;

use Dot\SsoClient\Tests\DotSsoTestCase;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

class BackchannelLogoutTest extends DotSsoTestCase
{
    private function logoutToken(array $override = [], ?string $alg = null, mixed $key = null): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => FakeIdp::ISSUER, 'aud' => FakeIdp::CLIENT_ID, 'iat' => $now, 'exp' => $now + 120, 'jti' => bin2hex(random_bytes(8)),
            'sub' => 'uuid-1111', 'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass],
        ], $override);

        return $this->idp->mint(array_filter($claims, fn ($v) => $v !== '__unset__'), $alg, $key);
    }

    private function send(?string $token): TestResponse
    {
        return $this->post('https://platform.test/sso/backchannel-logout', $token === null ? [] : ['logout_token' => $token]);
    }

    public function test_a_valid_logout_token_ends_the_matching_session_on_its_next_request(): void
    {
        $this->finishLogin($this->startLogin())->assertRedirect();
        $this->assertAuthenticated();

        $this->travel(1)->seconds();
        $this->send($this->logoutToken())->assertOk();
        $this->assertDatabaseHas('dotsso_revocations', ['sub' => 'uuid-1111']);

        $this->get('https://platform.test/_dotsso/probe')->assertStatus(302)->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_a_route_behind_auth_sanctum_ends_the_session_instead_of_a_500(): void
    {
        Route::middleware(['web', 'auth:sanctum'])->get('/_dotsso/sanctum-probe', fn () => 'ok');
        $this->app['router']->getRoutes()->refreshActionLookups();

        $this->finishLogin($this->startLogin());
        $this->get('https://platform.test/_dotsso/sanctum-probe')->assertOk();

        $this->travel(1)->seconds();
        $this->send($this->logoutToken())->assertOk();

        // Sanctum has made `sanctum` the default guard by the time the revocation check runs; logging out of it used to be a 500.
        $this->get('https://platform.test/_dotsso/sanctum-probe')->assertRedirect('/');
        $this->assertGuest('web');
    }

    public function test_a_json_request_after_logout_gets_401(): void
    {
        $this->finishLogin($this->startLogin());
        $this->travel(1)->seconds();
        $this->send($this->logoutToken())->assertOk();

        $this->getJson('https://platform.test/_dotsso/probe')->assertUnauthorized();
    }

    public function test_a_sign_in_after_the_logout_is_not_ended_by_the_old_revocation(): void
    {
        $this->send($this->logoutToken())->assertOk();
        $this->travel(1)->seconds();

        $this->finishLogin($this->startLogin());
        $this->get('https://platform.test/_dotsso/probe');

        $this->assertAuthenticated();
    }

    public function test_another_persons_logout_does_not_touch_this_session(): void
    {
        $this->finishLogin($this->startLogin());
        $this->travel(1)->seconds();
        $this->send($this->logoutToken(['sub' => 'someone-else']))->assertOk();

        $this->get('https://platform.test/_dotsso/probe');
        $this->assertAuthenticated();
    }

    public function test_a_session_not_opened_through_sso_is_left_alone(): void
    {
        $this->actingAs($this->localUser(['infodot_user_uuid' => 'uuid-1111']));
        $this->send($this->logoutToken())->assertOk();

        $this->get('https://platform.test/_dotsso/probe');
        $this->assertAuthenticated();
    }

    public function test_database_sessions_of_the_person_are_deleted_at_once(): void
    {
        config(['session.driver' => 'database']);
        Schema::dropIfExists('sessions');
        Schema::create('sessions', function ($t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        $user = $this->localUser(['infodot_user_uuid' => 'uuid-1111']);
        DB::table('sessions')->insert([
            ['id' => 'a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'b', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'c', 'user_id' => 999, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->send($this->logoutToken())->assertOk();

        $this->assertSame(['c'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_every_invalid_token_is_refused_and_records_nothing(): void
    {
        $bad = [
            'no token' => null,
            'garbage' => 'not.a.jwt',
            'wrong audience' => $this->logoutToken(['aud' => 'other']),
            'wrong issuer' => $this->logoutToken(['iss' => 'https://evil.example']),
            'no events claim' => $this->logoutToken(['events' => '__unset__']),
            'wrong event' => $this->logoutToken(['events' => ['x' => new \stdClass]]),
            'carries a nonce (an id_token)' => $this->logoutToken(['nonce' => 'n']),
            'no sub' => $this->logoutToken(['sub' => '__unset__']),
            'no jti' => $this->logoutToken(['jti' => '__unset__']),
            'too old' => $this->logoutToken(['iat' => time() - 3600, 'exp' => time() + 60]),
            'expired' => $this->logoutToken(['iat' => time() - 200, 'exp' => time() - 100]),
            'other key' => $this->logoutToken([], null, FakeIdp::otherKey()),
            'unsigned' => $this->logoutToken([], 'none'),
            'hmac confusion' => $this->logoutToken([], 'HS256'),
        ];

        foreach ($bad as $name => $token) {
            $this->send($token)->assertStatus(400);
        }

        $this->assertSame(0, DB::table('dotsso_revocations')->count());
    }

    public function test_a_replayed_logout_token_is_refused(): void
    {
        $token = $this->logoutToken();

        $this->send($token)->assertOk();
        $this->send($token)->assertStatus(400);
        $this->assertSame(1, DB::table('dotsso_revocations')->count());
    }

    public function test_it_needs_no_session_or_csrf_token_and_does_not_start_one(): void
    {
        $response = $this->send($this->logoutToken());

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies());
    }
}
