<?php

namespace Tests\Feature\Files;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Documents\Editor;
use App\Models\Files\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Save to Dot.Files": the same export the download route renders, filed
 * beside the document instead of sent to the browser. Plus the editor's own
 * location chip and Move sheet, which are the other half of the same idea -
 * the document knows where it lives.
 */
class SaveExportToFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
        RateLimiter::clear('export:1');
    }

    private function files(): FilesService
    {
        return app(FilesService::class);
    }

    public function test_a_markdown_export_is_filed_next_to_the_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $root = $this->files()->root($user->personalTeam());
        $folder = $this->files()->createFolder($root, 'Reports', $user);
        $document = app(DocumentStore::class)->create($user, 'Quarterly Numbers', null, [], $folder);

        $this->post(route('documents.export.save-to-files', [$document->uuid, 'markdown']))
            ->assertRedirect();

        $node = Obj::where('parent_id', $folder->id)->where('objectable_type', 'file')->firstOrFail();

        $this->assertSame('quarterly-numbers.md', $node->name());
        $this->assertSame('text/markdown', $node->objectable->mime_type);
        Storage::disk('files')->assertExists($node->objectable->path);
        $this->assertStringContainsString('# Quarterly Numbers', Storage::disk('files')->get($node->objectable->path));
    }

    public function test_someone_who_cannot_view_the_document_cannot_file_its_export(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($owner);
        $document = app(DocumentStore::class)->create($owner, 'Private');

        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->post(route('documents.export.save-to-files', [$document->uuid, 'markdown']))
            ->assertForbidden();

        $this->assertSame(0, Obj::where('objectable_type', 'file')->count());
    }

    public function test_it_spends_from_the_same_ten_per_hour_bucket_as_a_download(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $document = app(DocumentStore::class)->create($user, 'Busy');

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('documents.export.save-to-files', [$document->uuid, 'markdown']))->assertRedirect();
        }

        $this->get(route('documents.export', [$document->uuid, 'markdown']))->assertStatus(429);
    }

    public function test_the_editor_shows_where_the_document_is_filed_and_moves_it_from_the_sheet(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $root = $this->files()->root($user->personalTeam());
        $reports = $this->files()->createFolder($root, 'Reports', $user);
        $archive = $this->files()->createFolder($root, 'Archive', $user);
        $document = app(DocumentStore::class)->create($user, 'Movable', null, [], $reports);

        $component = Livewire::test(Editor::class, ['uuid' => $document->uuid]);

        $this->assertSame(
            [$user->personalTeam()->name, 'Reports'],
            array_map(fn (Obj $o) => $o->name(), $component->instance()->locationCrumbs()),
        );

        $component->call('moveTo', $archive->id)
            ->assertHasNoErrors()
            ->assertSet('showMoveSheet', false);

        $this->assertSame($archive->id, $document->fresh()->node->parent_id);
    }

    public function test_the_editor_refuses_a_move_into_another_teams_folder(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger);
        $theirs = $this->files()->createFolder($this->files()->root($stranger->personalTeam()), 'Theirs', $stranger);

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $document = app(DocumentStore::class)->create($user, 'Mine');
        $before = $document->node->parent_id;

        Livewire::test(Editor::class, ['uuid' => $document->uuid])
            ->call('moveTo', $theirs->id)
            ->assertHasErrors('location');

        $this->assertSame($before, $document->fresh()->node->parent_id);
    }
}
