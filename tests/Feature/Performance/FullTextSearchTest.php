<?php

namespace Tests\Feature\Performance;

use App\Documents\DocumentStore;
use App\Livewire\HeaderSearch;
use App\Models\Document;
use App\Models\User;
use App\Search\DocumentSearch;
use App\Search\FullTextIndex;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-5 / P-07: SQLite full-text search; P-09: the editor's code only on the editor page. */
class FullTextSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentStyleSeeder::class);
        $this->user = User::factory()->withPersonalTeam()->create();
    }

    private function doc(string $title, string $body): Document
    {
        $doc = app(DocumentStore::class)->create($this->user, $title);
        $doc->forceFill(['search_text' => $body])->save();

        return $doc;
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        app()->forgetScopedInstances();

        return app(DocumentSearch::class)->search($this->user, $query)->pluck('title')->all();
    }

    public function test_the_migrations_leave_the_index_and_its_three_triggers_in_place(): void
    {
        // Fails when a later migration rebuilds the documents table and takes the triggers with it.
        $this->assertTrue(app(FullTextIndex::class)->usable());
    }

    public function test_body_words_are_found_by_their_start_all_words_required_and_ranked(): void
    {
        $this->doc('Quarterly plan', 'The supplier invoices were reconciled in October.');
        $this->doc('Invoices register', 'A list of every invoice we paid.');
        $this->doc('Holiday rota', 'Nothing about money here.');

        $this->assertSame(['Invoices register', 'Quarterly plan'], $this->titles('invoic'), 'title hits rank first');
        $this->assertSame(['Quarterly plan'], $this->titles('supplier octob'));
        $this->assertSame([], $this->titles('supplier holiday'));
        $this->assertSame(['Holiday rota'], $this->titles('rota'), 'a title still matches inside a word');
        $this->assertSame(['Invoices register'], $this->titles('ster'));
    }

    public function test_the_index_follows_edits_and_deletes(): void
    {
        $doc = $this->doc('Notes', 'apples and pears');
        $this->assertSame(['Notes'], $this->titles('pears'));

        $doc->forceFill(['search_text' => 'plums only'])->save();
        $this->assertSame([], $this->titles('pears'));
        $this->assertSame(['Notes'], $this->titles('plums'));

        $doc->forceDelete();
        $this->assertSame([], $this->titles('plums'));
        $this->assertSame(0, DB::table(FullTextIndex::TABLE)->where(FullTextIndex::TABLE, 'plums')->count());
    }

    public function test_search_syntax_typed_by_a_person_is_just_text(): void
    {
        $this->doc('Contract', 'termination clause NEAR the end');

        foreach (['"', 'clause OR', 'NEAR(', '*', 'termin* -', "clause'; drop", '^end'] as $odd) {
            $this->titles($odd); // no exception
        }
        $this->assertSame(['Contract'], $this->titles('"termination" clause*'));
    }

    public function test_without_the_triggers_search_falls_back_and_search_rebuild_restores_the_index(): void
    {
        $this->doc('Budget', 'forecast for the next year');
        DB::statement('DROP TRIGGER documents_fts_au');

        $this->assertFalse(app(FullTextIndex::class)->usable());
        $this->assertSame(['Budget'], $this->titles('forecast'), 'LIKE still finds it');

        $this->artisan('search:rebuild')->expectsOutputToContain('rebuilt')->assertSuccessful();
        app()->forgetScopedInstances();
        $this->assertTrue(app(FullTextIndex::class)->usable());
        $this->assertSame(['Budget'], $this->titles('foreca'));
    }

    public function test_ranking_scores_are_computed_once_not_per_matching_row(): void
    {
        $this->doc('One', 'theme thesis thing');
        $builder = app(DocumentSearch::class)->constrain(Document::query(), $this->user, 'th');

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$builder->toSql(), $builder->getBindings()))->pluck('detail')->implode("\n");

        // A correlated subquery re-ran the MATCH for every hit: 12 s against 9 ms on 3,000 documents. The scores are a
        // joined table (the only correlated subquery left is the collaborators check, an EXISTS).
        $this->assertStringContainsString('SEARCH fts USING', $plan);
        $this->assertDoesNotMatchRegularExpression('/CORRELATED SCALAR SUBQUERY[^\n]*\n[^\n]*documents_fts/', $plan);
        $this->assertSame(['One'], $this->titles('th'));
    }

    public function test_single_letters_are_not_body_searches(): void
    {
        $this->assertSame('"don"*', FullTextIndex::matchQuery("don't"));
        $this->assertNull(FullTextIndex::matchQuery('a.'));
    }

    public function test_a_php_that_cannot_read_the_index_stands_it_down_on_up_and_saves_keep_working(): void
    {
        $this->doc('Kept', 'safe words');
        // What a PHP without FTS5 sees: the virtual table cannot be read. Simulated by swapping the table for a plain
        // one of the same name; the triggers still point at it.
        DB::statement('DROP TABLE '.FullTextIndex::TABLE);
        DB::statement('CREATE TABLE '.FullTextIndex::TABLE.' (title, search_text)');
        app()->forgetScopedInstances();

        $this->get('/up')->assertOk();

        $this->assertSame(0, DB::table('sqlite_master')->whereIn('name', FullTextIndex::TRIGGERS)->count(), 'triggers removed');
        $doc = app(DocumentStore::class)->create($this->user, 'Written after');
        $doc->forceFill(['search_text' => 'still saved'])->save();
        $this->assertSame(['Written after'], $this->titles('still saved'));
    }

    public function test_the_guard_leaves_a_working_index_alone(): void
    {
        $this->get('/up')->assertOk();
        $this->assertTrue(app(FullTextIndex::class)->usable());
    }

    public function test_the_header_search_matches_the_same_way(): void
    {
        $this->doc('Board minutes', 'resolution on dividends');

        $found = Livewire::actingAs($this->user)->test(HeaderSearch::class)
            ->set('q', 'dividend')->get('results')->pluck('title')->all();

        $this->assertSame(['Board minutes'], $found);
    }

    public function test_only_the_editor_page_loads_the_editor_code(): void
    {
        $hot = storage_path('framework/testing/vite-hot-'.getmypid());
        @mkdir(dirname($hot), 0777, true);
        file_put_contents($hot, 'http://vite.test');
        $this->withVite();
        Vite::useHotFile($hot);

        try {
            $doc = app(DocumentStore::class)->create($this->user, 'Open me');
            $this->actingAs($this->user)->get(route('documents.edit', $doc->uuid))
                ->assertSee('resources/js/editor.js', false);
            $this->actingAs($this->user)->get(route('documents.index'))
                ->assertSee('resources/js/app.js', false)
                ->assertDontSee('resources/js/editor.js', false);
        } finally {
            @unlink($hot);
        }
    }
}
