<?php

namespace App\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What `php artisan sync:report --check` prints: one screen of settings and
 * counts that says whether the recording pipeline is wired, so the owner can
 * tell "the pipeline is broken" from "nobody co-edited" and paste the answer
 * back.
 *
 * Settings and counts ONLY. It reads the one config switch, whether the table
 * and the route and the named limiter exist, the number of rows and the newest
 * DAY in the table (rows carry a day, not a time of day: that is by design),
 * the number of editor sessions, and whether the built page script knows the
 * sessions counter. It never prints an environment value, a key, a password, a
 * host, a name, a title or a document id, and it makes no request to anything.
 *
 * The things that could stop a real browser reaching the table, and where each
 * is covered, are listed in .ai/rules/app.md ("Co-editing metrics"): the Blade
 * literal (EditorSyncWiringTest), the token in the JSON body and the declared
 * JSON type (EditorSyncWiringTest), the route's middleware (printed here), the
 * limiter name (checked here), the URL helper (the same route() the sync and
 * autosave URLs use).
 */
class SyncMetricsCheck
{
    private const ROUTE = 'documents.sync-metrics';

    private const LIMITER = 'sync-metrics';

    /** A string only the page script of this version contains. */
    private const SESSIONS_MARK = 'editor_open';

    /**
     * @return array{ok: bool, lines: list<string>}
     */
    public function run(): array
    {
        $lines = [];
        $problems = [];

        $on = (bool) config('docs.sync_metrics');
        $cached = app()->configurationIsCached() ? 'yes (a changed .env needs php artisan config:clear)' : 'no';
        $lines[] = 'Collection switched on (docs.sync_metrics): '.($on ? 'yes' : 'NO (set DOCS_SYNC_METRICS=true)');
        $lines[] = "Config cached: {$cached}";
        if (! $on) {
            $problems[] = 'collection is switched off';
        }

        $rows = 0;
        $sessions = 0;
        $newest = null;
        if ($this->tableExists()) {
            $driver = DB::connection()->getDriverName();
            $rows = (int) DB::table('sync_metrics')->count();
            $newest = DB::table('sync_metrics')->max('day');
            $sessions = (int) DB::table('sync_metrics')->where('kind', 'editor_open')->sum('count');
            $lines[] = "Table sync_metrics: present ({$driver})";
            $lines[] = "Rows: {$rows}".($newest === null ? '' : " (newest day with a row: {$newest}; rows carry a day, not a time)");
            $lines[] = "Editor sessions in the table, all days: {$sessions}";
        } else {
            $lines[] = 'Table sync_metrics: MISSING (run php artisan migrate)';
            $problems[] = 'the table is missing';
        }

        $route = app('router')->getRoutes()->getByName(self::ROUTE);
        if ($route === null) {
            $lines[] = 'Route '.self::ROUTE.': MISSING';
            $problems[] = 'the route is missing';
        } else {
            $lines[] = 'Route '.self::ROUTE.': registered, '.implode('|', array_diff($route->methods(), ['HEAD'])).' /'.ltrim($route->uri(), '/');
            $lines[] = 'Route middleware: '.implode(', ', array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware()));
        }

        $limit = $this->limit();
        if ($limit === null) {
            $lines[] = 'Rate limiter '.self::LIMITER.': MISSING';
            $problems[] = 'the rate limiter is missing';
        } else {
            $lines[] = 'Rate limiter '.self::LIMITER.': defined, '.$limit->maxAttempts.' a minute per person and document';
        }

        $bundle = $this->bundle();
        $lines[] = 'Page bundle: '.$bundle['text'];
        if ($bundle['problem']) {
            $problems[] = $bundle['text'];
        }

        $lines[] = 'App URL scheme: '.(parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'none').' (pages build their request URLs with route() from the address the browser used, as the sync and autosave URLs do)';

        $lines[] = $this->verdict($problems, $rows, $sessions);

        return ['ok' => $problems === [], 'lines' => $lines];
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('sync_metrics');
        } catch (Throwable) {
            return false;
        }
    }

    private function limit(): ?Limit
    {
        try {
            $callback = app(RateLimiter::class)->limiter(self::LIMITER);
            if ($callback === null) {
                return null;
            }
            $limit = $callback(Request::create('/'));

            return is_array($limit) ? ($limit[0] ?? null) : $limit;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the page script the manifest points to knows the sessions counter. A script from before this change
     * (or a build that did not reach the folder the server reads) does not, and then no session can ever be sent.
     *
     * @return array{text: string, problem: bool}
     */
    private function bundle(): array
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            return ['text' => 'no build manifest found (public/build/manifest.json)', 'problem' => true];
        }

        try {
            $entries = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['text' => 'the build manifest cannot be read', 'problem' => true];
        }

        foreach ((array) $entries as $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            if (! is_string($file) || ! str_ends_with($file, '.js')) {
                continue;
            }
            $path = public_path('build/'.$file);
            if (is_file($path) && str_contains((string) file_get_contents($path), self::SESSIONS_MARK)) {
                return ['text' => 'has the sessions counter ('.self::SESSIONS_MARK.')', 'problem' => false];
            }
        }

        return ['text' => 'does NOT have the sessions counter ('.self::SESSIONS_MARK.'): the built page script is older than this change', 'problem' => true];
    }

    /** @param list<string> $problems */
    private function verdict(array $problems, int $rows, int $sessions): string
    {
        if ($problems !== []) {
            return 'Verdict: PROBLEM - '.implode('; ', $problems).'.';
        }
        if ($sessions > 0) {
            return "Verdict: recording works ({$sessions} editor ".($sessions === 1 ? 'session' : 'sessions').' so far).';
        }
        if ($rows > 0) {
            return 'Verdict: rows exist but no editor sessions: the page people have open is older than the sessions counter; reload the editor page, then check again.';
        }

        return 'Verdict: the server side is wired and the table is empty. Open a document, wait two minutes and run this again; if it is still empty the page is not reaching the server (look for a POST to sync-metrics in the browser network tab).';
    }
}
