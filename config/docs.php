<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Co-editing metrics
    |--------------------------------------------------------------------------
    |
    | Counts (never text) of the events the editor's sync layer meets: editor
    | sessions opened (the denominator), merges, what a merge could not keep,
    | conflict notices, failed saves, read only downgrades and how many people
    | had the document open together. The page flushes them about 5 s after it
    | opens, then once a minute and when it is left, to
    | POST /documents/{uuid}/sync-metrics; `php artisan sync:report` reads them
    | and `php artisan sync:report --check` says whether they arrive. Off: the page collects nothing and
    | the endpoint answers 204 and stores nothing.
    |
    */

    'sync_metrics' => (bool) env('DOCS_SYNC_METRICS', true),

    /*
    |--------------------------------------------------------------------------
    | Retention (R2-5 / P-02, P-12)
    |--------------------------------------------------------------------------
    |
    | Version history: every version someone named, every restore and the
    | newest version of each document are kept for ever. Automatic versions are
    | all kept for `keep_all_days`; older than that, the last one of each day is
    | kept until `daily_until_days`, and after that the last one of each week.
    | `php artisan documents:thin-versions` runs nightly; --dry-run says what it
    | would remove.
    |
    | View records: opening a document is recorded once an hour per person and
    | document (`view_record_minutes`), and `document.viewed` rows older than
    | `view_record_days` are removed nightly. Every other audit action is kept.
    |
    */

    'versions' => [
        'keep_all_days' => (int) env('DOCS_VERSIONS_KEEP_ALL_DAYS', 7),
        'daily_until_days' => (int) env('DOCS_VERSIONS_DAILY_UNTIL_DAYS', 90),
    ],

    'audit' => [
        'view_record_minutes' => (int) env('DOCS_AUDIT_VIEW_RECORD_MINUTES', 60),
        'view_record_days' => (int) env('DOCS_AUDIT_VIEW_RECORD_DAYS', 365),
    ],

];
