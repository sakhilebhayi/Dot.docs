<?php

namespace Tests\Feature\Ai;

use App\Ai\Client;
use App\Models\AiModelUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Throwable;

/**
 * The non-mock branch of App\Ai\Client, exercised without a network.
 *
 * Prism's providers talk through Laravel's own HTTP client
 * (Prism\Prism\Concerns\InitializesClient::baseClient() builds from the `Http`
 * facade), so Http::fake() intercepts every leg of the failover walk and
 * Http::preventStrayRequests() proves nothing escaped to a real provider.
 */
class ClientFailoverTest extends TestCase
{
    use RefreshDatabase;

    /** Every provider answers 500, so every leg of the chain throws. */
    private function failEveryProvider(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('{"error":{"type":"api_error","message":"upstream down"}}', 500)]);
    }

    public function test_a_failover_leg_infers_its_provider_from_the_model_not_the_config_literal(): void
    {
        // Both config legs are labelled `anthropic`; one of them is an OpenAI
        // model. The label must be ignored and the provider re-derived.
        config([
            'ai.models.quick' => 'claude-haiku-4-5-20251001',
            'ai.failover' => [
                ['anthropic', 'gpt-4o'],
                ['anthropic', 'gemini-2.5-pro'],
            ],
        ]);

        $chain = app(Client::class)->chainFor('quick');

        $this->assertSame([
            ['anthropic', 'claude-haiku-4-5-20251001'],
            ['openai', 'gpt-4o'],
            ['gemini', 'gemini-2.5-pro'],
        ], $chain);
    }

    public function test_total_failover_exhaustion_still_writes_one_usage_row_and_rethrows(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        config([
            'ai.provider' => 'anthropic',
            'ai.models.quick' => 'claude-haiku-4-5-20251001',
            'ai.failover' => [['anthropic', 'gpt-4o']],
        ]);

        $this->failEveryProvider();

        $thrown = null;

        try {
            app(Client::class)->text('quick', 'You fix grammar.', 'Teh cat', ['operation' => 'grammar']);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'The exhausted chain must still surface a real failure to the caller.');

        $this->assertSame(1, AiModelUsage::count());

        $row = AiModelUsage::firstOrFail();
        $this->assertSame('grammar', $row->operation);
        $this->assertSame('openai', $row->provider, 'The last leg actually attempted is what gets recorded.');
        $this->assertSame('gpt-4o', $row->model);
        $this->assertSame(0, (int) $row->input_tokens);
        $this->assertSame(0, (int) $row->output_tokens);
        $this->assertSame(0.0, (float) $row->cost_usd);
        $this->assertTrue((bool) $row->fallback_used);
        $this->assertSame($user->id, $row->user_id);
    }

    public function test_a_single_leg_failure_records_that_leg_and_is_not_marked_as_a_fallback(): void
    {
        $this->actingAs(User::factory()->create());

        config([
            'ai.provider' => 'anthropic',
            'ai.models.quick' => 'claude-haiku-4-5-20251001',
            'ai.failover' => [],
        ]);

        $this->failEveryProvider();

        try {
            app(Client::class)->text('quick', 'S', 'U', ['operation' => 'grammar']);
        } catch (Throwable) {
            // expected
        }

        $row = AiModelUsage::firstOrFail();
        $this->assertSame('anthropic', $row->provider);
        $this->assertSame('claude-haiku-4-5-20251001', $row->model);
        $this->assertFalse((bool) $row->fallback_used);
    }

    public function test_each_failed_leg_is_logged_with_its_provider_and_model(): void
    {
        $this->actingAs(User::factory()->create());

        config([
            'ai.provider' => 'anthropic',
            'ai.models.quick' => 'claude-haiku-4-5-20251001',
            'ai.failover' => [['anthropic', 'gpt-4o']],
        ]);

        $this->failEveryProvider();

        $logged = [];

        Log::listen(function ($message) use (&$logged) {
            if ($message->level === 'warning') {
                $logged[] = $message->context;
            }
        });

        try {
            app(Client::class)->text('quick', 'S', 'U', ['operation' => 'grammar']);
        } catch (Throwable) {
            // expected
        }

        $this->assertCount(2, $logged, 'Both legs failed, so both must leave a warning.');
        $this->assertSame('anthropic', $logged[0]['provider']);
        $this->assertSame('claude-haiku-4-5-20251001', $logged[0]['model']);
        $this->assertSame('openai', $logged[1]['provider']);
        $this->assertSame('gpt-4o', $logged[1]['model']);
        $this->assertNotEmpty($logged[0]['message']);
    }

    public function test_structured_exhaustion_is_accounted_for_too(): void
    {
        $this->actingAs(User::factory()->create());

        config([
            'ai.provider' => 'anthropic',
            'ai.models.draft' => 'claude-sonnet-5',
            'ai.failover' => [],
        ]);

        $this->failEveryProvider();

        try {
            app(Client::class)->structured('draft', 'S', 'U', ['type' => 'object'], ['operation' => 'findings']);
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(1, AiModelUsage::count());
        $this->assertDatabaseHas('ai_model_usage', [
            'operation' => 'findings',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
        ]);
    }
}
