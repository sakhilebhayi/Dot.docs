<?php

namespace App\Services\Guardian\Checks;

use App\Jobs\QueueHeartbeat;
use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Database-queue backlog, failure-rate and worker-liveness probe. The
 * queue is drained by a cron-driven worker, so a growing jobs table, an
 * old available_at, or a stale QueueHeartbeat all mean the drain stopped.
 * Jobs scheduled for the future are reported as delayed_jobs, never as
 * backlog.
 */
class QueueCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'queue';
    }

    public function run(): CheckResult
    {
        try {
            $now = now()->getTimestamp();
            $pending = DB::table('jobs')->where('available_at', '<=', $now)->count();
            $delayed = DB::table('jobs')->where('available_at', '>', $now)->count();
            $oldestAvailableAt = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');
            $oldestPendingSeconds = $oldestAvailableAt === null ? 0 : max(0, $now - (int) $oldestAvailableAt);
            $failedLastHour = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        } catch (\Throwable $e) {
            return CheckResult::unknown('Queue tables unreadable.', ['error' => $e::class]);
        }

        $workerAge = $this->workerHeartbeatAgeSeconds();

        $metrics = [
            'pending_jobs' => $pending,
            'delayed_jobs' => $delayed,
            'oldest_pending_seconds' => $oldestPendingSeconds,
            'failed_last_hour' => $failedLastHour,
            'worker_heartbeat_age_seconds' => $workerAge,
        ];

        if (
            $pending >= (int) config('guardian.queue.pending_critical', 500)
            || $oldestPendingSeconds >= (int) config('guardian.queue.oldest_critical_seconds', 900)
            || $failedLastHour >= (int) config('guardian.queue.failed_critical', 20)
            || ($workerAge !== null && $workerAge >= (int) config('guardian.queue.worker_critical_seconds', 3600))
        ) {
            return CheckResult::critical('Queue backlog, failure rate or worker liveness is critical.', $metrics);
        }

        if (
            $pending >= (int) config('guardian.queue.pending_warning', 100)
            || $oldestPendingSeconds >= (int) config('guardian.queue.oldest_warning_seconds', 300)
            || $failedLastHour >= (int) config('guardian.queue.failed_warning', 5)
            || ($workerAge !== null && $workerAge >= (int) config('guardian.queue.worker_warning_seconds', 900))
        ) {
            return CheckResult::warning('Queue backlog, failure rate or worker liveness is elevated.', $metrics);
        }

        if ($workerAge === null) {
            return CheckResult::unknown('Queue tables readable but no worker heartbeat recorded yet.', $metrics);
        }

        return CheckResult::healthy('Queue draining normally.', $metrics);
    }

    private function workerHeartbeatAgeSeconds(): ?int
    {
        $raw = Cache::get(QueueHeartbeat::KEY);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return max(0, (int) floor(Carbon::parse($raw)->diffInSeconds(now())));
        } catch (\Throwable) {
            return null;
        }
    }
}
