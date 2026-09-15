<?php

namespace Tests\Feature\Ai;

use App\Ai\Client;
use App\Documents\DocumentStore;
use App\Livewire\Documents\AiAssistant;
use App\Models\AiModelUsage;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_provider_returns_deterministic_text_and_records_usage(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $result = app(Client::class)->text('quick', 'You fix grammar.', 'Teh cat', ['operation' => 'grammar']);

        $this->assertStringStartsWith('[mock:quick]', $result->text);
        $this->assertStringContainsString('Teh cat', $result->text);
        $this->assertSame('mock', $result->provider);
        $this->assertSame('mock', $result->model);
        $this->assertSame(0, $result->inputTokens);
        $this->assertSame(0, $result->outputTokens);

        $this->assertDatabaseHas('ai_model_usage', [
            'operation' => 'grammar',
            'provider' => 'mock',
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
        ]);
        $this->assertSame('0.000000', (string) AiModelUsage::firstOrFail()->cost_usd);
    }

    public function test_the_mock_result_is_stable_for_the_same_input(): void
    {
        $this->actingAs(User::factory()->create());
        $client = app(Client::class);

        $first = $client->text('draft', 'S', 'Hello there', ['operation' => 'summarise']);
        $second = $client->text('draft', 'S', 'Hello there', ['operation' => 'summarise']);

        $this->assertSame($first->text, $second->text);
        $this->assertSame(2, AiModelUsage::count());
    }

    public function test_usage_rows_carry_the_document_when_one_is_given(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $doc = app(DocumentStore::class)->create($user, 'R');

        app(Client::class)->text('compose', 'S', 'Body', [
            'operation' => 'generate',
            'document_id' => $doc->id,
        ]);

        $this->assertDatabaseHas('ai_model_usage', [
            'operation' => 'generate',
            'document_id' => $doc->id,
        ]);
    }

    public function test_structured_round_trips_through_the_mock_provider(): void
    {
        $this->actingAs(User::factory()->create());

        $out = app(Client::class)->structured('quick', 'Return findings.', 'Teh cat', [
            'type' => 'object',
            'properties' => ['findings' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]);

        $this->assertIsArray($out);
        $this->assertSame([], $out);
        $this->assertDatabaseHas('ai_model_usage', ['provider' => 'mock', 'operation' => 'structured']);
    }

    public function test_ai_service_grammar_uses_client(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $out = app(AiService::class)->grammarCheck('<p>Teh cat</p>');

        $this->assertStringContainsString('[mock:quick]', $out);
        $this->assertDatabaseHas('ai_model_usage', ['operation' => 'grammar', 'provider' => 'mock']);
    }

    public function test_ai_service_summarise_uses_the_draft_role(): void
    {
        $this->actingAs(User::factory()->create());

        $out = app(AiService::class)->summarize('<p>A long report about haul roads</p>');

        $this->assertStringContainsString('[mock:draft]', $out);
        $this->assertDatabaseHas('ai_model_usage', ['operation' => 'summarise', 'provider' => 'mock']);
    }

    public function test_ai_service_translate_uses_the_quick_role(): void
    {
        $this->actingAs(User::factory()->create());

        $out = app(AiService::class)->translate('<p>Hello</p>', 'Zulu');

        $this->assertStringContainsString('[mock:quick]', $out);
        $this->assertDatabaseHas('ai_model_usage', ['operation' => 'translate', 'provider' => 'mock']);
    }

    public function test_run_command_still_returns_the_command_envelope(): void
    {
        $this->actingAs(User::factory()->create());

        $out = app(AiService::class)->runCommand('/continue', '<p>Once upon a time</p>');

        $this->assertSame('append', $out['type']);
        $this->assertStringContainsString('[mock:draft]', $out['content']);
    }

    /**
     * The editor's assistant panel is the only production caller of these
     * methods, and its public interface did not change when the transport
     * did - this proves the whole path still runs end to end on the mock.
     */
    public function test_the_assistant_panel_runs_end_to_end_on_the_mock(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        Livewire::actingAs($user)
            ->test(AiAssistant::class, ['document' => $doc])
            ->call('handleAction', 'summarize')
            ->assertSet('showResult', true)
            ->assertSee('[mock:draft]');

        $this->assertDatabaseHas('ai_suggestions', ['document_id' => $doc->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('ai_model_usage', ['operation' => 'summarise', 'provider' => 'mock']);
    }

    public function test_rate_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $svc = app(AiService::class);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($svc->checkRateLimit($user->id));
        }

        $this->assertFalse($svc->checkRateLimit($user->id));
    }

    public function test_the_mock_provider_is_the_default(): void
    {
        $this->assertSame('mock', config('ai.provider'));
        $this->assertSame(20, config('ai.rate_limit_per_hour'));
        $this->assertNotEmpty(config('ai.failover'));
    }
}
