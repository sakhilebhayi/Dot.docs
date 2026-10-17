<?php

namespace App\Services\Guardian\Checks;

use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cron scheduler proof-of-life: reads the minute heartbeat that
 * routes/console.php writes (the same key /up/scheduler reports).
 */
class SchedulerCheck implements GuardianCheck
{
    public const HEARTBEAT_KEY = 'dot.scheduler_heartbeat';

    public function key(): string
    {
        return 'scheduler';
    }

    public function run(): CheckResult
    {
        $ageSeconds = self::heartbeatAgeSeconds();

        if ($ageSeconds === null) {
            return CheckResult::unknown('No scheduler heartbeat recorded yet.');
        }

        $metrics = ['heartbeat_age_seconds' => $ageSeconds];

        if ($ageSeconds >= (int) config('guardian.scheduler.critical_seconds', 900)) {
            return CheckResult::critical('Scheduler heartbeat is very stale -- cron appears down.', $metrics);
        }

        if ($ageSeconds >= (int) config('guardian.scheduler.warning_seconds', 300)) {
            return CheckResult::warning('Scheduler heartbeat is stale.', $metrics);
        }

        return CheckResult::healthy('Scheduler beating.', $metrics);
    }

    public static function heartbeatAgeSeconds(): ?int
    {
        $raw = Cache::get(self::HEARTBEAT_KEY);

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
