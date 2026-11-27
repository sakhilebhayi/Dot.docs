<?php

use App\Console\Commands\BackupSqliteDatabase;
use App\Console\Commands\PurgeDeletedAccountDocuments;
use App\Http\Controllers\SchedulerProbeController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
// trace in the same SQLite file as the documents. Kept 30 days (R2-4 / E-02):
// an email given up on can still be found and resent after a long weekend.
Schedule::command('queue:prune-failed --hours=720')->daily();

// The co-editing counters (App\Services\SyncMetrics) are one row per document,
// person, day and kind: small, but nothing else ever deletes them. 120 days is
// long enough to read a trend and short enough to stay a few thousand rows.
Schedule::command('sync:prune-metrics')->daily();

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

// EA-2: a deleted account's personal documents stay recoverable for 30 days,
// then go for good. After the nightly backup, so the last copy before a purge
// still holds them.
Schedule::command(PurgeDeletedAccountDocuments::class)->dailyAt('03:15')->onOneServer();

// EA-3 / E-07: the bell keeps 90 days of read notices and 180 days of unread
// ones; older rows only show stale titles of documents that may be gone.
Schedule::call(function () {
    DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete();
    DB::table('notifications')->where('created_at', '<', now()->subDays(180))->delete();
})->dailyAt('03:30')->name('prune-bell-notifications')->onOneServer();

// EA-5 / P-12: a tab's presence row is swept when that document is polled
// again; a document nobody reopens kept its rows for ever.
Schedule::call(fn () => DB::table('document_presences')->where('last_seen_at', '<', now()->subDay())->delete())
    ->dailyAt('03:40')->name('prune-stale-presences')->onOneServer();

// R2-5 / P-02: old automatic versions thinned (config docs.versions); after the nightly backup, so the last copy
// before a thinning still has them.
Schedule::command('documents:thin-versions')->dailyAt('03:20')->onOneServer();

// R2-5 / P-12: view records older than a year go (config docs.audit). Only `document.viewed`: every other audit row is
// kept. A query, not the model: AuditLog refuses deletes so that nothing in the app can quietly change the record.
Schedule::call(fn () => DB::table('audit_logs')
    ->where('action', 'document.viewed')
    ->where('created_at', '<', now()->subDays(max(30, (int) config('docs.audit.view_record_days', 365))))
    ->delete())->dailyAt('03:25')->name('prune-view-records')->onOneServer();

// R2-4 / E-10: the sent-mail record only has to outlive any retry; 90 days is plenty to answer "did it go?".
Schedule::call(fn () => DB::table('sent_mails')->where('sent_at', '<', now()->subDays(90))->delete())
    ->dailyAt('03:45')->name('prune-sent-mails')->onOneServer();

// R2-1 / S-07: API tokens expire after config('sanctum.expiration'); their rows go a day later.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:50')->onOneServer();
