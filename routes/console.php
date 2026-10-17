<?php

use App\Console\Commands\BackupSqliteDatabase;
use App\Http\Controllers\SchedulerProbeController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// This shared host has no sudo/systemd access, so a persistent queue
// worker (bootstrap.yml's deploy/queue-worker.service) can never actually
// be installed here. Running the queue in short cron-driven bursts is the
// standard Laravel answer for shared hosting without daemon access:
// --stop-when-empty exits as soon as the queue drains instead of idling,
// and withoutOverlapping() stops a slow-draining minute from starting a
// second worker on top of it. The lock expires after 2 minutes, not the
// 24-hour default: a shared host can kill a process mid-run without it
// ever releasing its lock, and a stuck 24h lock would silently stop every
// queued email for a day.
//
// --queue=mail,default drains email first, so a backlog of anything else
// on the default queue can never hold a notification email back.
Schedule::command('queue:work --queue=mail,default --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping(2);

// Nothing else ever removes a failed job, and each one carries a full stack
// trace in the same SQLite file as the documents.
Schedule::command('queue:prune-failed --hours=168')->daily();

// Liveness of the platform itself. /up/scheduler reports this heartbeat's
// age and answers 503 once it is older than
// SchedulerProbeController::SCHEDULER_STALE_SECONDS; the Scheduler Watchdog
// workflow turns that into a failed (emailed) run every 15 minutes. Same
// shape as Dot.Central's probe so every Dot platform is watched alike.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat');

// Nightly copy of the SQLite file (documents, revisions and comments exist nowhere else). The five SQLite-backed Dot apps
// share one host, so each one backs up at its own minute. No-op on other
// database drivers. See docs/superpowers or the deploy README for restore.
Schedule::command(BackupSqliteDatabase::class)->dailyAt('02:35')->onOneServer();
