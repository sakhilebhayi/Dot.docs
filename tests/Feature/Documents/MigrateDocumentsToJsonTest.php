<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateDocumentsToJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_legacy_documents_and_templates(): void
    {
        $user = User::factory()->create();

        $docA = Document::factory()->for($user, 'owner')->create([
            'content' => '<h1>Old A</h1><p>first</p>',
            'content_json' => null,
        ]);
        $docB = Document::factory()->for($user, 'owner')->create([
            'content' => '<p>second doc</p>',
            'content_json' => null,
        ]);

        $template = DocumentTemplate::create([
            'name' => 'Legacy template',
            'category' => 'general',
            'content' => '<p>template body</p>',
            'is_global' => true,
            'created_by' => $user->id,
            'content_json' => null,
        ]);

        $this->artisan('dot:documents:migrate-json')
            ->assertExitCode(0);

        $this->assertNotNull($docA->fresh()->content_json);
        $this->assertSame('heading', $docA->fresh()->content_json['content'][0]['type']);
        $this->assertNotNull($docB->fresh()->content_json);
        $this->assertNotNull($template->fresh()->content_json);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();

        $doc = Document::factory()->for($user, 'owner')->create([
            'content' => '<p>untouched</p>',
            'content_json' => null,
        ]);

        $this->artisan('dot:documents:migrate-json', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($doc->fresh()->content_json);
    }
}
