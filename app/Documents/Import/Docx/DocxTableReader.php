<?php

namespace App\Documents\Import\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;

/**
 * A Word table -> a `table` node. A first row marked `tblHeader` (what
 * DocxExporter writes for a header row) becomes `tableHeader` cells.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
final class DocxTableReader
{
    public function __construct(private DocxBlockReader $blocks) {}

    /** @return PmNode */
    public function convertTable(Table $table): array
    {
        $rows = [];
        foreach ($table->getRows() as $index => $row) {
            $rows[] = ['type' => 'tableRow', 'content' => $this->convertRowCells($row, $index === 0 && $this->isHeaderRow($row))];
        }

        return ['type' => 'table', 'content' => $rows];
    }

    private function isHeaderRow(Row $row): bool
    {
        return $row->getStyle()->isTblHeader();
    }

    /** @return list<PmNode> */
    private function convertRowCells(Row $row, bool $header): array
    {
        $cells = [];
        foreach ($row->getCells() as $cell) {
            $cells[] = [
                'type' => $header ? 'tableHeader' : 'tableCell',
                'content' => $this->blocks->convertElements($cell->getElements()),
            ];
        }

        return $cells;
    }
}
