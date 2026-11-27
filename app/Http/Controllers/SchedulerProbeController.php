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

    /**
     * The nightly backup (02:35) is overdue after 26 hours (EA-6 / O-05). A
     * failed backup used to tell nobody; now the watchdog's 503 does.
     */
    public const BACKUP_STALE_SECONDS = 26 * 3600;

    public function __invoke(): JsonResponse
    {
        $scheduler = $this->age(self::SCHEDULER_KEY);
        $queue = $this->age(QueueHeartbeat::KEY);
        $backup = $this->backupAge();

        $healthy = $scheduler !== null && $scheduler <= self::SCHEDULER_STALE_SECONDS
            && $queue !== null && $queue <= self::QUEUE_STALE_SECONDS
            && (! $this->backupsExpected() || ($backup !== null && $backup <= self::BACKUP_STALE_SECONDS));

        return response()->json([
            'status' => $healthy ? 'ok' : 'stale',
            'scheduler' => ['age_seconds' => $scheduler, 'stale_after' => self::SCHEDULER_STALE_SECONDS],
            'queue' => ['age_seconds' => $queue, 'stale_after' => self::QUEUE_STALE_SECONDS],
            // Age only: never a file name, size or path.
            'backup' => ['age_seconds' => $backup, 'stale_after' => self::BACKUP_STALE_SECONDS],
        ], $healthy ? 200 : 503);
    }

    /** Only a real SQLite file is backed up (BackupSqliteDatabase); anything else has nothing to be late with. */
    private function backupsExpected(): bool
    {
        $file = (string) config('database.connections.sqlite.database');

        return config('database.default') === 'sqlite' && $file !== ':memory:' && is_file($file);
    }

    /** Seconds since the newest database backup was written, or null when there is none. */
    private function backupAge(): ?int
    {
        $files = glob(storage_path('backups/doc-*.sqlite*')) ?: [];
        $newest = $files === [] ? null : max(array_map('filemtime', $files));

        return $newest === null ? null : max(0, time() - (int) $newest);
    }

    private function age(string $key): ?int
    {
        $value = Cache::get($key);

        return is_string($value) ? max(0, (int) floor(Carbon::parse($value)->diffInSeconds(now()))) : null;
    }
}
