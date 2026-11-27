<?php

namespace Tests\Feature;

use App\Http\Controllers\SchedulerProbeController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulerProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_is_ok_while_the_heartbeats_are_fresh_and_503_otherwise(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subSeconds(40)->toIso8601String());
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(4)->toIso8601String());

        $this->getJson('/up/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('scheduler.age_seconds', 40)
            ->assertJsonPath('scheduler.stale_after', SchedulerProbeController::SCHEDULER_STALE_SECONDS);

        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subMinutes(10)->toIso8601String());
        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('status', 'stale');

        // A live scheduler cannot vouch for a dead worker.
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subSeconds(5)->toIso8601String());
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(20)->toIso8601String());
        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('queue.age_seconds', 1200);

        Cache::forget(SchedulerProbeController::SCHEDULER_KEY);
        Cache::forget(QueueHeartbeat::KEY);
        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('scheduler.age_seconds', null);
    }

    /** R2-9 / E-02: an email given up on reaches the owner through the watchdog, not through the mail server. */
    public function test_an_email_given_up_on_in_the_last_hour_turns_the_probe_503(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subSeconds(40)->toIso8601String());
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(4)->toIso8601String());
        $this->get('/up/scheduler')->assertOk();

        $row = fn (string $queue, $at) => DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => $queue,
            'payload' => '{}', 'exception' => 'TransportException', 'failed_at' => $at,
        ]);
        $row('default', now());
        $row('mail', now()->subHours(2));
        $this->get('/up/scheduler')->assertOk()->assertJsonPath('mail.given_up_last_hour', 0);

        $row('mail', now()->subMinutes(5));
        $this->get('/up/scheduler')->assertStatus(503)->assertJsonPath('mail.given_up_last_hour', 1);
    }

    /**
     * R2-11: while essential-only mode has paused the nightly backup, an old backup does not turn the probe 503 - it
     * would stay red for good and hide real failures - and the moment the pause ends, or the backup is made essential,
     * the 26-hour rule is back.
     */
    public function test_a_paused_backup_is_reported_as_paused_not_stale(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subSeconds(40)->toIso8601String());
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(4)->toIso8601String());

        // A real SQLite file and a backup 27 hours old, as production will be tomorrow morning.
        $db = storage_path('framework/testing/probe-'.getmypid().'.sqlite');
        @mkdir(dirname($db), 0777, true);
        touch($db);
        @mkdir(storage_path('backups'), 0777, true);
        $old = storage_path('backups/doc-probe-test-'.getmypid().'.sqlite');
        touch($old, time() - 27 * 3600);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $db]);

        try {
            config(['dot_cron.essential_only' => true, 'dot_cron.essential' => []]);
            $this->get('/up/scheduler')->assertOk()->assertJsonPath('backup.paused', true);

            config(['dot_cron.essential' => ['backup-database']]);
            $this->get('/up/scheduler')->assertStatus(503)->assertJsonPath('backup.paused', false);

            config(['dot_cron.essential_only' => false, 'dot_cron.essential' => []]);
            $this->get('/up/scheduler')->assertStatus(503)->assertJsonPath('backup.paused', false);
        } finally {
            @unlink($old);
            @unlink($db);
        }
    }

    public function test_probe_needs_no_authentication(): void
    {
        $this->getJson('/up/scheduler')->assertStatus(503);
    }

    public function test_queue_heartbeat_job_records_the_time_it_ran(): void
    {
        Cache::forget(QueueHeartbeat::KEY);
        (new QueueHeartbeat)->handle();
        $this->assertNotNull(Cache::get(QueueHeartbeat::KEY));
    }
}
