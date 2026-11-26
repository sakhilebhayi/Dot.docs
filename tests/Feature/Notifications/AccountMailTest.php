<?php

namespace Tests\Feature\Notifications;

use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Documents\DocumentStore;
use App\Livewire\Documents\ShareManager;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Notifications\Account\AccountDeleted;
use App\Notifications\Account\DocumentAccessRemoved;
use App\Notifications\Account\DocumentPublishedByAnother;
use App\Notifications\Account\DocumentSharedBell;
use App\Notifications\Account\DocumentSharedWithYou;
use App\Notifications\Account\EmailAddressChanged;
use App\Notifications\Account\PasswordChanged;
use App\Notifications\Account\RemovedFromTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-3 / E-04, F-11, E-03, E-05: people hear when their access or their
 * account changes. Every one of these mails is queued on the `mail` queue.
 */
class AccountMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sharing_a_document_tells_the_person_in_the_bell_and_by_email(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $colleague = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Board pack');

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->set('inviteEmail', $colleague->email)
            ->set('inviteRole', 'editor')
            ->call('invite');

        Notification::assertSentTo($colleague, DocumentSharedBell::class);
        Notification::assertSentTo($colleague, DocumentSharedWithYou::class, fn ($n) => $n->role === 'editor' && ! $n->roleChanged);
    }

    public function test_a_role_change_is_told_and_an_unchanged_role_is_not(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $colleague = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Board pack');
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $colleague->id, 'role' => 'viewer']);

        $page = Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid]);
        $page->set('inviteEmail', $colleague->email)->set('inviteRole', 'viewer')->call('invite');
        Notification::assertNothingSentTo($colleague);

        $page->set('inviteEmail', $colleague->email)->set('inviteRole', 'editor')->call('invite');
        Notification::assertSentTo($colleague, DocumentSharedWithYou::class, fn ($n) => $n->roleChanged);
    }

    public function test_removing_access_tells_the_person(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $colleague = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Board pack');
        $row = DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $colleague->id, 'role' => 'viewer']);

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])->call('removeCollaborator', $row->id);

        Notification::assertSentTo($colleague, DocumentAccessRemoved::class);
    }

    public function test_the_owner_is_told_when_a_team_admin_publishes_their_document(): void
    {
        Notification::fake();
        $teamOwner = User::factory()->withPersonalTeam()->create();
        $writer = User::factory()->withPersonalTeam()->create();
        $teamOwner->currentTeam->users()->attach($writer, ['role' => 'editor']);
        $doc = app(DocumentStore::class)->create($writer, 'Policy', null, ['team_id' => $teamOwner->currentTeam->id]);

        Livewire::actingAs($teamOwner)->test(ShareManager::class, ['uuid' => $doc->uuid])->call('togglePublicLink');
        Notification::assertSentTo($writer, DocumentPublishedByAnother::class, fn ($n) => $n->published);

        Notification::fake();
        Livewire::actingAs($writer)->test(ShareManager::class, ['uuid' => $doc->uuid])->call('togglePublicLink');
        Notification::assertNothingSent();
    }

    public function test_a_password_change_sends_a_security_notice(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'password',
            'password' => 'a-long-new-password',
            'password_confirmation' => 'a-long-new-password',
        ]);

        Notification::assertSentTo($user, PasswordChanged::class);
    }

    public function test_an_email_change_notifies_the_old_address(): void
    {
        Notification::fake();
        config(['dotsso.enabled' => false]);
        $user = User::factory()->create(['email' => 'old@example.test']);

        app(UpdateUserProfileInformation::class)->update($user, ['name' => $user->name, 'email' => 'new@example.test']);

        Notification::assertSentTo(new AnonymousNotifiable, EmailAddressChanged::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.test' && $n->newAddress === 'new@example.test');
    }

    public function test_with_central_sign_in_the_email_cannot_be_changed_here(): void
    {
        config(['dotsso.enabled' => true, 'dotsso.allow_local_login' => false]);
        $user = User::factory()->create(['email' => 'old@example.test']);

        $this->expectException(ValidationException::class);
        app(UpdateUserProfileInformation::class)->update($user, ['name' => $user->name, 'email' => 'new@example.test']);
    }

    public function test_being_removed_from_a_team_is_told_and_leaving_is_not(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create();
        $leaver = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->users()->attach($member, ['role' => 'editor']);
        $team->users()->attach($leaver, ['role' => 'editor']);

        app(RemoveTeamMember::class)->remove($owner, $team, $member);
        app(RemoveTeamMember::class)->remove($leaver, $team, $leaver);

        Notification::assertSentTo($member, RemovedFromTeam::class);
        Notification::assertNotSentTo($leaver, RemovedFromTeam::class);
    }

    public function test_deleting_an_account_sends_a_confirmation_to_its_address(): void
    {
        Notification::fake();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'gone@example.test']);

        app(DeleteUser::class)->delete($user);

        Notification::assertSentTo(new AnonymousNotifiable, AccountDeleted::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'gone@example.test');
    }

    public function test_access_mails_go_through_the_mail_queue_and_escape_user_text(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Eve [Verify](https://evil.test)']);
        $doc = app(DocumentStore::class)->create($owner, 'Plan <b>x</b>');
        $mail = new DocumentSharedWithYou($doc, 'viewer', $owner->name);

        $this->assertSame(['mail' => 'mail'], $mail->viaQueues());

        $html = (string) $mail->toMail($owner)->render();
        $this->assertStringNotContainsString('href="https://evil.test', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }
}
