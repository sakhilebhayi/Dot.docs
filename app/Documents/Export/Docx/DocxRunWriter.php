<?php

namespace App\Documents\Export\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Style\Font;

/**
 * Inline content — text with its marks, links, line breaks, variables,
 * cross-references and inline pictures — written into one TextRun.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 * @phpstan-import-type PmMark from DocumentSchema
 */
final class DocxRunWriter
{
    public function __construct(
        private DocxContext $context,
        private DocxImageWriter $images,
    ) {}

    /**
     * @param  array<mixed>  $nodes
     * @param  array<string,mixed>  $inherited  font properties every run in this container carries
     */
    public function writeInline(array $nodes, TextRun $run, array $inherited = []): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            match ($node['type'] ?? '') {
                'text' => $this->writeText($node, $run, $inherited),
                'hardBreak' => $run->addTextBreak(),
                'crossRef' => $run->addText($this->context->escape((string) ($node['attrs']['label'] ?? '?')), $inherited),
                'variable' => $run->addText($this->context->escape($this->variableText($node)), $inherited),
                'image' => $this->images->writeImage($node, $run),
                default => $this->writeInline($node['content'] ?? [], $run, $inherited),
            };
        }
    }

    /** @param PmNode $node */
    private function variableText(array $node): string
    {
        $key = (string) ($node['attrs']['key'] ?? '');

        return $this->context->variables[$key] ?? '{{ '.$key.' }}';
    }

    /**
     * The round-trip partner of DocxImporter's mark reader (Docx\DocxRunReader::fontMarks()):
     * every property written here is one it reads back.
     *
     * @param  PmNode  $node
     * @param  array<string,mixed>  $inherited
     */
    private function writeText(array $node, TextRun $run, array $inherited): void
    {
        $text = (string) ($node['text'] ?? '');
        if ($text === '') {
            return;
        }

        $font = $inherited;
        $href = null;
        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            match ($mark['type'] ?? '') {
                'bold' => $font['bold'] = true,
                'italic' => $font['italic'] = true,
                'underline' => $font['underline'] = 'single',
                'strike' => $font['strikethrough'] = true,
                'code' => $font['name'] = $this->context->font('mono'),
                // Word's highlight is `w:highlight`, whose value is one of a
                // fixed set of names — PhpWord calls it fgColor, and the
                // bgColor this used to set is not written by its font-style
                // writer at all (only `shading` is), so the mark was lost on
                // the way out AND had nothing to read back.
                'highlight' => $font['fgColor'] = Font::FGCOLOR_YELLOW,
                'subscript' => $font['subScript'] = true,
                'superscript' => $font['superScript'] = true,
                'textStyle' => $font = $this->applyTextStyle($mark, $font),
                'link' => $href = is_string($mark['attrs']['href'] ?? null) ? $mark['attrs']['href'] : null,
                default => null,
            };
        }

        if ($href !== null && preg_match('#^(https?://|mailto:)#i', $href) === 1) {
            $font['color'] ??= '0563C1';
            $font['underline'] ??= 'single';
            $run->addLink($href, $this->context->escape($text), $font);

            return;
        }

        $run->addText($this->context->escape($text), $font);
    }

    /**
     * @param  PmMark  $mark
     * @param  array<string,mixed>  $font
     * @return array<string,mixed>
     */
    private function applyTextStyle(array $mark, array $font): array
    {
        $colour = $mark['attrs']['color'] ?? null;
        if (is_string($colour) && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1) {
            $font['color'] = strtoupper(ltrim($colour, '#'));
        }

        return $font;
    }
}
