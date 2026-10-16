<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Livewire\Documents\VersionHistory;
use App\Models\Document;
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

    /**
     * A restore replaces the whole document. Somebody typing steadily has
     * their latest saves in NO version row (an autosave cuts none while the
     * same author's last one is under two minutes old), and their open tab
     * follows the restore and loses its undo. So the restore first keeps
     * what it replaces, or that text exists nowhere afterwards.
     */
    public function test_restoring_a_version_keeps_the_document_it_replaces(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Shared');

        $para = fn (string $text): array => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];

        $store->save($doc, $para('Old text'), $user, ['version' => 'named', 'label' => 'Old']);
        $old = DocumentVersion::where('document_id', $doc->id)->sole();

        $this->travel(5)->minutes();

        // Three autosaves by one author, thirty seconds apart: only the first cuts a version.
        $store->save(Document::findOrFail($doc->id), $para('First autosave'), $user);
        $this->travel(30)->seconds();
        $store->save(Document::findOrFail($doc->id), $para('Second autosave'), $user);
        $this->travel(30)->seconds();
        $store->save(Document::findOrFail($doc->id), $para('Last autosave, in no version'), $user);
        $this->assertSame([2, 3], DocumentVersion::where('document_id', $doc->id)->orderBy('id')->pluck('version_number')->all());

        Livewire::actingAs($user)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('restore', $old->id);

        $stored = $doc->fresh();
        $this->assertSame('Old text', $stored->search_text);
        $this->assertSame(6, $stored->version);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('label', 'Before restoring v2')->sole();

        $this->assertSame('named', $kept->kind);
        $this->assertSame(5, $kept->version_number);
        $this->assertSame('Last autosave, in no version', $kept->content_json['content'][0]['content'][0]['text']);

        $this->assertSame(
            [[2, 'named'], [3, 'auto'], [5, 'named'], [6, 'restore']],
            DocumentVersion::where('document_id', $doc->id)->orderBy('id')->get()
                ->map(fn (DocumentVersion $row) => [$row->version_number, $row->kind])->all(),
        );
    }
}
