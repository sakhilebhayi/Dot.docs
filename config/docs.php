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

];
