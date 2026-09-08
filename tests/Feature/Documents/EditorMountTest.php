<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Livewire\Documents\Editor;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditorMountTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_page_ships_json_style_and_mount_hook(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Mount me');
        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertSee('id="doc-style"', false)
            ->assertSee('DotDoc.mount', false)
            ->assertSee('&quot;type&quot;:&quot;doc&quot;', false);
    }

    public function test_editor_page_seeds_the_outline_so_headings_render_numbered_before_the_first_save(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $topId = BlockId::generate();
        $doc = app(DocumentStore::class)->create($user, 'Seeded', [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['id' => $topId, 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Overview']]],
            ],
        ]);

        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertSee('data-outline', false)
            ->assertSee('&quot;'.$topId.'&quot;:&quot;1&quot;', false);
    }

    public function test_editor_page_loads_alpine_only_once(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'One Alpine');

        // Livewire bundles and boots its own Alpine. A second copy (the
        // alpinejs CDN tag this layout used to carry) takes the
        // window.Alpine slot first and Livewire dies on
        // "window.Alpine.cloneNode is not a function" - every wire:click,
        // wire:model and $wire call on the page stops working.
        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertDontSee('alpinejs', false);
    }

    public function test_outline_returns_numbers_and_toc_for_numbered_headings(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $topId = BlockId::generate();
        $subId = BlockId::generate();
        $doc = app(DocumentStore::class)->create($user, 'Numbered', [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['id' => $topId, 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Overview']]],
                ['type' => 'heading', 'attrs' => ['id' => $subId, 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Detail']]],
            ],
        ]);

        $outline = Livewire::actingAs($user)
            ->test(Editor::class, ['uuid' => $doc->uuid])
            ->instance()
            ->outline();

        $this->assertSame('1', $outline['numbers'][$topId]);
        $this->assertSame('1.1', $outline['numbers'][$subId]);
        $this->assertSame(['1', '1.1'], array_column($outline['toc'], 'number'));
        $this->assertSame(['Overview', 'Detail'], array_column($outline['toc'], 'text'));
    }

    public function test_outline_lists_figures_and_tables_with_their_caption_text(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $figureId = BlockId::generate();
        $tableId = BlockId::generate();
        $doc = app(DocumentStore::class)->create($user, 'Referenced', [
            'type' => 'doc',
            'content' => [
                ['type' => 'figure', 'attrs' => ['id' => $figureId, 'kind' => 'image'], 'content' => [
                    ['type' => 'image', 'attrs' => ['id' => BlockId::generate(), 'src' => '/storage/x.png']],
                    ['type' => 'caption', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => 'Yield by region']]],
                ]],
                ['type' => 'figure', 'attrs' => ['id' => $tableId, 'kind' => 'table'], 'content' => [
                    ['type' => 'table', 'attrs' => ['id' => BlockId::generate()], 'content' => []],
                    ['type' => 'caption', 'attrs' => ['id' => BlockId::generate()], 'content' => []],
                ]],
            ],
        ]);

        $outline = Livewire::actingAs($user)
            ->test(Editor::class, ['uuid' => $doc->uuid])
            ->instance()
            ->outline();

        // The cross-reference picker offers figures and tables beside
        // headings; without these keys it could only list headings.
        $this->assertArrayHasKey('figures', $outline);
        $this->assertArrayHasKey('tables', $outline);
        $this->assertSame([['id' => $figureId, 'number' => '1', 'text' => 'Yield by region']], $outline['figures']);
        $this->assertSame([['id' => $tableId, 'number' => '1', 'text' => '']], $outline['tables']);
    }

    public function test_outline_authorises_view_on_every_call_not_only_at_mount(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Private');

        $component = Livewire::actingAs($user)
            ->test(Editor::class, ['uuid' => $doc->uuid])
            ->instance();
        $this->assertArrayHasKey('numbers', $component->outline());

        // Livewire hydrates a component from its snapshot on every later
        // request WITHOUT re-running mount(), and the editor's JS calls
        // outline() directly after every save - so it authorises for itself.
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger);

        $this->expectException(AuthorizationException::class);
        $component->outline();
    }

    public function test_a_rejected_save_is_reported_to_the_browser_and_shown_on_the_page(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Rejected');
        $version = $doc->version;

        // saveContent() returns a bool because $wire actions resolve with the
        // PHP return value: the bridge keeps the offline draft when it is
        // false, so a rejected save cannot silently lose the writer's work.
        Livewire::actingAs($user)
            ->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', ['type' => 'doc', 'content' => [
                ['type' => 'mermaidDiagram', 'attrs' => ['id' => BlockId::generate()]],
            ]])
            ->assertReturned(false)
            ->assertHasErrors('content')
            ->assertSee('Not saved');

        $this->assertSame($version, $doc->fresh()->version);
    }
}
