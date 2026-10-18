<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InfoDot's header search asks which documents match. Signed, members only, names and pointers only.
 */
class InfoDotSearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'service-secret-for-tests';

    private Team $acme;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot.secret' => self::SECRET]);

        $this->ann = User::factory()->withPersonalTeam()->create(['email' => 'Ann@Example.test']);
        $this->acme = Team::forceCreate(['user_id' => $this->ann->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->acme->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();
    }

    private function file(Team $team, string $name, bool $registered = true): Document
    {
        $document = Document::factory()->create(['owner_id' => $team->user_id, 'team_id' => $team->id, 'title' => $name]);
        Document::whereKey($document->id)->toBase()->update(['infodot_resource_uuid' => $registered ? (string) Str::uuid() : null]);

        return $document->fresh();
    }

    private function ask(array $payload, ?string $secret = self::SECRET, ?int $timestamp = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();

        return $this->call('POST', '/api/ecosystem/search', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $secret),
        ], $body);
    }

    private function q(string $q, ?Team $team = null, string $email = 'ann@example.test'): array
    {
        return ['team_uuid' => ($team ?? $this->acme)->infodot_team_uuid, 'email' => $email, 'q' => $q];
    }

    public function test_a_member_gets_matching_files_with_names_and_pointers_only(): void
    {
        $mine = $this->file($this->acme, 'Payroll 2026 plan');
        $this->file($this->acme, 'Holiday photos list');

        $response = $this->ask($this->q('payroll'))->assertOk();

        $this->assertSame([['resource' => $mine->infodot_resource_uuid, 'title' => 'Payroll 2026 plan', 'kind' => 'document']], $response->json('results'));
    }

    public function test_documents_without_an_infodot_pointer_are_not_offered(): void
    {
        $this->file($this->acme, 'Payroll draft', false);

        $this->assertSame([], $this->ask($this->q('payroll'))->assertOk()->json('results'));
    }

    public function test_someone_outside_the_company_gets_nothing_and_so_does_an_unknown_company_or_person(): void
    {
        $this->file($this->acme, 'Payroll 2026 plan');
        $gina = User::factory()->withPersonalTeam()->create(['email' => 'gina@example.test']);

        $this->assertSame([], $this->ask($this->q('payroll', null, 'gina@example.test'))->assertOk()->json('results'));
        $this->assertSame([], $this->ask($this->q('payroll', null, 'nobody@example.test'))->assertOk()->json('results'));
        $this->assertSame([], $this->ask(['team_uuid' => (string) Str::uuid(), 'email' => 'ann@example.test', 'q' => 'payroll'])->assertOk()->json('results'));
        $this->assertNotNull($gina);
    }

    public function test_one_company_never_sees_another_companys_files(): void
    {
        $gina = User::factory()->withPersonalTeam()->create(['email' => 'gina@example.test']);
        $globex = Team::forceCreate(['user_id' => $gina->id, 'name' => 'Globex', 'personal_team' => false]);
        $globex->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();
        $this->file($globex, 'Globex payroll plan');

        $this->assertSame([], $this->ask($this->q('payroll'))->assertOk()->json('results'), 'Acme members do not see Globex documents');
        $this->assertCount(1, $this->ask($this->q('payroll', $globex, 'gina@example.test'))->assertOk()->json('results'));
    }

    public function test_wildcards_in_the_query_are_text_not_match_everything(): void
    {
        $this->file($this->acme, 'Quarterly report');
        $odd = $this->file($this->acme, '100%_done notes');

        $this->assertSame([], $this->ask($this->q('%%%'))->assertOk()->json('results'));
        $this->assertSame([$odd->infodot_resource_uuid], array_column($this->ask($this->q('100%_'))->assertOk()->json('results'), 'resource'));
    }

    public function test_at_most_five_results(): void
    {
        foreach (range(1, 8) as $i) {
            $this->file($this->acme, "Budget $i plan");
        }

        $this->assertCount(5, $this->ask($this->q('budget'))->assertOk()->json('results'));
    }

    public function test_unsigned_wrongly_signed_stale_or_malformed_requests_are_refused(): void
    {
        $this->ask($this->q('payroll'), 'wrong')->assertStatus(401);
        $this->ask($this->q('payroll'), self::SECRET, time() - 3600)->assertStatus(401);
        $this->postJson('/api/ecosystem/search', $this->q('payroll'))->assertStatus(401);
        $this->ask($this->q('ab'))->assertStatus(422);
        $this->ask(['team_uuid' => 'x', 'email' => 'bad', 'q' => 'payroll'])->assertStatus(422);
    }
}
