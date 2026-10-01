<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Notifications\CommentPostedEmailNotification;
use App\Notifications\CommentPostedNotification;
use App\Notifications\MentionedInCommentEmailNotification;
use App\Notifications\MentionedInCommentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The email half of a comment notification: delayed, and not sent at all
 * if the recipient saw it in the bell first.
 */
class CommentEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function commentOn(User $owner, string $content = 'hello'): Comment
    {
        $doc = app(DocumentStore::class)->create($owner, 'Doc');

        return Comment::create([
            'document_id' => $doc->id,
            'user_id' => $owner->id,
            'content' => $content,
        ]);
    }

    private function seedBellNotification(User $user, string $type, int $commentId, bool $read): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $type === 'mention' ? MentionedInCommentNotification::class : CommentPostedNotification::class,
            'data' => ['type' => $type, 'comment_id' => $commentId],
            'read_at' => $read ? now() : null,
        ]);
    }

    private function collaborate(Document $doc, User $user): void
    {
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $user->id, 'role' => 'viewer']);
    }

    /** The one message in the array transport, as Symfony built it. */
    private function sentEmail(): Email
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        return $messages->first()->getOriginalMessage();
    }

    public function test_comment_email_is_not_sent_if_the_bell_notification_has_already_been_read(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'comment', $comment->id, read: true);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);

        $this->assertFalse($notification->shouldSend($owner, 'mail'));
    }

    public function test_comment_email_is_sent_if_the_bell_notification_is_still_unread(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'comment', $comment->id, read: false);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);

        $this->assertTrue($notification->shouldSend($owner, 'mail'));
    }

    /** Reading a DIFFERENT comment's bell notification must not cancel this one's email. */
    public function test_comment_email_is_still_sent_if_only_another_comments_notification_was_read(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'comment', $comment->id + 999, read: true);
        $this->seedBellNotification($owner, 'comment', $comment->id, read: false);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);

        $this->assertTrue($notification->shouldSend($owner, 'mail'));
    }

    public function test_mention_email_is_not_sent_if_the_bell_notification_has_already_been_read(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->seedBellNotification($owner, 'mention', $comment->id, read: true);

        $notification = new MentionedInCommentEmailNotification($comment->document, $comment);

        $this->assertFalse($notification->shouldSend($owner, 'mail'));
    }

    /** Access can be taken away during the two-minute delay. */
    public function test_email_is_not_sent_to_someone_who_lost_access_during_the_delay(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $collaborator = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $this->collaborate($comment->document, $collaborator);

        $commentEmail = new CommentPostedEmailNotification($comment->document, $comment);
        $mentionEmail = new MentionedInCommentEmailNotification($comment->document, $comment);

        $this->assertTrue($commentEmail->shouldSend($collaborator, 'mail'));
        $this->assertTrue($mentionEmail->shouldSend($collaborator, 'mail'));

        DocumentCollaborator::where('user_id', $collaborator->id)->delete();

        $this->assertFalse($commentEmail->shouldSend($collaborator, 'mail'));
        $this->assertFalse($mentionEmail->shouldSend($collaborator, 'mail'));
    }

    /** One person cannot be sent comment email without limit. */
    public function test_comment_email_to_one_recipient_is_capped_per_hour(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $notification = new CommentPostedEmailNotification($comment->document, $comment);

        foreach (range(1, 30) as $n) {
            $this->assertTrue($notification->shouldSend($owner, 'mail'), "Email {$n} should be allowed.");
        }

        $this->assertFalse($notification->shouldSend($owner, 'mail'));
    }

    /**
     * The job that carries the email: on its own queue (drained first),
     * held for about two minutes, retried rather than lost on a transient
     * mail failure, and quietly dropped if the comment is deleted first.
     */
    public function test_the_email_job_is_delayed_retried_and_on_the_mail_queue(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);

        $owner->notify(new CommentPostedEmailNotification($comment->document, $comment));

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) {
            return $job->queue === 'mail'
                && $job->tries === 3
                && $job->deleteWhenMissingModels === true
                && $job->delay->greaterThan(now()->addMinutes(1))
                && $job->delay->lessThanOrEqualTo(now()->addMinutes(3));
        });
    }

    /**
     * A mail line is rendered as Markdown, and the comment is somebody
     * else's text: it must arrive as plain words, never as a live link or
     * markup of the commenter's choosing.
     */
    public function test_comment_text_cannot_inject_links_or_markup_into_the_email(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner, '<b>Urgent</b> [reset your password](https://evil.example/phish)');

        $html = (string) (new CommentPostedEmailNotification($comment->document, $comment))->toMail($owner)->render();

        $this->assertStringNotContainsString('href="https://evil.example/phish"', $html);
        $this->assertStringNotContainsString('<b>Urgent</b>', $html);
        $this->assertStringContainsString('reset your password', $html);

        $html = (string) (new MentionedInCommentEmailNotification($comment->document, $comment))->toMail($owner)->render();

        $this->assertStringNotContainsString('href="https://evil.example/phish"', $html);
    }

    /**
     * The display name and the document title are user-controlled too. The
     * subject leads with the app's own words, never the commenter's name,
     * and bounds the title.
     */
    public function test_the_subject_and_name_are_bounded_and_cannot_impersonate_the_app(): void
    {
        $attacker = User::factory()->withPersonalTeam()->create([
            'name' => 'Dot.Doc Security: your account is locked. Verify now at [this link](https://evil.example/login)',
        ]);
        $doc = app(DocumentStore::class)->create($attacker, str_repeat('Long title ', 30)."\r\nBcc: victim@example.com");
        $comment = Comment::create(['document_id' => $doc->id, 'user_id' => $attacker->id, 'content' => 'hi']);
        $recipient = User::factory()->withPersonalTeam()->create();

        foreach ([CommentPostedEmailNotification::class, MentionedInCommentEmailNotification::class] as $class) {
            $mail = (new $class($doc, $comment))->toMail($recipient);

            $this->assertStringNotContainsString('Dot.Doc Security', $mail->subject);
            $this->assertStringNotContainsString("\n", $mail->subject);
            $this->assertStringNotContainsString("\r", $mail->subject);
            $this->assertLessThanOrEqual(100, mb_strlen($mail->subject));
            $this->assertStringNotContainsString('href="https://evil.example/login"', (string) $mail->render());
        }
    }

    /**
     * The whole path, unfaked: the testing queue is `sync` and the mailer
     * is `array`, so the email notification runs inline and a real message
     * lands in the array transport addressed to the owner - readable in
     * BOTH parts, with no escaping artefacts in the plain-text one.
     */
    public function test_an_unread_comment_produces_a_clean_email_to_the_owner(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create(['name' => 'J. Smith']);
        $doc = app(DocumentStore::class)->create($owner, 'Q3 plan', null, ['is_public' => true]);

        Livewire::actingAs($commenter)
            ->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Looks good. Ship-it! (see p.3)')
            ->call('postComment');

        $email = $this->sentEmail();

        $this->assertSame($owner->email, $email->getTo()[0]->getAddress());
        $this->assertSame('New comment on "Q3 plan"', $email->getSubject());
        $this->assertStringContainsString('J. Smith commented on "Q3 plan".', $email->getTextBody());
        $this->assertStringContainsString('"Looks good. Ship-it! (see p.3)"', $email->getTextBody());
        $this->assertStringNotContainsString('\\', $email->getTextBody());
        $this->assertStringNotContainsString('&#', $email->getTextBody());
        $this->assertStringContainsString('Looks good. Ship-it! (see p.3)', html_entity_decode(strip_tags($email->getHtmlBody()), ENT_QUOTES | ENT_HTML5));
    }

    public function test_mentioning_a_collaborator_sends_them_the_mention_email(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $mentioned = User::factory()->withPersonalTeam()->create(['name' => 'mentioned_user']);
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $mentioned);

        Livewire::actingAs($owner)
            ->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @mentioned_user check this out')
            ->call('postComment');

        Notification::assertSentTo($mentioned, MentionedInCommentEmailNotification::class);
        Notification::assertNotSentTo($mentioned, CommentPostedEmailNotification::class);
    }
}
