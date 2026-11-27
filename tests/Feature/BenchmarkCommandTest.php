<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
