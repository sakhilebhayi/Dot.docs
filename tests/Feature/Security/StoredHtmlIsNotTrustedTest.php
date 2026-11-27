<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentStore;
use App\Livewire\Documents\VersionHistory;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R2-1 / S-06: HTML stored in the database is not printed as written. A legacy row (or one written straight to the
 * database) carrying a script must not reach a reader's browser on the shared page or in a version preview.
 */
class StoredHtmlIsNotTrustedTest extends TestCase
{
    use RefreshDatabase;

    private const EVIL = '<p>Hello</p><script>alert(1)</script><img src="x" onerror="alert(2)">';

    public function test_the_shared_page_renders_from_json_not_the_stored_html(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared', ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Real text']]]]]);
        $doc->forceFill(['is_public' => true, 'content' => self::EVIL])->saveQuietly();

        $page = $this->get(route('documents.shared', $doc->uuid))->assertOk()->getContent();

        $this->assertStringContainsString('Real text', $page);
        $this->assertStringNotContainsString('alert(1)', $page);
        $this->assertStringNotContainsString('onerror', $page);
    }

    public function test_a_version_preview_is_sanitised(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'History');
        $version = DocumentVersion::create([
            'document_id' => $doc->id, 'version_number' => 7, 'content_snapshot' => self::EVIL, 'created_by' => $owner->id,
        ]);

        $html = Livewire::actingAs($owner)->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('preview', $version->id)
            ->html();

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }
}
