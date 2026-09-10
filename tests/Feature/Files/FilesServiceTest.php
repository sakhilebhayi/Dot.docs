<?php

namespace Tests\Feature\Files;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Files\UniqueName;
use App\Models\Document;
use App\Models\Files\File;
use App\Models\Files\Folder;
use App\Models\Files\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FilesServiceTest extends TestCase
{
    use RefreshDatabase;

    private FilesService $files;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        $this->files = app(FilesService::class);
    }

    private function member(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    public function test_root_is_created_once_and_written_as_rows_dot_files_can_read(): void
    {
        $user = $this->member();
        $team = $user->personalTeam();

        $root = $this->files->root($team);
        $again = $this->files->root($team);

        $this->assertSame($root->id, $again->id);
        $this->assertNull($root->parent_id);
        $this->assertSame('folder', $root->objectable_type);
        $this->assertDatabaseHas('objects', [
            'id' => $root->id,
            'objectable_type' => 'folder',
            'team_id' => $team->id,
            'parent_id' => null,
        ]);
        $this->assertDatabaseHas('folders', ['id' => $root->objectable_id, 'team_id' => $team->id]);
        $this->assertSame(1, Obj::where('team_id', $team->id)->whereNull('parent_id')->count());
    }

    public function test_creating_a_folder_inserts_objects_and_folders_rows(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());

        $folder = $this->files->createFolder($root, 'Reports', $user);

        $this->assertDatabaseHas('objects', [
            'id' => $folder->id,
            'objectable_type' => 'folder',
            'parent_id' => $root->id,
            'team_id' => $root->team_id,
        ]);
        $this->assertDatabaseHas('folders', ['id' => $folder->objectable_id, 'name' => 'Reports']);
        $this->assertNotEmpty($folder->uuid);
    }

    public function test_the_team_comes_from_the_parent_never_from_the_current_team(): void
    {
        $user = $this->member();
        $other = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $user->personalTeam()->id])->save();

        $otherRoot = $this->files->root($other);
        $folder = $this->files->createFolder($otherRoot, 'Elsewhere', $user->fresh());

        $this->assertSame($other->id, $folder->team_id);
        $this->assertNotSame($user->personalTeam()->id, $folder->team_id);
        $this->assertDatabaseHas('folders', ['id' => $folder->objectable_id, 'team_id' => $other->id]);
    }

    public function test_sibling_names_are_made_unique(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());

        $this->files->createFolder($root, 'Report', $user);
        $second = $this->files->createFolder($root, 'Report', $user);
        $third = $this->files->createFolder($root, 'report', $user);

        $this->assertSame('Report (2)', $second->name());
        $this->assertSame('report (3)', $third->name());
        $this->assertSame('Fresh', UniqueName::for($root, 'Fresh'));
    }

    public function test_a_document_created_through_the_store_lands_in_the_tree(): void
    {
        $user = $this->member();

        $document = app(DocumentStore::class)->create($user, 'Quarterly');

        $node = $document->node()->first();
        $this->assertNotNull($node);
        $this->assertSame('document', $node->objectable_type);
        $this->assertSame($this->files->root($user->personalTeam())->id, $node->parent_id);
        $this->assertInstanceOf(Folder::class, $document->fresh()->folder());
    }

    public function test_a_document_can_be_created_straight_into_a_folder(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());
        $folder = $this->files->createFolder($root, 'Reports', $user);

        $document = app(DocumentStore::class)->create($user, 'Buried', null, [], $folder);

        $this->assertSame($folder->id, $document->node()->first()->parent_id);
        $this->assertSame('Reports', $document->fresh()->folder()->name);
    }

    public function test_children_are_listed_folders_then_documents_then_files(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());

        $this->files->createFile($root, 'notes.txt', 'hello', 'text/plain', $user);
        app(DocumentStore::class)->create($user, 'A document');
        $this->files->createFolder($root, 'Zed folder', $user);

        $kinds = $this->files->children($root)->map(fn (Obj $o) => $o->kind())->all();

        $this->assertSame(['folder', 'document', 'file'], $kinds);
    }

    public function test_uploading_a_file_writes_the_blob_and_the_rows(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());

        $node = $this->files->createFile($root, 'notes.txt', 'hello there', 'text/plain', $user);

        /** @var File $file */
        $file = $node->objectable;
        $this->assertSame('notes.txt', $file->name);
        $this->assertSame(11, $file->size);
        $this->assertSame('text/plain', $file->mime_type);
        $this->assertSame($user->id, $file->owner_id);
        $this->assertStringEndsWith('.txt', $file->path);
        $this->assertStringStartsWith('documents/'.$root->team_id.'/', $file->path);
        Storage::disk('files')->assertExists($file->path);
    }

    public function test_moving_refuses_the_root_a_foreign_team_and_its_own_subtree(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());
        $parent = $this->files->createFolder($root, 'Parent', $user);
        $child = $this->files->createFolder($parent, 'Child', $user);

        try {
            $this->files->moveObject($root, $parent, $user);
            $this->fail('The root was moved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('root', $e->getMessage());
        }

        try {
            $this->files->moveObject($parent, $child, $user);
            $this->fail('A folder was filed inside itself.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }

        $stranger = User::factory()->withPersonalTeam()->create();
        $strangerRoot = $this->files->root($stranger->personalTeam());

        $this->expectException(AuthorizationException::class);
        $this->files->moveObject($parent, $strangerRoot, $user);
    }

    public function test_moving_a_document_relocates_it(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());
        $folder = $this->files->createFolder($root, 'Reports', $user);
        $document = app(DocumentStore::class)->create($user, 'Quarterly');

        $moved = $this->files->moveObject($document->node()->first(), $folder, $user);

        $this->assertSame($folder->id, $moved->parent_id);
        $this->assertSame('Reports', $document->fresh()->folder()->name);
    }

    public function test_renaming_touches_the_folder_name_and_the_document_title(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());
        $folder = $this->files->createFolder($root, 'Reports', $user);
        $document = app(DocumentStore::class)->create($user, 'Quarterly');

        $this->files->renameObject($folder, 'Quarterlies', $user);
        $this->files->renameObject($document->node()->first(), 'Q3 numbers', $user);

        $this->assertDatabaseHas('folders', ['id' => $folder->objectable_id, 'name' => 'Quarterlies']);
        $this->assertSame('Q3 numbers', $document->fresh()->title);
    }

    public function test_names_with_slashes_or_control_characters_are_refused(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());

        $this->expectException(ValidationException::class);
        $this->files->createFolder($root, '../escape', $user);
    }

    public function test_deleting_a_document_node_soft_deletes_the_document(): void
    {
        $user = $this->member();
        $document = app(DocumentStore::class)->create($user, 'Quarterly');
        $node = $document->node()->first();

        $this->files->deleteObject($node, $user);

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('objects', ['id' => $node->id]);
        $this->assertNotNull(Document::withTrashed()->find($document->id));
    }

    public function test_a_restored_document_is_filed_again(): void
    {
        $user = $this->member();
        $document = app(DocumentStore::class)->create($user, 'Quarterly');
        $this->files->deleteObject($document->node()->first(), $user);

        Document::withTrashed()->find($document->id)->restore();

        $this->assertNotNull($document->fresh()->node()->first());
    }

    public function test_a_folder_with_anything_in_it_refuses_to_be_deleted(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());
        $folder = $this->files->createFolder($root, 'Reports', $user);
        $document = app(DocumentStore::class)->create($user, 'Buried', null, [], $folder);

        try {
            $this->files->deleteObject($folder, $user);
            $this->fail('A non-empty folder was deleted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Empty the folder first', $e->getMessage());
        }

        $this->assertDatabaseHas('objects', ['id' => $folder->id]);
        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_deleting_a_file_takes_its_blob_with_it(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());
        $node = $this->files->createFile($root, 'notes.txt', 'hello', 'text/plain', $user);
        $path = $node->objectable->path;

        $this->files->deleteObject($node, $user);

        Storage::disk('files')->assertMissing($path);
        $this->assertDatabaseMissing('files', ['path' => $path]);
        $this->assertDatabaseMissing('objects', ['id' => $node->id]);
    }

    public function test_the_root_cannot_be_deleted(): void
    {
        $user = $this->member();
        $root = $this->files->root($user->personalTeam());

        $this->expectException(ValidationException::class);
        $this->files->deleteObject($root, $user);
    }
}
