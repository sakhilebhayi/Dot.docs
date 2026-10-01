<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
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
