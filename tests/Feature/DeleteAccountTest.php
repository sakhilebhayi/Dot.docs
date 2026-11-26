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
     * EA-2 (owner decision 2026-11-26): a document the leaver filed in a team
     * that stays passes to that team's owner and stays exactly where it is -
     * same team, same folder, same tree node. Before EA-2 the FK cascade
     * hard-deleted it. More cases in Security\AccountDeletionTest.
     */
    public function test_a_document_filed_in_a_team_that_survives_stays_filed_under_the_team_owner(): void
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

        $document = Document::find($document->id);
        $this->assertNotNull($document);
        $this->assertSame($owner->id, (int) $document->owner_id);
        $this->assertSame($team->id, (int) $document->team_id);
        $this->assertNotNull(Obj::find($node->id), 'still filed in the same folder');
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
