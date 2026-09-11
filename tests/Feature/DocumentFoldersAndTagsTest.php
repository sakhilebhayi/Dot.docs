<?php

namespace Tests\Feature;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Documents\DocumentSettings;
use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filing and tagging, now that filing IS the shared Dot.Files tree.
 *
 * These cases used to exercise Dot.Doc's own `folders` table and
 * `documents.folder_id`; 2026_09_08_000003 retired both, so every folder
 * here is an `objects` row and every id the component takes is that row's
 * id. The BEHAVIOUR each case pins is unchanged apart from one deliberate
 * break, called out on its own test: deleting a folder with things in it
 * is now refused rather than silently orphaning them.
 */
class DocumentFoldersAndTagsTest extends TestCase
{
    use RefreshDatabase;

    private function files(): FilesService
    {
        return app(FilesService::class);
    }

    private function root(User $user): Obj
    {
        return $this->files()->root($user->currentTeam ?? $user->personalTeam());
    }

    private function folder(User $user, string $name, ?Obj $parent = null): Obj
    {
        return $this->files()->createFolder($parent ?? $this->root($user), $name, $user);
    }

    private function personalDocument(User $user, ?Obj $parent = null, string $title = 'Doc', string $body = 'content'): Document
    {
        return app(DocumentStore::class)->create($user, $title, [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]]],
        ], [], $parent);
    }

    public function test_documents_index_only_shows_documents_in_the_current_folder(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $folder = $this->folder($user, 'Reports');

        $rootDoc = $this->personalDocument($user, null, 'At the root');
        $inFolderDoc = $this->personalDocument($user, $folder, 'Inside Reports');

        Livewire::test(Index::class)
            ->assertSee($rootDoc->title)
            ->assertDontSee($inFolderDoc->title)
            ->call('openFolder', $folder->id)
            ->assertSee($inFolderDoc->title)
            ->assertDontSee($rootDoc->title);
    }

    public function test_search_looks_across_every_folder_not_just_the_current_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $folder = $this->folder($user, 'Reports');
        $buried = $this->personalDocument($user, $folder, 'Quarterly Numbers');

        // Standing at the root, but searching should still find the buried doc.
        Livewire::test(Index::class)
            ->set('search', 'Quarterly')
            ->assertSee($buried->title);
    }

    public function test_search_matches_document_content_not_just_title(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $document = $this->personalDocument($user, null, 'Untitled', 'This mentions unobtainium specifically.');

        Livewire::test(Index::class)
            ->set('search', 'unobtainium')
            ->assertSee($document->title);
    }

    public function test_owner_can_create_a_subfolder_and_navigate_into_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $component = Livewire::test(Index::class)
            ->set('newFolderName', 'Reports')
            ->call('createFolder');

        $folder = Obj::where('team_id', $user->personalTeam()->id)
            ->where('objectable_type', 'folder')
            ->get()
            ->first(fn (Obj $obj) => $obj->name() === 'Reports');

        $this->assertNotNull($folder);
        $this->assertSame($this->root($user)->id, $folder->parent_id);

        $component->call('openFolder', $folder->id)
            ->set('newFolderName', '2026')
            ->call('createFolder');

        $subfolder = Obj::where('objectable_type', 'folder')->get()->first(fn (Obj $obj) => $obj->name() === '2026');
        $this->assertSame($folder->id, $subfolder->parent_id);
    }

    /**
     * The deliberate behaviour change. The old folder table let a delete
     * cascade-null `documents.folder_id`, so deleting a folder quietly
     * rearranged someone's filing. The tree is shared with Dot.Files now and
     * a folder can hold real files as well as documents, so a non-empty
     * folder refuses instead - and nothing inside is touched either way.
     */
    public function test_deleting_a_folder_with_anything_in_it_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $folder = $this->folder($user, 'Reports');
        $document = $this->personalDocument($user, $folder);

        Livewire::test(Index::class)
            ->call('deleteFolder', $folder->id)
            ->assertHasErrors('folder');

        $this->assertNotNull(Obj::find($folder->id));
        $this->assertNotNull(Document::find($document->id));
        $this->assertSame($folder->id, $document->fresh()->node->parent_id);
    }

    public function test_an_empty_folder_deletes(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $folder = $this->folder($user, 'Reports');

        Livewire::test(Index::class)
            ->call('deleteFolder', $folder->id)
            ->assertHasNoErrors();

        $this->assertNull(Obj::find($folder->id));
    }

    /**
     * Renaming used to run through the browser's native prompt() from an inline
     * onclick - unstyled, untranslatable and untestable. It is a sheet now, so
     * the rename is a state machine the component owns.
     */
    public function test_renaming_a_folder_runs_through_the_sheet_not_a_browser_prompt(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($owner);

        $folder = $this->folder($owner, 'Reports');

        Livewire::test(Index::class)
            ->assertDontSee('prompt(', false)
            ->call('startRenamingFolder', $folder->id)
            ->assertSet('renamingFolderId', $folder->id)
            ->assertSet('renameFolderName', 'Reports')
            ->set('renameFolderName', '   ')
            ->call('renameFolder')
            ->assertHasErrors('renameFolderName')
            ->set('renameFolderName', 'Quarterlies')
            ->call('renameFolder')
            ->assertSet('renamingFolderId', null);

        $this->assertSame('Quarterlies', $folder->fresh()->name());
    }

    public function test_an_outsider_cannot_delete_or_rename_someone_elses_folder(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner);
        $folder = $this->folder($owner, 'Reports');

        $this->actingAs($outsider);

        Livewire::test(Index::class)
            ->call('deleteFolder', $folder->id)
            ->assertForbidden();

        Livewire::test(Index::class)
            ->call('startRenamingFolder', $folder->id)
            ->assertForbidden();

        Livewire::test(Index::class)
            ->call('renameFolder', $folder->id, 'Taken')
            ->assertForbidden();

        $this->assertNotNull(Obj::find($folder->id));
        $this->assertSame('Reports', $folder->fresh()->name());
    }

    /** Nor can they merely LOOK inside it by putting its id in the query string. */
    public function test_an_outsider_opening_someone_elses_folder_lands_back_on_their_own_root(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner);
        $folder = $this->folder($owner, 'Reports');
        $secret = $this->personalDocument($owner, $folder, 'Owner only');

        $this->actingAs($outsider);

        Livewire::test(Index::class)
            ->call('openFolder', $folder->id)
            ->assertDontSee($secret->title)
            ->assertSet('folderId', null);
    }

    public function test_owner_can_add_and_remove_tags_on_a_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $document = $this->personalDocument($user);

        $component = Livewire::test(DocumentSettings::class, ['uuid' => $document->uuid])
            ->set('newTagName', 'Important')
            ->call('addTag');

        $tag = Tag::where('name', 'Important')->first();
        $this->assertNotNull($tag);
        $this->assertTrue($document->tags()->where('tags.id', $tag->id)->exists());

        $component->call('removeTag', $tag->id);
        $this->assertFalse($document->tags()->where('tags.id', $tag->id)->exists());
    }

    public function test_adding_the_same_tag_name_twice_reuses_the_existing_tag(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $documentOne = $this->personalDocument($user);
        $documentTwo = $this->personalDocument($user);

        Livewire::test(DocumentSettings::class, ['uuid' => $documentOne->uuid])
            ->set('newTagName', 'Important')
            ->call('addTag');

        Livewire::test(DocumentSettings::class, ['uuid' => $documentTwo->uuid])
            ->set('newTagName', 'Important')
            ->call('addTag');

        $this->assertSame(1, Tag::where('owner_id', $user->id)->where('name', 'Important')->count());
    }

    public function test_filtering_by_tag_searches_across_folders(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $folder = $this->folder($user, 'Reports');
        $document = $this->personalDocument($user, $folder);

        $tag = Tag::create(['owner_id' => $user->id, 'team_id' => $document->team_id, 'name' => 'Important']);
        $document->tags()->attach($tag->id);

        Livewire::test(Index::class)
            ->call('filterByTag', $tag->id)
            ->assertSee($document->title);
    }

    public function test_moving_a_document_to_the_root_via_the_settings_form_files_it_at_the_root(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $folder = $this->folder($user, 'Reports');
        $document = $this->personalDocument($user, $folder);

        // Empty string is what the "The root of this workspace" <option value=""> submits.
        Livewire::test(DocumentSettings::class, ['uuid' => $document->uuid])
            ->set('folderId', '')
            ->call('moveToFolder')
            ->assertHasNoErrors();

        $this->assertSame($this->root($user)->id, $document->fresh()->node->parent_id);
    }

    public function test_a_document_cannot_be_moved_into_a_folder_outside_its_own_scope(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $otherUser = User::factory()->withPersonalTeam()->create();

        $this->actingAs($otherUser);
        $foreignFolder = $this->folder($otherUser, 'Not Yours');

        $this->actingAs($owner);
        $document = $this->personalDocument($owner);
        $before = $document->node->parent_id;

        Livewire::test(DocumentSettings::class, ['uuid' => $document->uuid])
            ->set('folderId', $foreignFolder->id)
            ->call('moveToFolder')
            ->assertHasErrors('folderId');

        $this->assertSame($before, $document->fresh()->node->parent_id);
    }
}
