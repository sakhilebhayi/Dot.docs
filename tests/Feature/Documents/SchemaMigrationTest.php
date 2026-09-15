<?php

namespace Tests\Feature\Documents;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Models\DocumentVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_stores_structured_content_and_page_setup(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'content_json' => ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => []],
            'page_setup' => ['size' => 'A4', 'orientation' => 'portrait'],
            'variables' => ['period' => 'August 2026'],
        ]);

        $doc->refresh();
        $this->assertSame('doc', $doc->content_json['type']);
        $this->assertSame('A4', $doc->page_setup['size']);
        $this->assertSame('report', $doc->style_key);
        $this->assertSame('draft', $doc->review_state);
        $this->assertSame(1, $doc->schema_version);
    }

    public function test_version_keeps_json_label_and_kind(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create();
        $v = DocumentVersion::create([
            'document_id' => $doc->id, 'content_snapshot' => '<p>x</p>',
            'content_json' => ['type' => 'doc', 'content' => []],
            'version_number' => 1, 'created_by' => $user->id, 'created_at' => now(),
            'label' => 'Board draft', 'kind' => 'named',
        ]);
        $this->assertSame('named', $v->fresh()->kind);
        $this->assertSame('doc', $v->fresh()->content_json['type']);
    }

    public function test_style_and_audit_tables_exist(): void
    {
        $style = DocumentStyle::create(['key' => 'test', 'name' => 'Test', 'category' => 'report', 'tokens' => ['font' => 'x'], 'is_system' => true]);
        $this->assertSame('x', $style->fresh()->tokens['font']);

        $log = AuditLog::create(['actor_type' => 'user', 'actor_id' => 1, 'action' => 'document.viewed', 'subject_type' => Document::class, 'subject_id' => 1, 'context' => []]);
        $this->assertNotNull($log->created_at);
        $this->assertFalse($log->timestamps);
    }

    /**
     * The refusal THROWS as of Task 12 - it used to return false, which was
     * indistinguishable from a successful write to the caller.
     */
    public function test_audit_log_refuses_updates_and_deletes(): void
    {
        $log = AuditLog::create(['actor_type' => 'user', 'actor_id' => 1, 'action' => 'document.viewed', 'subject_type' => Document::class, 'subject_id' => 1, 'context' => []]);

        try {
            $log->update(['action' => 'document.deleted']);
            $this->fail('An audit row accepted an update.');
        } catch (\LogicException $e) {
            $this->assertSame('Audit rows are immutable', $e->getMessage());
        }

        $this->assertSame('document.viewed', $log->fresh()->action);

        try {
            $log->delete();
            $this->fail('An audit row accepted a delete.');
        } catch (\LogicException $e) {
            $this->assertSame('Audit rows are immutable', $e->getMessage());
        }

        $this->assertNotNull(AuditLog::find($log->id));
    }

    public function test_document_style_resolves_team_override_before_system_default(): void
    {
        $team = Team::factory()->create();

        $systemStyle = DocumentStyle::create([
            'key' => 'legal', 'name' => 'System Legal', 'category' => 'legal',
            'tokens' => ['source' => 'system'], 'is_system' => true, 'team_id' => null,
        ]);

        $teamStyle = DocumentStyle::create([
            'key' => 'legal', 'name' => 'Team Legal', 'category' => 'legal',
            'tokens' => ['source' => 'team'], 'is_system' => false, 'team_id' => $team->id,
        ]);

        $this->assertTrue(DocumentStyle::resolve('legal', $team->id)->is($teamStyle));
        $this->assertTrue(DocumentStyle::resolve('legal', null)->is($systemStyle));
        $this->assertTrue(DocumentStyle::resolve('legal', 999)->is($systemStyle));

        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['team_id' => $team->id, 'style_key' => 'legal']);

        $this->assertTrue($doc->resolvedStyle()->is($teamStyle));
        $this->assertFalse(method_exists($doc, 'style'));
    }
}
