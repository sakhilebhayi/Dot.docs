<?php

namespace App\Documents\Export\Docx;

use App\Brand\Brand;
use App\Models\BrandKit;
use App\Styles\TokenGuard;
use PhpOffice\PhpWord\Element\Section;
use Throwable;

/**
 * The company letterhead and disclaimer (R2-3c), written when the document
 * uses its brand.
 */
final class DocxLetterheadWriter
{
    public function __construct(private DocxContext $context) {}

    /**
     * Logo, company name and contact line at the top of page 1, in a borderless two-column table (the logo cell
     * only when there is a logo). Plain text runs: nothing a kit holds is interpreted.
     */
    public function writeLetterhead(Section $section, BrandKit $kit): void
    {
        $colours = is_array($this->context->tokens['colours'] ?? null) ? $this->context->tokens['colours'] : [];
        $colour = ltrim(TokenGuard::colour($colours['heading'] ?? null, '#1f2023'), '#');
        $table = $section->addTable(['borderSize' => 0, 'cellMargin' => 0, 'width' => 100 * 50, 'unit' => 'pct']);
        $row = $table->addRow();

        $logo = Brand::logoPath($kit);
        if ($logo !== null) {
            try {
                $row->addCell(2600, ['valign' => 'center'])->addImage($logo, ['height' => 44, 'wrappingStyle' => 'inline']);
            } catch (Throwable) {
                // An unreadable logo leaves the name and contact line; the export still goes out.
            }
        }

        $cell = $row->addCell(null, ['valign' => 'center']);
        $cell->addText($this->context->escape((string) $kit->name), ['size' => 14, 'bold' => true, 'color' => strlen($colour) === 6 ? $colour : '1F2023'], ['spaceAfter' => 40]);
        $contact = Brand::contactLine($kit);
        if ($contact !== '') {
            $cell->addText($this->context->escape($contact), ['size' => 8.5, 'color' => '5D5E5A'], ['spaceAfter' => 0]);
        }

        $section->addText('', [], ['spaceAfter' => 240, 'borderBottomSize' => 4, 'borderBottomColor' => 'D5D1C7']);
    }

    /** The kit's disclaimer, small and italic under a rule, after the body. */
    public function writeDisclaimer(Section $section, BrandKit $kit): void
    {
        if (trim((string) $kit->disclaimer) !== '') {
            $section->addText($this->context->escape(trim((string) $kit->disclaimer)), ['size' => 8, 'italic' => true, 'color' => '5D5E5A'], ['spaceBefore' => 400, 'borderTopSize' => 4, 'borderTopColor' => 'D5D1C7']);
        }
    }
}
