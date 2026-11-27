<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\ShareManager;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Notifications\Access\AccessRequested;
use App\Notifications\Access\AccessRequestedBell;
use App\Notifications\Account\DocumentSharedWithYou;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-3b / U-06: a person a link cannot open asks the owner, who grants or declines. */
class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Document} owner, outsider, document */
    private function setUpDocument(): array
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner Person']);
        $outsider = User::factory()->withPersonalTeam()->create(['name' => 'Outside Asker']);

        return [$owner, $outsider, app(DocumentStore::class)->create($owner, 'Secret merger plan')];
    }

    public function test_the_page_offers_to_ask_and_says_nothing_about_the_document(): void
    {
        [, $outsider, $doc] = $this->setUpDocument();

        $this->actingAs($outsider)->get(route('documents.open', $doc->uuid))
            ->assertForbidden()
            ->assertSee('Ask for access')
            ->assertDontSee('Secret merger plan')
            ->assertDontSee('Owner Person');
    }

    public function test_asking_records_one_request_and_tells_the_owner_once(): void
    {
        Notification::fake();
        [$owner, $outsider, $doc] = $this->setUpDocument();

        $this->actingAs($outsider)->post(route('documents.request-access', $doc->uuid), ['message' => 'I run the audit'])
            ->assertRedirect(route('documents.open', $doc->uuid));
        $this->actingAs($outsider)->post(route('documents.request-access', $doc->uuid), ['message' => 'again'])
            ->assertSessionHas('access_request', 'already');

        $this->assertSame(1, AccessRequest::count());
        $this->assertSame('I run the audit', AccessRequest::sole()->message);
        Notification::assertSentToTimes($owner, AccessRequestedBell::class, 1);
        Notification::assertSentToTimes($owner, AccessRequested::class, 1);

        $this->actingAs($outsider)->get(route('documents.open', $doc->uuid))
            ->assertSee('You asked')->assertDontSee('name="message"', false);
    }

    public function test_requests_are_capped_per_hour(): void
    {
        Notification::fake();
        [$owner, $outsider] = $this->setUpDocument();

        for ($i = 0; $i < 6; $i++) {
            $doc = app(DocumentStore::class)->create($owner, "Doc {$i}");
            $response = $this->actingAs($outsider)->post(route('documents.request-access', $doc->uuid));
        }

        $response->assertSessionHasErrors('message');
        $this->assertSame(5, AccessRequest::count());
    }

    public function test_the_owner_grants_reading_and_the_asker_is_told_and_can_open_it(): void
    {
        Notification::fake();
        [$owner, $outsider, $doc] = $this->setUpDocument();
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING]);

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->assertSee('Asking for access')
            ->assertSee('Outside Asker')
            ->call('grantAccess', $ask->id, 'viewer')
            ->assertSee('Outside Asker can now read it');

        $this->assertSame('viewer', DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $outsider->id)->value('role'));
        $this->assertSame(AccessRequest::GRANTED, $ask->fresh()->status);
        $this->assertSame($owner->id, $ask->fresh()->decided_by);
        Notification::assertSentTo($outsider, DocumentSharedWithYou::class);
        $this->assertTrue(AuditLog::where('action', 'document.access_granted')->exists());

        $this->actingAs($outsider)->get(route('documents.open', $doc->uuid))->assertRedirect(route('documents.edit', $doc->uuid));
    }

    public function test_declining_closes_it_quietly(): void
    {
        Notification::fake();
        [$owner, $outsider, $doc] = $this->setUpDocument();
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING]);

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->call('declineAccess', $ask->id)
            ->assertSee('Declined. Nothing was sent');

        $this->assertSame(AccessRequest::DECLINED, $ask->fresh()->status);
        Notification::assertNothingSentTo($outsider);
        $this->assertFalse($outsider->can('view', $doc));
    }

    public function test_only_someone_who_may_share_sees_and_decides_and_never_as_admin(): void
    {
        [$owner, $outsider, $doc] = $this->setUpDocument();
        $viewer = User::factory()->create();
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING]);

        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->call('grantAccess', $ask->id, 'admin')->assertStatus(422);
        $this->assertSame(AccessRequest::PENDING, $ask->fresh()->status);
        $this->assertFalse($viewer->can('share', $doc), 'a viewer cannot reach the Sharing page at all');
    }

    public function test_someone_who_can_already_read_it_is_just_sent_to_it(): void
    {
        [$owner, , $doc] = $this->setUpDocument();

        $this->actingAs($owner)->post(route('documents.request-access', $doc->uuid))
            ->assertRedirect(route('documents.open', $doc->uuid));
        $this->assertSame(0, AccessRequest::count());
    }

    /** The name and the note are a stranger's words: in the owner's email they are text, never markup or links. */
    public function test_the_email_carries_the_strangers_words_as_text(): void
    {
        [$owner, , $doc] = $this->setUpDocument();
        $asker = User::factory()->create(['name' => '<b>Evil</b> [click](https://evil.example)']);
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $asker->id, 'status' => AccessRequest::PENDING, 'message' => '<script>x</script> [here](https://evil.example)']);

        $html = (string) (new AccessRequested($ask))->toMail($owner)->render();

        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringContainsString(route('documents.share', $doc->uuid), $html);
    }

    public function test_a_grant_never_lowers_someone_invited_higher_since_and_an_invite_closes_the_request(): void
    {
        Notification::fake();
        [$owner, $outsider, $doc] = $this->setUpDocument();
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING]);

        // Invited as an editor through the normal form: that is the answer to the request.
        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->set('inviteEmail', $outsider->email)->set('inviteRole', 'editor')->call('invite');
        $this->assertSame(AccessRequest::GRANTED, $ask->fresh()->status);

        // A stale request somehow still open: granting "read" must not cut them to viewer.
        $stale = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING]);
        Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->call('grantAccess', $stale->id, 'viewer')
            ->assertSee('already had more access');
        $this->assertSame('editor', DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $outsider->id)->value('role'));
    }

    public function test_after_a_decline_the_same_person_waits_a_week_to_ask_again(): void
    {
        Notification::fake();
        [$owner, $outsider, $doc] = $this->setUpDocument();
        AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::DECLINED, 'decided_at' => now()->subDay()]);

        $this->actingAs($outsider)->post(route('documents.request-access', $doc->uuid), ['message' => 'please'])
            ->assertSessionHasErrors('message')->assertSessionHasInput('message', 'please');
        Notification::assertNothingSentTo($owner);

        $this->travel(7)->days();
        $this->actingAs($outsider)->post(route('documents.request-access', $doc->uuid))->assertSessionHas('access_request', 'sent');
    }

    public function test_a_second_decision_on_the_same_request_does_nothing(): void
    {
        Notification::fake();
        [$owner, $outsider, $doc] = $this->setUpDocument();
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING]);
        $first = Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid]);
        $second = Livewire::actingAs($owner)->test(ShareManager::class, ['uuid' => $doc->uuid]);

        $first->call('declineAccess', $ask->id);
        $second->call('grantAccess', $ask->id, 'viewer')->assertNotFound();

        $this->assertSame(AccessRequest::DECLINED, $ask->fresh()->status);
        $this->assertFalse($outsider->can('view', $doc));
    }

    public function test_links_in_the_note_are_taken_out_of_the_email(): void
    {
        [$owner, $outsider, $doc] = $this->setUpDocument();
        $ask = AccessRequest::create(['document_id' => $doc->id, 'requester_id' => $outsider->id, 'status' => AccessRequest::PENDING, 'message' => 'Log in at https://evil.example/login or www.evil.example now']);

        $html = (string) (new AccessRequested($ask))->toMail($owner)->render();

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('[link removed]', $html);
    }
}
