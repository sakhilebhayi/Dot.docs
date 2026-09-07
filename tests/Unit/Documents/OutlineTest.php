<?php

namespace Tests\Unit\Documents;

use App\Documents\Outline\Outline;
use PHPUnit\Framework\TestCase;

class OutlineTest extends TestCase
{
    private function h(int $level, string $text, string $id, bool $numbered = true): array
    {
        return ['type' => 'heading', 'attrs' => ['id' => $id, 'level' => $level, 'numbered' => $numbered], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function fig(string $id, string $kind = 'image'): array
    {
        return ['type' => 'figure', 'attrs' => ['id' => $id, 'kind' => $kind], 'content' => [
            $kind === 'image' ? ['type' => 'image', 'attrs' => ['id' => $id.'i', 'src' => '/storage/x.png']] : ['type' => 'table', 'attrs' => ['id' => $id.'t'], 'content' => []],
            ['type' => 'caption', 'attrs' => ['id' => $id.'c'], 'content' => [['type' => 'text', 'text' => 'Cap']]],
        ]];
    }

    public function test_decimal_numbering_and_toc(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'toc', 'attrs' => ['id' => 'tocXXXXX', 'depth' => 2]],
            $this->h(1, 'Intro', 'H1AAAAAA'), $this->h(2, 'Scope', 'H2AAAAAA'), $this->h(2, 'Method', 'H2BBBBBB'),
            $this->h(3, 'Deep', 'H3AAAAAA'), $this->h(1, 'Results', 'H1BBBBBB'), $this->h(2, 'Unnumbered', 'H2CCCCCC', false),
        ]];
        $r = (new Outline)->build($doc);
        $this->assertSame(['H1AAAAAA' => '1', 'H2AAAAAA' => '1.1', 'H2BBBBBB' => '1.2', 'H3AAAAAA' => '1.2.1', 'H1BBBBBB' => '2'], $r->numbers);
        $this->assertSame(['id' => 'H2AAAAAA', 'level' => 2, 'text' => 'Scope', 'number' => '1.1'], $r->toc[1]);
        $this->assertCount(6, $r->toc);
        $this->assertSame(['id' => 'H2CCCCCC', 'level' => 2, 'text' => 'Unnumbered', 'number' => ''], $r->toc[5]);

        $out = (new Outline)->apply($doc, $r);
        $this->assertSame('1.2', $out['content'][0]['attrs']['entries'][2]['number']);
    }

    public function test_figures_and_tables_number_separately_and_crossrefs_resolve(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            $this->fig('F1F1F1F1'), $this->fig('T1T1T1T1', 'table'), $this->fig('F2F2F2F2'),
            ['type' => 'paragraph', 'attrs' => ['id' => 'P1P1P1P1'], 'content' => [
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'F2F2F2F2', 'kind' => 'figure']],
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'T1T1T1T1', 'kind' => 'table']],
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'NOPE0000', 'kind' => 'figure']],
                ['type' => 'crossRef', 'attrs' => ['kind' => 'figure']],
            ]],
        ]];
        $r = (new Outline)->build($doc);
        $this->assertSame('2', $r->numbers['F2F2F2F2']);
        $this->assertSame('1', $r->numbers['T1T1T1T1']);
        $this->assertSame(['NOPE0000', ''], $r->broken);
        $out = (new Outline)->apply($doc, $r);
        $this->assertSame('Figure 2', $out['content'][3]['content'][0]['attrs']['label']);
        $this->assertSame('Table 1', $out['content'][3]['content'][1]['attrs']['label']);
        $this->assertSame('?', $out['content'][3]['content'][2]['attrs']['label']);
        $this->assertSame('?', $out['content'][3]['content'][3]['attrs']['label']);
    }

    public function test_renumbering_after_move(): void
    {
        $doc = ['type' => 'doc', 'content' => [$this->h(1, 'A', 'AAAAAAAA'), $this->h(1, 'B', 'BBBBBBBB')]];
        $this->assertSame('2', (new Outline)->build($doc)->numbers['BBBBBBBB']);
        $doc['content'] = array_reverse($doc['content']);
        $this->assertSame('1', (new Outline)->build($doc)->numbers['BBBBBBBB']);
    }

    public function test_headings_none_yields_no_numbers_but_full_toc(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            $this->h(1, 'Intro', 'H1AAAAAA'),
            $this->h(2, 'Scope', 'H2AAAAAA'),
            $this->h(2, 'Aside', 'H2BBBBBB', false),
        ]];
        $r = (new Outline)->build($doc, ['headings' => 'none']);
        $this->assertSame([], $r->numbers);
        $this->assertSame([
            ['id' => 'H1AAAAAA', 'level' => 1, 'text' => 'Intro', 'number' => ''],
            ['id' => 'H2AAAAAA', 'level' => 2, 'text' => 'Scope', 'number' => ''],
            ['id' => 'H2BBBBBB', 'level' => 2, 'text' => 'Aside', 'number' => ''],
        ], $r->toc);
    }

    public function test_forward_crossref_to_a_later_heading_is_not_broken(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'P1P1P1P1'], 'content' => [
                ['type' => 'crossRef', 'attrs' => ['targetId' => 'H1AAAAAA', 'kind' => 'heading']],
            ]],
            $this->h(1, 'Intro', 'H1AAAAAA'),
        ]];
        $r = (new Outline)->build($doc);
        $this->assertSame([], $r->broken);
        $out = (new Outline)->apply($doc, $r);
        $this->assertSame('Section 1', $out['content'][0]['content'][0]['attrs']['label']);
    }

    public function test_figures_by_chapter_prefix_current_chapter_and_reset(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            $this->fig('F0F0F0F0'),
            $this->h(1, 'Chapter A', 'H1AAAAAA'),
            $this->fig('F1F1F1F1'), $this->fig('F2F2F2F2'), $this->fig('T1T1T1T1', 'table'),
            $this->h(1, 'Chapter B', 'H1BBBBBB'),
            $this->fig('F3F3F3F3'),
        ]];
        $r = (new Outline)->build($doc, ['figures' => 'byChapter']);
        $this->assertSame('1', $r->numbers['F0F0F0F0']);
        $this->assertSame('1.1', $r->numbers['F1F1F1F1']);
        $this->assertSame('1.2', $r->numbers['F2F2F2F2']);
        $this->assertSame('1.1', $r->numbers['T1T1T1T1']);
        $this->assertSame('2.1', $r->numbers['F3F3F3F3']);
    }
}
