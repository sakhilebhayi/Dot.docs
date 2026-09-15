<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Documents\Export\MarkdownExporter;
use App\Documents\Import\DocxImporter;
use App\Documents\Import\HtmlToJson;
use App\Documents\Import\MarkdownImporter;
use App\Documents\Import\PlainTextImporter;
use App\Documents\Schema\BlockId;
use App\Documents\Schema\DocumentSchema;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

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

    public function test_variable_syntax_stays_literal_inside_code(): void
    {
        // `{{ key }}` inside a fenced code block or an inline code span is an
        // author documenting the convention, not a variable to resolve.
        // VariableTagger must not descend into a codeBlock's `text*` content
        // model (splitting it in would violate ProseMirror's content
        // expression and open the document read-only) or split a text node
        // that carries a `code` mark.
        $md = "```\n{{ example_key }}\n```\n\nSee `{{ key }}` for the syntax.\n";
        $json = (new MarkdownImporter)->import($md);
        $this->assertSame([], (new DocumentSchema)->validate($json));

        $codeBlock = $json['content'][0];
        $this->assertSame('codeBlock', $codeBlock['type']);
        $this->assertSame([['type' => 'text', 'text' => "{{ example_key }}\n"]], $codeBlock['content']);

        $paragraph = $json['content'][1];
        $this->assertSame('paragraph', $paragraph['type']);
        $this->assertNotContains('variable', array_column($paragraph['content'], 'type'));

        $inlineCode = $paragraph['content'][1];
        $this->assertSame('{{ key }}', $inlineCode['text']);
        $this->assertSame([['type' => 'code']], $inlineCode['marks']);
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

    public function test_docx_export_never_embeds_an_image_from_outside_the_public_disk(): void
    {
        Storage::fake('public');
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();

        // A picture the document must never be able to reach: it lives
        // outside the public disk entirely.
        $outside = tempnam(sys_get_temp_dir(), 'dotdoc_outside_').'.png';
        file_put_contents($outside, base64_decode(self::PNG));

        // ../ enough times to climb out of the disk root and back down to it
        // by absolute path — the shape a hand-written autosave payload would
        // use, since `image.attrs.src` is free text in the schema.
        $root = (string) realpath(Storage::disk('public')->path(''));
        $climb = str_repeat('../', substr_count(trim($root, '/'), '/') + 2);

        $doc = app(DocumentStore::class)->save(
            app(DocumentStore::class)->create($user, 'Traversal'),
            $this->doc([
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]],
                ['type' => 'image', 'attrs' => ['src' => '/storage/'.$climb.ltrim($outside, '/')]],
            ]),
            $user
        );

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));

        $this->assertSame([], $this->zipEntries($path, 'word/media/'));
        @unlink($path);
        @unlink($outside);
    }

    public function test_docx_round_trips_a_nested_list(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();

        $json = $this->doc([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    $this->para('Alpha'),
                    ['type' => 'bulletList', 'content' => [
                        ['type' => 'listItem', 'content' => [$this->para('Alpha one')]],
                    ]],
                ]],
                ['type' => 'listItem', 'content' => [$this->para('Beta')]],
                ['type' => 'listItem', 'content' => [
                    $this->para('Gamma'),
                    ['type' => 'orderedList', 'content' => [
                        ['type' => 'listItem', 'content' => [$this->para('Gamma one')]],
                    ]],
                ]],
            ]],
        ]);
        $doc = app(DocumentStore::class)->save(app(DocumentStore::class)->create($user, 'Nested'), $json, $user);

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $back = (new DocxImporter)->import($path);
        @unlink($path);

        $this->assertSame([], (new DocumentSchema)->validate($back));
        $this->assertSame(['bulletList'], array_column($back['content'], 'type'));

        $items = $back['content'][0]['content'];
        $this->assertCount(3, $items);

        // A sub-item is a nested list INSIDE its parent item, not a sibling.
        $this->assertSame(['paragraph', 'bulletList'], array_column($items[0]['content'], 'type'));
        $this->assertSame('Alpha', $this->schemaPlainText($items[0]['content'][0]));
        $this->assertSame('Alpha one', $this->schemaPlainText($items[0]['content'][1]));

        $this->assertSame('Beta', $this->schemaPlainText($items[1]));

        // A nested list of a DIFFERENT type keeps its own type.
        $this->assertSame(['paragraph', 'orderedList'], array_column($items[2]['content'], 'type'));
        $this->assertSame('Gamma one', $this->schemaPlainText($items[2]['content'][1]));
    }

    public function test_html_import_drops_javascript_link_and_image_sources(): void
    {
        $json = (new HtmlToJson)->convert(
            '<p><a href="javascript:alert(1)">click</a> and <a href="https://example.com">safe</a></p>'
            .'<img src="javascript:alert(2)" alt="x">'
        );

        $this->assertSame([], (new DocumentSchema)->validate($json));

        $runs = $json['content'][0]['content'];
        $this->assertSame('click', $runs[0]['text']);
        $this->assertSame([], $runs[0]['marks'] ?? []);

        $safe = $runs[count($runs) - 1];
        $this->assertSame('safe', $safe['text']);
        $this->assertSame('https://example.com', $safe['marks'][0]['attrs']['href']);

        $image = $json['content'][1];
        $this->assertSame('image', $image['type']);
        $this->assertArrayNotHasKey('src', $image['attrs']);
    }

    public function test_docx_import_skips_an_image_over_the_size_cap(): void
    {
        Storage::fake('public');

        $expected = ['heading', 'heading', 'heading', 'paragraph', 'bulletList', 'orderedList', 'table'];

        $json = (new DocxImporter(maxImageBytes: 64))->import(self::FIXTURES.'/sample.docx', 'capped');

        $this->assertSame([], (new DocumentSchema)->validate($json));
        // Everything but the oversized picture still imports.
        $this->assertSame($expected, array_column($json['content'], 'type'));

        // Same for the per-document count cap.
        $counted = (new DocxImporter(maxImages: 0))->import(self::FIXTURES.'/sample.docx', 'counted');
        $this->assertSame($expected, array_column($counted['content'], 'type'));
    }

    public function test_import_route_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Notes');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)
                ->post(route('documents.import', $doc->uuid), [
                    'file' => UploadedFile::fake()->createWithContent("notes{$i}.txt", 'Line.'),
                ])
                ->assertRedirect(route('documents.edit', $doc->uuid));
        }

        $this->actingAs($user)
            ->post(route('documents.import', $doc->uuid), [
                'file' => UploadedFile::fake()->createWithContent('notes11.txt', 'Line.'),
            ])
            ->assertStatus(429);
    }

    public function test_export_route_is_rate_limited(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'markdown']))->assertOk();
        }

        $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'markdown']))->assertStatus(429);
    }

    public function test_import_denies_a_user_who_cannot_update_the_document(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Private');

        $this->actingAs($stranger)
            ->post(route('documents.import', $doc->uuid), [
                'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Line.'),
            ])
            ->assertForbidden();
    }

    public function test_export_denies_a_user_who_cannot_view_the_document(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Private');

        $this->actingAs($stranger)
            ->get(route('documents.export', [$doc->uuid, 'markdown']))
            ->assertForbidden();
    }

    public function test_docx_round_trips_every_inline_mark(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();

        $marks = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'subscript', 'superscript'];
        $runs = [];
        foreach ($marks as $mark) {
            $runs[] = ['type' => 'text', 'text' => $mark.' ', 'marks' => [['type' => $mark]]];
        }
        $runs[] = ['type' => 'text', 'text' => 'coloured', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#123456']]]];

        $doc = app(DocumentStore::class)->save(
            app(DocumentStore::class)->create($user, 'Marks'),
            $this->doc([['type' => 'paragraph', 'content' => $runs]]),
            $user
        );

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $back = (new DocxImporter)->import($path);
        @unlink($path);

        $found = [];
        foreach ($back['content'][0]['content'] as $run) {
            foreach ($run['marks'] ?? [] as $mark) {
                $found[$mark['type']] = $mark;
            }
        }

        foreach ([...$marks, 'textStyle'] as $mark) {
            $this->assertArrayHasKey($mark, $found, "the {$mark} mark did not survive the round trip");
        }
        $this->assertSame('#123456', $found['textStyle']['attrs']['color']);
    }

    public function test_markdown_escapes_markdown_syntax_in_body_text(): void
    {
        $text = '# not a heading, 2 * 3 = 6, a_b_c, [not a link], a | pipe';

        $json = $this->doc([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]);
        $md = (new MarkdownExporter)->export($json);

        $back = (new MarkdownImporter)->import($md);

        $this->assertSame('paragraph', $back['content'][0]['type']);
        $this->assertSame($text, $this->schemaPlainText($back['content'][0]));
    }

    public function test_markdown_round_trips_a_code_span_holding_backticks(): void
    {
        $json = $this->doc([['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'a ``b`` c', 'marks' => [['type' => 'code']]],
        ]]]);

        $back = (new MarkdownImporter)->import((new MarkdownExporter)->export($json));

        $run = $back['content'][0]['content'][0];
        $this->assertSame('a ``b`` c', $run['text']);
        $this->assertSame(['code'], array_column($run['marks'], 'type'));
    }

    public function test_docx_header_text_is_stripped_of_control_characters(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Header');
        $doc->page_setup = ['header' => '{{ client }}', 'footer' => 'Page {{ page }} of {{ pages }}'];
        $doc->variables = ['client' => "Acme\x0BCorp"];
        $doc->save();

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));

        $xml = $this->zipContents($path, 'word/header1.xml');
        $this->assertStringContainsString('AcmeCorp', $xml);
        $this->assertStringNotContainsString("\x0B", $xml);
        @unlink($path);
    }

    public function test_docx_bands_write_no_word_field_from_a_user_controlled_title_or_variable(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Fields');
        $doc->title = '{INCLUDEPICTURE "http://attacker.test/x" \\d}';
        $doc->page_setup = [
            'header' => '{{ client }}',
            'footer' => '{{ title }} - Page {{ page }} of {{ pages }}',
        ];
        $doc->variables = ['client' => '{HYPERLINK "\\\\attacker.test\\share\\x"}'];
        $doc->save();

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));

        $header = $this->zipContents($path, 'word/header1.xml');
        $footer = $this->zipContents($path, 'word/footer1.xml');

        // The header's whole text came out of a variable, so it must carry no
        // field at all - the braces in it are literal characters.
        $this->assertSame([], $this->fieldInstructions($header));
        $this->assertStringContainsString('{HYPERLINK', $header);

        // The footer holds the two fields this app itself writes and nothing
        // else, even though the title substituted into it is a field code.
        $this->assertSame([' PAGE ', ' NUMPAGES '], $this->fieldInstructions($footer));
        $this->assertStringContainsString('{INCLUDEPICTURE', $footer);
        $this->assertStringContainsString('Page ', $footer);

        @unlink($path);
    }

    public function test_docx_import_rejects_an_archive_that_unpacks_far_larger_than_the_upload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dotdoc_bomb_').'.docx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('word/document.xml', str_repeat('a', 262_144));
        $zip->close();

        // A tiny upload: the compressed archive is orders of magnitude smaller
        // than what it unpacks to, which is the whole point of the attack.
        $this->assertLessThan(65_536, (int) filesize($path));

        try {
            // Its bytes are not XML either, so answering with THIS message
            // proves the guard ran before PhpWord opened the archive.
            (new DocxImporter(maxTotalBytes: 65_536))->import($path);
            $this->fail('An oversized archive should not have been imported.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('This file is too large to import.', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_docx_import_rejects_a_single_oversized_archive_entry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dotdoc_bomb_').'.docx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('word/document.xml', str_repeat('a', 262_144));
        $zip->close();

        try {
            (new DocxImporter(maxEntryBytes: 65_536))->import($path);
            $this->fail('An oversized entry should not have been imported.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        } finally {
            @unlink($path);
        }
    }

    public function test_docx_import_accepts_an_archive_inside_the_size_bounds(): void
    {
        $json = (new DocxImporter(maxEntryBytes: 1_048_576, maxTotalBytes: 4_194_304))
            ->import(self::FIXTURES.'/sample.docx');

        $this->assertSame([], (new DocumentSchema)->validate($json));
        $this->assertNotSame([], $json['content']);
    }

    public function test_import_rejects_a_corrupt_docx_with_a_422(): void
    {
        $user = User::factory()->create();
        $document = app(DocumentStore::class)->create($user, 'Report');

        $corrupt = tempnam(sys_get_temp_dir(), 'dotdoc_corrupt_').'.docx';
        copy(self::FIXTURES.'/sample.docx', $corrupt);
        $zip = new ZipArchive;
        $zip->open($corrupt);
        $zip->addFromString('word/document.xml', '<w:document><this is not xml');
        $zip->close();

        $this->actingAs($user)
            ->post(route('documents.import', $document->uuid), [
                'file' => new UploadedFile($corrupt, 'sample.docx', null, null, true),
            ])
            ->assertStatus(422);

        @unlink($corrupt);
    }

    public function test_docx_round_trips_a_hard_break_and_a_multi_paragraph_list_item(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();

        $json = $this->doc([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'First line'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Second line'],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [$this->para('Item opening'), $this->para('Item continued')]],
            ]],
        ]);
        $doc = app(DocumentStore::class)->save(app(DocumentStore::class)->create($user, 'Breaks'), $json, $user);

        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $back = (new DocxImporter)->import($path);
        @unlink($path);

        $this->assertSame([], (new DocumentSchema)->validate($back));
        $this->assertSame(['paragraph', 'bulletList'], array_column($back['content'], 'type'));

        // The break survives as a break — the two lines must not concatenate.
        $this->assertSame(
            ['text', 'hardBreak', 'text'],
            array_column($back['content'][0]['content'], 'type')
        );
        $this->assertSame("First line\nSecond line", $this->schemaPlainText($back['content'][0]));

        // The item's second paragraph stays INSIDE the list item rather than
        // escaping as a detached paragraph after the list.
        $items = $back['content'][1]['content'];
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Item opening', $this->schemaPlainText($items[0]));
        $this->assertStringContainsString('Item continued', $this->schemaPlainText($items[0]));
    }

    public function test_markdown_round_trips_adjacent_bullet_and_task_lists(): void
    {
        $json = $this->doc([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [$this->para('plain one')]],
            ]],
            ['type' => 'taskList', 'content' => [
                ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [$this->para('done')]],
                ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [$this->para('todo')]],
            ]],
        ]);

        $back = (new MarkdownImporter)->import((new MarkdownExporter)->export($json));

        $this->assertSame([], (new DocumentSchema)->validate($back));
        $this->assertSame(['bulletList', 'taskList'], array_column($back['content'], 'type'));
        $this->assertSame('plain one', $this->schemaPlainText($back['content'][0]));

        $tasks = $back['content'][1]['content'];
        $this->assertTrue($tasks[0]['attrs']['checked']);
        $this->assertFalse($tasks[1]['attrs']['checked']);
        $this->assertSame('done', $this->schemaPlainText($tasks[0]));
    }

    public function test_markdown_round_trips_a_two_paragraph_list_item(): void
    {
        $json = $this->doc([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [$this->para('opening'), $this->para('continued')]],
            ]],
        ]);

        $back = (new MarkdownImporter)->import((new MarkdownExporter)->export($json));

        $this->assertSame(['bulletList'], array_column($back['content'], 'type'));
        $items = $back['content'][0]['content'];
        $this->assertCount(1, $items);
        $this->assertSame(['paragraph', 'paragraph'], array_column($items[0]['content'], 'type'));
        $this->assertSame('opening', $this->schemaPlainText($items[0]['content'][0]));
        $this->assertSame('continued', $this->schemaPlainText($items[0]['content'][1]));
    }

    public function test_imports_strip_control_characters_from_text(): void
    {
        $schema = new DocumentSchema;

        $this->assertSame('AcmeCorp', trim($schema->plainText((new PlainTextImporter)->import("Acme\x0BCorp"))));
        $this->assertSame('AcmeCorp', trim($schema->plainText((new MarkdownImporter)->import("Acme\x0BCorp"))));
        $this->assertSame('AcmeCorp', trim($schema->plainText((new HtmlToJson)->convert("<p>Acme\x0BCorp</p>"))));
    }

    /** A 4x4 px PNG, base64. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAADJEPnJAAAAFklEQVQI12P8//8/AzJgYkAD5AsAAIEcAyEZKm4nAAAAAElFTkSuQmCC';

    /** @param list<array<string,mixed>> $content */
    private function doc(array $content): array
    {
        return [
            'type' => 'doc',
            'attrs' => ['schema' => DocumentSchema::VERSION, 'style' => 'report', 'vars' => []],
            'content' => $content,
        ];
    }

    /** @return array<string,mixed> */
    private function para(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return list<string> */
    private function zipEntries(string $path, string $prefix): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }
        $zip->close();

        return $names;
    }

    /**
     * Every Word field instruction in a document part.
     *
     * @return list<string>
     */
    private function fieldInstructions(string $xml): array
    {
        preg_match_all('#<w:instrText[^>]*>(.*?)</w:instrText>#s', $xml, $matches);

        return array_map(fn (string $t): string => html_entity_decode($t, ENT_XML1), $matches[1]);
    }

    private function zipContents(string $path, string $entry): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();
        $this->assertIsString($contents, "{$entry} is missing from the exported file");

        return $contents;
    }

    private function schemaPlainText(array $node): string
    {
        return trim((new DocumentSchema)->plainText($node));
    }
}
