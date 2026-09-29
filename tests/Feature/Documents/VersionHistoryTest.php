<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\VersionHistory;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeVersion(int $documentId, int $createdBy, int $number, string $html): DocumentVersion
    {
        return DocumentVersion::create([
            'document_id' => $documentId,
            'content_snapshot' => $html,
            'version_number' => $number,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
    }

    public function test_comparing_two_versions_with_different_text_renders_a_diff(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Diff me');
        $a = $this->makeVersion($doc->id, $user->id, 1, '<p>Original text.</p>');
        $b = $this->makeVersion($doc->id, $user->id, 2, '<p>Original text, changed.</p>');

        Livewire::actingAs($user)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('toggleCompare', $a->id)
            ->call('toggleCompare', $b->id)
            ->call('runDiff')
            ->assertSet('showDiff', true)
            ->assertSee('changed')
            ->assertDontSee('These two versions have no textual differences.');
    }

    /**
     * VersionHistory::runDiff() found live to leave $diffHtml empty (not an
     * error - DiffHelper::calculate() genuinely returns "" when both sides'
     * stripped text is byte-identical, which happens whenever an edit gets
     * undone before the next save: the version number still advances, but
     * the content does not). The Blade view's `@if ($showDiff && $diffHtml)`
     * used to fall through, silently, to the SAME "Pick a version to read
     * it, or tick two of them to compare." empty state shown before anything
     * is picked - misleading, since the writer picked two versions and asked
     * to compare them. Locks in the dedicated message instead.
     */
    public function test_comparing_two_versions_with_identical_text_shows_a_no_differences_message_not_the_pick_a_version_prompt(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Same twice');
        $a = $this->makeVersion($doc->id, $user->id, 1, '<p>Unchanged text.</p>');
        $b = $this->makeVersion($doc->id, $user->id, 2, '<p>Unchanged text.</p>');

        Livewire::actingAs($user)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('toggleCompare', $a->id)
            ->call('toggleCompare', $b->id)
            ->call('runDiff')
            ->assertSet('showDiff', true)
            ->assertSet('diffHtml', '')
            ->assertSee('These two versions have no textual differences.')
            ->assertDontSee('Pick a version to read it, or tick two of them to compare.');
    }
}
