<?php

namespace Tests\Feature\Ai;

use App\Ai\AiUnavailable;
use App\Ai\Client;
use App\Ai\Guard;
use App\Models\AiModelUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * The spend guard: a kill switch and daily token budgets for REAL model calls.
 * Nothing here reaches a provider: Http::preventStrayRequests() proves it.
 */
class GuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'ai.provider' => 'anthropic',
            'ai.enabled' => true,
            'ai.daily_token_budget' => 1000,
            'ai.daily_token_budget_per_user' => 400,
            'ai.models.quick' => 'claude-haiku-4-5-20251001',
            'ai.failover' => [],
        ]);
    }

    private function usage(int $tokens, ?int $userId = null, string $provider = 'anthropic', ?\DateTimeInterface $at = null): void
    {
        AiModelUsage::create([
            'user_id' => $userId,
            'provider' => $provider,
            'model' => 'claude-haiku-4-5-20251001',
            'operation' => 'test',
            'input_tokens' => intdiv($tokens, 2),
            'output_tokens' => $tokens - intdiv($tokens, 2),
            'created_at' => $at ?? now(),
        ]);
    }

    private function askModel(?int $userId = null): void
    {
        app(Client::class)->text('quick', 'system', 'user', ['user_id' => $userId]);
    }

    private function refusalReason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (AiUnavailable $e) {
            return $e->reason;
        } catch (Throwable) {
            return 'allowed-then-failed-at-the-provider';
        }

        return 'allowed';
    }

    public function test_the_kill_switch_refuses_a_real_call_without_touching_the_network_or_the_usage_table(): void
    {
        config(['ai.enabled' => false]);

        $this->assertSame(AiUnavailable::SWITCHED_OFF, $this->refusalReason(fn () => $this->askModel()));

        Http::assertNothingSent();
        $this->assertSame(0, AiModelUsage::count());
    }

    public function test_the_mock_provider_ignores_the_switch_and_the_budgets(): void
    {
        config(['ai.provider' => 'mock', 'ai.enabled' => false, 'ai.daily_token_budget' => 1]);
        $this->usage(5000, provider: 'anthropic');

        $result = app(Client::class)->text('quick', 'system', 'user');

        $this->assertSame('mock', $result->provider);
    }

    public function test_the_global_daily_budget_stops_real_calls_once_it_is_used_up(): void
    {
        $this->usage(1000);

        $this->assertSame(AiUnavailable::GLOBAL_BUDGET, $this->refusalReason(fn () => $this->askModel()));
        Http::assertNothingSent();
    }

    public function test_under_the_budget_a_call_goes_through_to_the_provider(): void
    {
        Http::fake(['*' => Http::response('{"error":{"type":"api_error","message":"upstream down"}}', 500)]);
        $this->usage(999);

        $this->assertSame('allowed-then-failed-at-the-provider', $this->refusalReason(fn () => $this->askModel()));
        Http::assertSentCount(1);
    }

    public function test_the_per_user_budget_blocks_that_user_and_nobody_else(): void
    {
        $heavy = User::factory()->create();
        $other = User::factory()->create();
        $this->usage(400, $heavy->id);
        Http::fake(['*' => Http::response('{"error":{"type":"api_error","message":"x"}}', 500)]);

        $this->assertSame(AiUnavailable::USER_BUDGET, $this->refusalReason(fn () => $this->askModel($heavy->id)));
        $this->assertSame('allowed-then-failed-at-the-provider', $this->refusalReason(fn () => $this->askModel($other->id)));
    }

    public function test_only_todays_real_calls_count(): void
    {
        $this->usage(5000, provider: 'anthropic', at: now()->utc()->subDay()->startOfDay());
        $this->usage(5000, provider: 'mock');
        $this->usage(5000, provider: 'none');

        $this->assertSame(0, app(Guard::class)->tokensToday());

        $this->usage(123);
        $this->assertSame(123, app(Guard::class)->tokensToday());
    }

    public function test_a_budget_of_zero_means_no_cap(): void
    {
        config(['ai.daily_token_budget' => 0, 'ai.daily_token_budget_per_user' => 0]);
        $this->usage(10_000_000);
        Http::fake(['*' => Http::response('{"error":{"type":"api_error","message":"x"}}', 500)]);

        $this->assertSame('allowed-then-failed-at-the-provider', $this->refusalReason(fn () => $this->askModel(1)));
    }

    public function test_structured_calls_are_guarded_too(): void
    {
        config(['ai.enabled' => false]);

        $reason = $this->refusalReason(fn () => app(Client::class)->structured('quick', 's', 'u', ['type' => 'object']));

        $this->assertSame(AiUnavailable::SWITCHED_OFF, $reason);
        Http::assertNothingSent();
    }

    public function test_the_shipped_defaults_are_on_and_capped(): void
    {
        $config = (require base_path('config/ai.php'));

        $this->assertTrue($config['enabled']);
        $this->assertGreaterThan(0, $config['daily_token_budget']);
        $this->assertGreaterThan(0, $config['daily_token_budget_per_user']);
        $this->assertLessThan($config['daily_token_budget'], $config['daily_token_budget_per_user']);
    }
}
