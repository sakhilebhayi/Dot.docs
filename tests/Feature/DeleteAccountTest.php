<?php

namespace Tests\Feature;

use App\Actions\Jetstream\DeleteUser;
use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Livewire\Livewire;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_accounts_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $this->actingAs($user = User::factory()->create());

        $component = Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser');

        $this->assertNull($user->fresh());
    }

    /**
     * `documents.owner_id` cascades at the DATABASE level, so deleting an
     * account hard-deletes every document that person owned - including the
     * ones filed in ANOTHER team they merely belonged to. That cascade never
     * reaches DocumentObserver or FilesService, so without an explicit step
     * each of those documents would leave its `objects` row behind in a tree
     * the account had nothing to do with: invisible in every listing, yet
     * still counting as "something is filed in here" forever after.
     *
     * The documents going with the account is a tracked limitation, recorded
     * in .ai/rules/files.md - what this pins is that the TREE is left clean.
     */
    public function test_deleting_an_account_takes_its_documents_tree_rows_with_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $leaving = User::factory()->withPersonalTeam()->create();

        $team = $owner->currentTeam;
        $team->users()->attach($leaving, ['role' => 'editor']);

        $files = app(FilesService::class);
        $folder = $files->createFolder($files->root($team), 'Reports', $owner);

        $document = app(DocumentStore::class)->create(
            $leaving,
            'Filed in a team that survives me',
            null,
            ['team_id' => $team->id],
            $folder,
        );

        $node = $document->node()->first();
        $this->assertNotNull($node);

        app(DeleteUser::class)->delete($leaving);

        $this->assertNull(Document::withTrashed()->find($document->id), 'the FK cascade is the documented limitation');
        $this->assertNull(Obj::find($node->id), 'but it must not leave a node dangling in another team’s tree');

        // Which is the point: the folder is genuinely empty, so the team can
        // still tidy it away afterwards.
        $files->deleteObject($folder->fresh(), $owner);
        $this->assertNull(Obj::find($folder->id));
    }

    public function test_correct_password_must_be_provided_before_account_can_be_deleted(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }

        $this->actingAs($user = User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->set('password', 'wrong-password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
