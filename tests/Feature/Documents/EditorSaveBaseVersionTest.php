<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Events\DocumentUpdated;
use App\Livewire\Documents\Editor;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The two ways the editor saves - the Livewire action and the unload
 * beacon - both say which version their copy was based on.
 */
class EditorSaveBaseVersionTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    private function doc(User $user): Document
    {
        $this->seed(DocumentStyleSeeder::class);

        return app(DocumentStore::class)->create($user, 'Shared');
    }

    public function test_a_save_based_on_the_current_version_is_accepted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('Typed'), 1)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => 2]);
    }

    public function test_a_save_based_on_an_older_version_is_refused_as_a_conflict(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        // Somebody else saves while this page is open.
        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user);

        $editor->call('saveContent', $this->para('Mine, based on version 1'), 1)
            ->assertReturned(['ok' => false, 'conflict' => true, 'version' => 2]);

        $this->assertSame('Theirs', $doc->fresh()->search_text);
    }

    /**
     * A tab still running the previous JavaScript sends no base version at
     * all. The refusal carries NO version: that JavaScript adopts any number
     * it is handed as the base of its offline draft (see the next test).
     */
    public function test_a_save_with_no_base_version_is_refused_with_a_reload_message(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('From an old tab'))
            ->assertReturned(['ok' => false, 'conflict' => false, 'version' => null])
            ->assertHasErrors('content')
            ->assertSee('Reload');

        $this->assertSame(1, $doc->fresh()->version);
    }

    /**
     * The tab was opened at version 1 and somebody else has saved since. Its
     * old JavaScript does `if (Number.isFinite(result.version)) baseVersion
     * = result.version` before it looks at `ok`, and stamps every later
     * keystroke's offline draft with that base. Handed the CURRENT version,
     * the draft would claim to be based on text its writer never saw; the
     * reload the message asks for would then offer it back as up to date and
     * its autosave would pass the stale check, replacing the other person's
     * saves. So the answer must not say which version the server is at.
     */
    public function test_the_refusal_to_an_old_tab_does_not_hand_it_the_current_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $oldTab = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $other);

        // Compared strictly: assertReturned() on an array is a loose
        // comparison, and the point here is that no number comes back.
        $oldTab->call('saveContent', $this->para('From an old tab, based on version 1'))
            ->assertReturned(fn (mixed $answer): bool => $answer === ['ok' => false, 'conflict' => false, 'version' => null]);

        $fresh = $doc->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame('Theirs', $fresh->search_text);
    }

    /**
     * "Keep mine": the writer saves over a newer version on purpose. The
     * text that is replaced must still be somewhere - the other person's
     * save may have cut no version of its own.
     */
    public function test_an_overwrite_first_keeps_the_replaced_text_in_the_version_history(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi']);
        $doc = $this->doc($user);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        // Somebody else saves while this page is open, and no version is cut for it.
        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user, ['version' => 'none']);

        // The page has moved its base up to their version and says so.
        $editor->call('saveContent', $this->para('Mine'), 2, true)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => 3]);

        $this->assertSame('Mine', $doc->fresh()->search_text);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->sole();

        $this->assertSame('Before Thandi kept their version', $kept->label);
        $this->assertSame(2, $kept->version_number);
        $this->assertSame('Theirs', $kept->content_json['content'][0]['content'][0]['text']);
    }

    /** An ordinary save cuts no such version, and neither does an overwrite that is itself refused. */
    public function test_only_an_accepted_overwrite_cuts_the_named_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);
        $editor->call('saveContent', $this->para('An ordinary save'), 1);

        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user, ['version' => 'none']);

        // Based on version 2, but the document is at 3: refused, nothing kept.
        $editor->call('saveContent', $this->para('Mine'), 2, true)
            ->assertReturned(['ok' => false, 'conflict' => true, 'version' => 3]);

        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->count());
    }

    /** Content the schema refuses is not stored, so nothing was replaced and nothing is kept. */
    public function test_an_overwrite_the_schema_refuses_keeps_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]], 1, true)
            ->assertReturned(['ok' => false, 'conflict' => false, 'version' => 1]);

        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->count());
    }

    /**
     * The broadcast after a save can fail (no socket server). The save has
     * already been stored, so the writer is told it succeeded - and the
     * failure is reported, not swallowed.
     */
    public function test_a_broadcast_that_fails_after_a_save_is_reported_and_the_save_still_succeeds(): void
    {
        Exceptions::fake();
        Event::listen(DocumentUpdated::class, function (): void {
            throw new RuntimeException('The socket server is down.');
        });
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('Typed'), 1)
            ->assertReturned(['ok' => true, 'conflict' => false, 'version' => 2]);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'The socket server is down.');
    }

    /**
     * Changing the style and accepting a suggestion both save the document
     * from inside the open page. The page must be told the version that
     * produced, or its own next autosave would be refused as stale.
     */
    public function test_a_style_change_tells_the_page_the_new_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('setStyle', 'legal')
            ->assertDispatched('style-changed', fn (string $event, array $params) => ($params['version'] ?? null) === 2);
    }

    public function test_an_accepted_suggestion_tells_the_page_the_new_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $suggestion = AiSuggestion::create([
            'document_id' => $doc->id,
            'user_id' => $user->id,
            'suggestion_text' => '<p>Suggested body</p>',
            'created_at' => now(),
        ]);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('acceptSuggestion', $suggestion->id)
            ->assertDispatched('suggestion-accepted', fn (string $event, array $params) => ($params['version'] ?? null) === 2);
    }

    public function test_the_beacon_is_refused_with_409_when_its_base_version_is_stale(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        app(DocumentStore::class)->save(Document::findOrFail($doc->id), $this->para('Theirs'), $user);

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->para('Last words'), 'base_version' => 1])
            ->assertStatus(409)
            ->assertExactJson(['conflict' => true, 'version' => 2]);

        $this->assertSame('Theirs', $doc->fresh()->search_text);
    }

    public function test_the_beacon_is_rejected_when_it_states_no_base_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->actingAs($user)
            ->postJson(route('documents.autosave', $doc->uuid), ['content' => $this->para('Last words')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_version');

        $this->assertSame(1, $doc->fresh()->version);
    }
}
