<?php

namespace Tests\Feature\Guardian;

use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Checks\SchedulerCheck;
use App\Services\Guardian\Contracts\GuardianCheck;
use App\Services\Guardian\GuardianHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GuardianEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['guardian.token' => 'test-guardian-token']);
    }

    public function test_rejects_requests_without_a_bearer_token(): void
    {
        $this->getJson('/guardian/health')->assertUnauthorized();
    }

    public function test_rejects_requests_with_a_wrong_bearer_token(): void
    {
        $this->getJson('/guardian/health', ['Authorization' => 'Bearer wrong-token'])->assertUnauthorized();
    }

    public function test_refuses_to_serve_when_no_token_is_configured(): void
    {
        config(['guardian.token' => null]);

        $this->getJson('/guardian/health', ['Authorization' => 'Bearer anything'])->assertServiceUnavailable();
    }

    private function report(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/guardian/health', ['Authorization' => 'Bearer test-guardian-token']);
    }

    public function test_returns_the_guardian_contract_shape(): void
    {
        $response = $this->report();

        $response->assertOk()
            ->assertJsonPath('platform', 'dot-docs')
            ->assertJsonPath('contract', 'dot-guardian/v1')
            ->assertJsonStructure(['platform', 'contract', 'generated_at', 'status', 'checks']);

        $this->assertContains($response->json('status'), ['healthy', 'warning', 'critical', 'unknown']);

        foreach (['database', 'cache', 'scheduler', 'error_rate'] as $key) {
            $this->assertArrayHasKey($key, $response->json('checks'));
        }

        foreach ($response->json('checks') as $check) {
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('metrics', $check);
        }
    }

    public function test_a_missing_heartbeat_is_unknown_not_failed_and_a_fresh_one_is_healthy(): void
    {
        Cache::forget(SchedulerCheck::HEARTBEAT_KEY);
        $this->assertSame('unknown', $this->report()->json('checks.scheduler.status'));

        Cache::forever(SchedulerCheck::HEARTBEAT_KEY, now()->subSeconds(30)->toIso8601String());
        $this->assertSame('healthy', $this->report()->json('checks.scheduler.status'));

        Cache::forever(SchedulerCheck::HEARTBEAT_KEY, now()->subMinutes(20)->toIso8601String());
        $this->assertSame('critical', $this->report()->json('checks.scheduler.status'));
    }

    public function test_overall_status_is_the_worst_check_and_unknown_outranks_only_healthy(): void
    {
        $payload = (new GuardianHealthReport([
            $this->fakeCheck('alpha', CheckResult::healthy('fine')),
            $this->fakeCheck('bravo', CheckResult::critical('broken')),
            $this->fakeCheck('charlie', CheckResult::warning('degrading')),
        ]))->toArray();
        $this->assertSame('critical', $payload['status']);

        $payload = (new GuardianHealthReport([
            $this->fakeCheck('alpha', CheckResult::healthy('fine')),
            $this->fakeCheck('bravo', CheckResult::unknown('no signal')),
        ]))->toArray();
        $this->assertSame('unknown', $payload['status']);

        $payload = (new GuardianHealthReport([
            $this->fakeCheck('alpha', CheckResult::warning('late')),
            $this->fakeCheck('bravo', CheckResult::unknown('no signal')),
        ]))->toArray();
        $this->assertSame('warning', $payload['status']);
    }

    public function test_a_check_that_throws_becomes_unknown_instead_of_a_500(): void
    {
        $check = new class implements GuardianCheck
        {
            public function key(): string
            {
                return 'boom';
            }

            public function run(): CheckResult
            {
                throw new \RuntimeException('kaboom');
            }
        };

        $payload = (new GuardianHealthReport([$check]))->toArray();

        $this->assertSame('unknown', $payload['checks']['boom']['status']);
        $this->assertStringContainsString('kaboom', $payload['checks']['boom']['message']);
    }

    private function fakeCheck(string $key, CheckResult $result): GuardianCheck
    {
        return new class($key, $result) implements GuardianCheck
        {
            public function __construct(private readonly string $key, private readonly CheckResult $result) {}

            public function key(): string
            {
                return $this->key;
            }

            public function run(): CheckResult
            {
                return $this->result;
            }
        };
    }
}
