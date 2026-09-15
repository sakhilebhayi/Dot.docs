<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportRoutesWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_importing_a_markdown_file_writes_through_document_store(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');

        $file = UploadedFile::fake()->createWithContent('notes.md', "# Imported Title\n\nSome body text.");

        $response = $this->actingAs($user)->post(route('documents.import', $doc->uuid), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('documents.edit', $doc->uuid));

        $doc->refresh();
        $this->assertSame('heading', $doc->content_json['content'][0]['type']);

        $latestVersion = $doc->versions()->latest('id')->first();
        $this->assertSame('named', $latestVersion->kind);
        $this->assertSame('Imported notes.md', $latestVersion->label);
    }
}
