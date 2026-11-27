<?php

namespace Tests\Feature\Performance;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** R2-5: version history and view records stop growing without limit (P-02, P-12), and the owner index (P-04). */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentStyleSeeder::class);
    }

    private function version(Document $doc, User $by, int $number, string $at, string $kind = 'auto'): int
    {
        return (int) DB::table('document_versions')->insertGetId([
            'document_id' => $doc->id, 'content_snapshot' => '', 'version_number' => $number,
            'created_by' => $by->id, 'created_at' => $at, 'kind' => $kind,
        ]);
    }

    public function test_old_automatic_versions_are_thinned_and_everything_that_matters_is_kept(): void
    {
        $this->travelTo('2026-11-28 12:00:00');
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'History');
        DB::table('document_versions')->where('document_id', $doc->id)->delete();

        $ids = [
            // Within the week: all kept.
            'recent_a' => $this->version($doc, $user, 100, '2026-11-25 09:00:00'),
            'recent_b' => $this->version($doc, $user, 101, '2026-11-25 09:05:00'),
            // Older than a week, same day: only the last of the day stays.
            'day_first' => $this->version($doc, $user, 50, '2026-11-10 09:00:00'),
            'day_mid' => $this->version($doc, $user, 51, '2026-11-10 12:00:00'),
            'day_last' => $this->version($doc, $user, 52, '2026-11-10 17:00:00'),
            // A named version that day: kept whatever its age.
            'named' => $this->version($doc, $user, 53, '2026-11-10 10:00:00', 'named'),
            // Older than 90 days, one ISO week (Mon 2026-07-06 to Sun 07-12): the last of the week stays.
            'week_mon' => $this->version($doc, $user, 10, '2026-07-06 09:00:00'),
            'week_thu' => $this->version($doc, $user, 11, '2026-07-09 09:00:00'),
            'week_sun' => $this->version($doc, $user, 12, '2026-07-12 09:00:00'),
            'restore' => $this->version($doc, $user, 13, '2026-07-07 09:00:00', 'restore'),
        ];

        $this->artisan('documents:thin-versions', ['--dry-run' => true])
            ->expectsOutputToContain('Would remove 4 automatic version(s) across 1 document(s).')->assertSuccessful();
        $this->assertSame(10, DB::table('document_versions')->count(), 'a dry run removes nothing');

        $this->artisan('documents:thin-versions')->expectsOutputToContain('Removed 4')->assertSuccessful();

        $left = DB::table('document_versions')->pluck('id')->all();
        foreach (['recent_a', 'recent_b', 'day_last', 'named', 'week_sun', 'restore'] as $kept) {
            $this->assertContains($ids[$kept], $left, $kept.' is kept');
        }
        foreach (['day_first', 'day_mid', 'week_mon', 'week_thu'] as $gone) {
            $this->assertNotContains($ids[$gone], $left, $gone.' is thinned');
        }
    }

    public function test_a_documents_newest_version_is_never_thinned_however_old(): void
    {
        $this->travelTo('2026-11-28 12:00:00');
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Quiet');
        DB::table('document_versions')->where('document_id', $doc->id)->delete();
        $this->version($doc, $user, 1, '2026-03-02 09:00:00');
        $newest = $this->version($doc, $user, 2, '2026-03-02 08:00:00'); // earlier in the day, higher number

        $this->artisan('documents:thin-versions')->assertSuccessful();

        $this->assertContains($newest, DB::table('document_versions')->pluck('id')->all());
    }

    public function test_opening_a_document_is_recorded_once_an_hour_per_person(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Viewed');
        $views = fn () => DB::table('audit_logs')->where('action', 'document.viewed')->where('subject_id', $doc->id)->count();

        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk();
        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk();
        $this->assertSame(1, $views());

        $this->travel(61)->minutes();
        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk();
        $this->assertSame(2, $views());
    }

    public function test_view_records_older_than_a_year_go_and_every_other_audit_row_stays(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Audited');
        $row = fn (string $action, string $at) => DB::table('audit_logs')->insert([
            'actor_type' => 'user', 'actor_id' => $user->id, 'action' => $action,
            'subject_type' => Document::class, 'subject_id' => $doc->id, 'created_at' => $at,
        ]);
        $row('document.viewed', now()->subDays(400)->toDateTimeString());
        $row('document.viewed', now()->subDays(10)->toDateTimeString());
        $row('document.exported', now()->subDays(400)->toDateTimeString());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'prune-view-records');
        $this->assertNotNull($event);
        $event->run(app());

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'document.viewed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'document.exported')->count());
    }

    public function test_the_owner_lookup_and_the_pruning_queries_are_indexed(): void
    {
        $this->assertTrue(Schema::hasIndex('documents', 'r25_documents_owner_id_updated_at_index'));
        $this->assertTrue(Schema::hasIndex('audit_logs', 'r25_audit_logs_action_created_at_index'));
        $this->assertTrue(Schema::hasIndex('document_versions', 'r25_document_versions_document_id_kind_created_at_index'));
    }
}
