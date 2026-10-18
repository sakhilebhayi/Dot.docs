<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan sync:report --check`: one screen the owner pastes back to tell
 * "the pipeline is broken" from "nobody co-edited". It prints settings and
 * counts only: never a secret, never a name, never a document.
 */
class SyncMetricsCheckTest extends TestCase
{
    use RefreshDatabase;

    private ?string $publicPath = null;

    protected function tearDown(): void
    {
        if ($this->publicPath !== null) {
            File::deleteDirectory($this->publicPath);
        }
        parent::tearDown();
    }

    private function check(): string
    {
        Artisan::call('sync:report', ['--check' => true]);

        return Artisan::output();
    }

    /** A public path with a Vite manifest whose one script contains `$script`. */
    private function builtPage(string $script): void
    {
        $this->publicPath = sys_get_temp_dir().'/dotdocs-check-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->publicPath.'/build/assets');
        File::put($this->publicPath.'/build/assets/app-abc.js', $script);
        File::put($this->publicPath.'/build/assets/app-abc.css', 'body{}');
        File::put($this->publicPath.'/build/manifest.json', json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-abc.js', 'isEntry' => true, 'css' => ['assets/app-abc.css']],
            'resources/css/app.css' => ['file' => 'assets/app-abc.css', 'isEntry' => true],
        ]));
        $this->app->usePublicPath($this->publicPath);
    }

    public function test_it_prints_the_switch_the_table_the_route_the_limiter_and_the_bundle_on_one_screen(): void
    {
        $this->builtPage('const kinds=["editor_open","merge_live_clean"];');
        $out = $this->check();

        $this->assertStringContainsString('Collection switched on (docs.sync_metrics): yes', $out);
        $this->assertStringContainsString('Table sync_metrics: present', $out);
        $this->assertStringContainsString('Rows: 0', $out);
        $this->assertMatchesRegularExpression('#Route documents\.sync-metrics: registered, POST /documents/\{uuid\}/sync-metrics#', $out);
        $this->assertStringContainsString('throttle:sync-metrics', $out);
        $this->assertStringContainsString('auth:sanctum', $out);
        $this->assertMatchesRegularExpression('/Rate limiter sync-metrics: defined, \d+ a minute per person and document/', $out);
        $this->assertStringContainsString('Page bundle: has the sessions counter (editor_open)', $out);
        $this->assertLessThanOrEqual(24, count(array_filter(explode("\n", $out), fn ($l) => trim($l) !== '')), 'one screen');
    }

    public function test_it_says_how_many_rows_and_the_newest_day_and_how_many_sessions(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Quarterly numbers');
        DB::table('sync_metrics')->insert([
            ['document_id' => $doc->id, 'user_id' => $user->id, 'day' => '2026-10-16', 'kind' => 'editor_open', 'count' => 2],
            ['document_id' => $doc->id, 'user_id' => $user->id, 'day' => '2026-10-17', 'kind' => 'editor_open', 'count' => 3],
            ['document_id' => $doc->id, 'user_id' => $user->id, 'day' => '2026-10-17', 'kind' => 'merge_live_clean', 'count' => 1],
        ]);

        $out = $this->check();

        $this->assertStringContainsString('Rows: 3', $out);
        $this->assertStringContainsString('newest day with a row: 2026-10-17', $out);
        $this->assertStringContainsString('Editor sessions in the table, all days: 5', $out);
        $this->assertStringNotContainsString('Quarterly numbers', $out);
        $this->assertStringNotContainsString($user->email, $out);
        $this->assertStringNotContainsString($doc->uuid, $out);
    }

    public function test_switched_off_it_says_so_and_fails(): void
    {
        config(['docs.sync_metrics' => false]);

        $this->assertSame(1, Artisan::call('sync:report', ['--check' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('Collection switched on (docs.sync_metrics): NO', $out);
        $this->assertStringContainsString('DOCS_SYNC_METRICS', $out);
    }

    public function test_a_missing_table_is_reported_not_thrown(): void
    {
        Schema::drop('sync_metrics');

        $this->assertSame(1, Artisan::call('sync:report', ['--check' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('Table sync_metrics: MISSING', $out);
        $this->assertStringContainsString('php artisan migrate', $out);
    }

    public function test_a_missing_limiter_is_reported(): void
    {
        // A fresh limiter registry, as if the provider that defines the limiter never ran.
        $this->app->instance(CacheRateLimiter::class, new CacheRateLimiter($this->app->make('cache')->driver()));
        RateLimiter::clearResolvedInstance(CacheRateLimiter::class);

        $this->assertSame(1, Artisan::call('sync:report', ['--check' => true]));
        $this->assertStringContainsString('Rate limiter sync-metrics: MISSING', Artisan::output());
    }

    public function test_a_page_bundle_without_the_counter_or_without_a_manifest_is_said_plainly(): void
    {
        $this->builtPage('const kinds=["merge_live_clean"];');
        $this->assertSame(1, Artisan::call('sync:report', ['--check' => true]));
        $this->assertStringContainsString('Page bundle: does NOT have the sessions counter', Artisan::output());

        File::delete($this->publicPath.'/build/manifest.json');
        $this->assertSame(1, Artisan::call('sync:report', ['--check' => true]));
        $this->assertStringContainsString('Page bundle: no build manifest found', Artisan::output());
    }

    public function test_it_gives_a_verdict_the_owner_can_act_on(): void
    {
        $this->builtPage('editor_open');
        $out = $this->check();
        $this->assertStringContainsString('Verdict: the server side is wired and the table is empty', $out);
        $this->assertStringContainsString('Open a document', $out);

        DB::table('sync_metrics')->insert(['document_id' => $this->makeDocumentId(), 'user_id' => null, 'day' => now()->toDateString(), 'kind' => 'editor_open', 'count' => 1]);
        $this->assertStringContainsString('Verdict: recording works', $this->check());
    }

    public function test_it_prints_no_secret(): void
    {
        config([
            'app.key' => 'base64:SECRETAPPKEYSECRETAPPKEYSECRETAPPKEY00000=',
            'database.connections.sqlite.password' => 'hunter2-db-password',
            'mail.mailers.smtp.password' => 'hunter2-mail-password',
        ]);
        $this->builtPage('editor_open');
        $out = $this->check();

        foreach (['SECRETAPPKEY', 'hunter2', 'APP_KEY'] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
    }

    private function makeDocumentId(): int
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create(User::factory()->withPersonalTeam()->create(), 'Any')->id;
    }
}
