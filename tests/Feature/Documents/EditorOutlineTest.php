<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditorOutlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_outline_response_includes_page_setup_and_header_footer_segments(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, [
            'page_setup' => [
                'orientation' => 'landscape',
                'header' => '{{ title }}',
                'footer' => 'Page {{ page }} of {{ pages }}',
            ],
        ]);

        $result = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->instance()->outline();

        $this->assertSame('landscape', $result['pageSetup']['orientation']);
        $this->assertSame('A4', $result['pageSetup']['size']);
        $this->assertSame([['type' => 'text', 'value' => 'Monthly report']], $result['headerSegments']);
        $this->assertSame([
            ['type' => 'text', 'value' => 'Page '],
            ['type' => 'field', 'value' => 'PAGE'],
            ['type' => 'text', 'value' => ' of '],
            ['type' => 'field', 'value' => 'NUMPAGES'],
        ], $result['footerSegments']);
    }

    public function test_outline_still_includes_the_pre_existing_fields(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        $result = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->instance()->outline();

        $this->assertArrayHasKey('numbers', $result);
        $this->assertArrayHasKey('toc', $result);
        $this->assertArrayHasKey('figures', $result);
        $this->assertArrayHasKey('tables', $result);
    }

    public function test_outline_does_not_crash_when_the_documents_team_has_been_deleted(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Orphaned document', null, [
            'page_setup' => [
                'header' => '{{ team }}',
                'footer' => 'Team: {{ team }}',
            ],
        ]);

        // Simulate team deletion by setting team_id to null
        $doc->update(['team_id' => null]);

        // Should not throw an error when team is null
        $result = Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->instance()->outline();

        // Team variable safely defaults to empty string, and empty text segments are skipped
        // Header: '{{ team }}' with team='' renders to empty, no segments
        // Footer: 'Team: {{ team }}' with team='' renders to 'Team: ', one text segment
        $this->assertSame([], $result['headerSegments']);
        $this->assertSame([['type' => 'text', 'value' => 'Team: ']], $result['footerSegments']);
    }
}
