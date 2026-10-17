<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guardian Service Token
    |--------------------------------------------------------------------------
    | Bearer token the Dot.Brain guardian presents to read /guardian/health.
    | The endpoint refuses to serve at all (503) when this is unset, so a
    | misconfigured deployment fails closed instead of exposing operational
    | detail. Rotate by changing the env value on both sides
    | (.github/workflows/set-guardian-token.yml on this side).
    */

    'token' => env('GUARDIAN_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Check Thresholds
    |--------------------------------------------------------------------------
    | Boundaries between healthy / warning / critical. Same defaults as
    | Dot.Mines (database queue drained from the scheduler on shared hosting).
    */

    'queue' => [
        'pending_warning' => (int) env('GUARDIAN_QUEUE_PENDING_WARNING', 100),
        'pending_critical' => (int) env('GUARDIAN_QUEUE_PENDING_CRITICAL', 500),
        'oldest_warning_seconds' => (int) env('GUARDIAN_QUEUE_OLDEST_WARNING', 300),
        'oldest_critical_seconds' => (int) env('GUARDIAN_QUEUE_OLDEST_CRITICAL', 900),
        'failed_warning' => (int) env('GUARDIAN_QUEUE_FAILED_WARNING', 5),
        'failed_critical' => (int) env('GUARDIAN_QUEUE_FAILED_CRITICAL', 20),
        'worker_warning_seconds' => (int) env('GUARDIAN_QUEUE_WORKER_WARNING', 900),
        'worker_critical_seconds' => (int) env('GUARDIAN_QUEUE_WORKER_CRITICAL', 3600),
    ],

    'scheduler' => [
        'warning_seconds' => (int) env('GUARDIAN_SCHEDULER_WARNING', 300),
        'critical_seconds' => (int) env('GUARDIAN_SCHEDULER_CRITICAL', 900),
    ],

    'errors' => [
        'warning_per_hour' => (int) env('GUARDIAN_ERRORS_WARNING', 10),
        'critical_per_hour' => (int) env('GUARDIAN_ERRORS_CRITICAL', 50),
    ],

    // Data-freshness checks express staleness as a multiple of the source's
    // own cadence: beyond 2x the data is late, beyond 4x it has stopped.
    'freshness' => [
        'warning_multiplier' => (float) env('GUARDIAN_FRESHNESS_WARNING_X', 2.0),
        'critical_multiplier' => (float) env('GUARDIAN_FRESHNESS_CRITICAL_X', 4.0),
    ],

];
