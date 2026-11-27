<?php

namespace App\Documents\Import\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;

/**
 * A run of sibling PHPWord elements (a section's, or a table cell's) ->
 * block nodes. Lists go to DocxListReader, tables to DocxTableReader, a TOC
 * field to DocxTocReader, pictures to DocxImageReader and inline content to
 * DocxRunReader.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 * @phpstan-import-type ListEntry from DocxListReader
 */
final class DocxBlockReader
{
    private DocxRunReader $runs;

    private DocxListReader $lists;

    private DocxTableReader $tables;

    private DocxTocReader $toc;

    public function __construct(private DocxImageReader $images)
    {
        $this->runs = new DocxRunReader;
        $this->lists = new DocxListReader($this->runs);
        $this->tables = new DocxTableReader($this);
        $this->toc = new DocxTocReader;
    }

    /**
     * Convert a run of sibling PHPWord elements into block nodes.
     *
     * Two pieces of state make this a loop rather than a map: consecutive
     * list items are gathered up and rebuilt into ONE (possibly nested) list
     * node, and a `PageBreak` is followed in the reader's output by an echo
     * of the very same `w:p` as a break-only paragraph
     * (Reader\Word2007\Document::readWPNode() emits both), which would
     * otherwise land as a stray empty paragraph.
     *
     * @param  array<int,AbstractElement>  $elements
     * @return list<PmNode>
     */
    public function convertElements(array $elements): array
    {
        $out = [];
        /** @var list<ListEntry> $items */
        $items = [];
        $afterPageBreak = false;
        $inToc = false;

        $flush = function () use (&$items, &$out): void {
            if ($items !== []) {
                array_push($out, ...$this->lists->buildLists($items));
                $items = [];
            }
        };

        foreach ($elements as $element) {
            if ($inToc) {
                if ($this->toc->isTocEntry($element)) {
                    continue;
                }
                $inToc = false;
            }

            if ($element instanceof PreserveText && $this->toc->tocDepth($element) !== null) {
                $flush();
                $out[] = ['type' => 'toc', 'attrs' => ['depth' => $this->toc->tocDepth($element)]];
                $inToc = true;
                $afterPageBreak = false;

                continue;
            }

            if ($element instanceof ListItemRun || $element instanceof ListItem) {
                $afterPageBreak = false;
                $item = $this->lists->listItem($element);
                // A deeper item always continues the list it is nested in,
                // whatever its own numbering definition says (a bullet list
                // with a numbered sub-list is two definitions, one list). At
                // the list's own depth, a different definition is a different
                // list.
                if ($items !== [] && $item['depth'] <= $items[0]['depth'] && $item['key'] !== $items[0]['key']) {
                    $flush();
                }
                $items[] = $item;

                continue;
            }

            $flush();

            if ($afterPageBreak && $this->isBreakOnlyRun($element)) {
                $afterPageBreak = false;

                continue;
            }
            $afterPageBreak = $element instanceof PageBreak;

            array_push($out, ...$this->convertBlock($element));
        }
        $flush();

        return $out;
    }

    /**
     * @return list<PmNode>
     */
    private function convertBlock(AbstractElement $element): array
    {
        return match (true) {
            $element instanceof Title => [$this->convertTitle($element)],
            $element instanceof Table => [$this->tables->convertTable($element)],
            $element instanceof TextRun => $this->convertTextRun($element),
            $element instanceof Image => $this->images->convertImage($element),
            $element instanceof PageBreak => [['type' => 'pageBreak']],
            $element instanceof TextBreak => [$this->runs->paragraph([])],
            default => $this->convertUnknown($element),
        };
    }

    /** @return PmNode */
    private function convertTitle(Title $title): array
    {
        $depth = (int) $title->getDepth();

        return [
            'type' => 'heading',
            // `Title` (depth 0) is a document title, not a level-0 heading;
            // the schema's headings start at 1.
            'attrs' => ['level' => max(1, min(6, $depth === 0 ? 1 : $depth))],
            'content' => $this->runs->runOrText($title->getText(), []),
        ];
    }

    /**
     * A `TextRun` is a paragraph, except that Word puts inline images inside
     * one too. An image is a BLOCK in this schema, so the run is split: the
     * inline text before an image becomes a paragraph, the image becomes its
     * own node, and the text after it starts a fresh paragraph.
     *
     * @return list<PmNode>
     */
    private function convertTextRun(TextRun $run): array
    {
        $out = [];
        $inline = [];
        $hadImage = false;

        foreach ($run->getElements() as $child) {
            if ($child instanceof Image) {
                $hadImage = true;
                if ($inline !== []) {
                    $out[] = $this->runs->paragraph($inline);
                    $inline = [];
                }
                array_push($out, ...$this->images->convertImage($child));

                continue;
            }
            $inline = array_merge($inline, $this->runs->inlineNodes($child));
        }

        if ($inline !== [] && ! $this->onlyHardBreaks($inline)) {
            $out[] = $this->runs->paragraph($inline);
        } elseif ($out === [] && ! $hadImage) {
            // Nothing but line breaks (or nothing at all) is Word's way of
            // writing an empty paragraph; keeping the hardBreaks would render
            // as blank lines nobody typed. A run that held ONLY a picture
            // this importer refused (too big, or a type it will not serve) is
            // not an empty paragraph the writer typed, so it leaves nothing.
            $out[] = $this->runs->paragraph([]);
        }

        return $out;
    }

    /** @param list<PmNode> $inline */
    private function onlyHardBreaks(array $inline): bool
    {
        foreach ($inline as $node) {
            if (($node['type'] ?? '') !== 'hardBreak') {
                return false;
            }
        }

        return true;
    }

    private function isBreakOnlyRun(AbstractElement $element): bool
    {
        if (! $element instanceof TextRun) {
            return $element instanceof TextBreak;
        }
        foreach ($element->getElements() as $child) {
            if (! $child instanceof TextBreak) {
                return false;
            }
        }

        return true;
    }

    /** @return list<PmNode> */
    private function convertUnknown(AbstractElement $element): array
    {
        $text = method_exists($element, 'getText') ? $element->getText() : null;
        if ($text instanceof TextRun) {
            return [$this->runs->paragraph($this->runs->inlineChildren($text))];
        }
        // PreserveText (a Word field, e.g. a header's "{PAGE}") hands back an
        // array of chunks rather than a string.
        if (is_array($text)) {
            $text = implode('', array_filter($text, 'is_string'));
        }
        $text = $this->runs->decode(is_scalar($text) ? (string) $text : '');

        return trim($text) === '' ? [] : [$this->runs->paragraph([['type' => 'text', 'text' => $text]])];
    }
}
