<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\ShareManager;
use App\Livewire\Documents\VersionHistory;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A change to who may open a document takes effect on the very next
 * request - not fifteen minutes later.
 *
 * Every case here asks the policy BEFORE the change as well as after it:
 * that first answer is what used to be cached, and the stale copy is what
 * made the second answer wrong. The second question is asked the way a new
 * request would ask it - with the user and the document freshly loaded -
 * so that relations already in memory from the first cannot answer it.
 */
class DocumentAccessFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private function privateDocument(User $owner): Document
    {
        return app(DocumentStore::class)->create($owner, 'Private doc');
    }

    private function collaboratorRow(Document $doc, User $user): DocumentCollaborator
    {
        return DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $user->id)->firstOrFail();
    }

    public function test_a_removed_collaborator_is_refused_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $collaborator = User::factory()->withPersonalTeam()->create();
        $doc = $this->privateDocument($owner);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $collaborator->id, 'role' => 'editor']);

        $this->assertTrue($collaborator->can('view', $doc));
        $this->assertTrue($collaborator->can('update', $doc));

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->call('removeCollaborator', $this->collaboratorRow($doc, $collaborator)->id);

        $this->assertFalse($collaborator->fresh()->can('view', $doc->fresh()));
        $this->assertFalse($collaborator->fresh()->can('update', $doc->fresh()));

        Livewire::actingAs($collaborator->fresh())->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertForbidden();
    }

    /**
     * Anything that asked "can this person see it?" before the invitation -
     * a failed attempt to open a link they were sent early, say - used to
     * leave a cached "no" that outlived the invitation.
     */
    public function test_someone_invited_after_being_refused_is_admitted_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $invitee = User::factory()->withPersonalTeam()->create();
        $doc = $this->privateDocument($owner);

        $this->assertFalse($invitee->can('view', $doc));
        $this->assertFalse($invitee->can('update', $doc));

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->set('inviteEmail', $invitee->email)
            ->set('inviteRole', 'editor')
            ->call('invite')
            ->assertHasNoErrors();

        $this->assertTrue($invitee->fresh()->can('view', $doc->fresh()));
        $this->assertTrue($invitee->fresh()->can('update', $doc->fresh()));

        Livewire::actingAs($invitee->fresh())->test(Editor::class, ['uuid' => $doc->uuid])
            ->assertOk();
    }

    public function test_changing_a_collaborators_role_takes_effect_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $collaborator = User::factory()->withPersonalTeam()->create();
        $doc = $this->privateDocument($owner);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $collaborator->id, 'role' => 'viewer']);

        $this->assertFalse($collaborator->can('update', $doc));

        $share = Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid]);

        $share->set('inviteEmail', $collaborator->email)->set('inviteRole', 'editor')->call('invite');
        $this->assertTrue($collaborator->fresh()->can('update', $doc->fresh()), 'Promoted to editor.');

        $share->set('inviteEmail', $collaborator->email)->set('inviteRole', 'viewer')->call('invite');
        $this->assertFalse($collaborator->fresh()->can('update', $doc->fresh()), 'Demoted back to viewer.');
    }

    public function test_someone_removed_from_the_documents_team_is_refused_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $doc = $this->privateDocument($owner);

        $this->assertTrue($member->can('view', $doc));
        $this->assertTrue($member->can('update', $doc));

        Livewire::actingAs($owner)->test(TeamMemberManager::class, ['team' => $owner->currentTeam])
            ->set('teamMemberIdBeingRemoved', $member->id)
            ->call('removeTeamMember');

        $this->assertFalse($member->fresh()->can('view', $doc->fresh()));
        $this->assertFalse($member->fresh()->can('update', $doc->fresh()));
    }

    public function test_someone_added_to_the_documents_team_is_admitted_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $newMember = User::factory()->withPersonalTeam()->create();
        $doc = $this->privateDocument($owner);

        $this->assertFalse($newMember->can('view', $doc));

        app(AddsTeamMembers::class)->add($owner, $owner->currentTeam, $newMember->email, 'viewer');

        $this->assertTrue($newMember->fresh()->can('view', $doc->fresh()));
    }

    public function test_someone_who_leaves_the_documents_team_is_refused_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $doc = $this->privateDocument($owner);

        $this->assertTrue($member->can('view', $doc));

        Livewire::actingAs($member)->test(TeamMemberManager::class, ['team' => $owner->currentTeam])
            ->call('leaveTeam');

        $this->assertFalse($member->fresh()->can('view', $doc->fresh()));
    }

    /**
     * Losing access has to reach a page that is ALREADY open, not only the
     * next page load: a Livewire component that checks the policy in
     * mount() alone goes on answering every later request from that tab -
     * here, previewing a version saved after the person was removed.
     */
    public function test_an_already_open_version_history_stops_working_once_access_is_removed(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $collaborator = User::factory()->withPersonalTeam()->create();
        $doc = $this->privateDocument($owner);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $collaborator->id, 'role' => 'viewer']);

        $history = Livewire::actingAs($collaborator)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->assertOk();

        $this->collaboratorRow($doc, $collaborator)->delete();
        $later = DocumentVersion::create([
            'document_id' => $doc->id,
            'content_snapshot' => '<p>Written after they were removed.</p>',
            'version_number' => 99,
            'created_by' => $owner->id,
            'created_at' => now(),
        ]);

        $history->call('preview', $later->id)
            ->assertForbidden()
            ->assertDontSee('Written after they were removed.');
    }

    public function test_an_already_open_comment_thread_stops_working_once_access_is_removed(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $collaborator = User::factory()->withPersonalTeam()->create();
        $doc = $this->privateDocument($owner);
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $collaborator->id, 'role' => 'viewer']);

        $thread = Livewire::actingAs($collaborator)->test(CommentThread::class, ['document' => $doc])
            ->assertOk();

        $this->collaboratorRow($doc, $collaborator)->delete();
        Comment::create(['document_id' => $doc->id, 'user_id' => $owner->id, 'content' => 'Said after they were removed.']);

        $thread->call('$refresh')
            ->assertForbidden()
            ->assertDontSee('Said after they were removed.');
    }

    public function test_changing_a_team_members_role_takes_effect_at_once(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $doc = $this->privateDocument($owner);

        $this->assertTrue($member->can('update', $doc));

        Livewire::actingAs($owner)->test(TeamMemberManager::class, ['team' => $owner->currentTeam])
            ->call('manageRole', $member->id)
            ->set('currentRole', 'viewer')
            ->call('updateRole');

        $this->assertFalse($member->fresh()->can('update', $doc->fresh()));
        $this->assertTrue($member->fresh()->can('view', $doc->fresh()));
    }
}
