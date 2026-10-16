<?php

namespace App\Http\Controllers;

use App\Jobs\QueueHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Public liveness probe for the cron-driven scheduler and, where the platform runs one, its queue worker.
 *
 * Same contract as Dot.Central's /up/scheduler so one watchdog shape fits
 * every Dot platform: HTTP 200 + "ok" while every heartbeat is fresh,
 * HTTP 503 + "stale" otherwise. Each age is seconds since that heartbeat
 * was last written; null means none has ever been recorded (first deploy,
 * or the cache store was reset). .github/workflows/scheduler-watchdog.yml
 * asks this every 15 minutes from GitHub and fails loudly on anything but
 * 200, which is how a dead crontab reaches a person.
 */
class SchedulerProbeController extends Controller
{
    public const SCHEDULER_KEY = 'dot.scheduler_heartbeat';

    /** The heartbeat is written every minute; three missed minutes is stale. */
    public const SCHEDULER_STALE_SECONDS = 180;

    /** QueueHeartbeat is dispatched every five minutes; fifteen without one is stale. */
    public const QUEUE_STALE_SECONDS = 900;

    public function __invoke(): JsonResponse
    {
        $scheduler = $this->age(self::SCHEDULER_KEY);
        $queue = $this->age(QueueHeartbeat::KEY);

        $healthy = $scheduler !== null && $scheduler <= self::SCHEDULER_STALE_SECONDS
            && $queue !== null && $queue <= self::QUEUE_STALE_SECONDS;

        return response()->json([
            'status' => $healthy ? 'ok' : 'stale',
            'scheduler' => ['age_seconds' => $scheduler, 'stale_after' => self::SCHEDULER_STALE_SECONDS],
            'queue' => ['age_seconds' => $queue, 'stale_after' => self::QUEUE_STALE_SECONDS],
        ], $healthy ? 200 : 503);
    }

    private function age(string $key): ?int
    {
        $value = Cache::get($key);

        return is_string($value) ? max(0, (int) floor(Carbon::parse($value)->diffInSeconds(now()))) : null;
    }
}
