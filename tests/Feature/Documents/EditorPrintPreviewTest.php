<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * togglePrintPreview() swaps $styleCss (see Editor::render()) between the
 * canvas and print stylesheets from App\Styles\StyleEngine::css() - the
 * print stylesheet is distinguished by carrying an @page rule (see
 * App\Styles\CssBuilder::build(), which only emits pageRule() in 'print'
 * mode), which the canvas stylesheet never has.
 */
class EditorPrintPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggling_print_preview_swaps_the_style_css_to_the_print_stylesheet(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');

        $component = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        $component->assertDontSee('@page', false);

        $component->call('togglePrintPreview')
            ->assertSee('@page', false);
    }
}
