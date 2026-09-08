<?php

namespace Tests\Unit\Documents;

use App\Documents\Import\HtmlToJson;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;
use PHPUnit\Framework\TestCase;

class HtmlRenderTest extends TestCase
{
    public function test_renders_blocks_with_data_ids_and_marks(): void
    {
        $doc = ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => [
            ['type' => 'heading', 'attrs' => ['id' => 'h1h1h1h1', 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Summary']]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'p1p1p1p1'], 'content' => [
                ['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' & link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.za']]]],
            ]],
            ['type' => 'pageBreak', 'attrs' => ['id' => 'pbpbpbpb']],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::share());
        $this->assertStringContainsString('<h2 data-id="h1h1h1h1">Summary</h2>', $html);
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://x.za" rel="noopener noreferrer">', $html);
        $this->assertStringContainsString('&amp; link', $html);
        $this->assertStringContainsString('<div class="page-break" data-id="pbpbpbpb"></div>', $html);
    }

    public function test_variables_and_numbers_are_substituted(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['id' => 'h2h2h2h2', 'level' => 1, 'numbered' => true], 'content' => [['type' => 'text', 'text' => 'Scope']]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'p2p2p2p2'], 'content' => [
                ['type' => 'text', 'text' => 'See '], ['type' => 'crossRef', 'attrs' => ['targetId' => 'h2h2h2h2', 'kind' => 'heading']],
                ['type' => 'text', 'text' => ' for '], ['type' => 'variable', 'attrs' => ['key' => 'period']],
            ]],
        ]];
        $ctx = RenderContext::print();
        $ctx->numbers = ['h2h2h2h2' => '1'];
        $ctx->vars = ['period' => 'August 2026'];
        $html = (new HtmlRenderer)->render($doc, $ctx);
        $this->assertStringContainsString('<span class="num">1</span>Scope', $html);
        $this->assertStringContainsString('<a class="xref" href="#h2h2h2h2">Section 1</a>', $html);
        $this->assertStringContainsString('August 2026', $html);
    }

    public function test_legacy_html_round_trips_to_json(): void
    {
        $html = '<h1>Title</h1><p>Hello <strong>world</strong></p><ul><li><p>One</p></li></ul><table><tbody><tr><td><p>c</p></td></tr></tbody></table>';
        $doc = (new HtmlToJson)->convert($html);
        $this->assertSame([], (new DocumentSchema)->validate($doc));
        $this->assertSame('heading', $doc['content'][0]['type']);
        $this->assertSame('table', $doc['content'][3]['type']);
        $this->assertSame('world', $doc['content'][1]['content'][1]['text']);
        $this->assertSame('bold', $doc['content'][1]['content'][1]['marks'][0]['type']);
    }

    public function test_javascript_link_is_neutralised(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'p3p3p3p3'], 'content' => [
                ['type' => 'text', 'text' => 'Click me', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
            ]],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('Click me', $html);
    }

    public function test_unknown_node_type_renders_nothing_without_exception(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'totallyUnknownNode', 'attrs' => ['id' => 'z1z1z1z1'], 'content' => [
                ['type' => 'text', 'text' => 'hidden'],
            ]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'p4p4p4p4'], 'content' => [
                ['type' => 'text', 'text' => 'visible'],
            ]],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringNotContainsString('hidden', $html);
        $this->assertStringContainsString('visible', $html);
    }

    public function test_table_cell_colspan_is_rendered(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'table', 'attrs' => ['id' => 't1t1t1t1'], 'content' => [
                ['type' => 'tableRow', 'attrs' => ['id' => 'r1r1r1r1'], 'content' => [
                    ['type' => 'tableCell', 'attrs' => ['id' => 'c1c1c1c1', 'colspan' => 2], 'content' => [
                        ['type' => 'paragraph', 'attrs' => ['id' => 'p5p5p5p5'], 'content' => [['type' => 'text', 'text' => 'Wide']]],
                    ]],
                ]],
            ]],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringContainsString('colspan="2"', $html);
    }

    public function test_image_block_renders_with_data_id(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['id' => 'i1i1i1i1', 'src' => 'https://example.com/a.png', 'alt' => 'Alt']],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringContainsString('<img data-id="i1i1i1i1"', $html);
    }

    public function test_figure_and_caption_render_with_data_id(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'figure', 'attrs' => ['id' => 'f1f1f1f1', 'kind' => 'figure'], 'content' => [
                ['type' => 'image', 'attrs' => ['id' => 'i2i2i2i2', 'src' => 'https://example.com/a.png']],
                ['type' => 'caption', 'attrs' => ['id' => 'c2c2c2c2'], 'content' => [['type' => 'text', 'text' => 'A caption']]],
            ]],
        ]];
        $ctx = RenderContext::print();
        $ctx->numbers = ['f1f1f1f1' => '1'];
        $html = (new HtmlRenderer)->render($doc, $ctx);
        $this->assertStringContainsString('<figcaption data-id="c2c2c2c2">', $html);
        $this->assertStringContainsString('<span class="num">Figure 1</span>', $html);
    }

    public function test_table_figure_caption_uses_table_prefix(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'figure', 'attrs' => ['id' => 'f3f3f3f3', 'kind' => 'table'], 'content' => [
                ['type' => 'table', 'attrs' => ['id' => 't3t3t3t3'], 'content' => []],
                ['type' => 'caption', 'attrs' => ['id' => 'c4c4c4c4'], 'content' => [['type' => 'text', 'text' => 'Cap']]],
            ]],
        ]];
        $ctx = RenderContext::print();
        $ctx->numbers = ['f3f3f3f3' => '1'];
        $ctx->kinds = ['f3f3f3f3' => 'table'];
        $html = (new HtmlRenderer)->render($doc, $ctx);
        $this->assertStringContainsString('<figcaption data-id="c4c4c4c4"><span class="num">Table 1</span>Cap</figcaption>', $html);
    }

    public function test_ordered_list_start_attribute_round_trips(): void
    {
        $html = '<ol start="3"><li><p>Third</p></li></ol>';
        $doc = (new HtmlToJson)->convert($html);
        $this->assertSame(3, $doc['content'][0]['attrs']['start']);
        $rendered = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringContainsString('<ol start="3"', $rendered);
    }

    public function test_ordered_list_start_of_one_is_not_stored(): void
    {
        $doc = (new HtmlToJson)->convert('<ol start="1"><li><p>One</p></li></ol>');
        $this->assertArrayNotHasKey('start', $doc['content'][0]['attrs'] ?? []);
    }

    public function test_section_break_setup_defaults_to_empty_object_on_encode_failure(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'sectionBreak', 'attrs' => ['id' => 's1s1s1s1', 'bad' => "\xB1\x31"]],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringContainsString("data-setup='{}'", $html);
    }

    public function test_image_src_only_allows_storage_and_images_paths(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['id' => 'i3i3i3i3', 'src' => '/uploads/evil.png']],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringNotContainsString('src=', $html);
    }

    public function test_image_src_allows_storage_path(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['id' => 'i4i4i4i4', 'src' => '/storage/uploads/a.png']],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringContainsString('src="/storage/uploads/a.png"', $html);
    }

    public function test_image_src_allows_images_path(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'image', 'attrs' => ['id' => 'i5i5i5i5', 'src' => '/images/a.png']],
        ]];
        $html = (new HtmlRenderer)->render($doc, RenderContext::editor());
        $this->assertStringContainsString('src="/images/a.png"', $html);
    }

    public function test_alignment_is_whitelisted_on_paragraphs_and_headings(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'p1p1p1p1', 'align' => 'center'], 'content' => [['type' => 'text', 'text' => 'Centred']]],
            ['type' => 'heading', 'attrs' => ['id' => 'h1h1h1h1', 'level' => 2, 'align' => 'right'], 'content' => [['type' => 'text', 'text' => 'Right']]],
            // Attrs reach the renderer straight from stored JSON, which an
            // API client or an Echo payload can have written.
            ['type' => 'paragraph', 'attrs' => ['id' => 'p2p2p2p2', 'align' => 'left; background: url(https://evil.example/x)'], 'content' => []],
            ['type' => 'heading', 'attrs' => ['id' => 'h2h2h2h2', 'level' => 3, 'align' => 'x"><script>alert(1)</script>'], 'content' => []],
        ]];

        $html = (new HtmlRenderer)->render($doc, RenderContext::share());

        $this->assertStringContainsString('<p data-id="p1p1p1p1" style="text-align:center">', $html);
        $this->assertStringContainsString('<h2 data-id="h1h1h1h1" style="text-align:right">', $html);
        $this->assertStringContainsString('<p data-id="p2p2p2p2">', $html);
        $this->assertStringContainsString('<h3 data-id="h2h2h2h2">', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_column_count_is_clamped_before_it_reaches_the_style_attribute(): void
    {
        $columns = fn (string $id, mixed $count) => ['type' => 'columns', 'attrs' => ['id' => $id, 'count' => $count], 'content' => []];
        $doc = ['type' => 'doc', 'content' => [
            $columns('c1c1c1c1', 3),
            $columns('c2c2c2c2', 99),
            $columns('c3c3c3c3', 0),
            $columns('c4c4c4c4', '2; position: fixed'),
        ]];

        $html = (new HtmlRenderer)->render($doc, RenderContext::share());

        $this->assertStringContainsString('data-id="c1c1c1c1" style="--cols:3"', $html);
        $this->assertStringContainsString('data-id="c2c2c2c2" style="--cols:4"', $html);
        $this->assertStringContainsString('data-id="c3c3c3c3" style="--cols:2"', $html);
        $this->assertStringContainsString('data-id="c4c4c4c4" style="--cols:2"', $html);
        $this->assertStringNotContainsString('position: fixed', $html);
    }
}
