<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Documents\Bin;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-3 / F-09: a deleted document can be brought back, by whoever could delete it. */
class BinTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Document} */
    private function deleted(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Quarterly plan');
        app(DocumentStore::class)->save($doc->fresh(), ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'v2']]]]], $owner);

        $files = app(FilesService::class);
        $files->deleteObject($doc->fresh()->node()->firstOrFail(), $owner);

        return [$owner, $doc];
    }

    public function test_a_deleted_document_is_listed_and_restore_brings_it_back_with_its_history_into_files(): void
    {
        [$owner, $doc] = $this->deleted();
        $this->assertSoftDeleted($doc);
        $versionsBefore = $doc->versions()->count();

        Livewire::actingAs($owner)->test(Bin::class)
            ->assertSee('Quarterly plan')
            ->call('restore', $doc->uuid)
            ->assertSee('is back, at the top of Files')
            ->assertDontSee('Deleted ');

        $doc = $doc->fresh();
        $this->assertNull($doc->deleted_at);
        $this->assertSame($versionsBefore, $doc->versions()->count());
        $root = app(FilesService::class)->root($owner->personalTeam());
        $this->assertSame($root->id, $doc->node()->firstOrFail()->parent_id);
        $this->assertSame(['document.deleted', 'document.restored'], AuditLog::where('subject_id', $doc->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_the_bin_page_is_reachable_from_the_shell(): void
    {
        [$owner] = $this->deleted();

        $this->actingAs($owner)->get(route('documents.bin'))->assertOk()->assertSee('Quarterly plan');
        $this->actingAs($owner)->get(route('documents.index'))->assertSee(route('documents.bin'), false);
    }

    public function test_a_company_admin_may_restore_and_a_viewer_or_stranger_may_not(): void
    {
        [$owner, $doc] = $this->deleted();
        $team = $owner->currentTeam;
        $admin = User::factory()->create(['current_team_id' => $team->id]);
        $viewer = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($admin, ['role' => 'admin']);
        $team->users()->attach($viewer, ['role' => 'viewer']);
        $stranger = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($viewer)->test(Bin::class)->assertDontSee('Quarterly plan');
        Livewire::actingAs($stranger)->test(Bin::class)->assertDontSee('Quarterly plan');
        // A colleague without delete rights: refused. Someone from another company: not even found.
        Livewire::actingAs($viewer)->test(Bin::class)->call('restore', $doc->uuid)->assertForbidden();
        Livewire::actingAs($stranger)->test(Bin::class)->call('restore', $doc->uuid)->assertNotFound();
        $this->assertSoftDeleted($doc);

        Livewire::actingAs($admin)->test(Bin::class)->assertSee('Quarterly plan')->call('restore', $doc->uuid);
        $this->assertNull($doc->fresh()->deleted_at);
    }

    public function test_a_published_document_comes_back_private_and_the_message_says_so(): void
    {
        [$owner, $doc] = $this->deleted();
        Document::withTrashed()->whereKey($doc->id)->update(['is_public' => true]);

        Livewire::actingAs($owner)->test(Bin::class)
            ->call('restore', $doc->uuid)
            ->assertSee('Its public link is off');

        $this->assertFalse((bool) $doc->fresh()->is_public);
    }
}
