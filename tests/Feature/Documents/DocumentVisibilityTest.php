<?php

namespace Tests\Feature\Documents;

use App\Files\FilesService;
use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Search\CompanyDocuments;
use App\Search\DocumentSearch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R2-12 (R2-11 performance High + code-quality Medium): ONE visibility rule, Document::scopeVisibleTo(), for the
 * documents list, its tag filter and search. It starts with the company, so:
 *
 *  - nobody ever sees another company's documents in the list, the tags or search (the isolation half), and
 *  - SQLite reads one company's rows through an index instead of every document on the platform (the query-plan
 *    half: EXPLAIN QUERY PLAN of the REAL queries each page runs, captured while it runs, never "SCAN documents").
 *
 * "Shared with me" is the one cross-company view: only documents the person collaborates on, so only ones
 * DocumentPolicy::view() lets them open.
 */
class DocumentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private User $gina;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ann = User::factory()->withPersonalTeam()->create();
        $this->gina = User::factory()->withPersonalTeam()->create();
        $this->acme = $this->company($this->ann, 'Acme');
        $this->globex = $this->company($this->gina, 'Globex');
    }

    private function company(User $owner, string $name): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $owner->switchTeam($team);

        return $team;
    }

    private function doc(User $owner, Team $team, string $title, string $text = 'nothing in particular'): Document
    {
        $document = Document::factory()->create([
            'owner_id' => $owner->id,
            'team_id' => $team->id,
            'title' => $title,
            'search_text' => $text,
        ]);

        $files = app(FilesService::class);
        $files->registerDocument($document, $files->root($team));

        return $document;
    }

    private function tag(Team $team, User $owner, string $name, Document ...$documents): Tag
    {
        $tag = Tag::create(['owner_id' => $owner->id, 'team_id' => $team->id, 'name' => $name]);
        $tag->documents()->attach(array_map(fn (Document $d) => $d->id, $documents));

        return $tag;
    }

    private function share(Document $document, User $with): void
    {
        DocumentCollaborator::create(['document_id' => $document->id, 'user_id' => $with->id, 'role' => 'viewer']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function listed(User $user, array $query = []): array
    {
        $this->actingAs($user->fresh());

        return Livewire::withQueryParams($query)->test(Index::class)->get('documents')->pluck('title')->all();
    }

    // ---- isolation -------------------------------------------------------------------------------------------

    public function test_the_list_search_and_tags_never_show_another_companys_documents(): void
    {
        $this->doc($this->ann, $this->acme, 'Acme haulage', 'zebrafinch route plan');
        $globex = $this->doc($this->gina, $this->globex, 'Globex haulage', 'zebrafinch route plan');
        // Gina tags hers with a name Ann's company also uses: the tag is still Globex's.
        $acmeDoc = Document::where('title', 'Acme haulage')->firstOrFail();
        $acmeTag = $this->tag($this->acme, $this->ann, 'Fleet', $acmeDoc);
        $globexTag = $this->tag($this->globex, $this->gina, 'Fleet', $globex);

        $this->assertSame(['Acme haulage'], $this->listed($this->ann));
        $this->assertSame(['Acme haulage'], $this->listed($this->ann, ['q' => 'zebrafinch']));
        $this->assertSame(['Acme haulage'], $this->listed($this->ann, ['q' => 'haulage']));
        $this->assertSame(['Acme haulage'], $this->listed($this->ann, ['tag' => $acmeTag->id]));
        // A hand-edited tag id from another company narrows to nothing, never to that company's documents.
        $this->assertSame([], $this->listed($this->ann, ['tag' => $globexTag->id]));

        $this->actingAs($this->ann->fresh());
        $tags = Livewire::test(Index::class)->get('availableTags');
        $this->assertSame([$acmeTag->id], $tags->pluck('id')->all());

        $found = app(DocumentSearch::class)->search($this->ann->fresh(), 'zebrafinch')->pluck('title')->all();
        $this->assertSame(['Acme haulage'], $found);
    }

    public function test_a_member_of_two_companies_sees_only_the_one_they_stand_in(): void
    {
        $this->doc($this->ann, $this->acme, 'Acme plan', 'quarterly zebrafinch');
        $this->doc($this->gina, $this->globex, 'Globex plan', 'quarterly zebrafinch');
        $this->globex->users()->attach($this->ann, ['role' => 'editor']);

        $this->assertSame(['Acme plan'], $this->listed($this->ann));
        $this->assertSame(['Acme plan'], $this->listed($this->ann, ['q' => 'zebrafinch']));

        $this->ann->fresh()->switchTeam($this->globex);
        $this->assertSame(['Globex plan'], $this->listed($this->ann));
        $this->assertSame(['Globex plan'], $this->listed($this->ann, ['q' => 'zebrafinch']));
    }

    /**
     * The one cross-company view: what another company shared with you is listed under "Shared with me" (wherever it
     * is filed), and nothing else of that company is. Search inside it stays in your company (owner decision
     * 2026-11-27: a shared document is not a search result).
     */
    public function test_shared_with_me_lists_only_documents_shared_with_the_person(): void
    {
        $this->doc($this->ann, $this->acme, 'Acme own', 'zebrafinch');
        $shared = $this->doc($this->gina, $this->globex, 'Globex shared', 'zebrafinch');
        $this->doc($this->gina, $this->globex, 'Globex private', 'zebrafinch');
        $this->share($shared, $this->ann);

        $this->assertSame(['Globex shared'], $this->listed($this->ann, ['filter' => 'shared']));
        $this->assertTrue($this->ann->fresh()->can('view', $shared), 'every row in it is one they can open');
        $this->assertNotContains('Globex shared', $this->listed($this->ann));
        $this->assertSame([], $this->listed($this->ann, ['filter' => 'shared', 'q' => 'zebrafinch']));
    }

    public function test_mine_and_team_scopes_stay_inside_the_company(): void
    {
        $mate = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($mate, ['role' => 'editor']);
        $this->doc($this->ann, $this->acme, 'Ann at Acme');
        $this->doc($mate, $this->acme, 'Mate at Acme');
        $this->doc($this->ann, $this->globex, 'Ann at Globex');

        $this->assertSame(['Ann at Acme'], $this->listed($this->ann, ['filter' => 'mine']));
        $this->assertEqualsCanonicalizing(['Ann at Acme', 'Mate at Acme'], $this->listed($this->ann, ['filter' => 'team']));
    }

    /**
     * A `current_team_id` that still points at a company the person was removed from is no company at all: the rule
     * never lists a team they can no longer open.
     */
    public function test_a_stale_current_team_is_not_a_company(): void
    {
        $member = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($member, ['role' => 'editor']);
        $member->switchTeam($this->acme);
        $this->doc($this->ann, $this->acme, 'Acme budget');
        $this->acme->users()->detach($member);
        $member->forceFill(['current_team_id' => $this->acme->id])->save();

        $member = $member->fresh();
        $this->assertNull(CompanyDocuments::companyOf($member));
        $this->assertSame([], Document::query()->visibleTo($member)->pluck('title')->all());
        $this->assertSame(['Acme budget'], Document::query()->visibleTo($this->ann->fresh())->pluck('title')->all());
    }

    public function test_with_no_company_only_the_persons_own_unfiled_documents_are_visible(): void
    {
        $loner = User::factory()->create();
        Document::factory()->create(['owner_id' => $loner->id, 'team_id' => null, 'title' => 'Loose note']);
        Document::factory()->create(['owner_id' => $this->ann->id, 'team_id' => null, 'title' => 'Ann loose note']);
        $this->doc($this->ann, $this->acme, 'Acme budget');

        $this->assertNull(CompanyDocuments::companyOf($loner));
        $this->assertSame(['Loose note'], Document::query()->visibleTo($loner)->pluck('title')->all());
        $this->assertSame(['Loose note'], $this->listed($loner));
        $this->assertSame([], $this->listed($loner, ['filter' => 'team']));
    }

    // ---- query plans -----------------------------------------------------------------------------------------

    /**
     * Every query a documents-list page runs against `documents`, as EXPLAIN QUERY PLAN rows.
     *
     * @param  array<string, mixed>  $params
     * @return list<array{sql: string, plan: list<string>}>
     */
    private function plansOfTheList(array $params): array
    {
        $this->actingAs($this->ann->fresh());

        return $this->plansWhile(fn () => Livewire::withQueryParams($params)->test(Index::class)->get('documents'));
    }

    /**
     * @return list<array{sql: string, plan: list<string>}>
     */
    private function plansWhile(callable $run): array
    {
        /** @var list<QueryExecuted> $seen */
        $seen = [];
        DB::listen(function (QueryExecuted $q) use (&$seen) {
            $seen[] = $q;
        });

        $run();

        $plans = [];
        foreach ($seen as $q) {
            if (! str_starts_with(ltrim($q->sql), 'select') || ! str_contains($q->sql, 'from "documents"')) {
                continue;
            }
            $rows = DB::select('EXPLAIN QUERY PLAN '.$q->sql, $q->connection->prepareBindings($q->bindings));
            $plans[] = ['sql' => $q->sql, 'plan' => array_map(fn ($r) => (string) $r->detail, $rows)];
        }

        return $plans;
    }

    /** @param  list<array{sql: string, plan: list<string>}>  $plans */
    private function assertNoDocumentsScan(array $plans): void
    {
        $this->assertNotEmpty($plans, 'the page ran no documents query at all');

        foreach ($plans as $p) {
            $context = $p['sql']."\n".implode("\n", $p['plan']);
            foreach ($p['plan'] as $line) {
                $this->assertDoesNotMatchRegularExpression('/^SCAN documents\b/', $line, "a full scan of documents:\n".$context);
            }
            $this->assertNotEmpty(
                preg_grep('/^SEARCH documents USING (INDEX|COVERING INDEX|INTEGER PRIMARY KEY)/', $p['plan']),
                "documents not read through an index:\n".$context,
            );
        }
    }

    private function seedTwoCompanies(): void
    {
        foreach (range(1, 5) as $i) {
            $a = $this->doc($this->ann, $this->acme, "Acme {$i}", "zebrafinch report {$i}");
            $g = $this->doc($this->gina, $this->globex, "Globex {$i}", "zebrafinch report {$i}");
        }
        $this->tag($this->acme, $this->ann, 'Fleet', $a);
        $this->share($g, $this->ann);
    }

    public function test_the_list_reads_documents_through_an_index_in_every_scope(): void
    {
        $this->seedTwoCompanies();
        $tag = Tag::where('team_id', $this->acme->id)->firstOrFail();

        foreach ([[], ['filter' => 'mine'], ['filter' => 'team'], ['filter' => 'shared'], ['tag' => $tag->id]] as $params) {
            $this->assertNoDocumentsScan($this->plansOfTheList($params));
        }
    }

    public function test_search_reads_documents_through_an_index_and_scores_only_the_company(): void
    {
        $this->seedTwoCompanies();

        $this->assertNoDocumentsScan($this->plansOfTheList(['q' => 'zebrafinch']));

        $plans = $this->plansWhile(fn () => app(DocumentSearch::class)->search($this->ann->fresh(), 'zebrafinch'));
        $this->assertNoDocumentsScan($plans);
        // The full-text scoring (the derived table) is narrowed by the company's ids before bm25 runs ...
        $this->assertMatchesRegularExpression('/documents_fts MATCH \? and \+rowid in \(select "documents"\."id" from "documents" where "documents"\."team_id" = \?.*\) limit \d+\) as "fts"/', $plans[0]['sql']);
        // ... as a filter: FTS5 never takes the ids as its access path (`INDEX 0:=M…`), which re-runs the MATCH once
        // per company document (3.3 s for a common word at 60k documents, measured).
        foreach ($plans[0]['plan'] as $line) {
            $this->assertDoesNotMatchRegularExpression('/^SCAN documents_fts VIRTUAL TABLE INDEX \d+:=/', $line);
        }
    }

    /** The detector itself: the rule this replaced (owner OR collaborator OR team) does scan, so the assertion bites. */
    public function test_the_old_or_rule_would_have_scanned_every_document(): void
    {
        $plan = array_map(fn ($r) => (string) $r->detail, DB::select(
            'EXPLAIN QUERY PLAN select "id" from "documents" where ("owner_id" = ? or exists (select * from "document_collaborators" '
            .'where "documents"."id" = "document_collaborators"."document_id" and "user_id" = ?) or "team_id" = ?) '
            .'and "deleted_at" is null order by "updated_at" desc limit 12',
            [1, 1, 1],
        ));

        $this->assertNotEmpty(preg_grep('/^SCAN documents\b/', $plan));
    }
}
