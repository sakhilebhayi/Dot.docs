<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Co-editing metrics
    |--------------------------------------------------------------------------
    |
    | Counts (never text) of the events the editor's sync layer meets: merges,
    | what a merge could not keep, conflict notices, failed saves, read only
    | downgrades and how many people had the document open together. The page
    | flushes them once a minute to POST /documents/{uuid}/sync-metrics and
    | `php artisan sync:report` reads them. Off: the page collects nothing and
    | the endpoint answers 204 and stores nothing.
    |
    */

    'sync_metrics' => (bool) env('DOCS_SYNC_METRICS', true),

];
