<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Documents\Export\MarkdownExporter;
use App\Documents\Import\DocxImporter;
use App\Documents\Import\MarkdownImporter;
use App\Documents\Schema\BlockId;
use App\Documents\Schema\DocumentSchema;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../fixtures/documents';

    public function test_markdown_round_trip(): void
    {
        $md = "# Title\n\nSome **bold** text.\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";
        $json = (new MarkdownImporter)->import($md);
        $this->assertSame([], (new DocumentSchema)->validate($json));
        $this->assertSame('heading', $json['content'][0]['type']);
        $this->assertSame('table', $json['content'][3]['type']);
        $out = (new MarkdownExporter)->export($json);
        $this->assertStringContainsString('# Title', $out);
        $this->assertStringContainsString('**bold**', $out);
        $this->assertStringContainsString('| a | b |', $out);
    }

    public function test_docx_export_keeps_structure_and_reimports(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $json = (new MarkdownImporter)->import("# Scope\n\n## Method\n\nBody **bold**.\n\n| h1 | h2 |\n|---|---|\n| x | y |\n");
        $doc = app(DocumentStore::class)->save(app(DocumentStore::class)->create($user, 'R'), $json, $user);
        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $this->assertFileExists($path);

        $back = (new DocxImporter)->import($path);
        $types = array_column($back['content'], 'type');
        $this->assertSame(['heading', 'heading', 'paragraph', 'table'], array_slice($types, 0, 4));
        $this->assertSame(1, $back['content'][0]['attrs']['level']);
        $this->assertSame(2, $back['content'][1]['attrs']['level']);
        $this->assertSame('bold', $back['content'][2]['content'][1]['marks'][0]['type']);
    }

    public function test_routes_export_all_formats(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        foreach (['pdf' => 'application/pdf', 'word' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'html' => 'text/html', 'markdown' => 'text/markdown'] as $fmt => $mime) {
            $this->actingAs($user)->get(route('documents.export', [$doc->uuid, $fmt]))->assertOk()->assertHeader('content-type', $mime.($fmt === 'html' || $fmt === 'markdown' ? '; charset=UTF-8' : ''));
        }
    }

    public function test_docx_fixture_imports_headings_lists_table_and_image(): void
    {
        Storage::fake('public');

        $json = (new DocxImporter)->import(self::FIXTURES.'/sample.docx', 'fixture-uuid');

        $this->assertSame([], (new DocumentSchema)->validate($json));

        $types = array_column($json['content'], 'type');
        $this->assertSame(
            ['heading', 'heading', 'heading', 'paragraph', 'bulletList', 'orderedList', 'table', 'image'],
            $types
        );

        // Title -> level 1, Heading1 -> 1, Heading2 -> 2.
        $this->assertSame([1, 1, 2], array_map(
            fn (array $n): int => $n['attrs']['level'],
            array_slice($json['content'], 0, 3)
        ));

        // Inline marks survive the walk. The hyperlink run carries an
        // underline mark as well as the link one, because that is how Word
        // writes a hyperlink — as character formatting, not as link styling.
        $runs = $json['content'][3]['content'];
        $marks = array_map(fn (array $n): array => array_column($n['marks'] ?? [], 'type'), $runs);
        $this->assertContains(['bold'], $marks);
        $this->assertContains(['italic'], $marks);
        $this->assertContains(['underline', 'link'], $marks);

        $link = $runs[array_search(['underline', 'link'], $marks, true)];
        $this->assertSame('method note', $link['text']);
        $this->assertSame('https://example.com/method', $link['marks'][1]['attrs']['href']);

        $this->assertCount(3, $json['content'][4]['content']);
        $this->assertCount(2, $json['content'][5]['content']);

        // First table row is tblHeader -> tableHeader cells, the rest tableCell.
        $rows = $json['content'][6]['content'];
        $this->assertSame(['tableHeader', 'tableHeader'], array_column($rows[0]['content'], 'type'));
        $this->assertSame(['tableCell', 'tableCell'], array_column($rows[1]['content'], 'type'));

        // The embedded PNG is extracted onto the public disk and referenced by path.
        $src = $json['content'][7]['attrs']['src'];
        $this->assertStringStartsWith('/storage/documents/fixture-uuid/', $src);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $src), '/'));
    }

    public function test_markdown_export_renders_pipe_tables_and_cross_references(): void
    {
        $headingId = BlockId::generate();
        $json = [
            'type' => 'doc',
            'attrs' => ['schema' => DocumentSchema::VERSION, 'style' => 'report', 'vars' => []],
            'content' => [
                ['type' => 'heading', 'attrs' => ['id' => $headingId, 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Findings']]],
                ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [
                    ['type' => 'text', 'text' => 'See '],
                    ['type' => 'crossRef', 'attrs' => ['targetId' => $headingId, 'kind' => 'heading', 'label' => 'Section 1.1']],
                    ['type' => 'text', 'text' => ' and '],
                    ['type' => 'variable', 'attrs' => ['key' => 'client']],
                    ['type' => 'text', 'text' => '.'],
                ]],
                ['type' => 'toc', 'attrs' => ['id' => BlockId::generate(), 'depth' => 3]],
                ['type' => 'table', 'attrs' => ['id' => BlockId::generate()], 'content' => [
                    ['type' => 'tableRow', 'attrs' => ['id' => BlockId::generate()], 'content' => [
                        ['type' => 'tableHeader', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => 'Region']]]]],
                        ['type' => 'tableHeader', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => 'Yield']]]]],
                    ]],
                    ['type' => 'tableRow', 'attrs' => ['id' => BlockId::generate()], 'content' => [
                        ['type' => 'tableCell', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => 'North']]]]],
                        ['type' => 'tableCell', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => [['type' => 'text', 'text' => '12.4']]]]],
                    ]],
                ]],
            ],
        ];

        $md = (new MarkdownExporter)->export($json);

        $this->assertStringContainsString('## Findings', $md);
        $this->assertStringContainsString('See Section 1.1 and {{ client }}.', $md);
        $this->assertStringContainsString('| Region | Yield |', $md);
        $this->assertStringContainsString('| --- | --- |', $md);
        $this->assertStringContainsString('| North | 12.4 |', $md);
        $this->assertStringNotContainsString('toc', $md);
    }

    public function test_importing_a_plain_text_file_splits_paragraphs_on_blank_lines(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        $file = UploadedFile::fake()->createWithContent('notes.txt', "First thought.\n\nSecond thought.\n");

        $this->actingAs($user)
            ->post(route('documents.import', $doc->uuid), ['file' => $file])
            ->assertRedirect(route('documents.edit', $doc->uuid));

        $doc->refresh();
        $this->assertSame(['paragraph', 'paragraph'], array_column($doc->content_json['content'], 'type'));
        $this->assertSame('First thought.', $doc->content_json['content'][0]['content'][0]['text']);
        $this->assertSame('Second thought.', $doc->content_json['content'][1]['content'][0]['text']);
    }

    public function test_importing_a_docx_file_writes_structured_json_through_the_store(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');

        $file = new UploadedFile(self::FIXTURES.'/sample.docx', 'sample.docx', null, null, true);

        $this->actingAs($user)
            ->post(route('documents.import', $doc->uuid), ['file' => $file])
            ->assertRedirect(route('documents.edit', $doc->uuid));

        $doc->refresh();
        $types = array_column($doc->content_json['content'], 'type');
        $this->assertSame(['heading', 'heading', 'heading', 'paragraph', 'bulletList', 'orderedList', 'table', 'image'], $types);
        $this->assertSame('Imported sample.docx', $doc->versions()->latest('id')->first()->label);
    }

    public function test_docx_export_round_trips_an_image_and_a_numbered_heading(): void
    {
        Storage::fake('public');
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();

        // A 4x4 px PNG on the public disk, referenced exactly as
        // DocumentImageController and DocxImporter reference one.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAADJEPnJAAAAFklEQVQI12P8//8/AzJgYkAD5AsAAIEcAyEZKm4nAAAAAElFTkSuQmCC');
        Storage::disk('public')->put('documents/src/pic.png', $png);

        $json = [
            'type' => 'doc',
            'attrs' => ['schema' => DocumentSchema::VERSION, 'style' => 'report', 'vars' => []],
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Scope']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]],
                ['type' => 'image', 'attrs' => ['src' => '/storage/documents/src/pic.png']],
            ],
        ];
        $doc = app(DocumentStore::class)->save(app(DocumentStore::class)->create($user, 'Round trip'), $json, $user);

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $back = (new DocxImporter)->import($path, 'reimported');
        @unlink($path);

        $this->assertSame([], (new DocumentSchema)->validate($back));
        $this->assertSame(['heading', 'paragraph', 'image'], array_column($back['content'], 'type'));

        // The 'report' style numbers headings, so the exported heading text
        // carries its outline number as literal text (Word has no linked
        // numbering definition this writer emits).
        $this->assertSame('1 Scope', $this->schemaPlainText($back['content'][0]));

        $src = $back['content'][2]['attrs']['src'];
        $this->assertStringStartsWith('/storage/documents/reimported/', $src);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $src), '/'));
        $this->assertSame($png, Storage::disk('public')->get(ltrim(str_replace('/storage/', '', $src), '/')));
    }

    public function test_docx_export_and_reimport_keeps_a_toc_as_a_toc_node(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();

        $json = [
            'type' => 'doc',
            'attrs' => ['schema' => DocumentSchema::VERSION, 'style' => 'report', 'vars' => []],
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Scope']]],
                ['type' => 'toc', 'attrs' => ['depth' => 2]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]],
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Method']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'More.']]],
            ],
        ];
        $doc = app(DocumentStore::class)->save(app(DocumentStore::class)->create($user, 'With TOC'), $json, $user);

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $back = (new DocxImporter)->import($path);
        @unlink($path);

        // The Word TOC field comes back as one `toc` node — NOT as the frozen
        // entry paragraphs Word caches inside the field.
        $this->assertSame([], (new DocumentSchema)->validate($back));
        $this->assertSame(
            ['heading', 'toc', 'paragraph', 'heading', 'paragraph'],
            array_column($back['content'], 'type')
        );
        $this->assertStringNotContainsString('1.1 Method', (new DocumentSchema)->plainText($back['content'][2]));
    }

    public function test_importing_an_html_file_writes_structured_json(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Page');

        $file = UploadedFile::fake()->createWithContent(
            'page.html',
            '<h2>Method</h2><p>Some <strong>bold</strong> text.</p><ul><li>one</li><li>two</li></ul>'
        );

        $this->actingAs($user)
            ->post(route('documents.import', $doc->uuid), ['file' => $file])
            ->assertRedirect(route('documents.edit', $doc->uuid));

        $doc->refresh();
        $this->assertSame(['heading', 'paragraph', 'bulletList'], array_column($doc->content_json['content'], 'type'));
        $this->assertSame(2, $doc->content_json['content'][0]['attrs']['level']);
    }

    public function test_import_rejects_an_unsupported_file_type(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        $this->actingAs($user)
            ->post(route('documents.import', $doc->uuid), ['file' => UploadedFile::fake()->create('sheet.csv', 4, 'text/csv')])
            ->assertSessionHasErrors('file');
    }

    private function schemaPlainText(array $node): string
    {
        return trim((new DocumentSchema)->plainText($node));
    }
}
