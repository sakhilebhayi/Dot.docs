<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Export\DocxExporter;
use App\Documents\Export\MarkdownExporter;
use App\Models\Document;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * R2-10: every block a writer can make reaches Word and Markdown with its words intact - callouts, quotes, code,
 * rules, task lists, columns, section breaks and lists - not only the headings and paragraphs the older export tests
 * use.
 */
class RichExportTest extends TestCase
{
    use RefreshDatabase;

    private function p(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function rich(): Document
    {
        $this->seed(DocumentStyleSeeder::class);
        $owner = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $json = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Quarterly review']]],
            ['type' => 'callout', 'attrs' => ['tone' => 'note'], 'content' => [$this->p('Callout words')]],
            ['type' => 'blockquote', 'content' => [$this->p('Quoted words')]],
            ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo "code words";']]],
            ['type' => 'horizontalRule'],
            ['type' => 'taskList', 'content' => [
                ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [$this->p('Done task')]],
                ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [$this->p('Open task')]],
            ]],
            ['type' => 'columns', 'attrs' => ['count' => 2], 'content' => [
                ['type' => 'column', 'content' => [$this->p('Left column words')]],
                ['type' => 'column', 'content' => [$this->p('Right column words')]],
            ]],
            ['type' => 'sectionBreak'],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->p('Bullet words')]]]],
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [$this->p('Numbered words')]]]],
            $this->p('Closing words with **no** markdown injected'),
        ]];

        return $store->save($store->create($owner, 'Rich'), $json, $owner);
    }

    /** @var list<string> */
    private const WORDS = ['Quarterly review', 'Callout words', 'Quoted words', 'code words', 'Done task', 'Open task',
        'Left column words', 'Right column words', 'Bullet words', 'Numbered words', 'Closing words'];

    public function test_word_export_keeps_every_block_and_is_well_formed(): void
    {
        $doc = $this->rich();
        $path = (new DocxExporter)->export($doc->content_json, $doc, app(StyleEngine::class)->resolve($doc));
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse(@simplexml_load_string($xml), 'document.xml is well-formed');
        foreach (self::WORDS as $words) {
            $this->assertStringContainsString($words, $xml, "Word export lost: {$words}");
        }
    }

    public function test_markdown_export_keeps_every_block_in_markdown_form(): void
    {
        $doc = $this->rich();
        $md = app(MarkdownExporter::class)->export($doc->content_json);

        foreach (self::WORDS as $words) {
            $this->assertStringContainsString($words, $md, "Markdown export lost: {$words}");
        }
        $this->assertStringContainsString('> Quoted words', $md);
        $this->assertStringContainsString('```', $md);
        $this->assertMatchesRegularExpression('/^- \[x\] Done task$/m', $md);
        $this->assertMatchesRegularExpression('/^- \[ \] Open task$/m', $md);
        $this->assertMatchesRegularExpression('/^1\. Numbered words$/m', $md);
    }
}
