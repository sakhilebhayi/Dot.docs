<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\ShareManager;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-9: who may change a collaborator's role, and that every such change is in the audit log. */
class CollaboratorRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $editor;

    private User $admin;

    private Document $doc;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->editor = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->doc = app(DocumentStore::class)->create($this->owner, 'Plan');
        DocumentCollaborator::create(['document_id' => $this->doc->id, 'user_id' => $this->editor->id, 'role' => 'editor']);
        DocumentCollaborator::create(['document_id' => $this->doc->id, 'user_id' => $this->admin->id, 'role' => 'admin']);
    }

    private function roleOf(User $user): ?string
    {
        return DocumentCollaborator::where('document_id', $this->doc->id)->where('user_id', $user->id)->value('role');
    }

    public function test_an_editor_cannot_demote_an_admin_collaborator(): void
    {
        Livewire::actingAs($this->editor)->test(ShareManager::class, ['uuid' => $this->doc->uuid])
            ->set('inviteEmail', $this->admin->email)->set('inviteRole', 'viewer')
            ->call('invite')->assertForbidden();

        $this->assertSame('admin', $this->roleOf($this->admin));
    }

    public function test_an_editor_cannot_remove_an_admin_collaborator_but_can_remove_a_viewer(): void
    {
        $viewer = User::factory()->create();
        DocumentCollaborator::create(['document_id' => $this->doc->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $adminRow = DocumentCollaborator::where('user_id', $this->admin->id)->value('id');
        $viewerRow = DocumentCollaborator::where('user_id', $viewer->id)->value('id');

        Livewire::actingAs($this->editor)->test(ShareManager::class, ['uuid' => $this->doc->uuid])
            ->call('removeCollaborator', $adminRow)->assertForbidden();
        $this->assertSame('admin', $this->roleOf($this->admin));

        Livewire::actingAs($this->editor)->test(ShareManager::class, ['uuid' => $this->doc->uuid])
            ->call('removeCollaborator', $viewerRow)->assertOk();
        $this->assertNull($this->roleOf($viewer));
    }

    public function test_the_owner_can_change_and_remove_an_admin_and_every_change_is_audited(): void
    {
        $newcomer = User::factory()->create();
        $component = Livewire::actingAs($this->owner)->test(ShareManager::class, ['uuid' => $this->doc->uuid]);

        $component->set('inviteEmail', $newcomer->email)->set('inviteRole', 'viewer')->call('invite')->assertHasNoErrors();
        $component->set('inviteEmail', $this->admin->email)->set('inviteRole', 'editor')->call('invite')->assertHasNoErrors();
        $component->call('removeCollaborator', DocumentCollaborator::where('user_id', $this->admin->id)->value('id'));

        $rows = DB::table('audit_logs')->where('subject_id', $this->doc->id)->where('action', 'like', 'collaborator.%')
            ->orderBy('id')->get(['action', 'context'])->map(fn ($r) => [$r->action, json_decode((string) $r->context, true)])->all();

        $this->assertSame([
            ['collaborator.added', ['user_id' => $newcomer->id, 'role' => 'viewer']],
            ['collaborator.role_changed', ['user_id' => $this->admin->id, 'role' => 'editor', 'previous_role' => 'admin']],
            ['collaborator.removed', ['user_id' => $this->admin->id, 'role' => 'editor']],
        ], $rows);
    }

    public function test_sharing_gives_the_same_answer_whether_or_not_the_address_has_an_account(): void
    {
        $known = User::factory()->create(['email' => 'known@example.test']);

        $withAccount = Livewire::actingAs($this->owner)->test(ShareManager::class, ['uuid' => $this->doc->uuid])
            ->set('inviteEmail', $known->email)->call('invite')
            ->assertHasNoErrors();
        $withoutAccount = Livewire::actingAs($this->owner)->test(ShareManager::class, ['uuid' => $this->doc->uuid])
            ->set('inviteEmail', 'nobody@example.test')->call('invite')
            ->assertHasNoErrors();

        $this->assertSame(ShareManager::SHARED_NOTICE, $withAccount->get('inviteNotice'));
        $this->assertSame(ShareManager::SHARED_NOTICE, $withoutAccount->get('inviteNotice'));
        $withoutAccount->assertSet('inviteEmail', '');
        $this->assertSame('viewer', $this->roleOf($known));
    }

    public function test_sharing_is_limited_per_person_per_hour(): void
    {
        $page = Livewire::actingAs($this->owner)->test(ShareManager::class, ['uuid' => $this->doc->uuid]);

        for ($i = 0; $i < ShareManager::SHARES_PER_USER_PER_HOUR; $i++) {
            $page->set('inviteEmail', "person{$i}@example.test")->call('invite')->assertHasNoErrors();
        }

        $page->set('inviteEmail', 'one-more@example.test')->call('invite')->assertHasErrors('inviteEmail');
    }

    public function test_sharing_is_limited_per_company_per_hour(): void
    {
        RateLimiter::increment('document-share:team:'.$this->doc->team_id, 3600, ShareManager::SHARES_PER_TEAM_PER_HOUR);
        $known = User::factory()->create();

        Livewire::actingAs($this->editor)->test(ShareManager::class, ['uuid' => $this->doc->uuid])
            ->set('inviteEmail', $known->email)->call('invite')
            ->assertHasErrors('inviteEmail');

        $this->assertNull($this->roleOf($known));
    }
}
