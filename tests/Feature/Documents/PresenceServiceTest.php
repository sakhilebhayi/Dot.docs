<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who has a document open right now, one row per open tab.
 */
class PresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_touched_tab_makes_its_user_a_member(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');

        $members = $presence->members($doc->id);

        $this->assertCount(1, $members);
        $this->assertSame($user->id, $members[0]['id']);
        $this->assertSame('Thandi', $members[0]['name']);
        $this->assertArrayHasKey('avatar', $members[0]);
    }

    public function test_two_tabs_of_one_account_are_one_member_but_each_sees_the_other(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');
        $presence->touch($doc->id, $user, 'tab-b');

        $this->assertCount(1, $presence->members($doc->id));
        $this->assertSame(1, $presence->others($doc->id, 'tab-a'));
        $this->assertSame(1, $presence->others($doc->id, 'tab-b'));
    }

    public function test_a_tab_alone_has_no_others(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');

        $this->assertSame(0, $presence->others($doc->id, 'tab-a'));
    }

    public function test_members_are_listed_in_order_of_arrival(): void
    {
        $first = User::factory()->withPersonalTeam()->create();
        $second = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($first, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $second, 'tab-b');
        $presence->touch($doc->id, $first, 'tab-a');

        $this->assertSame([$second->id, $first->id], array_column($presence->members($doc->id), 'id'));
    }

    public function test_a_tab_that_leaves_is_gone_at_once(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');
        $presence->leave($doc->id, 'tab-a');

        $this->assertSame([], $presence->members($doc->id));
    }

    public function test_a_tab_not_heard_from_within_the_ttl_is_no_longer_present(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');

        $this->travel(PresenceService::TTL_SECONDS + 1)->seconds();

        $this->assertSame([], $presence->members($doc->id));
        $this->assertSame(0, $presence->others($doc->id, 'tab-z'));

        // The next arrival sweeps the dead row out.
        $presence->touch($doc->id, $other, 'tab-b');

        $this->assertSame(1, DB::table('document_presences')->where('document_id', $doc->id)->count());
    }

    /** The poll runs every 1.5 seconds; it must not write to the database every time. */
    public function test_a_row_is_not_rewritten_more_than_once_every_five_seconds(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $user, 'tab-a');
        $firstSeen = DB::table('document_presences')->value('last_seen_at');

        $this->travel(2)->seconds();
        $presence->touch($doc->id, $user, 'tab-a');
        $this->assertSame($firstSeen, DB::table('document_presences')->value('last_seen_at'));

        $this->travel(4)->seconds();
        $presence->touch($doc->id, $user, 'tab-a');
        $this->assertNotSame($firstSeen, DB::table('document_presences')->value('last_seen_at'));
    }

    public function test_presence_is_per_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $one = app(DocumentStore::class)->create($user, 'One');
        $two = app(DocumentStore::class)->create($user, 'Two');
        $presence = app(PresenceService::class);

        $presence->touch($one->id, $user, 'tab-a');

        $this->assertSame([], $presence->members($two->id));
    }

    public function test_the_editor_lists_the_person_opening_it_and_whoever_is_already_there(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner']);
        $doc = app(DocumentStore::class)->create($owner, 'Open', null, ['is_public' => true]);
        $visitor = User::factory()->withPersonalTeam()->create(['name' => 'Visitor']);
        app(PresenceService::class)->touch($doc->id, $visitor, 'tab-v');

        $editor = Livewire::actingAs($owner)->test(Editor::class, ['uuid' => $doc->uuid]);

        $this->assertSame(['Visitor', 'Owner'], array_column($editor->get('activeUsers'), 'name'));

        app(PresenceService::class)->leave($doc->id, 'tab-v');
        $editor->call('refreshPresence');

        $this->assertSame(['Owner'], array_column($editor->get('activeUsers'), 'name'));
    }

    /** Access is checked on every presence refresh, not only when the page was opened. */
    public function test_refreshing_presence_is_forbidden_once_the_viewer_access_is_removed(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $visitor = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Open');
        $collaborator = DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $visitor->id, 'role' => 'editor']);

        $editor = Livewire::actingAs($visitor)->test(Editor::class, ['uuid' => $doc->uuid]);
        $editor->call('refreshPresence')->assertOk();

        $collaborator->delete();

        $editor->call('refreshPresence')->assertForbidden();
    }

    /** One tab id is one row: whoever last claimed it owns it. */
    public function test_a_touch_for_the_same_tab_id_by_another_user_overwrites_the_row(): void
    {
        $first = User::factory()->withPersonalTeam()->create();
        $second = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($first, 'Open');
        $presence = app(PresenceService::class);

        $presence->touch($doc->id, $first, 'tab-a');
        $presence->touch($doc->id, $second, 'tab-a');

        $rows = DB::table('document_presences')->where('document_id', $doc->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($second->id, (int) $rows[0]->user_id);
        $this->assertSame([$second->id], array_column($presence->members($doc->id), 'id'));
    }

    /** The sweep that rides on a touch cleans only the document being touched. */
    public function test_the_sweep_leaves_other_documents_dead_rows_alone(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $one = app(DocumentStore::class)->create($user, 'One');
        $two = app(DocumentStore::class)->create($user, 'Two');
        $presence = app(PresenceService::class);

        $presence->touch($one->id, $user, 'tab-one');
        $presence->touch($two->id, $user, 'tab-two');

        $this->travel(PresenceService::TTL_SECONDS + 1)->seconds();

        // Somebody arrives in document two: its dead row goes, document one's stays.
        $presence->touch($two->id, $other, 'tab-three');

        $this->assertSame(['tab-one'], DB::table('document_presences')->where('document_id', $one->id)->pluck('tab_id')->all());
        $this->assertSame(['tab-three'], DB::table('document_presences')->where('document_id', $two->id)->pluck('tab_id')->all());
    }

    /**
     * A tab opened before this shipped still calls heartbeat() every 60
     * seconds and leaving() when it closes. Both must answer without an
     * error, and neither records presence any more.
     */
    public function test_the_old_presence_calls_from_a_tab_opened_before_the_deploy_do_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Open');

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('heartbeat')
            ->call('leaving')
            ->assertOk();

        $this->assertSame(0, DB::table('document_presences')->count());
    }
}
