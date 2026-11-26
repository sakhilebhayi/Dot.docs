<?php

namespace Tests\Feature\Notifications;

use App\Documents\DocumentStore;
use App\Http\Controllers\CommentEmailPreferenceController;
use App\Livewire\NotificationBell;
use App\Models\Comment;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Notifications\CommentPostedEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** EA-3 / E-06 (comment emails are optional) and E-08 (open marks read first). */
class CommentEmailPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_signed_link_turns_comment_emails_off_and_on_without_signing_in(): void
    {
        $user = User::factory()->create();
        $link = CommentEmailPreferenceController::link($user);

        $this->get($link)->assertOk()->assertSee('Comment emails are on');
        $this->post($link, ['state' => 'off'])->assertRedirect();
        $this->assertFalse($user->fresh()->comment_emails);
        $this->get($link)->assertSee('Comment emails are off');
        $this->post($link, ['state' => 'on']);
        $this->assertTrue($user->fresh()->comment_emails);
    }

    public function test_a_mail_clients_one_click_unsubscribe_works(): void
    {
        $user = User::factory()->create();

        $this->post(CommentEmailPreferenceController::link($user), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertFalse($user->fresh()->comment_emails);
    }

    public function test_an_unsigned_or_tampered_link_is_refused(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tampered = str_replace('/email/comments/'.$user->id, '/email/comments/'.$other->id, CommentEmailPreferenceController::link($user));

        $this->post('/email/comments/'.$user->id, ['state' => 'off'])->assertForbidden();
        $this->post($tampered, ['state' => 'off'])->assertForbidden();
        $this->assertTrue($other->fresh()->comment_emails);
    }

    public function test_no_comment_email_once_switched_off_and_each_carries_the_unsubscribe_headers(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Plan');
        $other = User::factory()->withPersonalTeam()->create();
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $other->id, 'role' => 'viewer']);
        $comment = Comment::create(['document_id' => $doc->id, 'user_id' => $other->id, 'content' => 'Hi']);
        $mail = new CommentPostedEmailNotification($doc, $comment);

        $message = $mail->toMail($owner);
        $email = new Email;
        foreach ($message->callbacks as $callback) {
            $callback($email);
        }
        $this->assertStringContainsString('/email/comments/'.$owner->id, $email->getHeaders()->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());

        $this->assertTrue($mail->shouldSend($owner, 'mail'));
        $owner->forceFill(['comment_emails' => false])->save();
        $this->assertFalse($mail->shouldSend($owner->fresh(), 'mail'));
    }

    public function test_opening_a_notice_marks_it_read_before_going_there(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => 'test', 'data' => ['type' => 'comment', 'url' => url('/documents/x/edit')]]);

        Livewire::actingAs($user)->test(NotificationBell::class)->call('openNotice', $id)->assertRedirect(url('/documents/x/edit'));

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_a_stored_link_to_another_site_is_never_followed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => 'test', 'data' => ['url' => 'https://evil.test/']]);

        Livewire::actingAs($user)->test(NotificationBell::class)->call('openNotice', $id)->assertRedirect(route('dashboard'));
    }
}
