<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CommentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Queue::fake() swallows anything pushed to the queue without running
     * it, so the bell row can only exist here if it was written inline.
     */
    public function test_posting_a_comment_notifies_the_document_owner_immediately_without_queueing(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment');

        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }

    public function test_mentioning_a_user_in_a_comment_notifies_them_immediately(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $mentioned = User::factory()->withPersonalTeam()->create(['name' => 'mentioned_user']);
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @mentioned_user check this out')
            ->call('postComment');

        $this->assertSame(1, $mentioned->fresh()->unreadNotifications()->count());
    }

    /**
     * The owner is both "document owner" and "author of the comment being
     * replied to" here - one comment, one notification, not two.
     */
    public function test_a_reply_to_the_owners_own_comment_notifies_the_owner_once(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $replier = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);
        $parent = Comment::create(['document_id' => $doc->id, 'user_id' => $owner->id, 'content' => 'First']);

        Livewire::actingAs($replier)->test(CommentThread::class, ['document' => $doc])
            ->call('startReply', $parent->id)
            ->set('replyContent', 'A reply')
            ->call('postReply');

        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }

    /** A mention is the more specific notice, so it replaces the generic one. */
    public function test_an_owner_who_is_mentioned_gets_the_mention_only(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @docowner take a look')
            ->call('postComment');

        $notifications = $owner->fresh()->unreadNotifications;

        $this->assertCount(1, $notifications);
        $this->assertSame('mention', $notifications->first()->data['type']);
    }

    /**
     * A mention now emails the comment's text, so it must not reach someone
     * who could not open the document themselves.
     */
    public function test_mentioning_someone_without_access_to_a_private_document_notifies_nobody(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $outsider = User::factory()->withPersonalTeam()->create(['name' => 'outsider']);
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @outsider look at this')
            ->call('postComment');

        $this->assertSame(0, $outsider->fresh()->notifications()->count());
    }

    public function test_a_broadcast_failure_does_not_prevent_the_comment_from_posting(): void
    {
        Notification::extend('broadcast', fn () => new class
        {
            public function send($notifiable, $notification): void
            {
                throw new \RuntimeException('Reverb unreachable');
            }
        });

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('comments', ['content' => 'Hello there']);
        // `database` is listed before `broadcast` in via(), so the bell row
        // is already written by the time the broadcast throws.
        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());
    }
}
