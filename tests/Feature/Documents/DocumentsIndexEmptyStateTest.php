<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Documents\Index;
use App\Models\Files\Obj;
use App\Models\Tag;
use App\Models\User;
use App\Services\TagRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The documents listing when it has nothing to list.
 *
 * Spec §4: an empty state is one sentence that names what is actually going
 * on, plus at least one action that gets you out of it. The first pass had two
 * reachable states that broke that: filtering by a TAG with an empty search
 * box produced `Nothing here matches "".` and offered to clear a search that
 * was already empty (leaving the tag on, with no way out of the panel), and an
 * empty folder that still held SUBFOLDERS fell through to the list branch and
 * rendered nothing at all — no sentence, no action.
 *
 * Every reachable combination gets a case here.
 */
class DocumentsIndexEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    private function root(User $user): Obj
    {
        return app(FilesService::class)->root($user->currentTeam ?? $user->personalTeam());
    }

    private function tag(User $user, string $name): Tag
    {
        return app(TagRepository::class)->findOrCreate($user, $user->currentTeam?->id, $name);
    }

    public function test_nothing_filed_and_nothing_filtered_offers_a_place_to_start(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)->test(Index::class)
            ->assertSee('Start with an idea.')
            ->assertSee('Blank document')
            ->assertSee('Use a template')
            ->assertDontSee('Nothing here matches');
    }

    /**
     * Folders but no documents. This used to render an empty <ul> and say
     * nothing whatsoever.
     */
    public function test_a_folder_holding_only_subfolders_still_says_something(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(FilesService::class)->createFolder($this->root($user), 'Reports', $user);

        Livewire::actingAs($user)->test(Index::class)
            ->assertSee('Nothing is filed here yet.')
            ->assertSee('Blank document')
            ->assertSee('Use a template');
    }

    public function test_a_search_that_matches_nothing_names_the_search_and_clears_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(DocumentStore::class)->create($user, 'Quarterly review');

        Livewire::actingAs($user)->test(Index::class)
            ->set('search', 'zzzqqq')
            ->assertSee('Nothing here matches "zzzqqq".')
            ->assertSee('Clear the search')
            ->assertDontSee('Start with an idea.')
            ->call('$set', 'search', '')
            ->assertSee('Quarterly review');
    }

    /**
     * The bug this file was written for: a tag filter with an empty search box
     * must never say `matches ""`, and "Clear the search" must never be the
     * only way offered out of a state the search box did not create.
     */
    public function test_a_tag_filter_that_matches_nothing_names_the_tag_and_clears_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(DocumentStore::class)->create($user, 'Quarterly review');
        $tag = $this->tag($user, 'Finance');

        Livewire::actingAs($user)->test(Index::class)
            ->call('filterByTag', $tag->id)
            ->assertSee('Nothing here matches the tag "Finance".')
            ->assertDontSee('Nothing here matches "".')
            ->assertDontSee('Clear the search')
            ->assertSee('Clear the tag filter')
            ->call('filterByTag', null)
            ->assertSee('Quarterly review');
    }

    public function test_a_search_and_a_tag_together_name_both_and_clear_either(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(DocumentStore::class)->create($user, 'Quarterly review');
        $tag = $this->tag($user, 'Finance');

        Livewire::actingAs($user)->test(Index::class)
            ->set('search', 'zzzqqq')
            ->call('filterByTag', $tag->id)
            ->assertSee('Nothing here matches "zzzqqq" and the tag "Finance".')
            ->assertSee('Clear the search')
            ->assertSee('Clear the tag filter');
    }

    /**
     * The rail links straight to ?filter=shared, so an empty scope is one click
     * away from every page. It is a filter like any other and names itself.
     */
    public function test_an_empty_scope_names_itself_and_offers_the_way_back(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(DocumentStore::class)->create($user, 'Quarterly review');

        Livewire::actingAs($user)->test(Index::class)
            ->set('filter', 'shared')
            ->assertSee('Nothing here matches the Shared scope.')
            ->assertSee('Show all documents')
            ->assertDontSee('Start with an idea.')
            ->call('$set', 'filter', 'all')
            ->assertSee('Quarterly review');
    }

    /**
     * Every empty state carries at least one action. Asserted structurally so a
     * later branch cannot be added without one.
     */
    public function test_every_empty_state_carries_an_action(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $tag = $this->tag($user, 'Finance');

        $states = [
            'nothing at all' => fn ($component) => $component,
            'search only' => fn ($component) => $component->set('search', 'zzzqqq'),
            'tag only' => fn ($component) => $component->call('filterByTag', $tag->id),
            'both' => fn ($component) => $component->set('search', 'zzzqqq')->call('filterByTag', $tag->id),
            'scope only' => fn ($component) => $component->set('filter', 'shared'),
        ];

        foreach ($states as $name => $arrange) {
            // Livewire wraps every @if in `<!--[if BLOCK]><![endif]-->`; the
            // markup is what is being measured here, not the conditional
            // bookkeeping around it.
            $html = preg_replace('/<!--\[if (?:BLOCK|ENDBLOCK)\]><!\[endif\]-->/', '', $arrange(Livewire::actingAs($user)->test(Index::class))->html());

            $this->assertSame(1, preg_match('/<div class="empty">(.*?)<\/div>\s*<\/div>/s', $html, $found), "{$name} rendered no empty state");
            $this->assertMatchesRegularExpression('/<p class="empty-line">\S.*?<\/p>/s', $found[1], "{$name} rendered no sentence");
            $this->assertMatchesRegularExpression('/<div class="empty-actions">\s*<(?:button|a)\b/s', $found[1], "{$name} rendered no action");
        }
    }
}
