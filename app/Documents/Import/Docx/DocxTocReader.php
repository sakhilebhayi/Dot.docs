<?php

namespace App\Documents\Import\Docx;

use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;

/**
 * Recognises a Word table-of-contents field and the cached entry paragraphs
 * behind it, so DocxBlockReader can read them back as one `toc` node.
 */
final class DocxTocReader
{
    /**
     * The heading depth of a Word TOC field, or null if this is not one.
     *
     * `DocxExporter` writes a `toc` node as a real `addTOC()` field, and
     * PhpWord's reader hands the field-carrying paragraph back as a
     * `PreserveText` holding the raw instruction ("{TOC \o 1-3 \h \z \u}").
     * Reading it as a `toc` node again - rather than letting it and the
     * cached entry paragraphs behind it fall through to convertUnknown() -
     * is what stops a re-import from pasting a frozen copy of the table of
     * contents into the body as ordinary paragraphs.
     */
    public function tocDepth(PreserveText $element): ?int
    {
        $text = $element->getText();
        $text = is_array($text) ? implode('', array_filter($text, 'is_string')) : (string) $text;
        if (preg_match('/\{\s*TOC\b/i', $text) !== 1) {
            return null;
        }

        // \o "1-3" is Word's heading-level range; only its upper bound maps
        // onto this schema's single `depth` attr.
        return preg_match('/\\\\o\s*"?\d+-(\d+)"?/', $text, $m) === 1
            ? max(1, min(9, (int) $m[1]))
            : 3;
    }

    /**
     * One of the cached entry paragraphs Word stores inside a TOC field: a
     * text-only run carrying the tab that separates an entry from its page
     * number, or the empty paragraph that closes the field. Requiring the
     * tab is what keeps an ordinary paragraph sitting directly under a table
     * of contents from being swallowed with it.
     */
    public function isTocEntry(AbstractElement $element): bool
    {
        if (! $element instanceof TextRun) {
            return false;
        }

        $children = $element->getElements();
        if ($children === []) {
            return true;
        }

        $hasTab = false;
        foreach ($children as $child) {
            if (! $child instanceof Text) {
                return false;
            }
            $hasTab = $hasTab || str_contains((string) $child->getText(), "\t");
        }

        return $hasTab;
    }
}
