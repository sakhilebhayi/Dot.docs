<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'docs',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
        ], $extra);
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 'one-time-token']))->assertRedirect();

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 'one-time-token']));

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'docs';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']));

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        $this->get(route('auth.ecosystem', ['token' => 'bad']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->get(route('auth.ecosystem', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(503);

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->get(route('auth.ecosystem', ['token' => 't']))->assertStatus(503);

        $this->assertGuest();
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->get(route('auth.ecosystem', ['token' => 'unknown']))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $token = $user->createToken('handoff', ['ecosystem:read'])->plainTextToken;
        Http::fake();

        $this->get(route('auth.ecosystem', ['token' => $token]))->assertRedirect();
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        $this->get(route('auth.ecosystem', ['token' => $token]))->assertForbidden();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('auth.ecosystem', ['token' => 't']))->assertForbidden();

        $this->assertSame(0, User::count());
    }

    public function test_a_missing_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->get(route('auth.ecosystem'))->assertForbidden();
        $this->get('/auth/ecosystem?token[]=x')->assertForbidden();

        Http::assertNothingSent();
    }
}
