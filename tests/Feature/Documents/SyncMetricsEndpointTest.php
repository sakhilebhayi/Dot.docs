<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Services\SyncMetrics;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The counters the editor page flushes once a minute (and when it is left):
 * counts and kinds, nothing else. See resources/js/editor/sync/metrics.js and
 * `php artisan sync:report`.
 */
class SyncMetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function documentOf(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'A secret title that must never be stored');
    }

    public function test_a_viewer_flushes_counts_and_they_are_stored_per_document_user_day_and_kind(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        $this->actingAs($owner)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['merge_live_clean' => 2, 'lost_both_live' => 1]])
            ->assertNoContent();

        $rows = DB::table('sync_metrics')->orderBy('kind')->get();
        $this->assertSame(['lost_both_live', 'merge_live_clean'], $rows->pluck('kind')->all());
        $this->assertSame([1, 2], $rows->pluck('count')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([$doc->id, $doc->id], $rows->pluck('document_id')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([$owner->id, $owner->id], $rows->pluck('user_id')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([now()->toDateString(), now()->toDateString()], $rows->pluck('day')->all());
    }

    public function test_flushes_add_up_instead_of_replacing_the_row(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        foreach ([3, 4] as $n) {
            $this->actingAs($owner)
                ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['merge_live_lost' => $n, 'peers_1_secs' => 10]])
                ->assertNoContent();
        }

        $this->assertSame(2, DB::table('sync_metrics')->count());
        $this->assertSame(7, (int) DB::table('sync_metrics')->where('kind', 'merge_live_lost')->value('count'));
        $this->assertSame(20, (int) DB::table('sync_metrics')->where('kind', 'peers_1_secs')->value('count'));
    }

    public function test_another_person_or_another_day_is_another_row(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $other = User::factory()->create();
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $other->id, 'role' => 'viewer']);

        $this->actingAs($owner)->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['lost_both_live' => 1]])->assertNoContent();
        $this->actingAs($other)->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['lost_both_live' => 1]])->assertNoContent();
        $this->travel(1)->days();
        $this->actingAs($owner)->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['lost_both_live' => 1]])->assertNoContent();

        $this->assertSame(3, DB::table('sync_metrics')->count());
        $this->assertSame(2, DB::table('sync_metrics')->where('user_id', $owner->id)->count());
    }

    public function test_a_read_only_viewer_may_send_too(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $viewer = User::factory()->create();
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->assertFalse($viewer->can('update', $doc));

        $this->actingAs($viewer)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['readonly_confirmed' => 1]])
            ->assertNoContent();

        $this->assertSame(1, DB::table('sync_metrics')->where('user_id', $viewer->id)->count());
    }

    public function test_a_guest_is_turned_away_and_nothing_is_stored(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        $this->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['merge_live_clean' => 1]])->assertUnauthorized();

        $this->assertSame(0, DB::table('sync_metrics')->count());
    }

    public function test_somebody_who_may_not_view_the_document_is_refused_and_nothing_is_stored(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['merge_live_clean' => 1]])
            ->assertForbidden();

        $this->assertSame(0, DB::table('sync_metrics')->count());
    }

    public function test_a_document_that_does_not_exist_is_a_404(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->postJson(route('documents.sync-metrics', '00000000-0000-0000-0000-000000000000'), ['counts' => ['merge_live_clean' => 1]])
            ->assertNotFound();
    }

    public function test_an_unknown_kind_is_dropped_and_the_known_ones_in_the_same_batch_are_stored(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        // A newer page may send a kind this server does not know yet (a deploy in between): that must not cost the rest.
        $this->actingAs($owner)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => [
                'merge_live_clean' => 1,
                'my_document_text' => 1,
                'another_junk' => 'not even a number',
                'peers_0_secs' => 40,
            ]])
            ->assertNoContent();

        $this->assertSame(['merge_live_clean'], DB::table('sync_metrics')->pluck('kind')->all());
    }

    public function test_a_batch_of_only_unknown_kinds_stores_nothing_and_answers_204(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        $this->actingAs($owner)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['my_document_text' => 1, 'x' => 2]])
            ->assertNoContent();

        $this->assertSame(0, DB::table('sync_metrics')->count());
    }

    public function test_the_rows_are_written_in_one_fixed_order_so_two_flushes_cannot_deadlock(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'sync_metrics') && str_starts_with($query->sql, 'insert')) {
                $statements[] = $query->bindings;
            }
        });

        $this->actingAs($owner)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['peers_2_secs' => 1, 'merge_live_lost' => 1, 'backoff_1' => 1]])
            ->assertNoContent();

        $this->assertCount(1, $statements);
        $kinds = array_values(array_filter($statements[0], fn ($binding) => in_array($binding, SyncMetrics::kinds(), true)));
        $this->assertSame(['backoff_1', 'merge_live_lost', 'peers_2_secs'], $kinds);
    }

    public function test_time_alone_is_not_collected_at_all(): void
    {
        $this->assertNotContains('peers_0_secs', SyncMetrics::kinds());
        $this->assertSame(
            ['peers_1_secs', 'peers_2_secs', 'peers_3plus_secs'],
            array_values(array_filter(SyncMetrics::kinds(), fn ($kind) => str_starts_with($kind, 'peers_')))
        );
    }

    public function test_counts_must_be_whole_numbers_inside_the_cap(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $url = route('documents.sync-metrics', $doc->uuid);

        foreach ([0, -1, 1.5, '7', 'lots', SyncMetrics::MAX_COUNT + 1, null, [1], true] as $bad) {
            $this->actingAs($owner)->postJson($url, ['counts' => ['merge_live_clean' => $bad]])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('sync_metrics')->count());

        $this->actingAs($owner)->postJson($url, ['counts' => ['merge_live_clean' => SyncMetrics::MAX_COUNT]])->assertNoContent();
        $this->assertSame(SyncMetrics::MAX_COUNT, (int) DB::table('sync_metrics')->value('count'));
    }

    public function test_the_shape_must_be_an_object_of_counts(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $url = route('documents.sync-metrics', $doc->uuid);

        $this->actingAs($owner)->postJson($url, [])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['counts' => []])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['counts' => 'merge_live_clean'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['counts' => ['merge_live_clean']])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['counts' => [1, 2]])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['counts' => null])->assertUnprocessable();
        $this->assertSame(0, DB::table('sync_metrics')->count());
    }

    public function test_a_payload_over_the_size_limit_is_refused_before_it_is_read(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        $counts = array_fill_keys(SyncMetrics::kinds(), SyncMetrics::MAX_COUNT);
        $this->assertLessThan(SyncMetrics::MAX_BODY_BYTES, strlen(json_encode(['counts' => $counts])), 'a payload that names every kind fits');

        $this->actingAs($owner)->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => $counts])->assertNoContent();

        $this->actingAs($owner)
            ->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['merge_live_clean' => 1], 'note' => str_repeat('x', SyncMetrics::MAX_BODY_BYTES)])
            ->assertStatus(413);
        $this->assertSame(count(SyncMetrics::kinds()), DB::table('sync_metrics')->count());
    }

    public function test_extra_keys_in_the_body_are_never_stored(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena', 'email' => 'thandi@example.test']);
        $doc = $this->documentOf($owner);

        $this->actingAs($owner)
            ->postJson(route('documents.sync-metrics', $doc->uuid), [
                'counts' => ['merge_live_clean' => 1],
                'title' => 'Quarterly numbers',
                'text' => 'The secret paragraph',
                'email' => 'thandi@example.test',
            ])
            ->assertNoContent();

        $dump = json_encode(DB::table('sync_metrics')->get());
        foreach (['Quarterly', 'secret', 'Thandi', 'thandi@', $doc->title, $doc->uuid] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
    }

    public function test_the_table_holds_only_ids_a_day_a_kind_and_a_count(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'document_id', 'user_id', 'day', 'kind', 'count'],
            Schema::getColumnListing('sync_metrics')
        );
    }

    public function test_the_unique_key_stops_a_second_row_for_the_same_document_user_day_and_kind(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $row = ['document_id' => $doc->id, 'user_id' => $owner->id, 'day' => '2026-10-17', 'kind' => 'merge_live_clean', 'count' => 1];
        DB::table('sync_metrics')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('sync_metrics')->insert($row);
    }

    public function test_the_endpoint_is_throttled_per_person_and_document_and_a_429_stores_nothing(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);
        $url = route('documents.sync-metrics', $doc->uuid);

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($owner)->postJson($url, ['counts' => ['merge_live_clean' => 1]])->assertNoContent();
        }
        $this->actingAs($owner)->postJson($url, ['counts' => ['merge_live_clean' => 1]])->assertStatus(429);
        $this->assertSame(12, (int) DB::table('sync_metrics')->value('count'));

        // Somebody else is not held back by this person's budget.
        $other = User::factory()->withPersonalTeam()->create();
        $second = $this->documentOf($other);
        $this->actingAs($other)->postJson(route('documents.sync-metrics', $second->uuid), ['counts' => ['merge_live_clean' => 1]])->assertNoContent();
    }

    public function test_switched_off_the_endpoint_answers_204_and_stores_nothing_even_for_a_bad_body(): void
    {
        config(['docs.sync_metrics' => false]);
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        $this->actingAs($owner)->postJson(route('documents.sync-metrics', $doc->uuid), ['counts' => ['merge_live_clean' => 1]])->assertNoContent();
        $this->actingAs($owner)->postJson(route('documents.sync-metrics', $doc->uuid), ['nonsense' => true])->assertNoContent();

        $this->assertSame(0, DB::table('sync_metrics')->count());
    }

    public function test_the_default_is_on(): void
    {
        $this->assertTrue(config('docs.sync_metrics'));
    }

    public function test_the_beacon_form_works_with_the_token_in_the_body(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = $this->documentOf($owner);

        // sendBeacon cannot set headers: the CSRF token rides in the JSON body, as the autosave beacon's does.
        $this->actingAs($owner)
            ->call('POST', route('documents.sync-metrics', $doc->uuid), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                '_token' => 'whatever',
                'counts' => ['merge_live_clean' => 1],
            ]))
            ->assertNoContent();

        $this->assertSame(1, DB::table('sync_metrics')->count());
    }

    public function test_the_closed_list_lives_in_one_file_that_the_server_and_the_bundle_both_read(): void
    {
        $file = resource_path('js/editor/sync/metricKinds.json');
        $kinds = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($kinds, SyncMetrics::kinds());
        $this->assertSame(array_values(array_unique($kinds)), $kinds);

        $js = file_get_contents(resource_path('js/editor/sync/metrics.js'));
        $this->assertStringContainsString("from './metricKinds.json'", $js);
        $this->assertDoesNotMatchRegularExpression('/METRIC_KINDS\s*=\s*\[/', $js, 'no second copy of the list in the bundle');
        $this->assertStringNotContainsString("'merge_live_clean'", file_get_contents(app_path('Services/SyncMetrics.php')), 'and none on the server');
    }
}
