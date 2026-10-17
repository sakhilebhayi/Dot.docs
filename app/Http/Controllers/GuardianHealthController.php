<?php

namespace App\Http\Controllers;

use App\Services\Guardian\Checks\CacheCheck;
use App\Services\Guardian\Checks\DatabaseCheck;
use App\Services\Guardian\Checks\ErrorRateCheck;
use App\Services\Guardian\Checks\QueueCheck;
use App\Services\Guardian\Checks\SchedulerCheck;
use App\Services\Guardian\Contracts\GuardianCheck;
use App\Services\Guardian\GuardianHealthReport;
use Illuminate\Http\JsonResponse;

/**
 * Machine-readable production health report for the Dot.Brain guardian
 * and the hourly platform collector (dot-guardian/v1). Always answers 200
 * when authenticated -- the body is the signal, so the caller can tell
 * "endpoint unreachable" from "checks failing". The public /up and
 * /up/scheduler probes stay as they are; this one carries the detail.
 */
class GuardianHealthController extends Controller
{
    /** @var list<class-string<GuardianCheck>> */
    private const CHECKS = [
        DatabaseCheck::class,
        CacheCheck::class,
        SchedulerCheck::class,
        ErrorRateCheck::class,
        QueueCheck::class,
    ];

    public function __invoke(): JsonResponse
    {
        $checks = array_map(static fn (string $class): GuardianCheck => app($class), self::CHECKS);

        return response()->json((new GuardianHealthReport($checks))->toArray());
    }
}
