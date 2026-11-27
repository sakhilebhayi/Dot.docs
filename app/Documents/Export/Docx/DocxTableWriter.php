<?php

namespace App\Documents\Export\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * A `table` node as a Word table. A first row of `tableHeader` cells is
 * marked `tblHeader`, which is what DocxImporter reads back as a header row.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
final class DocxTableWriter
{
    public function __construct(
        private DocxContext $context,
        private DocxRunWriter $runs,
        private DocxBlockWriter $blocks,
    ) {}

    /** @param PmNode $node */
    public function writeTable(array $node, Section|Cell $container): void
    {
        $tableTokens = is_array($this->context->tokens['table'] ?? null) ? $this->context->tokens['table'] : [];
        $table = $container->addTable([
            'borderSize' => 6,
            'borderColor' => $this->context->colour('rule', 'D5D1C7'),
            'cellMargin' => 80,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
        ]);

        $zebra = (bool) ($tableTokens['zebra'] ?? false);
        $banded = ($tableTokens['header'] ?? 'band') === 'band';

        $rowIndex = 0;
        foreach ($node['content'] ?? [] as $row) {
            if (! is_array($row) || ($row['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $isHeader = $rowIndex === 0 && $this->rowIsHeader($row);
            $wordRow = $table->addRow(null, $isHeader ? ['tblHeader' => true] : []);

            $fill = match (true) {
                $isHeader && $banded => 'EFEDE7',
                ! $isHeader && $zebra && $rowIndex % 2 === 0 => 'F7F5F0',
                default => null,
            };

            foreach ($row['content'] ?? [] as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $wordCell = $wordRow->addCell(null, $fill === null ? [] : ['bgColor' => $fill]);
                $this->writeCell($cell, $wordCell, $isHeader);
            }
            $rowIndex++;
        }
    }

    /** @param PmNode $row */
    private function rowIsHeader(array $row): bool
    {
        foreach ($row['content'] ?? [] as $cell) {
            if (is_array($cell) && ($cell['type'] ?? '') === 'tableHeader') {
                return true;
            }
        }

        return false;
    }

    /** @param PmNode $cell */
    private function writeCell(array $cell, Cell $wordCell, bool $header): void
    {
        foreach ($cell['content'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }
            if ($header && ($child['type'] ?? '') === 'paragraph') {
                $run = $wordCell->addTextRun();
                $this->runs->writeInline($child['content'] ?? [], $run, ['bold' => true]);

                continue;
            }
            $this->blocks->writeBlock($child, $wordCell);
        }
    }
}
