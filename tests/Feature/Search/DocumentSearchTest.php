<?php

namespace Tests\Feature\Search;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Index;
use App\Models\DocumentCollaborator;
use App\Models\User;
use App\Search\DocumentSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text, string $id = 'p0p0p0p0'): array
    {
        return ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    public function test_search_respects_access_and_matches_body_text(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);

        $mine = $store->save($store->create($me, 'Fleet'), $this->para('Bell trucks below target at Klipfontein'), $me);
        $theirs = $store->save($store->create($other, 'Secret'), $this->para('Bell trucks at Klipfontein'), $other);
        $public = $store->save($store->create($other, 'Open', null, ['is_public' => true]), $this->para('Klipfontein site'), $other);

        $ids = app(DocumentSearch::class)->search($me, 'Klipfontein')->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($public->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_search_matches_the_title_too(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->save($store->create($me, 'Klipfontein weekly'), $this->para('Nothing else here'), $me);

        $ids = app(DocumentSearch::class)->search($me, 'Klipfontein')->pluck('id')->all();

        $this->assertSame([$doc->id], $ids);
    }

    public function test_a_named_collaborator_can_find_the_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mate = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->save($store->create($owner, 'Shared'), $this->para('Klipfontein haul road'), $owner);

        DocumentCollaborator::create([
            'document_id' => $doc->id,
            'user_id' => $mate->id,
            'role' => 'viewer',
        ]);

        $ids = app(DocumentSearch::class)->search($mate, 'Klipfontein')->pluck('id')->all();

        $this->assertSame([$doc->id], $ids);
    }

    public function test_a_team_mate_can_find_the_team_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mate = User::factory()->withPersonalTeam()->create();
        $owner->currentTeam->users()->attach($mate, ['role' => 'editor']);
        $mate->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $store = app(DocumentStore::class);
        $doc = $store->save($store->create($owner, 'Team plan'), $this->para('Klipfontein rollout'), $owner);

        $ids = app(DocumentSearch::class)->search($mate->fresh(), 'Klipfontein')->pluck('id')->all();

        $this->assertSame([$doc->id], $ids);
    }

    public function test_a_query_shorter_than_two_characters_returns_nothing(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $store->save($store->create($me, 'Klipfontein'), $this->para('K'), $me);

        $this->assertCount(0, app(DocumentSearch::class)->search($me, 'K'));
        $this->assertCount(0, app(DocumentSearch::class)->search($me, '  '));
    }

    public function test_the_index_search_box_uses_the_search_service(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $hit = $store->save($store->create($me, 'Fleet'), $this->para('Klipfontein haul road'), $me);
        $miss = $store->save($store->create($me, 'Payroll'), $this->para('Nothing to see'), $me);

        Livewire::actingAs($me)
            ->test(Index::class)
            ->set('search', 'Klipfontein')
            ->assertSee('Fleet')
            ->assertDontSee('Payroll');

        $this->assertNotSame($hit->id, $miss->id);
    }
}
