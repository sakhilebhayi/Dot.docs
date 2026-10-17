<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use App\Services\SyncMetrics;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan sync:report`: what the owner reads to decide whether Phase 3
 * (true same-sentence co-typing) is needed, and `sync:prune-metrics`.
 */
class SyncReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    private User $ben;

    private Document $d1;

    private Document $d2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->seed(DocumentStyleSeeder::class);
        $this->ada = User::factory()->withPersonalTeam()->create();
        $this->ben = User::factory()->withPersonalTeam()->create();
        $this->d1 = app(DocumentStore::class)->create($this->ada, 'First');
        $this->d2 = app(DocumentStore::class)->create($this->ada, 'Second');
    }

    private function row(Document $doc, User $user, string $day, string $kind, int $count): void
    {
        DB::table('sync_metrics')->insert(['document_id' => $doc->id, 'user_id' => $user->id, 'day' => $day, 'kind' => $kind, 'count' => $count]);
    }

    /** The fixture: three days, two documents, two people. */
    private function fixture(): void
    {
        // 15 Oct: d1, Ada and Ben both present for a while; one clash, one deletion.
        $this->row($this->d1, $this->ada, '2026-10-15', 'merge_live_clean', 4);
        $this->row($this->d1, $this->ada, '2026-10-15', 'merge_live_lost', 1);
        $this->row($this->d1, $this->ada, '2026-10-15', 'lost_both_live', 1);
        $this->row($this->d1, $this->ben, '2026-10-15', 'merge_live_lost', 1);
        $this->row($this->d1, $this->ben, '2026-10-15', 'lost_deleted_live', 1);
        $this->row($this->d1, $this->ada, '2026-10-15', 'peers_1_secs', 1800);
        $this->row($this->d1, $this->ben, '2026-10-15', 'peers_1_secs', 1740);
        $this->row($this->d1, $this->ben, '2026-10-15', 'peers_2_secs', 60);
        // 16 Oct: d2 only: a draft merge with a loss, a conflict, a failed save.
        $this->row($this->d2, $this->ada, '2026-10-16', 'merge_draft_lost', 1);
        $this->row($this->d2, $this->ada, '2026-10-16', 'lost_both_draft', 2);
        $this->row($this->d2, $this->ada, '2026-10-16', 'conflict_expiry', 1);
        $this->row($this->d2, $this->ada, '2026-10-16', 'save_resent', 2);
        $this->row($this->d2, $this->ada, '2026-10-16', 'backoff_3', 1);
        $this->row($this->d2, $this->ben, '2026-10-16', 'readonly_confirmed', 1);
        // 17 Oct (today): d1 and d2, a second clash on d2 and 30 minutes of company on d2.
        $this->row($this->d2, $this->ada, '2026-10-17', 'merge_live_lost', 1);
        $this->row($this->d2, $this->ada, '2026-10-17', 'lost_both_live', 3);
        $this->row($this->d2, $this->ada, '2026-10-17', 'peers_1_secs', 1800);
        $this->row($this->d2, $this->ben, '2026-10-17', 'peers_1_secs', 1800);
        // Too old for a 14 day window.
        $this->row($this->d1, $this->ada, '2026-09-01', 'lost_both_live', 50);
    }

    private function reportJson(int $days = 14): array
    {
        $this->assertSame(0, Artisan::call('sync:report', ['--days' => $days, '--json' => true]));

        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_totals_each_kind_per_day_and_for_the_window(): void
    {
        $this->fixture();
        $report = $this->reportJson(14);

        $this->assertSame(14, $report['days']);
        $this->assertSame('2026-10-04', $report['from']);
        $this->assertSame('2026-10-17', $report['to']);
        $this->assertCount(14, $report['per_day'], 'every day of the window is there, quiet ones empty');
        $this->assertSame([], $report['per_day']['2026-10-04']);
        $this->assertSame(['merge_live_clean' => 4, 'merge_live_lost' => 2, 'lost_both_live' => 1, 'lost_deleted_live' => 1, 'peers_1_secs' => 3540, 'peers_2_secs' => 60],
            $report['per_day']['2026-10-15']);
        $this->assertSame(1, $report['per_day']['2026-10-16']['conflict_expiry']);
        $this->assertSame(3, $report['per_day']['2026-10-17']['lost_both_live']);
        $this->assertSame(4, $report['totals']['lost_both_live'], 'the row from September is outside the window');
        $this->assertSame(4, $report['totals']['merge_live_clean']);
        $this->assertArrayNotHasKey('merge_suggestion_lost', $report['totals'], 'a kind with no count is not listed');
    }

    public function test_it_says_what_share_of_merges_left_something_over(): void
    {
        $this->fixture();
        $merges = $this->reportJson()['merges'];

        // live: 4 clean + 3 with lost entries; draft: 1 with lost entries.
        $this->assertSame(8, $merges['total']);
        $this->assertSame(4, $merges['with_lost']);
        $this->assertSame(0.5, $merges['share_with_lost']);
        $this->assertSame(['total' => 7, 'with_lost' => 3], $merges['by_source']['live']);
        $this->assertSame(['total' => 1, 'with_lost' => 1], $merges['by_source']['draft']);
        $this->assertSame(['total' => 0, 'with_lost' => 0], $merges['by_source']['suggestion']);
    }

    public function test_it_counts_distinct_documents_and_people_with_something_to_report(): void
    {
        $this->fixture();
        $report = $this->reportJson();

        $this->assertSame(2, $report['events']['documents']);
        $this->assertSame(2, $report['events']['users']);
        $this->assertSame(4, $report['offered_back']['paragraphs']);
        $this->assertSame(2, $report['offered_back']['documents']);
        $this->assertArrayNotHasKey('clashes', $report, 'a paragraph offered back is not a clash');
        $this->assertSame(1, $report['deletions']['count']);
        $this->assertSame(1, $report['deletions']['documents']);
    }

    public function test_a_document_with_only_presence_is_not_an_event(): void
    {
        $this->row($this->d1, $this->ada, '2026-10-17', 'peers_1_secs', 60);
        $report = $this->reportJson();

        $this->assertSame(0, $report['events']['documents']);
        $this->assertSame(0, $report['events']['users']);
    }

    public function test_hours_with_two_or_more_people_come_from_the_busiest_tab_not_the_sum_of_them(): void
    {
        $this->fixture();
        $concurrency = $this->reportJson()['concurrency'];

        // 15 Oct on d1: Ada saw company for 1800 s, Ben for 1740 + 60: the same half hour, seen twice.
        $this->assertSame(0.5, $concurrency['hours_per_day']['2026-10-15']);
        $this->assertSame(0.0, $concurrency['hours_per_day']['2026-10-16']);
        $this->assertSame(0.5, $concurrency['hours_per_day']['2026-10-17']);
        $this->assertSame(60, $concurrency['minutes_two_or_more']);
        $this->assertSame(1, $concurrency['minutes_three_or_more']);
        $this->assertSame(2, $concurrency['documents']);
        $this->assertSame(4.0, $concurrency['offered_back_per_hour'], 'an hour together is enough for a rate');
    }

    public function test_the_same_paragraph_figure_counts_merges_with_a_loss_not_paragraphs(): void
    {
        $this->fixture();
        $offered = $this->reportJson()['offered_back'];

        // 4 paragraphs were offered back, in at most the 3 live merges that left something over, of 7 live merges.
        $this->assertSame(4, $offered['paragraphs']);
        $this->assertSame(3, $offered['in_at_most_merges']);
        $this->assertSame(42.9, $offered['live_merges_with_a_loss_per_100']);
    }

    public function test_no_rate_per_hour_is_given_until_there_is_an_hour_together(): void
    {
        $this->fixture();

        $today = $this->reportJson(1)['concurrency'];
        $this->assertSame(30, $today['minutes_two_or_more']);
        $this->assertNull($today['offered_back_per_hour']);
        $this->assertSame(60, $today['minutes_needed_for_a_rate']);

        $this->artisan('sync:report', ['--days' => 1])
            ->expectsOutputToContain('too little time together to give a rate')
            ->assertSuccessful();
        $this->artisan('sync:report', ['--days' => 14])
            ->doesntExpectOutputToContain('too little time together')
            ->assertSuccessful();
    }

    public function test_the_header_says_what_the_figures_are_and_how_exact(): void
    {
        $this->fixture();

        $this->artisan('sync:report', ['--days' => 14])
            ->expectsOutputToContain('UTC calendar days; today is partial')
            ->expectsOutputToContain('readers and idle open tabs included')
            ->expectsOutputToContain('approximate')
            ->expectsOutputToContain('a few tens of seconds')
            ->assertSuccessful();
        $this->assertSame('UTC', $this->reportJson()['timezone']);
    }

    public function test_the_window_is_the_last_n_days_counting_today(): void
    {
        $this->fixture();

        $today = $this->reportJson(1);
        $this->assertSame('2026-10-17', $today['from']);
        $this->assertSame(['2026-10-17'], array_keys($today['per_day']));
        $this->assertSame(3, $today['totals']['lost_both_live']);

        $two = $this->reportJson(2);
        $this->assertSame('2026-10-16', $two['from']);
        $this->assertSame(3, $two['totals']['lost_both_live']);
        $this->assertSame(1, $two['totals']['conflict_expiry']);
    }

    public function test_the_last_line_is_the_sentence_the_owner_reads(): void
    {
        $this->fixture();

        $line = 'In the last 14 days: 4 paragraphs offered back after same-paragraph clashes (in at most 3 live merges) across 2 documents; 60 minutes with two or more people in the document at once (readers and idle open tabs count).';
        $this->artisan('sync:report', ['--days' => 14])->expectsOutputToContain($line)->assertSuccessful();

        $this->assertSame($line, $this->reportJson()['summary']);
        $this->assertSame(
            'In the last day: 3 paragraphs offered back after same-paragraph clashes (in at most 1 live merge) across 1 document; 30 minutes with two or more people in the document at once (readers and idle open tabs count).',
            $this->reportJson(1)['summary']
        );
        $this->row($this->d1, $this->ben, '2026-10-17', 'lost_both_live', 1);
        $this->row($this->d1, $this->ben, '2026-10-17', 'merge_live_lost', 1);
        $this->assertSame(
            'In the last day: 4 paragraphs offered back after same-paragraph clashes (in at most 2 live merges) across 2 documents; 30 minutes with two or more people in the document at once (readers and idle open tabs count).',
            $this->reportJson(1)['summary']
        );
    }

    public function test_the_text_report_lists_the_days_and_the_share(): void
    {
        $this->fixture();
        $this->artisan('sync:report', ['--days' => 3])
            ->expectsOutputToContain('Merges: 8, 4 of them left something over (50.0%).')
            ->expectsOutputToContain('Paragraphs offered back after a same-paragraph clash: 4 in 2 documents, in at most 3 live merges')
            ->expectsOutputToContain('lost_both_live')
            ->expectsOutputToContain('10-15')
            ->assertSuccessful();
    }

    public function test_an_empty_table_reports_zeros_not_an_error(): void
    {
        $report = $this->reportJson();

        $this->assertSame([], $report['totals']);
        $this->assertSame(0, $report['merges']['total']);
        $this->assertNull($report['merges']['share_with_lost']);
        $this->assertNull($report['concurrency']['offered_back_per_hour']);
        $this->assertSame('In the last 14 days: 0 paragraphs offered back after same-paragraph clashes (in at most 0 live merges) across 0 documents; 0 minutes with two or more people in the document at once (readers and idle open tabs count).', $report['summary']);

        $this->artisan('sync:report')->expectsOutputToContain('Nothing recorded yet')->assertSuccessful();
    }

    public function test_a_window_that_makes_no_sense_is_refused(): void
    {
        $this->artisan('sync:report', ['--days' => 0])->assertFailed();
        $this->artisan('sync:report', ['--days' => 'abc'])->assertFailed();
        $this->artisan('sync:report', ['--days' => 4000])->assertFailed();
    }

    public function test_the_report_holds_no_names_titles_or_addresses(): void
    {
        $this->fixture();
        Artisan::call('sync:report', ['--days' => 14, '--json' => true]);
        $text = Artisan::output();
        Artisan::call('sync:report', ['--days' => 14]);
        $out = $text.Artisan::output();

        foreach ([$this->ada->name, $this->ada->email, $this->ben->name, $this->ben->email, 'First', 'Second', $this->d1->uuid] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
    }

    public function test_pruning_removes_rows_older_than_120_days_and_keeps_the_rest(): void
    {
        $this->row($this->d1, $this->ada, '2026-06-18', 'merge_live_clean', 1); // 121 days ago
        $this->row($this->d1, $this->ada, '2026-06-19', 'merge_live_clean', 1); // 120 days ago: kept
        $this->row($this->d1, $this->ada, '2026-10-17', 'merge_live_clean', 1);

        $this->artisan('sync:prune-metrics')->assertSuccessful();

        $this->assertSame(['2026-06-19', '2026-10-17'], DB::table('sync_metrics')->orderBy('day')->pluck('day')->all());
        $this->assertSame(120, SyncMetrics::KEEP_DAYS);
    }

    public function test_pruning_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'sync:prune-metrics'));

        $this->assertNotNull($event, 'Expected a scheduled sync:prune-metrics.');
        $this->assertSame('0 0 * * *', $event->getExpression());
    }

    public function test_deleting_a_document_takes_its_counts_and_deleting_a_person_keeps_theirs_unnamed(): void
    {
        $this->row($this->d1, $this->ada, '2026-10-17', 'merge_live_clean', 1);
        $this->row($this->d2, $this->ben, '2026-10-17', 'merge_live_clean', 1);

        DB::table('users')->where('id', $this->ben->id)->delete();
        $this->assertNull(DB::table('sync_metrics')->where('document_id', $this->d2->id)->value('user_id'));

        $this->d1->forceDelete();
        $this->assertSame(0, DB::table('sync_metrics')->where('document_id', $this->d1->id)->count());
    }
}
