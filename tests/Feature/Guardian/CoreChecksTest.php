<?php

namespace Tests\Feature\Guardian;

use App\Jobs\QueueHeartbeat;
use App\Services\Guardian\Checks\CacheCheck;
use App\Services\Guardian\Checks\DatabaseCheck;
use App\Services\Guardian\Checks\ErrorRateCheck;
use App\Services\Guardian\Checks\QueueCheck;
use App\Services\Guardian\ErrorCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_check_is_healthy_when_the_database_answers(): void
    {
        $result = app(DatabaseCheck::class)->run();

        $this->assertSame('healthy', $result->status());
        $this->assertArrayHasKey('latency_ms', $result->toArray()['metrics']);
    }

    public function test_cache_check_round_trips_a_value(): void
    {
        $this->assertSame('healthy', app(CacheCheck::class)->run()->status());
    }

    public function test_error_rate_counts_reported_exceptions_and_escalates(): void
    {
        config(['guardian.errors.warning_per_hour' => 2, 'guardian.errors.critical_per_hour' => 4]);
        $counter = app(ErrorCounter::class);

        $this->assertSame('healthy', app(ErrorRateCheck::class)->run()->status());

        $counter->record(new \RuntimeException('one'));
        $counter->record(new \RuntimeException('two'));
        $result = app(ErrorRateCheck::class)->run();
        $this->assertSame('warning', $result->status());
        $this->assertSame('two', $result->toArray()['metrics']['last_error']['message']);

        $counter->record(new \RuntimeException('three'));
        $counter->record(new \RuntimeException('four'));
        $this->assertSame('critical', app(ErrorRateCheck::class)->run()->status());
    }

    public function test_reported_exceptions_reach_the_counter_through_the_handler(): void
    {
        report(new \RuntimeException('reported'));

        $this->assertSame(1, app(ErrorCounter::class)->countForHour(now()));
    }

    public function test_queue_check_is_unknown_with_an_empty_queue_and_no_worker_heartbeat(): void
    {
        Cache::forget(QueueHeartbeat::KEY);
        $result = app(QueueCheck::class)->run();

        $this->assertSame('unknown', $result->status());
        $this->assertSame(0, $result->toArray()['metrics']['pending_jobs']);
    }

    public function test_queue_check_is_healthy_with_an_empty_queue_and_a_live_worker(): void
    {
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(2)->toIso8601String());

        $this->assertSame('healthy', app(QueueCheck::class)->run()->status());
    }

    public function test_queue_check_warns_on_backlog_and_does_not_count_delayed_jobs(): void
    {
        config(['guardian.queue.pending_warning' => 2, 'guardian.queue.pending_critical' => 100]);
        Cache::forever(QueueHeartbeat::KEY, now()->toIso8601String());

        $this->insertJobs(5, availableIn: 600);
        $result = app(QueueCheck::class)->run()->toArray();
        $this->assertSame('healthy', $result['status']);
        $this->assertSame(5, $result['metrics']['delayed_jobs']);

        $this->insertJobs(3, availableIn: -10);
        $this->assertSame('warning', app(QueueCheck::class)->run()->status());
    }

    public function test_queue_check_goes_critical_when_the_worker_is_an_hour_silent(): void
    {
        Cache::forever(QueueHeartbeat::KEY, now()->subHours(2)->toIso8601String());

        $this->assertSame('critical', app(QueueCheck::class)->run()->status());
    }

    private function insertJobs(int $count, int $availableIn): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->addSeconds($availableIn)->getTimestamp(),
                'created_at' => now()->getTimestamp(),
            ]);
        }
    }
}
