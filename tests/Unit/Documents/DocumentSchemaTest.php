<?php

namespace Tests\Unit\Documents;

use App\Documents\Schema\BlockId;
use App\Documents\Schema\DocumentSchema;
use PHPUnit\Framework\TestCase;

class DocumentSchemaTest extends TestCase
{
    public function test_block_ids_are_eight_base62_chars_and_unique(): void
    {
        $ids = array_map(fn () => BlockId::generate(), range(1, 200));
        foreach ($ids as $id) {
            $this->assertTrue(BlockId::isValid($id), $id);
        }
        $this->assertCount(200, array_unique($ids));
        $this->assertFalse(BlockId::isValid('abc'));
        $this->assertFalse(BlockId::isValid('abcd-fgh'));
    }

    public function test_ensure_ids_assigns_ids_to_every_block_and_keeps_existing(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1, 'id' => 'KeepMe01'], 'content' => [['type' => 'text', 'text' => 'T']]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]]]],
            ]],
        ]];
        $out = (new DocumentSchema)->ensureIds($doc);
        $this->assertSame('KeepMe01', $out['content'][0]['attrs']['id']);
        $this->assertTrue(BlockId::isValid($out['content'][1]['attrs']['id']));
        $this->assertTrue(BlockId::isValid($out['content'][1]['content'][0]['attrs']['id']));
        $this->assertTrue(BlockId::isValid($out['content'][1]['content'][0]['content'][0]['attrs']['id']));
        $this->assertArrayNotHasKey('attrs', $out['content'][0]['content'][0]);
    }

    public function test_validate_rejects_unknown_nodes_and_duplicate_ids(): void
    {
        $schema = new DocumentSchema;
        $bad = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => []],
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => []],
            ['type' => 'marquee', 'attrs' => ['id' => 'bbbbbbbb']],
        ]];
        $errors = $schema->validate($bad);
        $this->assertContains('Duplicate block id aaaaaaaa', $errors);
        $this->assertContains('Unknown node type marquee', $errors);
        $this->assertSame([], $schema->validate($schema->ensureIds(['type' => 'doc', 'content' => []])));
    }

    public function test_plain_text_and_word_count(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fleet report']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ten trucks ran '], ['type' => 'text', 'text' => 'today.', 'marks' => [['type' => 'bold']]]]],
        ]];
        $schema = new DocumentSchema;
        $this->assertSame("Fleet report\nTen trucks ran today.", $schema->plainText($doc));
        $this->assertSame(6, $schema->wordCount($doc));
    }

    public function test_ensure_ids_treats_non_array_content_as_empty(): void
    {
        $schema = new DocumentSchema;
        $out = $schema->ensureIds(['type' => 'doc', 'content' => 'junk']);
        $this->assertSame([], $out['content']);
        $this->assertSame([], $schema->validate($out));
    }

    public function test_plain_text_renders_cross_ref_label(): void
    {
        $doc = ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'See '],
            ['type' => 'crossRef', 'attrs' => ['label' => 'Figure 2']],
        ]];
        $schema = new DocumentSchema;
        $this->assertSame('See Figure 2', $schema->plainText($doc));
    }

    public function test_word_count_counts_unicode_letters(): void
    {
        $schema = new DocumentSchema;
        $accented = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'café résumé naïve']]],
        ]];
        $this->assertSame(3, $schema->wordCount($accented));

        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fleet report']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ten trucks ran '], ['type' => 'text', 'text' => 'today.', 'marks' => [['type' => 'bold']]]]],
        ]];
        $this->assertSame(6, $schema->wordCount($doc));
    }

    public function test_normalise_keeps_only_whitelisted_alignments(): void
    {
        // align is interpolated into a `style` attribute by HtmlRenderer and
        // by the editor's renderHTML. Echo payloads reach both without ever
        // passing through parseHTML, so a bad value must never persist.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa', 'align' => 'center'], 'content' => []],
            ['type' => 'paragraph', 'attrs' => ['id' => 'bbbbbbbb', 'align' => 'RIGHT'], 'content' => []],
            ['type' => 'heading', 'attrs' => ['id' => 'cccccccc', 'level' => 1, 'align' => 'justify'], 'content' => []],
            ['type' => 'paragraph', 'attrs' => ['id' => 'dddddddd', 'align' => 'left; background: url(https://evil.example/x)'], 'content' => []],
            ['type' => 'heading', 'attrs' => ['id' => 'eeeeeeee', 'level' => 2, 'align' => ['left']], 'content' => []],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame('center', $out['content'][0]['attrs']['align']);
        $this->assertSame('right', $out['content'][1]['attrs']['align']);
        $this->assertSame('justify', $out['content'][2]['attrs']['align']);
        $this->assertArrayNotHasKey('align', $out['content'][3]['attrs']);
        $this->assertArrayNotHasKey('align', $out['content'][4]['attrs']);
    }

    public function test_normalise_clamps_column_count_to_two_through_four(): void
    {
        $columns = fn (string $id, mixed $count) => ['type' => 'columns', 'attrs' => ['id' => $id, 'count' => $count], 'content' => []];
        $doc = ['type' => 'doc', 'content' => [
            $columns('aaaaaaaa', 3),
            $columns('bbbbbbbb', 99),
            $columns('cccccccc', 1),
            $columns('dddddddd', '2; background: red'),
            $columns('eeeeeeee', '4'),
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame([3, 4, 2, 2, 4], array_column(array_column($out['content'], 'attrs'), 'count'));
    }

    public function test_normalise_reaches_nested_blocks(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'callout', 'attrs' => ['id' => 'aaaaaaaa', 'tone' => 'note'], 'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => 'bbbbbbbb', 'align' => 'evil"><script>'], 'content' => []],
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertArrayNotHasKey('align', $out['content'][0]['content'][0]['attrs']);
    }

    public function test_validate_rejects_unknown_mark_type(): void
    {
        $schema = new DocumentSchema;
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => [
                ['type' => 'text', 'text' => 'hi', 'marks' => [['type' => 'blink']]],
            ]],
        ]];
        $this->assertContains('Unknown mark type blink', $schema->validate($doc));
    }
}
