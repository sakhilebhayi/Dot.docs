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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    /**
     * A folder in ANOTHER of this person's own teams is a legitimate
     * destination, and the document has to land in that team - not in the one
     * that happens to be active in the session.
     *
     * DocumentStore::create() defaults team_id to $owner->currentTeam, while
     * FilesService::registerDocument() stamps the tree node with
     * $parent->team_id. Without the location's team in $attrs the two
     * disagree: the node says team B and the document says team A, so team B
     * sees a title in the Navigator it cannot open (children() does not run
     * DocumentPolicy) and the writer loses the document the moment they switch
     * teams. Navigator::createDocument()/importHere() pass the parent's team
     * for exactly this reason.
     */
    public function test_filing_into_another_of_the_users_teams_takes_that_teams_id(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = $user->ownedTeams()->create(['name' => 'Second team', 'personal_team' => false]);
        $board = app(FilesService::class)->createFolder(
            app(FilesService::class)->root($other),
            'Board',
            $user,
        );

        Livewire::actingAs($user)->test(Index::class)
            ->call('openSmartSave')
            ->set('newDocumentName', 'Board pack')
            ->set('newDocumentFolderId', $board->id)
            ->call('createDocumentAtLocation')
            ->assertRedirect();

        $doc = Document::where('title', 'Board pack')->firstOrFail();

        $this->assertNotSame($other->id, $user->currentTeam->id, 'the two teams must differ for this to prove anything');
        $this->assertSame($other->id, $doc->team_id, 'the document belongs to the team it was filed in');
        $this->assertSame($other->id, $doc->node->team_id, 'and the tree node agrees with it');
    }

    /**
     * The race the sheet is open across: the chosen folder is deleted between
     * the picker naming it and the document being created. That is a 404 the
     * writer can read, NOT the 403 a foreign folder gets and not a silent
     * refiling into their own root.
     */
    public function test_a_location_deleted_while_the_sheet_was_open_is_a_404_with_a_message(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->currentTeam);
        $reports = app(FilesService::class)->createFolder($root, 'Reports', $user);

        $sheet = Livewire::actingAs($user)->test(Index::class)
            ->call('openSmartSave')
            ->set('newDocumentName', 'Q4 Update')
            ->set('newDocumentFolderId', $reports->id);

        // Held on to before the refusal: a call that aborts leaves the
        // Testable with no state to hand an instance back from.
        $sheetComponent = $sheet->instance();

        app(FilesService::class)->deleteObject($reports, $user);

        // 404, not the 403 a folder in someone else's team gets: the two
        // failures are told apart, which watching the stale-bookmark fallback
        // substitute could never do.
        $sheet->call('createDocumentAtLocation')->assertStatus(404);

        // The MESSAGE cannot be read back off the wire. Livewire's
        // RequestBroker deliberately keeps framework handling for
        // HttpException, so what comes back is the stock error view - which
        // prints "Not Found" and drops whatever abort() was given. Calling the
        // action on the hydrated component is the only way to read it.
        try {
            $sheetComponent->createDocumentAtLocation();

            $this->fail('A deleted location was accepted.');
        } catch (NotFoundHttpException $e) {
            $this->assertStringContainsString('no longer there', $e->getMessage());
        }

        $this->assertDatabaseMissing('documents', ['title' => 'Q4 Update']);
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
            ->assertSee('Start from')
            // Two navigation landmarks on one page need two names. The ledger
            // behind the sheet already has an "Folder path" breadcrumb, so the
            // picker's is the destination's.
            ->assertSeeHtml('aria-label="Destination folder path"')
            ->assertSeeHtml('aria-label="Folder path"');
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
