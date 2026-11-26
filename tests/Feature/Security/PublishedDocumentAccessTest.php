<?php

namespace Tests\Feature\Security;

use App\Documents\DocumentStore;
use App\Livewire\Documents\CommentThread;
use App\Livewire\Documents\DocumentSettings;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\VersionHistory;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EA-1 / S-01 and S-02 (enterprise audit, docs/programme/ENTERPRISE_AUDIT.md).
 *
 * S-01: publishing a document used to make DocumentPolicy::view() true for
 * every signed-in account, so anyone holding the share link could open the
 * editor, the whole version history and every export - past the link's
 * password and after it expired. Readers of a published document now go
 * through /shared/{uuid} and /d/{slug} only, where the gates apply.
 *
 * S-02: the Settings form (needs only `update`) could switch `is_public`
 * without the `manage` right and without an audit row.
 */
class PublishedDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Document} */
    private function publishedWithPassword(?string $expires = null): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $document = app(DocumentStore::class)->create($owner, 'Board pack');
        $document->forceFill([
            'is_public' => true,
            'share_password' => Hash::make('letmein-please'),
            'share_expires_at' => $expires,
        ])->save();

        return [$owner, $outsider, $document->fresh()];
    }

    public function test_an_outsider_cannot_view_a_published_document_in_the_app(): void
    {
        [, $outsider, $document] = $this->publishedWithPassword();

        $this->assertFalse($outsider->can('view', $document));
    }

    public function test_an_outsider_holding_the_link_cannot_open_the_editor_history_or_comments(): void
    {
        [, $outsider, $document] = $this->publishedWithPassword();
        $this->actingAs($outsider);

        Livewire::test(Editor::class, ['uuid' => $document->uuid])->assertForbidden();
        Livewire::test(VersionHistory::class, ['uuid' => $document->uuid])->assertForbidden();
        // The thread is mounted inside the editor; every later request re-checks.
        Livewire::test(CommentThread::class, ['document' => $document])
            ->call('postComment')
            ->assertForbidden();
        $this->assertSame(0, $document->comments()->count());
    }

    public function test_an_outsider_holding_the_link_cannot_export_or_sync(): void
    {
        [, $outsider, $document] = $this->publishedWithPassword();
        $this->actingAs($outsider);

        foreach (['pdf', 'word', 'html', 'markdown'] as $format) {
            $this->get(route('documents.export', [$document->uuid, $format]))->assertForbidden();
        }
        $this->get(route('documents.preview-pdf', $document->uuid))->assertForbidden();
        $this->postJson(route('documents.sync', $document->uuid), ['version' => 1, 'tab' => 'tab-test-0001'])->assertForbidden();
    }

    public function test_an_expired_link_gives_an_outsider_nothing_in_the_app_either(): void
    {
        [, $outsider, $document] = $this->publishedWithPassword(now()->subDay()->toDateTimeString());
        $this->actingAs($outsider);

        $this->get(route('documents.export', [$document->uuid, 'markdown']))->assertForbidden();
        $this->get(route('documents.shared', $document->uuid))->assertStatus(410);
    }

    public function test_the_public_page_still_asks_a_signed_in_outsider_for_the_password(): void
    {
        [, $outsider, $document] = $this->publishedWithPassword();

        $this->actingAs($outsider)
            ->get(route('documents.shared', $document->uuid))
            ->assertOk()
            ->assertViewIs('documents.shared-password');
    }

    public function test_a_deep_link_to_a_published_document_sends_an_outsider_to_its_public_page(): void
    {
        [, $outsider, $document] = $this->publishedWithPassword();

        $this->actingAs($outsider)
            ->get(route('documents.open', $document->uuid))
            ->assertRedirect(route('documents.shared', $document->uuid));
    }

    public function test_the_owner_keeps_full_access(): void
    {
        [$owner, , $document] = $this->publishedWithPassword();
        $this->actingAs($owner);

        Livewire::test(Editor::class, ['uuid' => $document->uuid])->assertOk();
        $this->get(route('documents.export', [$document->uuid, 'markdown']))->assertOk();
    }

    public function test_the_settings_form_never_changes_the_published_state(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $document = app(DocumentStore::class)->create($owner, 'Draft');
        $this->assertFalse((bool) $document->is_public);

        Livewire::actingAs($owner)
            ->test(DocumentSettings::class, ['uuid' => $document->uuid])
            ->set('title', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $document->refresh();
        $this->assertSame('Renamed', $document->title);
        $this->assertFalse((bool) $document->is_public);
        $this->assertSame(0, AuditLog::where('action', 'share.updated')->count());
    }

    public function test_the_settings_component_has_no_published_property_to_tamper_with(): void
    {
        $this->assertFalse(property_exists(DocumentSettings::class, 'isPublic'));
    }
}
