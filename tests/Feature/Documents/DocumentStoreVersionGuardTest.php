<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Documents\StaleDocumentException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\WebhookService;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A save states the version it was based on, and one based on an older
 * version is refused instead of silently replacing somebody else's work.
 */
class DocumentStoreVersionGuardTest extends TestCase
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

        return app(DocumentStore::class)->create($user, 'Guarded');
    }

    public function test_a_save_based_on_the_current_version_is_stored(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $saved = app(DocumentStore::class)->save($doc, $this->para('Mine'), $user, ['expectedVersion' => $doc->version]);

        $this->assertSame(2, $saved->version);
        $this->assertSame('Mine', $doc->fresh()->search_text);
    }

    public function test_a_save_based_on_an_older_version_is_refused_and_changes_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('Somebody else got here first'), $user, ['expectedVersion' => 1]);

        try {
            $store->save(Document::findOrFail($doc->id), $this->para('Based on what I opened'), $user, ['expectedVersion' => 1]);
            $this->fail('A save based on version 1 must be refused once the document is at version 2.');
        } catch (StaleDocumentException $e) {
            $this->assertSame(2, $e->currentVersion);
        }

        $fresh = $doc->fresh();
        $this->assertSame(2, $fresh->version);
        $this->assertSame('Somebody else got here first', $fresh->search_text);
    }

    /** Restore, import and "accept suggestion" replace the document on purpose and state no base. */
    public function test_a_save_that_states_no_base_version_still_goes_through(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('First'), $user);
        $saved = $store->save(Document::findOrFail($doc->id), $this->para('Second'), $user);

        $this->assertSame(3, $saved->version);
    }

    /**
     * Two requests can each hold a model loaded at version 1. The second
     * must become version 3, never a second "version 2".
     */
    public function test_the_new_version_is_counted_from_the_database_not_from_a_stale_model(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $first = Document::findOrFail($doc->id);
        $second = Document::findOrFail($doc->id);

        $store->save($first, $this->para('One'), $user);
        $saved = $store->save($second, $this->para('Two'), $user);

        $this->assertSame(3, $saved->version);
        $this->assertSame(3, $doc->fresh()->version);
    }

    /**
     * The editor's "Keep mine": the caller replaces a newer version on
     * purpose and asks for what it replaces to be kept. That version may
     * have no entry of its own in the history.
     */
    public function test_a_save_can_keep_the_version_it_replaces_as_a_named_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('Theirs'), $user, ['version' => 'none']);

        $saved = $store->save(Document::findOrFail($doc->id), $this->para('Mine'), $user, [
            'expectedVersion' => 2,
            'keepReplacedAs' => 'Before Thandi kept their version',
        ]);

        $this->assertSame(3, $saved->version);
        $this->assertSame('Mine', $doc->fresh()->search_text);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->sole();

        $this->assertSame('Before Thandi kept their version', $kept->label);
        $this->assertSame(2, $kept->version_number);
        $this->assertSame('Theirs', $kept->content_json['content'][0]['content'][0]['text']);
    }

    /** The kept version is part of the save: a save that is not stored keeps nothing. */
    public function test_a_save_that_is_not_stored_keeps_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $store = app(DocumentStore::class);

        $store->save($doc, $this->para('Theirs'), $user, ['version' => 'none']);

        try {
            $store->save(Document::findOrFail($doc->id), $this->para('Mine'), $user, [
                'expectedVersion' => 1,
                'keepReplacedAs' => 'Before Thandi kept their version',
            ]);
            $this->fail('A stale save must be refused even when it asks to keep what it replaces.');
        } catch (StaleDocumentException) {
            // Refused, as it should be.
        }

        try {
            $store->save(Document::findOrFail($doc->id), ['type' => 'doc', 'content' => [['type' => 'marquee']]], $user, [
                'expectedVersion' => 2,
                'keepReplacedAs' => 'Before Thandi kept their version',
            ]);
            $this->fail('Content the schema does not know must be refused.');
        } catch (InvalidArgumentException) {
            // Refused, as it should be.
        }

        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->where('kind', 'named')->count());
        $this->assertSame('Theirs', $doc->fresh()->search_text);
    }

    public function test_the_webhook_fires_once_after_the_save_has_committed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);
        $outerLevel = DB::transactionLevel();

        $this->mock(WebhookService::class, function (MockInterface $mock) use ($outerLevel) {
            $mock->shouldReceive('fire')->once()->andReturnUsing(function () use ($outerLevel) {
                $this->assertSame($outerLevel, DB::transactionLevel(), 'The webhook must fire after the save transaction has closed.');
            });
        });

        app(DocumentStore::class)->save($doc, $this->para('Announce me'), $user, ['expectedVersion' => 1]);
    }

    public function test_a_refused_save_fires_no_webhook(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->doc($user);

        $this->mock(WebhookService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('fire'));

        $this->expectException(StaleDocumentException::class);

        app(DocumentStore::class)->save($doc, $this->para('Too late'), $user, ['expectedVersion' => 99]);
    }
}
