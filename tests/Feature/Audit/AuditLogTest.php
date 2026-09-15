<?php

namespace Tests\Feature\Audit;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Livewire\Documents\ShareManager;
use App\Livewire\Documents\VersionHistory;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text, string $id = 'p0p0p0p0'): array
    {
        return ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    public function test_viewing_and_exporting_write_audit_rows(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);
        $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'markdown']));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.viewed',
            'subject_type' => Document::class,
            'subject_id' => $doc->id,
            'actor_id' => $user->id,
            'team_id' => $user->currentTeam->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.exported',
            'subject_id' => $doc->id,
            'actor_id' => $user->id,
        ]);

        $exported = AuditLog::where('action', 'document.exported')->firstOrFail();
        $this->assertSame(['format' => 'markdown'], $exported->context);
        $this->assertSame('user', $exported->actor_type);
    }

    public function test_share_changes_write_a_share_updated_row(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'S');

        Livewire::actingAs($user)
            ->test(ShareManager::class, ['uuid' => $doc->uuid])
            ->call('togglePublicLink');

        $row = AuditLog::where('action', 'share.updated')->firstOrFail();
        $this->assertSame($doc->id, $row->subject_id);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertTrue($row->context['is_public']);
    }

    public function test_restoring_a_version_writes_a_version_restored_row(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'V');
        $doc = $store->save($doc, $this->para('one'), $user, ['version' => 'named', 'label' => 'First']);
        $version = $doc->versions()->firstOrFail();

        Livewire::actingAs($user)
            ->test(VersionHistory::class, ['uuid' => $doc->uuid])
            ->call('restore', $version->id);

        $row = AuditLog::where('action', 'version.restored')->firstOrFail();
        $this->assertSame($doc->id, $row->subject_id);
        $this->assertSame($version->version_number, $row->context['version_number']);
    }

    public function test_audit_rows_refuse_to_be_updated(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        $row = AuditLog::firstOrFail();

        $this->expectException(\LogicException::class);
        $row->update(['action' => 'tampered']);
    }

    public function test_audit_rows_refuse_to_be_deleted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid]);

        $row = AuditLog::firstOrFail();

        $this->expectException(\LogicException::class);
        $row->delete();
    }
}
