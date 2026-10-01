<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who hears about a comment in the bell, and how immediately.
 */
class CommentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function collaborate(Document $doc, User $user): void
    {
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $user->id, 'role' => 'viewer']);
    }

    /**
     * Production's queue, for real: the `database` driver, which nothing
     * drains until the once-a-minute cron worker runs. After posting a
     * comment the bell row must already exist, and the ONLY thing waiting
     * on the queue must be the delayed email - no broadcast. A queued
     * broadcast is a "live" update that arrives up to a minute late, which
     * is what dropping ShouldQueue alone still left behind: the broadcast
     * channel and every ShouldBroadcast event queue themselves regardless.
     */
    public function test_posting_a_comment_notifies_the_owner_inline_and_queues_only_the_email(): void
    {
        config(['queue.default' => 'database']);

        $owner = User::factory()->withPersonalTeam()->create();
        $commenter = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);

        Livewire::actingAs($commenter)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hello there')
            ->call('postComment');

        $this->assertSame(1, $owner->fresh()->unreadNotifications()->count());

        $jobs = DB::table('jobs')->get();

        $this->assertCount(1, $jobs, 'Only the delayed email should be queued: '.$jobs->pluck('payload')->map(fn ($p) => json_decode($p)->displayName)->implode(', '));
        $this->assertSame('mail', $jobs->first()->queue);
        $this->assertStringContainsString('CommentPostedEmailNotification', $jobs->first()->payload);
    }

    public function test_mentioning_a_collaborator_notifies_them_immediately(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $mentioned = User::factory()->withPersonalTeam()->create(['name' => 'mentioned_user']);
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $mentioned);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Hey @mentioned_user check this out')
            ->call('postComment');

        $notifications = $mentioned->fresh()->unreadNotifications;

        $this->assertCount(1, $notifications);
        $this->assertSame('mention', $notifications->first()->data['type']);
    }

    /**
     * The mention picker inserts the FULL name. Pulling `@word` out of the
     * text used to turn "@Thandi Mokoena" into a lookup for "Thandi".
     */
    public function test_a_mention_of_a_two_word_name_reaches_that_person(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Doc Owner']);
        $thandi = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $thandi);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Over to you @Thandi Mokoena, thanks')
            ->call('postComment');

        $this->assertSame(1, $thandi->fresh()->unreadNotifications()->count());
    }

    /** "@Sam Smith" is a mention of Sam Smith, not also of a participant called Sam. */
    public function test_the_longest_matching_name_wins(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Doc Owner']);
        $sam = User::factory()->withPersonalTeam()->create(['name' => 'Sam']);
        $samSmith = User::factory()->withPersonalTeam()->create(['name' => 'Sam Smith']);
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $sam);
        $this->collaborate($doc, $samSmith);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', 'Can you check this @Sam Smith')
            ->call('postComment');

        $this->assertSame(1, $samSmith->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $sam->fresh()->unreadNotifications()->count());
    }

    /**
     * A mention emails the comment's text, so it must not reach someone
     * with no relationship to the document.
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

    /**
     * "Can view" is every account on the platform once a document is
     * link-shared, and names are not unique - so a mention goes to the
     * participant with that name, never to a same-named stranger.
     */
    public function test_a_same_named_stranger_is_not_notified_on_a_link_shared_document(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'docowner']);
        $teammate = User::factory()->withPersonalTeam()->create(['name' => 'Sam']);
        $stranger = User::factory()->withPersonalTeam()->create(['name' => 'Sam']);
        $doc = app(DocumentStore::class)->create($owner, 'Shared doc', null, ['is_public' => true]);
        $this->collaborate($doc, $teammate);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->set('newComment', '@Sam the offer is 4.2m, keep quiet')
            ->call('postComment');

        $this->assertSame(1, $teammate->fresh()->notifications()->count());
        $this->assertSame(0, $stranger->fresh()->notifications()->count());
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
     * Somebody who commented while they had access, and has since been
     * removed, must not be sent what is written in reply.
     */
    public function test_a_reply_does_not_notify_a_parent_author_who_lost_access(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $former = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $former);
        $parent = Comment::create(['document_id' => $doc->id, 'user_id' => $former->id, 'content' => 'A question']);

        DocumentCollaborator::where('document_id', $doc->id)->where('user_id', $former->id)->delete();

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->call('startReply', $parent->id)
            ->set('replyContent', 'Confidential: the acquisition price is 4.2m')
            ->call('postReply');

        $this->assertSame(0, $former->fresh()->notifications()->count());
    }

    public function test_a_reply_notifies_a_parent_author_who_still_has_access(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $collaborator = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $collaborator);
        $parent = Comment::create(['document_id' => $doc->id, 'user_id' => $collaborator->id, 'content' => 'A question']);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->call('startReply', $parent->id)
            ->set('replyContent', 'An answer')
            ->call('postReply');

        $this->assertSame(1, $collaborator->fresh()->unreadNotifications()->count());
    }

    /** The picker only offers people a mention can actually reach. */
    public function test_the_mention_picker_only_offers_participants(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Doc Owner']);
        $collaborator = User::factory()->withPersonalTeam()->create(['name' => 'Sam Inside']);
        User::factory()->withPersonalTeam()->create(['name' => 'Sam Outside']);
        $doc = app(DocumentStore::class)->create($owner, 'Private doc');
        $this->collaborate($doc, $collaborator);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->call('searchMentions', 'Sam')
            ->assertSet('mentionResults', [['id' => $collaborator->id, 'name' => 'Sam Inside']]);
    }

    /** Every comment can send email now, so one account cannot post without limit. */
    public function test_posting_is_rate_limited_per_user(): void
    {
        Queue::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Doc');

        $thread = Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc]);

        foreach (range(1, 20) as $n) {
            $thread->set('newComment', "Comment {$n}")->call('postComment')->assertHasNoErrors();
        }

        $thread->set('newComment', 'One too many')->call('postComment')
            ->assertHasErrors('newComment')
            ->assertSee('You are posting very quickly');

        $this->assertSame(20, Comment::where('document_id', $doc->id)->count());
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
