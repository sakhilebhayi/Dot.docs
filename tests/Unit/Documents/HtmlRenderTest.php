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
}
