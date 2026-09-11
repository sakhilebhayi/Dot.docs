<?php

namespace Tests\Feature\Documents;

use App\Files\FilesService;
use App\Livewire\Documents\Index;
use App\Livewire\Documents\LocationPicker;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmartSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_document_from_the_smart_save_sheet_files_it_at_the_chosen_location(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->currentTeam);
        $reports = app(FilesService::class)->createFolder($root, 'Reports', $user);

        Livewire::actingAs($user)->test(Index::class)
            ->set('newDocumentName', 'Q3 Update')
            ->call('openSmartSave')
            ->set('newDocumentFolderId', $reports->id)
            ->call('createDocumentAtLocation')
            ->assertRedirect();

        $doc = Document::where('title', 'Q3 Update')->firstOrFail();
        $this->assertSame($reports->id, $doc->node->parent_id);
    }

    public function test_the_smart_save_sheet_defaults_to_the_folder_the_user_is_currently_viewing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->currentTeam);
        $drafts = app(FilesService::class)->createFolder($root, 'Drafts', $user);

        Livewire::actingAs($user)->test(Index::class, ['folderId' => $drafts->id])
            ->call('openSmartSave')
            ->assertSet('newDocumentFolderId', $drafts->id);
    }

    /**
     * `newDocumentFolderId` is a PUBLIC property, so the sheet's answer can be
     * rewritten on the wire without ever going through the picker. The tree's
     * stale-bookmark rule would quietly file the document in the writer's own
     * root; a sheet the reader just filled in is not a stale bookmark, so this
     * is refused instead.
     */
    public function test_a_folder_in_another_team_cannot_be_named_as_the_location(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $strangersRoot = app(FilesService::class)->root($stranger->currentTeam);

        Livewire::actingAs($user)->test(Index::class)
            ->call('openSmartSave')
            ->set('newDocumentName', 'Not yours')
            ->set('newDocumentFolderId', $strangersRoot->id)
            ->call('createDocumentAtLocation')
            ->assertForbidden();

        $this->assertDatabaseMissing('documents', ['title' => 'Not yours']);
    }

    public function test_the_sheet_can_start_the_document_from_a_template(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $template = DocumentTemplate::create([
            'name' => 'Weekly report',
            'category' => 'report',
            'content' => '<h1>Weekly report</h1><p>What changed.</p>',
            'is_global' => true,
            'team_id' => null,
            'created_by' => $user->id,
            'style_key' => 'memo',
        ]);

        Livewire::actingAs($user)->test(Index::class)
            ->call('openSmartSave')
            ->set('newDocumentName', 'Week 12')
            ->set('newDocumentTemplateId', $template->id)
            ->call('createDocumentAtLocation')
            ->assertRedirect();

        $doc = Document::where('title', 'Week 12')->firstOrFail();
        $this->assertSame('memo', $doc->style_key);
        $this->assertStringContainsString('What changed.', $doc->search_text ?? '');
    }

    public function test_a_template_from_another_team_cannot_be_used(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();

        $theirs = DocumentTemplate::create([
            'name' => 'Their private template',
            'category' => 'general',
            'content' => '<p>Private</p>',
            'is_global' => false,
            'team_id' => $stranger->currentTeam->id,
            'created_by' => $stranger->id,
        ]);

        // The visibility scope refuses it the same way
        // TemplateGallery::useTemplate() does - firstOrFail(), which is a 404
        // over HTTP and the raw exception here, where nothing has translated
        // it yet.
        try {
            Livewire::actingAs($user)->test(Index::class)
                ->call('openSmartSave')
                ->set('newDocumentName', 'Borrowed')
                ->set('newDocumentTemplateId', $theirs->id)
                ->call('createDocumentAtLocation');

            $this->fail('Another team\'s private template was accepted.');
        } catch (ModelNotFoundException) {
            // refused, which is the point
        }

        $this->assertDatabaseMissing('documents', ['title' => 'Borrowed']);
    }

    public function test_the_sheet_embeds_the_location_picker_and_names_the_destination(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->currentTeam);
        app(FilesService::class)->createFolder($root, 'Reports', $user);

        Livewire::actingAs($user)->test(Index::class)
            ->call('openSmartSave')
            ->assertSeeLivewire(LocationPicker::class)
            ->assertSee('Location')
            ->assertSee('Start from');
    }

    /**
     * The name survives the sheet being opened, because a control that has
     * already collected one can open it - clearing the field under somebody
     * who has just typed into it is worse than a stale value they can see.
     */
    public function test_opening_the_sheet_does_not_clear_a_name_already_typed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)->test(Index::class)
            ->set('newDocumentName', 'Half typed')
            ->call('openSmartSave')
            ->assertSet('newDocumentName', 'Half typed');
    }
}
