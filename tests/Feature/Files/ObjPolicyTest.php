<?php

namespace Tests\Feature\Files;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Models\Files\Obj;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may work with a node of the shared tree.
 *
 * The whole point of ObjPolicy is that the answer comes off the ROW'S team,
 * not the session's current team - so the cases that matter are the ones
 * where the two disagree.
 */
class ObjPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    private function files(): FilesService
    {
        return app(FilesService::class);
    }

    public function test_a_team_member_may_view_create_move_rename_and_delete(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $root = $this->files()->root($owner->currentTeam);
        $folder = $this->files()->createFolder($root, 'Reports', $owner);

        foreach (['view', 'move', 'rename', 'delete'] as $ability) {
            $this->assertTrue($member->can($ability, $folder), $ability.' should be allowed for a team member');
        }

        $this->assertTrue($member->can('create', [Obj::class, $root]));
    }

    public function test_an_outsider_may_do_none_of_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $root = $this->files()->root($owner->personalTeam());
        $folder = $this->files()->createFolder($root, 'Reports', $owner);

        foreach (['view', 'move', 'rename', 'delete'] as $ability) {
            $this->assertFalse($outsider->can($ability, $folder), $ability.' should be refused for an outsider');
        }

        $this->assertFalse($outsider->can('create', [Obj::class, $root]));
    }

    /**
     * Being in the team is not enough to delete a DOCUMENT node: filing is
     * filing, but deleting reaches the document itself, so DocumentPolicy
     * has to agree as well.
     */
    public function test_deleting_a_document_node_also_asks_document_policy(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $this->actingAs($owner);
        $document = app(DocumentStore::class)->create($owner, 'Owner only');
        $node = $document->node;

        $this->assertTrue($member->can('view', $node));
        $this->assertTrue($member->can('rename', $node));
        $this->assertSame($member->can('delete', $document), $member->can('delete', $node));
        $this->assertTrue($owner->can('delete', $node));
    }

    /**
     * The failure mode this policy exists to close: a node reached while the
     * session's current team is a DIFFERENT team is still judged by the
     * node's own team, so switching teams never widens or narrows what a
     * person can reach.
     */
    public function test_the_answer_comes_off_the_node_not_the_current_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $personal = $user->personalTeam();

        $other = User::factory()->withPersonalTeam()->create();
        $shared = $other->currentTeam;
        $shared->users()->attach($user, ['role' => 'editor']);

        $personalRoot = $this->files()->root($personal);
        $sharedRoot = $this->files()->root($shared);

        // Standing in the shared team...
        $user->forceFill(['current_team_id' => $shared->id])->save();
        $user = $user->fresh();

        // ...both are still reachable, because membership - not the current
        // team - is the question.
        $this->assertTrue($user->can('view', $personalRoot));
        $this->assertTrue($user->can('view', $sharedRoot));

        // And a team the user is not in stays closed either way.
        $stranger = User::factory()->withPersonalTeam()->create();
        $this->assertFalse($user->can('view', $this->files()->root($stranger->personalTeam())));
    }
}
