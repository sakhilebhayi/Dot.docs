<?php

namespace App\Console\Commands;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Enterprise audit EA-9 (M-01, P-14): measure Dot.docs under load.
 *
 *   php artisan doc:bench seed --documents=10000
 *   php artisan doc:bench run --typists=1,3,10 --out=storage/bench.json
 *
 * `seed` adds a "Benchmark" company (one owner, ten writers, all
 *
 * @bench.invalid, no password anyone knows) and tops its documents up to
 * --documents. `run` drives the real HTTP endpoints at --url (APP_URL by
 * default): K writers autosaving at once with their sync polls, then the
 * list, search, history and exports. Every request goes through the web
 * server, PHP, the middleware and the one SQLite file, so on a staging copy
 * of the host it measures the host. Signed-in sessions are minted here with
 * the app's own key: no password and no central sign-in round trip.
 *
 * It never runs in production: it writes thousands of rows and its load
 * competes with real people for the single write lock.
 */
class Benchmark extends Command
{
    protected $signature = 'doc:bench
        {action : seed or run}
        {--documents=1000 : seed: how many numbered Benchmark documents there should be (plus one history and ten typing documents)}
        {--typists=1,3,10 : run: the writer counts to measure, comma separated}
        {--rounds=30 : run: autosave rounds per writer count}
        {--interval=1500 : run: milliseconds between one writer\'s saves (the editor saves about every 1.5 s while typing)}
        {--url= : run: the site to drive (default APP_URL)}
        {--out= : run: also write the results as JSON to this file}';

    protected $description = 'EA-9: seed a benchmark company and load-test saves, polls, list, search, history and export (never in production)';

    private const DOMAIN = 'bench.invalid';

    private const WRITERS = 10;

    private const KEYWORD = 'zebrafinch';

    public function handle(DocumentStore $store): int
    {
        if (app()->environment('production')) {
            $this->error('doc:bench never runs in production. Use a staging copy (APP_ENV=staging) or a local one.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed' => $this->seed($store),
            'run' => $this->runBench(),
            default => $this->invalidAction(),
        };
    }

    private function invalidAction(): int
    {
        $this->error('The action is seed or run.');

        return self::INVALID;
    }

    // ── seed ────────────────────────────────────────────────────────────

    private function seed(DocumentStore $store): int
    {
        [$owner, $team, $writers] = $this->company();

        $target = max(1, (int) $this->option('documents'));
        // The numbered documents only: the history and typing documents
        // come on top of --documents.
        $have = Document::where('team_id', $team->id)->where('title', 'like', 'Bench %')
            ->where('title', '!=', 'Bench history')->where('title', 'not like', 'Bench typing %')->count();
        $started = microtime(true);

        if ($have < $target) {
            $this->info('Adding '.($target - $have)." documents (have {$have}, want {$target})…");
            $bar = $this->output->createProgressBar($target - $have);

            for ($i = $have; $i < $target; $i++) {
                $author = $i % 3 === 0 ? $owner : $writers[$i % self::WRITERS];
                $store->create($author, "Bench {$i} ".$this->words($i, 4), $this->body($i, 12));
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        // One document with a long history, for the history page.
        $history = Document::where('team_id', $team->id)->where('title', 'Bench history')->first();
        if ($history === null) {
            $history = $store->create($owner, 'Bench history', $this->body(1, 30));
            for ($v = 0; $v < 300; $v++) {
                $history = $store->save($history->fresh(), $this->body($v + 2, 30), $owner, ['version' => 'named', 'label' => "Bench v{$v}"]);
            }
        }

        // One working document per writer, for the typing rounds.
        foreach ($writers as $n => $writer) {
            if (! Document::where('owner_id', $writer->id)->where('title', "Bench typing {$n}")->exists()) {
                $store->create($writer, "Bench typing {$n}", $this->body($n, 60));
            }
        }

        $this->info(sprintf(
            'Benchmark company: %d documents, %d versions on the history document (%.1f s).',
            Document::where('team_id', $team->id)->count(),
            $history->versions()->count(),
            microtime(true) - $started,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: User, 1: Team, 2: list<User>}
     */
    private function company(): array
    {
        $make = function (string $email, string $name): User {
            return User::firstOrCreate(['email' => $email], [
                'name' => $name,
                // Random and thrown away: nobody signs in as a benchmark user.
                'password' => Hash::make(Str::random(40)),
            ])->forceFill(['email_verified_at' => now()]);
        };

        $owner = $make('owner@'.self::DOMAIN, 'Bench Owner');
        $owner->save();

        $team = Team::where('user_id', $owner->id)->where('name', 'Benchmark')->first()
            ?? Team::forceCreate(['user_id' => $owner->id, 'name' => 'Benchmark', 'personal_team' => false]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $writers = [];
        for ($n = 0; $n < self::WRITERS; $n++) {
            $writer = $make("writer-{$n}@".self::DOMAIN, "Bench Writer {$n}");
            $writer->forceFill(['current_team_id' => $team->id])->save();
            if (! $team->users()->whereKey($writer->id)->exists()) {
                $team->users()->attach($writer, ['role' => 'editor']);
            }
            $writers[] = $writer;
        }

        return [$owner, $team, $writers];
    }

    /** A deterministic document of $paragraphs paragraphs; every 100th one carries the search keyword. */
    private function body(int $seed, int $paragraphs): array
    {
        $blocks = [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Report '.$this->words($seed, 3)]]]];

        for ($p = 0; $p < $paragraphs; $p++) {
            $text = $this->words($seed * 31 + $p, 60);
            if ($p === 0 && $seed % 100 === 0) {
                $text .= ' '.self::KEYWORD;
            }
            $blocks[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        }

        return ['type' => 'doc', 'content' => $blocks];
    }

    private function words(int $seed, int $count): string
    {
        static $lexicon = ['board', 'quarter', 'revenue', 'supplier', 'contract', 'review', 'staff', 'policy', 'budget',
            'customer', 'audit', 'risk', 'delivery', 'schedule', 'invoice', 'margin', 'region', 'target', 'meeting',
            'minutes', 'approval', 'tender', 'safety', 'training', 'payroll', 'forecast', 'pricing', 'stock', 'branch'];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $lexicon[($seed * 7 + $i * 13 + intdiv($i, 5)) % count($lexicon)];
        }

        return implode(' ', $out);
    }

    // ── run ─────────────────────────────────────────────────────────────

    private function runBench(): int
    {
        $owner = User::where('email', 'owner@'.self::DOMAIN)->first();
        if ($owner === null) {
            $this->error('Run `php artisan doc:bench seed` first.');

            return self::FAILURE;
        }

        $writers = User::where('email', 'like', 'writer-%@'.self::DOMAIN)->orderBy('id')->get()->values();
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $documents = Document::where('team_id', $owner->current_team_id)->count();

        $results = [
            'url' => $url,
            'when' => now()->toIso8601String(),
            'documents' => $documents,
            'php' => PHP_VERSION,
            'db' => config('database.default'),
            'session_driver' => config('session.driver'),
            'typing' => [],
            'reads' => [],
        ];

        $this->info("Driving {$url} ({$documents} documents in the Benchmark company).");

        foreach ($this->typistCounts() as $k) {
            $results['typing'][] = $this->typing($url, $writers->take($k)->all());
        }

        $results['reads'] = $this->reads($url, $owner, $writers->all());

        $this->report($results);

        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($results, JSON_PRETTY_PRINT).PHP_EOL);
            $this->info("Written to {$out}.");
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function typistCounts(): array
    {
        return collect(explode(',', (string) $this->option('typists')))
            ->map(fn ($n) => max(1, min(self::WRITERS, (int) trim($n))))
            ->unique()->values()->all();
    }

    /**
     * K writers, each typing into their own document: every round all K
     * autosave at the same moment (the worst alignment) and poll /sync, as
     * an open editor does. Saves differ from the stored text every round,
     * so each one is a real write under SQLite's single write lock.
     *
     * @param  list<User>  $writers
     */
    private function typing(string $url, array $writers): array
    {
        $k = count($writers);
        $rounds = max(1, (int) $this->option('rounds'));
        $interval = max(0, (int) $this->option('interval'));
        $this->line("  {$k} writer(s) × {$rounds} rounds…");

        $sessions = [];
        foreach ($writers as $n => $writer) {
            $doc = Document::where('owner_id', $writer->id)->where('title', "Bench typing {$n}")->firstOrFail();
            $sessions[] = ['session' => $this->session($writer), 'doc' => $doc, 'version' => $doc->version, 'tab' => 'bench-'.Str::lower(Str::random(8)), 'n' => $n];
        }

        $saves = $polls = [];
        $statuses = [];

        for ($r = 0; $r < $rounds; $r++) {
            $began = microtime(true);

            $responses = Http::pool(function (Pool $pool) use ($url, $sessions, $r) {
                $calls = [];
                foreach ($sessions as $i => $s) {
                    $calls[] = $this->client($pool->as("save{$i}"), $s['session'])
                        ->post("{$url}/documents/{$s['doc']->uuid}/autosave", [
                            'content' => $this->body($s['n'] * 1000 + $r, 60),
                            'base_version' => $s['version'],
                        ]);
                    $calls[] = $this->client($pool->as("poll{$i}"), $s['session'])
                        ->post("{$url}/documents/{$s['doc']->uuid}/sync", ['version' => $s['version'], 'tab' => $s['tab']]);
                }

                return $calls;
            });

            foreach ($sessions as $i => &$s) {
                $save = $responses["save{$i}"];
                $poll = $responses["poll{$i}"];
                foreach (['save' => $save, 'poll' => $poll] as $kind => $response) {
                    if (! $response instanceof Response) {
                        $statuses["{$kind} error"] = ($statuses["{$kind} error"] ?? 0) + 1;

                        continue;
                    }
                    $statuses["{$kind} ".$this->outcome($response, 'json')] = ($statuses["{$kind} ".$this->outcome($response, 'json')] ?? 0) + 1;
                    $ms = ($response->transferStats?->getTransferTime() ?? 0) * 1000;
                    $kind === 'save' ? $saves[] = $ms : $polls[] = $ms;
                }
                if ($save instanceof Response && $save->successful()) {
                    $s['version'] = (int) $save->json('version', $s['version']);
                }
            }
            unset($s);

            $left = $interval / 1000 - (microtime(true) - $began);
            if ($left > 0 && $r < $rounds - 1) {
                usleep((int) ($left * 1_000_000));
            }
        }

        ksort($statuses);

        return ['writers' => $k, 'rounds' => $rounds, 'save_ms' => $this->percentiles($saves), 'poll_ms' => $this->percentiles($polls), 'statuses' => $statuses];
    }

    /**
     * The read side, one request at a time, as one person would: the list,
     * a search, a long history, and the exports. Exports are limited to 10
     * per person per hour, so each format is spread over the writers.
     *
     * @param  list<User>  $writers
     */
    private function reads(string $url, User $owner, array $writers): array
    {
        $this->line('  list, search, history, export…');
        $own = $this->session($owner);
        $history = Document::where('team_id', $owner->current_team_id)->where('title', 'Bench history')->firstOrFail();
        $sample = Document::where('team_id', $owner->current_team_id)->where('title', 'like', 'Bench %')->orderBy('id')->firstOrFail();

        $time = function (array $session, string $path, int $times) use ($url): array {
            $ms = [];
            $statuses = [];
            for ($i = 0; $i < $times; $i++) {
                $response = $this->client(Http::getFacadeRoot(), $session)->get($url.$path);
                $statuses[$this->outcome($response, 'page')] = ($statuses[$this->outcome($response, 'page')] ?? 0) + 1;
                $ms[] = ($response->transferStats?->getTransferTime() ?? 0) * 1000;
            }

            return $this->percentiles($ms) + ['statuses' => $statuses];
        };

        // Exports are limited to 10 per person per hour; earlier runs must
        // not turn this one's exports into 429s. Benchmark users only.
        foreach ($writers as $writer) {
            RateLimiter::clear('export:'.$writer->id);
        }

        $exports = [];
        foreach (['markdown', 'word', 'pdf'] as $f => $format) {
            $ms = [];
            $statuses = [];
            foreach (array_slice($writers, 0, 4) as $w => $writer) {
                $response = $this->client(Http::getFacadeRoot(), $this->session($writer))->get("{$url}/documents/{$sample->uuid}/export/{$format}");
                $statuses[$this->outcome($response, 'file')] = ($statuses[$this->outcome($response, 'file')] ?? 0) + 1;
                $ms[] = ($response->transferStats?->getTransferTime() ?? 0) * 1000;
            }
            $exports[$format] = $this->percentiles($ms) + ['statuses' => $statuses];
        }

        return [
            'documents list' => $time($own, '/documents', 10),
            'search (rare word)' => $time($own, '/documents?q='.self::KEYWORD, 10),
            'search (common word)' => $time($own, '/documents?q=revenue', 10),
            'history (300 versions)' => $time($own, "/documents/{$history->uuid}/history", 10),
            'editor page' => $time($own, "/documents/{$sample->uuid}/edit", 10),
        ] + collect($exports)->mapWithKeys(fn ($v, $k) => ["export {$k}" => $v])->all();
    }

    /**
     * A signed-in session for $user, written to the app's own session store,
     * and the encrypted cookie a browser would hold for it.
     *
     * @return array{cookie: string, value: string, token: string}
     */
    private function session(User $user): array
    {
        $handler = app('session')->driver()->getHandler();
        // The database handler is shared and remembers that its last row
        // existed: without this, every session after the first is an UPDATE
        // of a row that is not there, and nothing is stored.
        if (method_exists($handler, 'setExists')) {
            $handler->setExists(false);
        }
        $store = new Store(config('session.cookie'), $handler, null, config('session.serialization', 'php'));
        $store->start();
        $store->put('login_web_'.sha1(SessionGuard::class), $user->id);
        $store->save();

        $name = config('session.cookie');
        $value = Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()).$store->getId(), false);

        return ['cookie' => $name, 'value' => $value, 'token' => $store->token()];
    }

    private function client($factory, array $session)
    {
        $host = parse_url((string) ($this->option('url') ?: config('app.url')), PHP_URL_HOST);

        return $factory
            ->withCookies([$session['cookie'] => $session['value']], $host)
            ->withHeaders(['X-CSRF-TOKEN' => $session['token'], 'Accept' => 'application/json, text/html', 'X-Requested-With' => 'XMLHttpRequest'])
            ->timeout(60)
            ->withoutRedirecting();
    }

    /**
     * The status, or "bad" when a 200 is not what the endpoint answers:
     * a PHP error page served as 200 must never count as a fast success.
     */
    private function outcome(Response $response, string $expect): string
    {
        if (! $response->successful()) {
            return (string) $response->status();
        }

        $body = $response->body();
        $ok = match ($expect) {
            'json' => is_array($response->json()),
            'page' => str_contains($body, '</html>') && ! str_contains($body, 'Fatal error'),
            default => strlen($body) > 100 && ! str_contains($body, 'Fatal error'),
        };

        return $ok ? (string) $response->status() : 'bad';
    }

    /** @param  list<float>  $ms */
    private function percentiles(array $ms): array
    {
        if ($ms === []) {
            return ['n' => 0];
        }
        sort($ms);
        $at = fn (float $q) => round($ms[(int) min(count($ms) - 1, ceil($q * count($ms)) - 1)], 1);

        return ['n' => count($ms), 'p50' => $at(0.50), 'p95' => $at(0.95), 'p99' => $at(0.99), 'max' => round(end($ms), 1)];
    }

    private function report(array $results): void
    {
        $this->newLine();
        $this->table(
            ['writers', 'save p50', 'p95', 'p99', 'max', 'poll p50', 'p95', 'p99', 'responses'],
            collect($results['typing'])->map(fn ($t) => [
                $t['writers'], $t['save_ms']['p50'] ?? '-', $t['save_ms']['p95'] ?? '-', $t['save_ms']['p99'] ?? '-', $t['save_ms']['max'] ?? '-',
                $t['poll_ms']['p50'] ?? '-', $t['poll_ms']['p95'] ?? '-', $t['poll_ms']['p99'] ?? '-',
                collect($t['statuses'])->map(fn ($n, $k) => "{$k}×{$n}")->join(', '),
            ])->all(),
        );
        $this->table(
            ['read', 'n', 'p50 ms', 'p95 ms', 'max ms', 'responses'],
            collect($results['reads'])->map(fn ($r, $name) => [
                $name, $r['n'], $r['p50'] ?? '-', $r['p95'] ?? '-', $r['max'] ?? '-',
                collect($r['statuses'] ?? [])->map(fn ($n, $k) => "{$k}×{$n}")->join(', '),
            ])->values()->all(),
        );
    }
}
