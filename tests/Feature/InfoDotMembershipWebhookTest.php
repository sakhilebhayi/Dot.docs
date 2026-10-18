<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InfoDot's signed membership webhook: removal, role change and company deletion end access
 * at once, and nothing unsigned, stale or unknown has any effect.
 */
class InfoDotMembershipWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'service-secret-for-tests';

    private User $owner;

    private User $member;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot.secret' => self::SECRET]);

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->member = User::factory()->withPersonalTeam()->create(['email' => 'Thabo@Example.test']);
        $this->team = Team::forceCreate(['user_id' => $this->owner->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->team->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();
        $this->team->users()->attach($this->member, ['role' => 'editor']);
        $this->member->switchTeam($this->team);
    }

    private function send(array $payload, ?string $secret = self::SECRET, ?int $timestamp = null, ?string $signature = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $secret);

        return $this->call('POST', '/api/ecosystem/membership', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => $signature,
        ], $body);
    }

    private function removal(?string $email = 'thabo@example.test'): array
    {
        return ['event' => 'member.removed', 'team_uuid' => $this->team->infodot_team_uuid, 'email' => $email];
    }

    public function test_a_signed_removal_ends_access_at_once_and_clears_the_current_team(): void
    {
        $this->send($this->removal())->assertStatus(202);

        $this->assertFalse($this->team->fresh()->users()->where('users.id', $this->member->id)->exists());
        $this->assertNull($this->member->fresh()->current_team_id, 'no scoped query keeps using the company');
    }

    public function test_the_email_is_matched_whatever_its_case(): void
    {
        $this->send($this->removal('THABO@EXAMPLE.TEST'))->assertStatus(202);

        $this->assertFalse($this->team->fresh()->users()->where('users.id', $this->member->id)->exists());
    }

    public function test_the_local_owner_is_never_detached(): void
    {
        $this->send($this->removal($this->owner->email))->assertStatus(202);

        $this->assertSame($this->owner->id, $this->team->fresh()->user_id);
    }

    public function test_a_role_change_is_applied_with_the_sign_in_mapping(): void
    {
        $this->send(['event' => 'member.role_changed', 'team_uuid' => $this->team->infodot_team_uuid, 'email' => 'thabo@example.test', 'role' => 'admin'])->assertStatus(202);

        $this->assertSame('admin', $this->team->fresh()->users()->where('users.id', $this->member->id)->first()->membership->role);
    }

    public function test_deleting_the_company_unlinks_it_and_detaches_everyone_but_the_owner_without_deleting_data(): void
    {
        $this->send(['event' => 'team.deleted', 'team_uuid' => $this->team->infodot_team_uuid])->assertStatus(202);

        $team = $this->team->fresh();
        $this->assertNull($team->infodot_team_uuid);
        $this->assertSame(0, $team->users()->count());
        $this->assertSame($this->owner->id, $team->user_id);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_an_unsigned_wrongly_signed_or_stale_request_changes_nothing(): void
    {
        $this->send($this->removal(), 'a-different-secret')->assertStatus(401);
        $this->send($this->removal(), self::SECRET, null, 'sha256=deadbeef')->assertStatus(401);
        $this->send($this->removal(), self::SECRET, time() - 3600)->assertStatus(401);
        $this->postJson('/api/ecosystem/membership', $this->removal())->assertStatus(401);

        $this->assertTrue($this->team->fresh()->users()->where('users.id', $this->member->id)->exists());
    }

    public function test_a_signature_cannot_be_reused_for_a_different_body(): void
    {
        $timestamp = time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.json_encode($this->removal('nobody@example.test')), self::SECRET);

        $this->send($this->removal(), self::SECRET, $timestamp, $signature)->assertStatus(401);

        $this->assertTrue($this->team->fresh()->users()->where('users.id', $this->member->id)->exists());
    }

    public function test_unknown_teams_and_people_are_ignored_and_reveal_nothing(): void
    {
        $this->send(['event' => 'member.removed', 'team_uuid' => (string) Str::uuid(), 'email' => 'thabo@example.test'])->assertStatus(202);
        $this->send($this->removal('nobody@example.test'))->assertStatus(202);

        $this->assertTrue($this->team->fresh()->users()->where('users.id', $this->member->id)->exists());
    }

    public function test_malformed_and_unknown_events_are_refused(): void
    {
        $this->send(['event' => 'member.exploded', 'team_uuid' => $this->team->infodot_team_uuid])->assertStatus(422);
        $this->send(['event' => 'member.removed', 'team_uuid' => 'not-a-uuid'])->assertStatus(422);
    }

    public function test_without_a_configured_secret_nothing_is_accepted(): void
    {
        config(['ecosystem.infodot.secret' => null]);

        $this->send($this->removal(), '')->assertStatus(401);
    }
}
