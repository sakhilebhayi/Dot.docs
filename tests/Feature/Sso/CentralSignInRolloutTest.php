<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\DocsProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** R-54 phase 2 rollout: Dot.docs behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => DocsProvisioner::class,
            'dotsso.enabled' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        // The fake provider's redirect URI is the one this test app uses.
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create(); // two NULLs are allowed

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Remember me');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Remember me');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/documents')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/documents');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_after_an_infodot_sign_out_a_real_auth_sanctum_page_ends_the_session_instead_of_a_500(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-uuid-3', 'email' => 'out@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        $this->travel(1)->seconds();
        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-uuid-3', 'revoked_at_ms' => (int) floor(microtime(true) * 1000), 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect('/');
        $this->assertGuest('web');
    }

    /**
     * R2-1 / S-07: the API answers a bearer token only. A signed-in identity that did not arrive with a token
     * (here Sanctum's test helper, in life a session) is refused, SSO on or off; the real-token case is below.
     */
    public function test_the_api_answers_only_a_bearer_token_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
    }

    public function test_the_claim_flow_keeps_team_memberships_tokens_and_the_password(): void
    {
        $this->on();
        $owner = User::factory()->withPersonalTeam()->create();
        $shared = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Acme docs', 'personal_team' => false]);
        $person = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $shared->users()->attach($person, ['role' => 'editor']);
        $person->switchTeam($shared);
        $person->createToken('integration');
        $hash = $person->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $code = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->post('/sso/claim', ['code' => $code])->assertRedirect();

        $person->refresh();
        $this->assertTrue($person->belongsToTeam($shared));
        $this->assertSame($shared->id, $person->current_team_id);
        $this->assertSame($hash, $person->password);
        $this->assertSame(1, $person->tokens()->count(), 'API tokens survive');
        $this->assertAuthenticatedAs($person);
    }

    public function test_a_person_whose_infodot_email_changed_is_matched_by_id_not_given_a_second_account(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => 'infodot-uuid-8']);

        $this->signInVia(['sub' => 'infodot-uuid-8', 'email' => 'new@example.test'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_an_open_document_link_survives_the_sign_in(): void
    {
        $this->on();
        $path = '/documents/3f1c2a4e-0000-4000-8000-000000000000/edit';

        $this->get($path)->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->signInVia(['sub' => 'infodot-uuid-d', 'email' => 'doc@example.test'])->assertRedirect($path);
    }

    public function test_a_team_invitation_link_is_an_allowed_deep_link(): void
    {
        $this->on();
        $url = '/team-invitations/5?expires=1&signature=abc';

        $this->get($url)->assertRedirect('/login');
        $this->signInVia(['sub' => 'infodot-uuid-t', 'email' => 'team@example.test'])->assertRedirect($url);
    }

    public function test_the_outside_party_pages_and_the_infodot_signed_endpoints_are_not_redirected_to_sign_in(): void
    {
        $this->on();

        $this->get('/shared/not-a-real-uuid')->assertNotFound();
        $this->get('/d/not-a-real-slug')->assertNotFound();
        $this->postJson('/api/ecosystem/membership', [])->assertUnauthorized();
        $this->postJson('/api/ecosystem/search', [])->assertUnauthorized();
        $this->get('/up')->assertOk();
    }

    public function test_comment_and_notification_mail_still_links_into_the_app(): void
    {
        $this->on();

        // Notification mail keeps working: its signed-in links go through the ordinary guest redirect, which now ends at InfoDot.
        $this->get('/documents')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));
    }

    public function test_the_fallback_receiver_no_longer_takes_an_unverified_account_over(): void
    {
        $this->assertStringNotContainsString('Hash::make(Str::random(48))]);'.PHP_EOL.'                Log::notice', file_get_contents(app_path('Actions/Ecosystem/ProvisionUserFromInfoDot.php')));
        $this->assertStringContainsString('ClaimRequired', file_get_contents(app_path('Actions/Ecosystem/ProvisionUserFromInfoDot.php')));
    }
}
