<?php

// Owner order 2026-11-27: until the whole Dot ecosystem is built, the shared host runs only scheduled work that
// sends email and notifications (plus queue workers that deliver queued mail and the heartbeats the watchdogs
// read). Everything else, nightly backups included, is paused. Restart: DOT_CRON_ESSENTIAL_ONLY=false, then
// `php artisan config:cache`. See App\Support\EssentialCron and `php artisan dot:cron-status`.
return [
    'essential_only' => (bool) env('DOT_CRON_ESSENTIAL_ONLY', true),

    // Kept on every platform: queue workers deliver queued email and notifications; heartbeats keep the
    // scheduler watchdogs quiet.
    'always' => [
        'queue:work',
        'heartbeat',
    ],

    // This platform's scheduled jobs that send email or notifications (matched against the command or name).
    'essential' => [],
];
