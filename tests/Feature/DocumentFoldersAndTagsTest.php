<?php

namespace Tests\Feature;

use App\Livewire\Documents\DocumentSettings;
use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentFoldersAndTagsTest extends TestCase
{
    use RefreshDatabase;

    private function personalDocument(User $user, ?int $folderId = null): Document
    {
        return Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Doc',
            'content' => 'content',
            'owner_id' => $user->id,
            'team_id' => null,
            'folder_id' => $folderId,
            'version' => 1,
            'is_public' => false,
        ]);
    }

    public function test_documents_index_only_shows_documents_in_the_current_folder(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $folder = Folder::create(['owner_id' => $user->id, 'team_id' => null, 'name' => 'Reports']);

        $rootDoc = $this->personalDocument($user);
        $inFolderDoc = $this->personalDocument($user, $folder->id);

        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSee($rootDoc->title)
            ->call('openFolder', $folder->id)
            ->assertSee($inFolderDoc->title);
    }

    public function test_search_looks_across_every_folder_not_just_the_current_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $folder = Folder::create(['owner_id' => $user->id, 'team_id' => null, 'name' => 'Reports']);

        $buried = Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Quarterly Numbers',
            'content' => 'content',
            'owner_id' => $user->id,
            'team_id' => null,
            'folder_id' => $folder->id,
            'version' => 1,
            'is_public' => false,
        ]);

        $this->actingAs($user);

        // Root folder, but searching should still find the buried doc.
        Livewire::test(Index::class)
            ->set('search', 'Quarterly')
            ->assertSee($buried->title);
    }

    public function test_search_matches_document_content_not_just_title(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Untitled',
            'content' => 'This mentions unobtainium specifically.',
            'owner_id' => $user->id,
            'team_id' => null,
            'version' => 1,
            'is_public' => false,
        ]);

        $this->actingAs($user);

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

        $folder = Folder::where('owner_id', $user->id)->where('name', 'Reports')->first();
        $this->assertNotNull($folder);
        $this->assertNull($folder->parent_id);

        $component->call('openFolder', $folder->id)
            ->set('newFolderName', '2026')
            ->call('createFolder');

        $subfolder = Folder::where('name', '2026')->first();
        $this->assertSame($folder->id, $subfolder->parent_id);
    }

    public function test_deleting_a_folder_orphans_its_documents_instead_of_deleting_them(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $folder = Folder::create(['owner_id' => $user->id, 'team_id' => null, 'name' => 'Reports']);
        $document = $this->personalDocument($user, $folder->id);

        $this->actingAs($user);
        Livewire::test(Index::class)->call('deleteFolder', $folder->id);

        $this->assertNull(Folder::find($folder->id));
        $this->assertNotNull(Document::find($document->id));
        $this->assertNull($document->fresh()->folder_id);
    }

    /**
     * Renaming used to run through the browser's native prompt() from an inline
     * onclick - unstyled, untranslatable and untestable. It is a sheet now, so
     * the rename is a state machine the component owns.
     */
    public function test_renaming_a_folder_runs_through_the_sheet_not_a_browser_prompt(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $folder = Folder::create(['owner_id' => $owner->id, 'team_id' => null, 'name' => 'Reports']);

        $this->actingAs($owner);

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

        $this->assertSame('Quarterlies', $folder->fresh()->name);
    }

    public function test_an_outsider_cannot_delete_or_rename_someone_elses_folder(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $folder = Folder::create(['owner_id' => $owner->id, 'team_id' => null, 'name' => 'Reports']);

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

        $this->assertNotNull(Folder::find($folder->id));
        $this->assertSame('Reports', $folder->fresh()->name);
    }

    public function test_owner_can_add_and_remove_tags_on_a_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $document = $this->personalDocument($user);

        $this->actingAs($user);

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
        $documentOne = $this->personalDocument($user);
        $documentTwo = $this->personalDocument($user);

        $this->actingAs($user);

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
        $folder = Folder::create(['owner_id' => $user->id, 'team_id' => null, 'name' => 'Reports']);
        $document = $this->personalDocument($user, $folder->id);
        $tag = Tag::create(['owner_id' => $user->id, 'team_id' => null, 'name' => 'Important']);
        $document->tags()->attach($tag->id);

        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('filterByTag', $tag->id)
            ->assertSee($document->title);
    }

    public function test_moving_a_document_to_root_via_the_settings_form_does_not_violate_the_foreign_key(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $folder = Folder::create(['owner_id' => $user->id, 'team_id' => null, 'name' => 'Reports']);
        $document = $this->personalDocument($user, $folder->id);

        $this->actingAs($user);

        // Empty string is what the "No folder (root)" <option value=""> submits.
        Livewire::test(DocumentSettings::class, ['uuid' => $document->uuid])
            ->set('folderId', '')
            ->call('moveToFolder')
            ->assertHasNoErrors();

        $this->assertNull($document->fresh()->folder_id);
    }

    public function test_a_document_cannot_be_moved_into_a_folder_outside_its_own_scope(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $otherUser = User::factory()->withPersonalTeam()->create();
        $foreignFolder = Folder::create(['owner_id' => $otherUser->id, 'team_id' => null, 'name' => 'Not Yours']);
        $document = $this->personalDocument($owner);

        $this->actingAs($owner);

        Livewire::test(DocumentSettings::class, ['uuid' => $document->uuid])
            ->set('folderId', $foreignFolder->id)
            ->call('moveToFolder')
            ->assertHasErrors('folderId');

        $this->assertNull($document->fresh()->folder_id);
    }
}
