<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Events\CommentPosted;
use App\Events\DocumentUpdated;
use App\Models\Comment;
use App\Models\User;
use App\Support\LiveBroadcast;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** R2-11: with a broadcaster that reaches nobody, a save or a comment hands nothing to it. */
class LiveBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_real_broadcaster_counts_as_available(): void
    {
        foreach (['null' => false, 'log' => false, 'reverb' => true, 'pusher' => true] as $connection => $expected) {
            config(['broadcasting.default' => $connection]);
            $this->assertSame($expected, LiveBroadcast::available(), $connection);
        }

        config(['broadcasting.default' => 'missing']);
        $this->assertFalse(LiveBroadcast::available());
    }

    public function test_events_are_not_handed_to_a_broadcaster_that_reaches_nobody(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Quiet');
        $comment = Comment::create(['document_id' => $doc->id, 'user_id' => $user->id, 'content' => 'hi']);

        $factory = Mockery::mock(BroadcastFactory::class);
        $this->app->instance(BroadcastFactory::class, $factory);

        config(['broadcasting.default' => 'null']);
        $factory->shouldNotReceive('queue');
        DocumentUpdated::dispatch($doc, $user, '', [], 1);
        CommentPosted::dispatch($doc, $comment);

        config(['broadcasting.default' => 'reverb']);
        $factory->shouldReceive('queue')->twice();
        DocumentUpdated::dispatch($doc, $user, '', [], 2);
        CommentPosted::dispatch($doc, $comment);
    }
}
