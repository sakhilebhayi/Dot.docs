<?php

namespace Tests\Feature\Security;

use App\Actions\Jetstream\DeleteUser;
use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Livewire\Documents\VersionHistory;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\DocumentWebhook;
use App\Models\Files\Obj;
use App\Models\User;
use App\Notifications\CommentPostedEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-2 (enterprise audit S-03): the owner's four rules for deleting an account,
 * decided 2026-11-26. Before this, the database cascade destroyed every
 * document the person owned (other companies' included), every version they
 * wrote and their comments, with no audit row.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User} the company owner and a member who will leave */
    private function companyWithMember(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $member->switchTeam($owner->currentTeam);

        return [$owner, $member->fresh()];
    }

    public function test_rule_1_a_company_document_passes_to_the_team_owner_and_is_audited(): void
    {
        [$owner, $member] = $this->companyWithMember();
        $doc = app(DocumentStore::class)->create($member, 'Quarterly report');

        app(DeleteUser::class)->delete($member);

        $doc = Document::find($doc->id);
        $this->assertNotNull($doc);
        $this->assertSame($owner->id, (int) $doc->owner_id);
        $this->assertSame($owner->currentTeam->id, (int) $doc->team_id);
        $this->assertTrue($owner->can('update', $doc));

        $row = AuditLog::where('action', 'document.owner_transferred')->firstOrFail();
        $this->assertSame($doc->id, (int) $row->subject_id);
        $this->assertSame('account_deleted', $row->context['reason']);
    }

    public function test_rule_1_a_company_document_already_in_the_bin_also_passes_to_the_owner(): void
    {
        [$owner, $member] = $this->companyWithMember();
        $doc = app(DocumentStore::class)->create($member, 'Binned plan');
        $doc->delete();

        app(DeleteUser::class)->delete($member);

        $doc = Document::withTrashed()->find($doc->id);
        $this->assertSame($owner->id, (int) $doc->owner_id, 'never left ownerless, so never purged');
    }

    public function test_rule_2_a_personal_document_is_recoverable_for_30_days_then_purged(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'My notes');
        $loose = app(DocumentStore::class)->create($user, 'No team at all', null, ['team_id' => null]);

        app(DeleteUser::class)->delete($user);

        foreach ([$doc, $loose] as $d) {
            $kept = Document::withTrashed()->find($d->id);
            $this->assertNotNull($kept, 'recoverable, not gone');
            $this->assertTrue($kept->trashed());
            $this->assertNull($kept->owner_id);
        }

        $this->travel(29)->days();
        $this->artisan('doc:purge-deleted-accounts')->assertSuccessful();
        $this->assertNotNull(Document::withTrashed()->find($doc->id), 'still inside the 30 days');

        $this->travel(2)->days();
        $this->artisan('doc:purge-deleted-accounts')->assertSuccessful();
        $this->assertNull(Document::withTrashed()->find($doc->id));
        $this->assertNull(Document::withTrashed()->find($loose->id));
    }

    public function test_the_purge_never_touches_binned_documents_of_people_who_still_have_an_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'In my bin');
        $doc->delete();

        $this->travel(90)->days();
        $this->artisan('doc:purge-deleted-accounts')->assertSuccessful();

        $this->assertNotNull(Document::withTrashed()->find($doc->id));
    }

    public function test_rule_3_an_owner_of_a_team_with_members_cannot_delete_their_account(): void
    {
        [$owner] = $this->companyWithMember();
        $doc = app(DocumentStore::class)->create($owner, 'Company handbook');

        try {
            app(DeleteUser::class)->delete($owner);
            $this->fail('deletion should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Another member must become its owner', $e->errors()['password'][0]);
        }

        $this->assertNotNull($owner->fresh());
        $this->assertNotNull($owner->currentTeam->fresh());
        $this->assertNotNull(Document::find($doc->id));
    }

    public function test_rule_3_the_delete_form_shows_the_refusal(): void
    {
        [$owner] = $this->companyWithMember();

        $this->actingAs($owner);
        Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($owner->fresh());
    }

    public function test_rule_4_comments_and_versions_on_colleagues_documents_stay_as_former_member(): void
    {
        [$owner, $member] = $this->companyWithMember();
        $store = app(DocumentStore::class);
        $doc = $store->create($owner, 'Shared plan');
        $json = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Edited by the member']]]]];
        $store->save($doc, $json, $member, ['kind' => 'manual']);
        Comment::create(['document_id' => $doc->id, 'user_id' => $member->id, 'content' => 'My review note']);

        $versionsBefore = DocumentVersion::where('document_id', $doc->id)->count();

        app(DeleteUser::class)->delete($member);

        $this->assertSame($versionsBefore, DocumentVersion::where('document_id', $doc->id)->count());
        $this->assertTrue(DocumentVersion::where('document_id', $doc->id)->whereNull('created_by')->exists());
        $comment = Comment::where('content', 'My review note')->firstOrFail();
        $this->assertNull($comment->user_id);

        $this->actingAs($owner);
        Livewire::test(CommentThread::class, ['document' => $doc->fresh()])->assertSee('Former member')->assertSee('My review note');
        Livewire::test(VersionHistory::class, ['uuid' => $doc->uuid])->assertSee('Former member');
    }

    public function test_a_personal_document_binned_long_ago_still_gets_its_30_days(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Old draft');
        $doc->delete();
        Document::withTrashed()->whereKey($doc->id)->update(['deleted_at' => now()->subDays(60)]);

        app(DeleteUser::class)->delete($user);
        $this->artisan('doc:purge-deleted-accounts')->assertSuccessful();

        $this->assertNotNull(Document::withTrashed()->find($doc->id), 'the 30 days start at the account deletion');
    }

    public function test_every_purge_is_audited(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Gone later');
        app(DeleteUser::class)->delete($user);

        $this->travel(31)->days();
        $this->artisan('doc:purge-deleted-accounts')->assertSuccessful();

        $this->assertTrue(AuditLog::where('action', 'document.purged')->where('subject_id', $doc->id)->exists());
    }

    public function test_another_persons_document_in_a_team_that_goes_moves_to_their_own_team(): void
    {
        $leaver = User::factory()->withPersonalTeam()->create();
        $former = User::factory()->withPersonalTeam()->create();
        $team = $leaver->currentTeam;
        $doc = app(DocumentStore::class)->create($former, 'Written while a member', null, ['team_id' => $team->id]);
        // They have since left the team; it now has no other members.

        app(DeleteUser::class)->delete($leaver);

        $doc = Document::find($doc->id);
        $this->assertNotNull($doc);
        $this->assertSame($former->personalTeam()->id, (int) $doc->team_id);
        $this->assertNotNull(Obj::where('objectable_type', 'document')->where('objectable_id', $doc->id)->first(), 'listed in their tree');
        $this->assertTrue($former->can('view', $doc));
    }

    public function test_the_leavers_webhooks_on_a_handed_over_document_keep_running_under_the_new_owner(): void
    {
        [$owner, $member] = $this->companyWithMember();
        $doc = app(DocumentStore::class)->create($member, 'Integrated');
        $hook = DocumentWebhook::create(['document_id' => $doc->id, 'user_id' => $member->id, 'url' => 'https://93.184.216.34/hook', 'events' => ['on_save'], 'status' => 'active']);

        app(DeleteUser::class)->delete($member);

        $this->assertSame($owner->id, (int) $hook->fresh()->user_id);
    }

    public function test_support_can_move_team_ownership_so_the_owner_can_then_leave(): void
    {
        [$owner, $member] = $this->companyWithMember();
        $team = $owner->currentTeam;

        $this->artisan('doc:transfer-team-ownership', ['team' => $team->id, 'email' => $member->email])->assertSuccessful();

        $team->refresh();
        $this->assertSame($member->id, (int) $team->user_id);
        $this->assertTrue($team->users()->whereKey($owner->id)->exists(), 'the old owner stays as a member');
        $this->assertTrue(AuditLog::where('action', 'team.owner_transferred')->exists());

        $doc = app(DocumentStore::class)->create($owner, 'Handbook', null, ['team_id' => $team->id]);
        app(DeleteUser::class)->delete($owner->fresh());

        $this->assertSame($member->id, (int) Document::find($doc->id)->owner_id);
        $this->assertNotNull($team->fresh());
    }

    public function test_ownership_cannot_go_to_someone_outside_the_team(): void
    {
        [$owner] = $this->companyWithMember();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->artisan('doc:transfer-team-ownership', ['team' => $owner->currentTeam->id, 'email' => $stranger->email])->assertFailed();
        $this->assertSame($owner->id, (int) $owner->currentTeam->fresh()->user_id);
    }

    public function test_support_can_restore_a_deleted_accounts_document_to_a_living_account(): void
    {
        $leaver = User::factory()->withPersonalTeam()->create();
        $heir = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($leaver, 'Wanted after all');
        app(DeleteUser::class)->delete($leaver);

        $this->artisan('doc:restore-deleted-account-document', ['uuid' => $doc->uuid, 'email' => $heir->email])->assertSuccessful();

        $doc = Document::find($doc->id);
        $this->assertNotNull($doc, 'live again');
        $this->assertSame($heir->id, (int) $doc->owner_id);
        $this->assertTrue($heir->can('update', $doc));
        $this->assertNotNull(Obj::where('objectable_type', 'document')->where('objectable_id', $doc->id)->first());
    }

    public function test_a_comment_notice_for_an_author_who_has_since_left_still_renders(): void
    {
        [$owner, $member] = $this->companyWithMember();
        $doc = app(DocumentStore::class)->create($owner, 'Plan');
        $comment = Comment::create(['document_id' => $doc->id, 'user_id' => $member->id, 'content' => 'Note']);
        $mail = new CommentPostedEmailNotification($doc, $comment);

        app(DeleteUser::class)->delete($member);

        $this->assertStringContainsString('Former member', implode(' ', array_map('strval', $mail->toMail($owner)->introLines)));
    }
}
