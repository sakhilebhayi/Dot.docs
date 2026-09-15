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

    public function test_normalise_drops_structurally_empty_containers(): void
    {
        // ProseMirror content expressions: `table` is `tableRow+`,
        // `bulletList`/`orderedList`/`taskList` need at least one item. An
        // empty one is not a thin document, it is an INVALID one, and with
        // enableContentCheck the editor refuses to open it.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => [['type' => 'text', 'text' => 'keep']]],
            ['type' => 'table', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => []],
            ['type' => 'bulletList', 'attrs' => ['id' => 'cccccccc'], 'content' => []],
            ['type' => 'orderedList', 'attrs' => ['id' => 'dddddddd'], 'content' => []],
            ['type' => 'taskList', 'attrs' => ['id' => 'eeeeeeee'], 'content' => []],
            ['type' => 'table', 'attrs' => ['id' => 'ffffffff'], 'content' => [
                ['type' => 'tableRow', 'attrs' => ['id' => 'gggggggg'], 'content' => []],
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame(['paragraph'], array_column($out['content'], 'type'));
    }

    public function test_normalise_gives_an_empty_container_a_paragraph_child(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'bulletList', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => [
                ['type' => 'listItem', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => []],
            ]],
            ['type' => 'blockquote', 'attrs' => ['id' => 'cccccccc'], 'content' => []],
            ['type' => 'callout', 'attrs' => ['id' => 'dddddddd', 'tone' => 'note'], 'content' => []],
            ['type' => 'table', 'attrs' => ['id' => 'eeeeeeee'], 'content' => [
                ['type' => 'tableRow', 'attrs' => ['id' => 'ffffffff'], 'content' => [
                    ['type' => 'tableHeader', 'attrs' => ['id' => 'gggggggg'], 'content' => []],
                    ['type' => 'tableCell', 'attrs' => ['id' => 'hhhhhhhh'], 'content' => []],
                ]],
            ]],
            ['type' => 'taskList', 'attrs' => ['id' => 'iiiiiiii'], 'content' => [
                ['type' => 'taskItem', 'attrs' => ['id' => 'jjjjjjjj'], 'content' => []],
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $listItem = $out['content'][0]['content'][0];
        $this->assertSame('paragraph', $listItem['content'][0]['type']);
        $this->assertTrue(BlockId::isValid($listItem['content'][0]['attrs']['id']));
        $this->assertSame('paragraph', $out['content'][1]['content'][0]['type']);
        $this->assertSame('paragraph', $out['content'][2]['content'][0]['type']);
        $this->assertSame('paragraph', $out['content'][3]['content'][0]['content'][0]['content'][0]['type']);
        $this->assertSame('paragraph', $out['content'][3]['content'][0]['content'][1]['content'][0]['type']);
        $this->assertSame('paragraph', $out['content'][4]['content'][0]['content'][0]['type']);
    }

    public function test_normalise_gives_a_list_item_whose_first_child_is_not_a_paragraph_one(): void
    {
        // `listItem` is `paragraph block*` — a list item that opens with a
        // nested list violates the expression and fails the content check.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'bulletList', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => [
                ['type' => 'listItem', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => [
                    ['type' => 'bulletList', 'attrs' => ['id' => 'cccccccc'], 'content' => [
                        ['type' => 'listItem', 'attrs' => ['id' => 'dddddddd'], 'content' => [
                            ['type' => 'paragraph', 'attrs' => ['id' => 'eeeeeeee'], 'content' => [['type' => 'text', 'text' => 'deep']]],
                        ]],
                    ]],
                ]],
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $children = $out['content'][0]['content'][0]['content'];
        $this->assertSame(['paragraph', 'bulletList'], array_column($children, 'type'));
        $this->assertTrue(BlockId::isValid($children[0]['attrs']['id']));
    }

    public function test_normalise_makes_columns_children_match_the_count(): void
    {
        $column = fn (string $id, string $text) => ['type' => 'column', 'attrs' => ['id' => $id], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => $id.'p'], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];

        // Three columns declared as two: the third column's blocks are merged
        // into the last kept column rather than thrown away.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'columns', 'attrs' => ['id' => 'aaaaaaaa', 'count' => 2], 'content' => [
                $column('bbbbbbb1', 'one'), $column('bbbbbbb2', 'two'), $column('bbbbbbb3', 'three'),
            ]],
            // One column declared as three: padded with empty columns.
            ['type' => 'columns', 'attrs' => ['id' => 'cccccccc', 'count' => 3], 'content' => [
                $column('ddddddd1', 'only'),
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $merged = $out['content'][0];
        $this->assertCount(2, $merged['content']);
        $this->assertSame(['column', 'column'], array_column($merged['content'], 'type'));
        $this->assertSame(2, count($merged['content'][1]['content']));
        $this->assertSame('three', $merged['content'][1]['content'][1]['content'][0]['text']);

        $padded = $out['content'][1];
        $this->assertCount(3, $padded['content']);
        $this->assertSame('paragraph', $padded['content'][2]['content'][0]['type']);
        $this->assertTrue(BlockId::isValid($padded['content'][2]['attrs']['id']));
    }

    public function test_normalise_repairs_a_figure_that_is_not_media_plus_caption_without_losing_its_content(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            // Media with no caption: the caption is added, the picture kept.
            ['type' => 'figure', 'attrs' => ['id' => 'aaaaaaaa', 'kind' => 'image'], 'content' => [
                ['type' => 'image', 'attrs' => ['id' => 'bbbbbbbb', 'src' => '/storage/a.png']],
            ]],
            // Nothing a figure can be built around. The figure goes; what it
            // was holding is the writer's and is lifted out in its place.
            ['type' => 'figure', 'attrs' => ['id' => 'cccccccc', 'kind' => 'image'], 'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => 'dddddddd'], 'content' => []],
                ['type' => 'caption', 'attrs' => ['id' => 'gggggggg'], 'content' => [['type' => 'text', 'text' => 'Orphan']]],
            ]],
            // Held nothing at all: nothing to lift, nothing left.
            ['type' => 'figure', 'attrs' => ['id' => 'eeeeeeee', 'kind' => 'image'], 'content' => []],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame(['figure', 'paragraph', 'paragraph'], array_column($out['content'], 'type'));
        $this->assertSame(['image', 'caption'], array_column($out['content'][0]['content'], 'type'));
        $this->assertTrue(BlockId::isValid($out['content'][0]['content'][1]['attrs']['id']));
        // The caption came first out of the figure, then the loose paragraph.
        $this->assertSame('Orphan', $out['content'][1]['content'][0]['text']);
        $this->assertSame('dddddddd', $out['content'][2]['attrs']['id']);
    }

    public function test_normalise_lifts_a_figures_extra_children_out_instead_of_dropping_them(): void
    {
        // `figure` is `(image | table) caption` - exactly two children - but
        // the second picture is the writer's, not ours to delete. It is lifted
        // out as a sibling immediately after the figure.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'figure', 'attrs' => ['id' => 'aaaaaaaa', 'kind' => 'image'], 'content' => [
                ['type' => 'image', 'attrs' => ['id' => 'bbbbbbbb', 'src' => '/storage/one.png']],
                ['type' => 'image', 'attrs' => ['id' => 'cccccccc', 'src' => '/storage/two.png']],
                ['type' => 'caption', 'attrs' => ['id' => 'dddddddd'], 'content' => [['type' => 'text', 'text' => 'First']]],
                ['type' => 'caption', 'attrs' => ['id' => 'eeeeeeee'], 'content' => [['type' => 'text', 'text' => 'Second']]],
            ]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'ffffffff'], 'content' => []],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame(['figure', 'image', 'paragraph', 'paragraph'], array_column($out['content'], 'type'));
        $this->assertSame(['image', 'caption'], array_column($out['content'][0]['content'], 'type'));
        $this->assertSame('/storage/one.png', $out['content'][0]['content'][0]['attrs']['src']);
        $this->assertSame('First', $out['content'][0]['content'][1]['content'][0]['text']);
        // The second image kept its own id and its src; the extra caption
        // became a paragraph carrying the words that were in it.
        $this->assertSame('/storage/two.png', $out['content'][1]['attrs']['src']);
        $this->assertSame('cccccccc', $out['content'][1]['attrs']['id']);
        $this->assertSame('Second', $out['content'][2]['content'][0]['text']);
        $this->assertTrue(BlockId::isValid($out['content'][2]['attrs']['id']));
    }

    public function test_normalise_lifts_a_figures_column_child_as_a_paragraph_of_its_text(): void
    {
        // `column` is legal only inside `columns`. Lifting it out of a figure
        // unconverted would leave a node ProseMirror's content check refuses
        // at `doc`'s top level - the editor's content check would then open
        // the whole document read-only. It is converted to a paragraph
        // carrying its own plain text instead, keeping its own block id.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'figure', 'attrs' => ['id' => 'aaaaaaaa', 'kind' => 'image'], 'content' => [
                ['type' => 'column', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => [
                    ['type' => 'paragraph', 'attrs' => ['id' => 'cccccccc'], 'content' => [['type' => 'text', 'text' => 'Stranded']]],
                ]],
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame(['paragraph'], array_column($out['content'], 'type'));
        $this->assertSame('Stranded', $out['content'][0]['content'][0]['text']);
        $this->assertSame('bbbbbbbb', $out['content'][0]['attrs']['id']);
    }

    public function test_normalise_lifts_a_figures_table_row_child_as_a_paragraph_of_its_cell_text(): void
    {
        // `tableRow` is legal only inside `table`; same rule as `column`.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'figure', 'attrs' => ['id' => 'aaaaaaaa', 'kind' => 'image'], 'content' => [
                ['type' => 'tableRow', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => [
                    ['type' => 'tableCell', 'attrs' => ['id' => 'cccccccc'], 'content' => [
                        ['type' => 'paragraph', 'attrs' => ['id' => 'dddddddd'], 'content' => [['type' => 'text', 'text' => 'Cell text']]],
                    ]],
                ]],
            ]],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame(['paragraph'], array_column($out['content'], 'type'));
        $this->assertSame('Cell text', $out['content'][0]['content'][0]['text']);
        $this->assertSame('bbbbbbbb', $out['content'][0]['attrs']['id']);
    }

    public function test_normalise_of_a_repaired_figure_is_idempotent(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'figure', 'attrs' => ['id' => 'aaaaaaaa', 'kind' => 'image'], 'content' => [
                ['type' => 'column', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => [
                    ['type' => 'paragraph', 'attrs' => ['id' => 'cccccccc'], 'content' => [['type' => 'text', 'text' => 'Stranded']]],
                ]],
                ['type' => 'tableRow', 'attrs' => ['id' => 'dddddddd'], 'content' => [
                    ['type' => 'tableCell', 'attrs' => ['id' => 'eeeeeeee'], 'content' => [
                        ['type' => 'paragraph', 'attrs' => ['id' => 'ffffffff'], 'content' => [['type' => 'text', 'text' => 'Cell']]],
                    ]],
                ]],
            ]],
        ]];
        $schema = new DocumentSchema;

        $once = $schema->normalise($doc);
        $twice = $schema->normalise($once);

        $this->assertSame($once, $twice);
    }

    public function test_ensure_ids_treats_non_array_attrs_as_empty(): void
    {
        $schema = new DocumentSchema;
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => 'boom', 'content' => []],
        ]];

        $out = $schema->ensureIds($doc);

        $this->assertTrue(BlockId::isValid($out['content'][0]['attrs']['id']));
    }

    public function test_normalise_treats_non_array_attrs_as_empty(): void
    {
        $schema = new DocumentSchema;
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => 'boom', 'content' => []],
        ]];

        $out = $schema->normalise($doc);

        $this->assertSame([], $out['content'][0]['attrs']);
    }

    public function test_normalise_survives_non_array_content(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'columns', 'attrs' => ['id' => 'aaaaaaaa'], 'content' => 'nonsense'],
            ['type' => 'paragraph', 'attrs' => ['id' => 'bbbbbbbb'], 'content' => ['not-an-array-node']],
        ]];

        $out = (new DocumentSchema)->normalise($doc);

        $this->assertSame(2, (new DocumentSchema)->normalise($doc)['content'][0]['attrs']['count']);
        $this->assertSame([], $out['content'][1]['content']);
    }

    public function test_normalise_gives_an_empty_document_a_paragraph(): void
    {
        $out = (new DocumentSchema)->normalise(['type' => 'doc', 'content' => []]);

        $this->assertSame('paragraph', $out['content'][0]['type']);
        $this->assertTrue(BlockId::isValid($out['content'][0]['attrs']['id']));
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
