<?php

namespace Tests\Feature\Files;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Files\Navigator;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The tree browser.
 *
 * Nothing here uses drag-and-drop, so nothing here needs a keyboard
 * fallback: Move is a sheet-based folder picker and every row action is a
 * button, which is what these cases exercise.
 */
class NavigatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    private function files(): FilesService
    {
        return app(FilesService::class);
    }

    private function signedIn(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_it_opens_on_the_team_root_and_lists_folders_then_documents_then_files(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());

        $this->files()->createFolder($root, 'Reports', $user);
        $this->files()->createFile($root, 'notes.txt', 'bytes', 'text/plain', $user);
        app(DocumentStore::class)->create($user, 'A document', null, [], $root);

        $component = Livewire::test(Navigator::class);

        $kinds = $component->instance()->rows()->map(fn (Obj $o) => $o->kind())->all();

        $this->assertSame(['folder', 'document', 'file'], $kinds);
        $component->assertSee('Reports')->assertSee('A document')->assertSee('notes.txt');
    }

    public function test_opening_a_folder_shows_only_what_is_in_it_and_a_crumb_trail(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());
        $reports = $this->files()->createFolder($root, 'Reports', $user);
        $this->files()->createFolder($reports, '2026', $user);
        $this->files()->createFolder($root, 'Elsewhere', $user);

        Livewire::test(Navigator::class)
            ->call('open', $reports->uuid)
            ->assertSee('2026')
            ->assertDontSee('Elsewhere')
            ->assertSeeInOrder([$root->name(), 'Reports']);
    }

    public function test_a_folder_from_another_team_falls_back_to_your_own_root(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger);
        $theirs = $this->files()->createFolder($this->files()->root($stranger->personalTeam()), 'Private', $stranger);
        $this->files()->createFolder($theirs, 'Very private', $stranger);

        $user = $this->signedIn();

        $component = Livewire::test(Navigator::class)->call('open', $theirs->uuid);

        $this->assertSame($this->files()->root($user->personalTeam())->id, $component->instance()->parent()->id);
        $component->assertDontSee('Very private');
    }

    public function test_new_folder_and_new_document_land_in_the_open_folder(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());
        $reports = $this->files()->createFolder($root, 'Reports', $user);

        Livewire::test(Navigator::class)
            ->call('open', $reports->uuid)
            ->set('newFolderName', '2026')
            ->call('createFolder')
            ->assertHasNoErrors();

        $subfolder = Obj::where('parent_id', $reports->id)->firstOrFail();
        $this->assertSame('2026', $subfolder->name());

        Livewire::test(Navigator::class)
            ->call('open', $reports->uuid)
            ->set('newTitle', 'Q1')
            ->call('createDocument');

        $document = Document::where('title', 'Q1')->firstOrFail();
        $this->assertSame($reports->id, $document->node->parent_id);
    }

    public function test_rename_runs_through_the_sheet(): void
    {
        $user = $this->signedIn();
        $folder = $this->files()->createFolder($this->files()->root($user->personalTeam()), 'Reports', $user);

        Livewire::test(Navigator::class)
            ->call('startRenaming', $folder->uuid)
            ->assertSet('renamingUuid', $folder->uuid)
            ->assertSet('renameName', 'Reports')
            ->set('renameName', '  ')
            ->call('renameObject')
            ->assertHasErrors('renameName')
            ->set('renameName', 'Quarterlies')
            ->call('renameObject')
            ->assertSet('renamingUuid', '');

        $this->assertSame('Quarterlies', $folder->fresh()->name());
    }

    public function test_move_files_a_node_under_the_chosen_folder_and_refuses_its_own_subtree(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());
        $reports = $this->files()->createFolder($root, 'Reports', $user);
        $inside = $this->files()->createFolder($reports, '2026', $user);

        Livewire::test(Navigator::class)
            ->call('startMoving', $inside->uuid)
            ->assertSet('movingUuid', $inside->uuid)
            ->call('moveTo', $root->uuid)
            ->assertHasNoErrors()
            ->assertSet('movingUuid', '');

        $this->assertSame($root->id, $inside->fresh()->parent_id);

        // And a folder cannot be filed inside itself.
        $this->files()->moveObject($inside->fresh(), $reports->fresh(), $user);

        Livewire::test(Navigator::class)
            ->call('startMoving', $reports->uuid)
            ->call('moveTo', $inside->uuid)
            ->assertHasErrors('object');

        $this->assertSame($root->id, $reports->fresh()->parent_id);
    }

    public function test_the_move_picker_only_offers_this_workspaces_folders(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger);
        $this->files()->createFolder($this->files()->root($stranger->personalTeam()), 'Theirs', $stranger);

        $user = $this->signedIn();
        $this->files()->createFolder($this->files()->root($user->personalTeam()), 'Mine', $user);

        $labels = array_column(Livewire::test(Navigator::class)->instance()->folderChoices(), 'label');

        $this->assertContains($user->personalTeam()->name.' / Mine', $labels);
        $this->assertEmpty(array_filter($labels, fn (string $l) => str_contains($l, 'Theirs')));
    }

    public function test_deleting_a_folder_with_things_in_it_is_refused_and_says_so(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());
        $reports = $this->files()->createFolder($root, 'Reports', $user);
        $this->files()->createFile($reports, 'notes.txt', 'bytes', 'text/plain', $user);

        Livewire::test(Navigator::class)
            ->call('deleteObject', $reports->uuid)
            ->assertHasErrors('object');

        $this->assertNotNull(Obj::find($reports->id));
    }

    public function test_uploading_a_file_files_it_in_the_open_folder(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());
        $reports = $this->files()->createFolder($root, 'Reports', $user);

        $this->post(route('files.upload', $reports->uuid), [
            'file' => UploadedFile::fake()->createWithContent('brief.txt', 'hello'),
        ])->assertRedirect();

        $node = Obj::where('parent_id', $reports->id)->where('objectable_type', 'file')->firstOrFail();

        $this->assertSame('brief.txt', $node->name());
        $this->assertSame(5, $node->objectable->size);
        Storage::disk('files')->assertExists($node->objectable->path);
    }

    public function test_uploading_into_another_teams_folder_is_refused(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger);
        $theirs = $this->files()->createFolder($this->files()->root($stranger->personalTeam()), 'Private', $stranger);

        $this->signedIn();

        $this->post(route('files.upload', $theirs->uuid), [
            'file' => UploadedFile::fake()->createWithContent('brief.txt', 'hello'),
        ])->assertForbidden();

        $this->assertSame(0, Obj::where('objectable_type', 'file')->count());
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        $user = $this->signedIn();
        $root = $this->files()->root($user->personalTeam());

        $this->post(route('files.upload', $root->uuid), [
            'file' => UploadedFile::fake()->create('huge.txt', 30000, 'text/plain'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Obj::where('objectable_type', 'file')->count());
    }
}
