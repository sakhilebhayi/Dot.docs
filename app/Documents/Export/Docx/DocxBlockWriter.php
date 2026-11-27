<?php

namespace App\Documents\Export\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Style\TOC;

/**
 * Block nodes — headings, paragraphs, lists, quotes, callouts, code, rules,
 * figures, tables, a table of contents and breaks — written into the body
 * section or a table cell. Inline content goes to DocxRunWriter, pictures to
 * DocxImageWriter and tables to DocxTableWriter.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
final class DocxBlockWriter
{
    /** The bullet numbering definition DocxExporter registers and lists use. */
    public const BULLET_STYLE = 'DotDocBullet';

    /** The numbered-list numbering definition DocxExporter registers and lists use. */
    public const NUMBER_STYLE = 'DotDocNumber';

    private DocxRunWriter $runs;

    private DocxImageWriter $images;

    private DocxTableWriter $tables;

    public function __construct(
        private DocxContext $context,
        private DocumentSchema $schema,
    ) {
        $this->images = new DocxImageWriter($context);
        $this->runs = new DocxRunWriter($context, $this->images);
        $this->tables = new DocxTableWriter($context, $this->runs, $this);
    }

    /** @param array<mixed> $nodes */
    public function writeBlocks(array $nodes, Section|Cell $container): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->writeBlock($node, $container);
            }
        }
    }

    /** @param PmNode $node */
    public function writeBlock(array $node, Section|Cell $container): void
    {
        match ($node['type'] ?? '') {
            'heading' => $this->writeHeading($node, $container),
            'paragraph' => $this->writeParagraph($node, $container),
            'bulletList' => $this->writeList($node, $container, false, 0),
            'orderedList' => $this->writeList($node, $container, true, 0),
            'taskList' => $this->writeList($node, $container, false, 0),
            'blockquote' => $this->writeQuote($node, $container),
            'callout' => $this->writeCallout($node, $container),
            'codeBlock' => $this->writeCodeBlock($node, $container),
            'horizontalRule' => $this->writeRule($container),
            'image' => $this->images->writeImage($node, $container),
            'figure' => $this->writeBlocks($node['content'] ?? [], $container),
            'caption' => $this->writeCaption($node, $container),
            'table' => $this->tables->writeTable($node, $container),
            'toc' => $this->writeToc($node, $container),
            'pageBreak', 'sectionBreak' => $this->writePageBreak($container),
            'columns', 'column' => $this->writeBlocks($node['content'] ?? [], $container),
            default => $this->writeParagraph($node, $container),
        };
    }

    /** @param PmNode $node */
    private function writeHeading(array $node, Section|Cell $container): void
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));
        // The Outline number is written as literal text. Word's own heading
        // numbering lives in a linked list definition this writer does not
        // emit, so a numbered document that carried no number here would
        // export with its cross-references pointing at nothing.
        $number = $this->context->numbers[$node['attrs']['id'] ?? ''] ?? '';

        if ($this->context->hasToc) {
            $container->addTitle($this->context->escape($this->headingText($node, $number)), $level);

            return;
        }

        // A DETACHED TextRun, not a plain string: PhpWord\Element\Title
        // accepts either, and only the run keeps the heading's own
        // italic/link marks that plainText() would flatten. The Word2007
        // writer walks it with its normal Container writer, so hyperlink
        // relation ids are still resolved at write time even though the run
        // was never added to a container itself. Its TOC writer does NOT —
        // see DocxContext::$hasToc.
        $run = new TextRun;
        if ($number !== '') {
            $run->addText($number.' ');
        }
        $this->runs->writeInline($node['content'] ?? [], $run);
        $container->addTitle($run, $level);
    }

    /** @param PmNode $node */
    private function headingText(array $node, string $number): string
    {
        return trim($number.' '.trim($this->schema->plainText($node)));
    }

    /**
     * @param  PmNode  $node
     * @param  array<string,mixed>  $paragraphStyle
     */
    private function writeParagraph(array $node, Section|Cell $container, array $paragraphStyle = []): void
    {
        $align = $node['attrs']['align'] ?? ($this->context->tokens['align'] ?? null);
        if (is_string($align) && in_array($align, DocumentSchema::ALIGNMENTS, true)) {
            $paragraphStyle['alignment'] = $align;
        }
        $run = $container->addTextRun($paragraphStyle);
        $this->runs->writeInline($node['content'] ?? [], $run);
    }

    /**
     * A list, one `addListItemRun($depth, …)` per item; a nested list is
     * written at $depth + 1 into the SAME container, which is how Word
     * expresses nesting (one flat run of paragraphs, each carrying its own
     * `w:ilvl`) and what DocxImporter reads the nesting back out of.
     *
     * An item's SECOND and later paragraphs go into the run the item already
     * opened, separated by an inline break. Word has no "continuation
     * paragraph" this writer could emit without giving it its own bullet, and
     * writing them as ordinary paragraphs (what this did before) detached
     * them from the list entirely: the text left the item on the way out and
     * came back after the list on the way in.
     *
     * @param  PmNode  $node
     */
    private function writeList(array $node, Section|Cell $container, bool $ordered, int $depth): void
    {
        $numStyle = $ordered ? self::NUMBER_STYLE : self::BULLET_STYLE;
        foreach ($node['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $run = null;
            foreach ($item['content'] ?? [] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $type = $child['type'] ?? '';
                if ($type === 'bulletList' || $type === 'orderedList' || $type === 'taskList') {
                    $this->writeList($child, $container, $type === 'orderedList', $depth + 1);

                    continue;
                }
                if ($type === 'paragraph') {
                    if ($run === null) {
                        $run = $container->addListItemRun($depth, $numStyle);
                        $prefix = ($node['type'] ?? '') === 'taskList'
                            ? ((($item['attrs']['checked'] ?? false)) ? '[x] ' : '[ ] ')
                            : '';
                        if ($prefix !== '') {
                            $run->addText($prefix);
                        }
                    } else {
                        $run->addTextBreak();
                    }
                    $this->runs->writeInline($child['content'] ?? [], $run);

                    continue;
                }
                $this->writeBlock($child, $container);
            }
            if ($run === null) {
                $container->addListItemRun($depth, $numStyle);
            }
        }
    }

    /** @param PmNode $node */
    private function writeQuote(array $node, Section|Cell $container): void
    {
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'paragraph') {
                $this->writeParagraph($child, $container, ['indentation' => ['left' => 480]]);

                continue;
            }
            if (is_array($child)) {
                $this->writeBlock($child, $container);
            }
        }
    }

    /** @param PmNode $node */
    private function writeCallout(array $node, Section|Cell $container): void
    {
        $shading = ['shading' => ['fill' => 'F3F1EA'], 'indentation' => ['left' => 240, 'right' => 240], 'spaceBefore' => 120, 'spaceAfter' => 120];
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'paragraph') {
                $this->writeParagraph($child, $container, $shading);

                continue;
            }
            if (is_array($child)) {
                $this->writeBlock($child, $container);
            }
        }
    }

    /** @param PmNode $node */
    private function writeCodeBlock(array $node, Section|Cell $container): void
    {
        $run = $container->addTextRun(['shading' => ['fill' => 'F3F1EA'], 'spaceBefore' => 120, 'spaceAfter' => 120]);
        $font = ['name' => $this->context->font('mono'), 'size' => $this->context->size('small', 9.0)];
        foreach (explode("\n", $this->schema->plainText($node)) as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }
            $run->addText($line, $font);
        }
    }

    private function writeRule(Section|Cell $container): void
    {
        $container->addTextRun([
            'borderBottomSize' => 6,
            'borderBottomColor' => $this->context->colour('rule', 'D5D1C7'),
            'spaceBefore' => 120,
            'spaceAfter' => 120,
        ]);
    }

    /** @param PmNode $node */
    private function writeCaption(array $node, Section|Cell $container): void
    {
        $this->writeParagraph($node, $container, ['spaceAfter' => 240]);
    }

    private function writePageBreak(Section|Cell $container): void
    {
        // PageBreak is a Section-only element; inside a table cell the
        // nearest honest equivalent is an empty paragraph.
        if ($container instanceof Section) {
            $container->addPageBreak();

            return;
        }
        $container->addTextBreak();
    }

    /** @param PmNode $node */
    private function writeToc(array $node, Section|Cell $container): void
    {
        if (! $container instanceof Section) {
            return;
        }
        $depth = (int) ($node['attrs']['depth'] ?? 3);
        $container->addTOC(
            ['name' => $this->context->font('heading'), 'size' => $this->context->size('body', 11.0)],
            ['tabLeader' => TOC::TAB_LEADER_DOT],
            1,
            max(1, min(9, $depth))
        );
    }
}
