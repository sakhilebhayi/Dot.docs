<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping(2);
