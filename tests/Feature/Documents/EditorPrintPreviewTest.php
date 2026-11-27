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
 * Print preview is the editor's "Print preview" page view (pagination's print-preview mode: real page sizes and
 * breaks). The unused server toggle that swapped in the PRINT stylesheet was removed (R2-3 / F-13): that stylesheet
 * is written for the PDF renderer, whose @page rule supplies the margins, so on screen it put the text flush against
 * the paper's edge. The screen keeps the canvas stylesheet in every view.
 */
class EditorPrintPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_editor_offers_the_print_preview_page_view_and_keeps_the_screen_stylesheet(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertSeeHtml('<option value="print-preview">Print preview</option>')
            ->assertDontSee('@page', false);
    }
}
