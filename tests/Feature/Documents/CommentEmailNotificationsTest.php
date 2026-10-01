<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\CommentPostedEmailNotification;
use App\Notifications\CommentPostedNotification;
use App\Notifications\MentionedInCommentEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CommentEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function commentOn(User $owner): Comment
    {
        $doc = app(DocumentStore::class)->create($owner, 'Doc');

        return Comment::create([
            'document_id' => $doc->id,
            'user_id' => $owner->id,
            'content' => 'hello',
        ]);
    }

    private function seedBellNotification(User $user, string $type, int $commentId, bool $read): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => CommentPostedNotification::class,
            'data' => ['type' => $type, 'comment_id' => $commentId],
            'read_at' => $read ? now() : null,
        ]);
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

    /**
     * A mail line is rendered as Markdown, and the comment is somebody
     * else's text: it must arrive as plain words, never as a live link or
     * markup of the commenter's choosing.
     */
    public function test_comment_text_cannot_inject_links_or_markup_into_the_email(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);
        $comment->forceFill(['content' => '<b>Urgent</b> [reset your password](https://evil.example/phish)'])->save();

        $html = (string) (new CommentPostedEmailNotification($comment->document, $comment))->toMail($owner)->render();

        $this->assertStringNotContainsString('href="https://evil.example/phish"', $html);
        $this->assertStringNotContainsString('<b>Urgent</b>', $html);
        $this->assertStringContainsString('reset your password', $html);

        $html = (string) (new MentionedInCommentEmailNotification($comment->document, $comment))->toMail($owner)->render();

        $this->assertStringNotContainsString('href="https://evil.example/phish"', $html);
    }

    public function test_email_delay_is_about_two_minutes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $comment = $this->commentOn($owner);

        $notification = new CommentPostedEmailNotification($comment->document, $comment);
        $delay = $notification->withDelay($owner)['mail'];

        $this->assertTrue($delay->greaterThan(now()->addMinutes(1)));
        $this->assertTrue($delay->lessThanOrEqualTo(now()->addMinutes(3)));
    }

    public function test_posting_a_comment_queues_an_email_notification_for_the_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)
            ->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment');

        Notification::assertSentTo($owner, CommentPostedEmailNotification::class);
    }

    public function test_mentioning_a_user_queues_an_email_notification_for_them(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $mentioned = User::factory()->withPersonalTeam()->create(['name' => 'mentioned_user']);
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($owner)
            ->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @mentioned_user check this out')
            ->call('postComment');

        Notification::assertSentTo($mentioned, MentionedInCommentEmailNotification::class);
    }

    /**
     * The whole path, unfaked: the testing queue is `sync` and the mailer
     * is `array`, so the email notification runs inline and a real message
     * lands in the array transport addressed to the owner.
     */
    public function test_an_unread_comment_actually_produces_an_email_to_the_owner(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)
            ->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment');

        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);
        $this->assertSame($owner->email, $messages->first()->getOriginalMessage()->getTo()[0]->getAddress());
    }
}
