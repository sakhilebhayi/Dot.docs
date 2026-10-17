<?php

namespace App\Services\Guardian\Checks;

use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use App\Services\Guardian\ErrorCounter;

/** Application exception-rate probe (reads ErrorCounter's hourly buckets). */
class ErrorRateCheck implements GuardianCheck
{
    public function __construct(private readonly ErrorCounter $counter) {}

    public function key(): string
    {
        return 'error_rate';
    }

    public function run(): CheckResult
    {
        $thisHour = $this->counter->countForHour(now());

        $metrics = [
            'errors_this_hour' => $thisHour,
            'errors_prev_hour' => $this->counter->countForHour(now()->subHour()),
            'last_error' => $this->counter->lastError(),
        ];

        if ($thisHour >= (int) config('guardian.errors.critical_per_hour', 50)) {
            return CheckResult::critical('Exception rate is critical.', $metrics);
        }

        if ($thisHour >= (int) config('guardian.errors.warning_per_hour', 10)) {
            return CheckResult::warning('Exception rate is elevated.', $metrics);
        }

        return CheckResult::healthy('Exception rate normal.', $metrics);
    }
}
