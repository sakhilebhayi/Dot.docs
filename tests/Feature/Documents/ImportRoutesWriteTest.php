<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Schema\BlockId;
use App\Models\Document;
use App\Models\DocumentVersion;
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

    /**
     * An import replaces the whole document. What it replaces may be in no
     * version row - an autosave cuts none while the same author's last one
     * is under two minutes old - and every open tab follows the replacement,
     * so the replaced text must be kept first.
     */
    public function test_an_import_keeps_the_document_it_replaces(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Report');

        $para = fn (string $text): array => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];

        // Two autosaves by one author inside two minutes: only the first cuts a version.
        $store->save($doc, $para('First autosave'), $user);
        $store->save(Document::findOrFail($doc->id), $para('Second autosave, in no version'), $user);
        $this->assertSame([2], DocumentVersion::where('document_id', $doc->id)->pluck('version_number')->all());

        $this->actingAs($user)->post(route('documents.import', $doc->uuid), [
            'file' => UploadedFile::fake()->createWithContent('notes.md', 'Imported body.'),
        ])->assertRedirect(route('documents.edit', $doc->uuid));

        $this->assertSame('Imported body.', $doc->fresh()->search_text);

        $kept = DocumentVersion::where('document_id', $doc->id)->where('label', 'Before import of notes.md')->sole();

        $this->assertSame('named', $kept->kind);
        $this->assertSame(3, $kept->version_number);
        $this->assertSame('Second autosave, in no version', $kept->content_json['content'][0]['content'][0]['text']);
    }

    /** The label column holds 120 characters; an upload's name is the user's to choose. */
    public function test_the_kept_versions_label_fits_the_column_whatever_the_file_is_called(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Report');
        // 108 characters: "Imported <name>" still fits the column, "Before
        // import of <name>" would not.
        $name = str_repeat('n', 105).'.md';

        $this->actingAs($user)->post(route('documents.import', $doc->uuid), [
            'file' => UploadedFile::fake()->createWithContent($name, 'Imported body.'),
        ])->assertRedirect(route('documents.edit', $doc->uuid));

        $kept = DocumentVersion::where('document_id', $doc->id)->where('label', 'like', 'Before import of %')->sole();

        $this->assertSame('Before import of '.str_repeat('n', 100), $kept->label);
        $this->assertLessThanOrEqual(120, mb_strlen($kept->label));
    }
}
