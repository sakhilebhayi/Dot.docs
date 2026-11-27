<?php

namespace Tests\Feature\Files;

use App\Files\FilesService;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** R2-3 / F-05: Files > Import a document makes a real document from the file, or nothing at all. */
class ImportIntoFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_file_becomes_a_document_in_that_folder_titled_after_the_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->withPersonalTeam()->create();
        $files = app(FilesService::class);
        $folder = $files->createFolder($files->root($user->personalTeam()), 'Board', $user);

        $response = $this->actingAs($user)->post(route('files.import', $folder->uuid), [
            'file' => UploadedFile::fake()->createWithContent('Minutes March.md', "# Minutes\n\nAll agreed."),
        ]);

        $doc = Document::sole();
        $response->assertRedirect(route('documents.edit', $doc->uuid));
        $this->assertSame('Minutes March', $doc->title);
        $this->assertStringContainsString('All agreed.', (string) $doc->search_text);
        $this->assertSame($folder->id, $doc->node()->firstOrFail()->parent_id);
    }

    public function test_an_unreadable_file_leaves_no_document_behind(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($user->personalTeam());

        $this->actingAs($user)->post(route('files.import', $root->uuid), [
            'file' => UploadedFile::fake()->createWithContent('broken.docx', 'PK not really a word file'),
        ])->assertRedirect()->assertSessionHasErrors('file', null, 'import');

        $this->assertSame(0, Document::withTrashed()->count());
    }

    public function test_someone_who_cannot_add_to_the_folder_is_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $root = app(FilesService::class)->root($owner->personalTeam());

        $this->actingAs(User::factory()->withPersonalTeam()->create())->post(route('files.import', $root->uuid), [
            'file' => UploadedFile::fake()->createWithContent('x.md', 'x'),
        ])->assertForbidden();

        $this->assertSame(0, Document::count());
    }

    public function test_the_files_page_offers_the_real_import_form(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('files.index'))->assertOk()
            ->assertSee('id="files-import-form"', false)
            ->assertDontSee('wire:click="importHere"', false);
    }
}
