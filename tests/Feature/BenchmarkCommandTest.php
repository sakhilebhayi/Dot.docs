<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EA-9: the load-test command. Its `run` drives a live server over HTTP and
 * is exercised by hand (docs/programme/EA9_MEASUREMENT.md); what is pinned
 * here is that it never touches production and that `seed` builds what
 * `run` needs, idempotently.
 */
class BenchmarkCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('doc:bench', ['action' => 'seed', '--documents' => 3])
            ->expectsOutputToContain('never runs in production')
            ->assertFailed();

        $this->assertSame(0, User::where('email', 'like', '%@bench.invalid')->count());
    }

    public function test_seed_builds_the_benchmark_company_once_and_tops_it_up(): void
    {
        $this->artisan('doc:bench', ['action' => 'seed', '--documents' => 5])->assertSuccessful();

        $owner = User::where('email', 'owner@bench.invalid')->firstOrFail();
        $team = $owner->currentTeam;
        $this->assertSame('Benchmark', $team->name);
        $this->assertSame(10, $team->users()->count());
        $this->assertNotNull($owner->email_verified_at);

        $history = Document::where('title', 'Bench history')->firstOrFail();
        $this->assertSame(300, $history->versions()->count());
        // 5 numbered + the history document + 10 typing documents.
        $this->assertSame(16, Document::where('team_id', $team->id)->count());

        $this->artisan('doc:bench', ['action' => 'seed', '--documents' => 8])->assertSuccessful();

        $this->assertSame(11, User::where('email', 'like', '%@bench.invalid')->count());
        $this->assertSame(19, Document::where('team_id', $team->id)->count());
        $this->assertSame(300, $history->versions()->count());
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->artisan('doc:bench', ['action' => 'nonsense'])->assertExitCode(2);
    }

    /**
     * `run` against a faked server: every endpoint the bench drives is hit
     * with a signed-in session cookie and the CSRF header, the results are
     * counted by outcome, and a 200 that is not the right kind of body is
     * counted as `bad`, never as a fast success.
     */
    public function test_run_drives_every_endpoint_and_counts_a_wrong_body_as_bad(): void
    {
        $this->artisan('doc:bench', ['action' => 'seed', '--documents' => 2])->assertSuccessful();

        $page = '<!DOCTYPE html><html><body>'.str_repeat('x', 200).'</body></html>';
        Http::fake([
            '*/autosave' => Http::response(['version' => 9]),
            '*/sync' => Http::response(['version' => 9, 'present' => []]),
            '*/export/pdf' => Http::response('Fatal error: nope'),
            '*/export/*' => Http::response(str_repeat('b', 300)),
            '*' => Http::response($page),
        ]);

        $out = tempnam(sys_get_temp_dir(), 'bench');
        $this->artisan('doc:bench', ['action' => 'run', '--typists' => '1,2', '--rounds' => 2, '--interval' => 0, '--url' => 'http://bench.test', '--out' => $out])
            ->assertSuccessful();

        $results = json_decode((string) file_get_contents($out), true);
        unlink($out);

        $this->assertSame([1, 2], array_column($results['typing'], 'writers'));
        $this->assertSame(['poll 200' => 4, 'save 200' => 4], $results['typing'][1]['statuses']);
        $this->assertSame([200 => 10], $results['reads']['documents list']['statuses']);
        $this->assertSame([200 => 4], $results['reads']['export markdown']['statuses']);
        $this->assertSame(['bad' => 4], $results['reads']['export pdf']['statuses']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/autosave')
            && $request->hasHeader('X-CSRF-TOKEN')
            && str_contains($request->header('Cookie')[0] ?? '', config('session.cookie').'=')
            && $request['base_version'] >= 1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents?q=zebrafinch'));
    }

    public function test_run_asks_for_a_seed_first(): void
    {
        $this->artisan('doc:bench', ['action' => 'run'])
            ->expectsOutputToContain('doc:bench seed')
            ->assertFailed();
    }
}
