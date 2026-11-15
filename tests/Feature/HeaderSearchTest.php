<?php

namespace Tests\Feature;

use App\Documents\DocumentStore;
use App\Livewire\HeaderSearch;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-52: the header search. Live results over the CURRENT company's documents (title and body text), on every page and at
 * every width (the pill is in the shared header). It is scoped through App\Search\CompanyDocuments, the arrival page's
 * company rule, then policy-checked row by row: another company's documents never appear, however public they are.
 */
class HeaderSearchTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $company): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $company, 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    private function write(User $author, ?Team $team, string $title, string $body = 'Plain body.'): Document
    {
        return app(DocumentStore::class)->create($author, $title, [
            'type' => 'doc',
            'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]]],
        ], $team ? ['team_id' => $team->id] : []);
    }

    public function test_it_finds_by_title_and_by_body_text_inside_the_current_company(): void
    {
        $acme = $this->member('Acme Ltd');
        $this->write($acme, $acme->currentTeam, 'Borrowdale rota', 'Zebra crossing schedule.');
        $this->write($acme, $acme->currentTeam, 'Other page', 'Nothing relevant.');

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('q', 'borrowdale')->assertSee('Borrowdale rota')->assertDontSee('Other page')
            ->set('q', 'zebra')->assertSee('Borrowdale rota')->assertDontSee('Other page');
    }

    public function test_it_never_shows_another_companys_documents_even_public_ones(): void
    {
        $acme = $this->member('Acme Ltd');
        $globex = $this->member('Globex Ltd');
        $this->write($acme, $acme->currentTeam, 'Acme zebra plan');
        $theirs = $this->write($globex, $globex->currentTeam, 'Globex zebra budget', 'Zebra herd.');
        $theirs->forceFill(['is_public' => true])->save();

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('q', 'zebra')->assertSee('Acme zebra plan')->assertDontSee('Globex zebra budget');

        Livewire::actingAs($globex)->test(HeaderSearch::class)
            ->set('q', 'zebra')->assertSee('Globex zebra budget')->assertDontSee('Acme zebra plan');
    }

    public function test_a_document_shared_by_name_from_another_company_is_not_listed_in_this_one(): void
    {
        $acme = $this->member('Acme Ltd');
        $globex = $this->member('Globex Ltd');
        $doc = $this->write($globex, $globex->currentTeam, 'Shared across companies');
        $doc->collaborators()->create(['user_id' => $acme->id, 'role' => 'viewer']);

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('q', 'Shared across')->assertDontSee('Shared across companies');
    }

    public function test_with_no_company_only_the_owners_unfiled_documents_show(): void
    {
        $solo = User::factory()->create();
        $solo->forceFill(['current_team_id' => null])->save();
        $other = User::factory()->create();

        $this->write($solo, null, 'My loose note');
        $this->write($other, null, 'Their loose note');

        Livewire::actingAs($solo->fresh())->test(HeaderSearch::class)
            ->set('q', 'loose note')->assertSee('My loose note')->assertDontSee('Their loose note');
    }

    public function test_the_policy_is_checked_after_the_scope(): void
    {
        $acme = $this->member('Acme Ltd');
        $globex = $this->member('Globex Ltd');
        $doc = $this->write($globex, $globex->currentTeam, 'Policy walled zebra');

        // A stale current team the person does not belong to: the scope would match the row, the policy refuses it.
        $acme->forceFill(['current_team_id' => $globex->currentTeam->id])->save();

        Livewire::actingAs($acme->fresh())->test(HeaderSearch::class)
            ->set('q', 'zebra')->assertDontSee('Policy walled zebra');
        $this->assertTrue($doc->exists);
    }

    public function test_wildcards_are_literal_and_short_queries_search_nothing(): void
    {
        $acme = $this->member('Acme Ltd');
        $this->write($acme, $acme->currentTeam, 'Quarterly report');

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('q', '%%')->assertDontSee('Quarterly report')->assertSee('No documents in Acme Ltd match')
            ->set('q', '_u')->assertDontSee('Quarterly report')
            ->set('q', 'q')->assertDontSee('Quarterly report')->assertDontSee('No documents');
    }

    public function test_the_empty_state_says_so(): void
    {
        $acme = $this->member('Acme Ltd');

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('q', 'nothing like this')->assertSee('No documents in Acme Ltd match');
    }

    public function test_a_result_opens_the_document_and_the_form_keeps_the_ledger_route(): void
    {
        $acme = $this->member('Acme Ltd');
        $doc = $this->write($acme, $acme->currentTeam, 'Findable page');

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('q', 'findable')->assertSeeHtml('href="'.route('documents.edit', $doc->uuid).'"')
            ->assertSeeHtml('action="'.route('documents.index').'"')->assertSeeHtml('name="q"');
    }

    public function test_the_pill_is_in_the_header_of_every_page_type(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = $this->member('Acme Ltd');
        $doc = $this->write($user, $user->currentTeam, 'Everywhere');

        foreach ([
            route('dashboard'), route('documents.index'), route('files.index'), route('slash-commands.index'),
            route('profile.show'), route('api-tokens.index'), route('teams.create'),
            route('documents.edit', $doc->uuid), route('documents.history', $doc->uuid),
            route('documents.share', $doc->uuid), route('documents.settings', $doc->uuid),
        ] as $url) {
            $this->app->forgetScopedInstances();
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match('/<header class="dh [^"]*shell-header.*?<\/header>/s', $html, $header), "{$url}: no shared header");
            $this->assertStringContainsString('data-dsr-nav', $header[0], "{$url}: the search is not in the header");
            $this->assertStringContainsString('placeholder="Search documents"', $header[0]);
            $this->assertStringContainsString('data-dh-menu-button', $header[0], "{$url}: no menu button for phones");
        }
    }

    public function test_ctrl_k_belongs_to_the_editor_there_and_to_the_search_everywhere_else(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = $this->member('Acme Ltd');
        $doc = $this->write($user, $user->currentTeam, 'Shortcut page');

        $page = $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-dsr-shortcut', $page);
        $this->assertStringContainsString('Ctrl K', $page);

        $this->app->forgetScopedInstances();
        $editor = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-dsr-shortcut', $editor, 'the pill must not bind the editor palette\'s key');
        $this->assertStringNotContainsString('dsr-kbd', $editor);
        $this->assertStringContainsString('aria-keyshortcuts="Meta+K Control+K"', $editor, 'the editor palette keeps its key');
    }

    public function test_a_guest_has_no_search_endpoint_to_call(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-dsr-nav', false);
    }
}
