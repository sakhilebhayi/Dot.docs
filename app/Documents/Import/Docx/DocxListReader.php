<?php

namespace App\Documents\Import\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Numbering;

/**
 * Word list paragraphs -> `bulletList`/`orderedList` nodes. Word has no
 * list element, so DocxBlockReader gathers a run of consecutive list
 * paragraphs with listItem() and this rebuilds them with buildLists().
 *
 * @phpstan-import-type PmNode from DocumentSchema
 *
 * @phpstan-type ListEntry array{depth:int,type:string,key:string,node:PmNode}
 */
final class DocxListReader
{
    public function __construct(private DocxRunReader $runs) {}

    /**
     * A list item, its Word indent level, and the key that decides whether it
     * continues the list before it: two adjacent lists that share a type but
     * not a numbering definition are two lists, not one.
     *
     * @return ListEntry
     */
    public function listItem(ListItemRun|ListItem $element): array
    {
        $style = $element->getStyle();
        $numStyleName = (string) $style->getNumStyle();
        $ordered = $this->isOrderedNumbering($numStyleName, $style);

        $inline = $element instanceof ListItemRun
            ? $this->runs->inlineChildren($element)
            : $this->runs->textNodes($element->getTextObject()->getText(), $this->runs->fontMarks($element->getTextObject()->getFontStyle()));

        return [
            // Word's `w:ilvl`, which the reader passes straight to
            // addListItemRun() — the ONLY record of nesting in the file.
            'depth' => max(0, (int) $element->getDepth()),
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'key' => ($ordered ? 'ol:' : 'ul:').$numStyleName,
            'node' => ['type' => 'listItem', 'content' => [$this->runs->paragraph($inline)]],
        ];
    }

    /**
     * Rebuild the tree a flat run of Word list paragraphs describes.
     *
     * Word has no nested list element: nesting is a run of sibling paragraphs
     * each carrying its own indent level, and a sub-item is simply an item
     * with a deeper one. This is the inverse of `DocxBlockWriter::writeList()`,
     * which flattens the tree the same way on the way out. Reading it back as
     * one flat list (what this did before) silently destroyed the structure
     * of every nested list on a full export -> re-import round trip.
     *
     * @param  list<ListEntry>  $items
     * @return list<PmNode>
     */
    public function buildLists(array $items): array
    {
        $out = [];
        $index = 0;
        while ($index < count($items)) {
            $out[] = $this->buildList($items, $index, $items[$index]['depth']);
        }

        return $out;
    }

    /**
     * One list node, consuming every item at $depth or deeper from $index.
     *
     * @param  list<ListEntry>  $items
     * @return PmNode
     */
    private function buildList(array $items, int &$index, int $depth): array
    {
        $type = $items[$index]['type'];
        $nodes = [];
        $count = count($items);

        while ($index < $count) {
            $item = $items[$index];

            if ($item['depth'] < $depth) {
                break;
            }

            if ($item['depth'] > $depth) {
                if ($nodes === []) {
                    // Word can open a list at a sub-level with no parent item
                    // above it; this schema cannot, so the missing parent is
                    // invented rather than the nesting flattened away.
                    $nodes[] = ['type' => 'listItem', 'content' => [$this->runs->paragraph([])]];
                }
                $nodes[count($nodes) - 1]['content'][] = $this->buildList($items, $index, $item['depth']);

                continue;
            }

            if ($nodes !== [] && $item['type'] !== $type) {
                break;
            }

            $nodes[] = $item['node'];
            $index++;
        }

        return ['type' => $type, 'content' => $nodes];
    }

    /**
     * Whether a list item's numbering definition counts (1., a., i.) rather
     * than bullets. The reader registers each `w:num` it finds as a
     * `Numbering` style named "PHPWordList{numId}", so the level-0 `numFmt`
     * is the only reliable signal — `Style\ListItem::getListType()` is the
     * legacy pre-0.10 field and is never set on a read document.
     */
    private function isOrderedNumbering(string $numStyleName, Style\ListItem $style): bool
    {
        $numbering = $numStyleName === '' ? null : Style::getStyle($numStyleName);
        if ($numbering instanceof Numbering) {
            foreach ($numbering->getLevels() as $level) {
                $format = (string) $level->getFormat();

                return $format !== '' && $format !== 'bullet' && $format !== 'none';
            }
        }

        return in_array($style->getListType(), [
            Style\ListItem::TYPE_NUMBER,
            Style\ListItem::TYPE_NUMBER_NESTED,
            Style\ListItem::TYPE_ALPHANUM,
        ], true);
    }
}
