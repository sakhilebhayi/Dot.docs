<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Models\Comment;
use App\Models\DocumentCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** R2-3 / F-12: what the page highlights as a mention is exactly who gets notified. */
class CommentMentionDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_name_is_highlighted_whole_and_a_name_that_notifies_nobody_is_not(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner Person']);
        $thandi = User::factory()->create(['name' => "Thandi O'Mokoena"]);
        $doc = app(DocumentStore::class)->create($owner, 'Plan');
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $thandi->id, 'role' => 'viewer']);
        Comment::create(['document_id' => $doc->id, 'user_id' => $owner->id, 'content' => "Over to @Thandi O'Mokoena and @Nobody <script>x</script>"]);

        $html = Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])->html();

        $this->assertStringContainsString('<span class="mention">@Thandi O&#039;Mokoena</span>', $html);
        $this->assertStringNotContainsString('<span class="mention">@Nobody', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_suggestions_while_replying_show_under_the_reply_not_the_new_comment_box(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $sam = User::factory()->create(['name' => 'Sam Inside']);
        $doc = app(DocumentStore::class)->create($owner, 'Plan');
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $sam->id, 'role' => 'viewer']);
        $comment = Comment::create(['document_id' => $doc->id, 'user_id' => $owner->id, 'content' => 'First']);

        Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])
            ->call('startReply', $comment->id)
            ->call('searchMentions', 'Sam Ins', 'reply')
            ->assertSet('mentionTarget', 'reply')
            ->assertSet('mentionResults', [['id' => $sam->id, 'name' => 'Sam Inside']])
            ->assertSeeHtml('x-data="mentionInput(')
            ->assertSee('Sam Inside');
    }

    public function test_a_name_with_regex_replacement_characters_is_shown_as_written(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner Person']);
        $odd = User::factory()->create(['name' => 'Cash $1 \\0 Man']);
        $doc = app(DocumentStore::class)->create($owner, 'Plan');
        DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $odd->id, 'role' => 'viewer']);
        Comment::create(['document_id' => $doc->id, 'user_id' => $owner->id, 'content' => 'Ping @Cash $1 \\0 Man please']);

        $html = Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])->html();

        $this->assertStringContainsString('<span class="mention">@Cash $1 \\0 Man</span>', $html);
    }

    public function test_a_shorter_name_is_not_highlighted_again_inside_a_longer_one(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner Person']);
        $doc = app(DocumentStore::class)->create($owner, 'Plan');
        foreach (['Thandi Mokoena', 'Thandi'] as $name) {
            DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => User::factory()->create(['name' => $name])->id, 'role' => 'viewer']);
        }
        Comment::create(['document_id' => $doc->id, 'user_id' => $owner->id, 'content' => 'For @Thandi Mokoena only']);

        $html = Livewire::actingAs($owner)->test(CommentThread::class, ['document' => $doc])->html();

        $this->assertStringContainsString('<span class="mention">@Thandi Mokoena</span>', $html);
        $this->assertSame(1, substr_count($html, 'class="mention"'));
    }
}
