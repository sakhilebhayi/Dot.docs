<?php

namespace Tests\Feature;

use App\Files\FilesService;
use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two synthetic companies. A document belongs to its company (and its owner and
 * named collaborators); nobody else may list, open, edit, export or sync it, and a
 * member of two companies reaches each company's documents through that company.
 */
class DocsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private User $gina;

    private Team $acme;

    private Team $globex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ann = User::factory()->withPersonalTeam()->create();
        $this->gina = User::factory()->withPersonalTeam()->create();
        $this->acme = $this->company($this->ann, 'Acme');
        $this->globex = $this->company($this->gina, 'Globex');
    }

    private function company(User $owner, string $name): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false]);
        $owner->switchTeam($team);

        return $team;
    }

    private function doc(User $owner, Team $team, string $title): Document
    {
        $document = Document::factory()->create(['owner_id' => $owner->id, 'team_id' => $team->id, 'title' => $title]);

        // Filed in the company's tree, the way a real document is.
        $files = app(FilesService::class);
        $files->registerDocument($document, $files->root($team));

        return $document;
    }

    public function test_the_document_list_only_shows_the_companys_documents(): void
    {
        $this->doc($this->ann, $this->acme, 'Acme budget');
        $this->doc($this->gina, $this->globex, 'Globex payroll');

        $this->actingAs($this->ann);
        $titles = Livewire::test(Index::class)->get('documents')->pluck('title')->all();

        $this->assertContains('Acme budget', $titles);
        $this->assertNotContains('Globex payroll', $titles);
    }

    public function test_another_companys_document_cannot_be_opened_edited_or_exported_by_uuid(): void
    {
        $theirs = $this->doc($this->gina, $this->globex, 'Globex payroll');

        $this->actingAs($this->ann);
        $this->get(route('documents.edit', ['uuid' => $theirs->uuid]))->assertForbidden();
        $this->get(route('documents.settings', ['uuid' => $theirs->uuid]))->assertForbidden();
        $this->get(route('documents.history', ['uuid' => $theirs->uuid]))->assertForbidden();
        $this->get(route('documents.share', ['uuid' => $theirs->uuid]))->assertForbidden();
    }

    public function test_the_company_member_can_open_and_the_owner_company_viewer_cannot_edit(): void
    {
        $mine = $this->doc($this->ann, $this->acme, 'Acme budget');
        $viewer = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($viewer, ['role' => 'viewer']);
        $editor = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($editor, ['role' => 'editor']);

        $this->assertTrue($this->ann->can('update', $mine));
        $this->assertTrue($editor->can('update', $mine));
        $this->assertTrue($viewer->can('view', $mine));
        $this->assertFalse($viewer->can('update', $mine));
        $this->assertFalse($viewer->can('delete', $mine));
        $this->assertFalse($this->gina->can('view', $mine));
        $this->assertFalse($this->gina->can('update', $mine));
    }

    public function test_removing_a_member_removes_their_access_to_the_companys_documents(): void
    {
        $mine = $this->doc($this->ann, $this->acme, 'Acme budget');
        $member = User::factory()->withPersonalTeam()->create();
        $this->acme->users()->attach($member, ['role' => 'editor']);
        $this->assertTrue($member->can('view', $mine));

        $this->acme->users()->detach($member);

        $this->assertFalse($member->fresh()->can('view', $mine));
    }

    public function test_a_member_of_two_companies_reaches_each_through_its_own_company(): void
    {
        $a = $this->doc($this->ann, $this->acme, 'Acme only');
        $g = $this->doc($this->gina, $this->globex, 'Globex only');
        $this->globex->users()->attach($this->ann, ['role' => 'editor']);

        $ann = $this->ann->fresh();
        $this->assertTrue($ann->can('view', $a));
        $this->assertTrue($ann->can('view', $g));

        // The list follows the active company.
        $ann->switchTeam($this->acme);
        $this->actingAs($ann->fresh());
        $titles = Livewire::test(Index::class)->get('documents')->pluck('title')->all();
        $this->assertContains('Acme only', $titles);
        $this->assertNotContains('Globex only', $titles);
    }

    public function test_the_sync_endpoint_refuses_a_stranger(): void
    {
        $theirs = $this->doc($this->gina, $this->globex, 'Globex payroll');

        $this->actingAs($this->ann)
            ->postJson('/documents/'.$theirs->uuid.'/sync', ['version' => 1, 'tab' => 'tab-a'])
            ->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $theirs = $this->doc($this->gina, $this->globex, 'Globex payroll');

        $this->get(route('documents.edit', ['uuid' => $theirs->uuid]))->assertRedirect();
        $this->get('/documents')->assertRedirect();
    }
}
