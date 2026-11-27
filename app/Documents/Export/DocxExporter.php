<?php

namespace App\Documents\Export;

use App\Brand\Brand;
use App\Documents\Export\Docx\DocxBandWriter;
use App\Documents\Export\Docx\DocxBlockWriter;
use App\Documents\Export\Docx\DocxContext;
use App\Documents\Export\Docx\DocxLetterheadWriter;
use App\Documents\Outline\Outline;
use App\Documents\Schema\DocumentSchema;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Print\PageSetup;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style;
use ZipArchive;

/**
 * Dot.Doc JSON -> a .docx file, styled from the document's resolved
 * DocumentStyle tokens (see DocumentStyleSeeder) and its PageSetup.
 *
 * The export is the round-trip partner of DocxImporter: what this writer
 * emits as a `Heading{n}` paragraph style, a numbered `ListItemRun`, or a
 * `tblHeader` row is exactly what that importer reads back. See the mapping
 * table in .ai/rules/documents-io.md before changing either side.
 *
 * This class sets up the Word document (styles, page, running bands,
 * letterhead) and hands the body to the writers in App\Documents\Export\Docx:
 * DocxBlockWriter for blocks, which uses DocxRunWriter (inline content),
 * DocxTableWriter and DocxImageWriter. DocxContext carries the per-export
 * state they share.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
class DocxExporter
{
    /** Page sizes in twips (w, h), portrait. */
    private const PAGE_SIZES = [
        'A4' => [11906, 16838],
        'A3' => [16838, 23811],
        'Letter' => [12240, 15840],
    ];

    public function __construct(
        private Outline $outline = new Outline,
        private DocumentSchema $schema = new DocumentSchema,
    ) {}

    /**
     * @param  PmNode  $json  Dot.Doc JSON
     * @return string absolute path to a temporary .docx file — the caller owns it
     */
    public function export(array $json, Document $doc, DocumentStyle $style): string
    {
        // PhpOffice\PhpWord\Style is a process-wide static registry, so the
        // heading/numbering styles of a previous export would otherwise win
        // over this document's tokens (setStyleValues() keeps the first
        // definition of a name and ignores later ones).
        Style::resetStyles();

        $tokens = $style->tokens;
        $variables = array_map(
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            is_array($doc->variables) ? $doc->variables : []
        );

        $numbering = $tokens['numbering'] ?? [];
        $result = $this->outline->build($json, is_array($numbering) ? $numbering : []);
        $json = $this->outline->apply($json, $result);

        $context = new DocxContext(
            tokens: $tokens,
            variables: $variables,
            numbers: $result->numbers,
            hasToc: $this->containsToc($json),
            mediaOwner: (string) $doc->uuid ?: null,
        );

        $setup = PageSetup::fromDocument($doc, $style);
        $word = $this->newPhpWord($context);
        $section = $word->addSection($this->sectionStyle($setup, $context));
        (new DocxBandWriter($context))->writeBands($section, $setup, $doc);

        // The company letterhead and disclaimer (R2-3c), when the document uses its brand.
        $kit = Brand::forDocument($doc);
        $letterhead = new DocxLetterheadWriter($context);
        if ($kit !== null) {
            $letterhead->writeLetterhead($section, $kit);
        }

        $content = $json['content'] ?? [];
        (new DocxBlockWriter($context, $this->schema))->writeBlocks(is_array($content) ? $content : [], $section);

        if ($kit !== null) {
            $letterhead->writeDisclaimer($section, $kit);
        }

        // tempnam() creates the file itself, atomically and only readable by
        // this process — so the document is written to THAT path rather than
        // to a second, differently named one created under the umask.
        $path = tempnam(sys_get_temp_dir(), 'dotdoc_');
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $this->reattachInlineBreaks($path);

        return $path;
    }

    /**
     * Move every inline line break inside a run.
     *
     * PhpWord writes a `TextBreak` that sits inside a `TextRun` as a bare
     * `<w:br/>` directly under `<w:p>` (Writer\Word2007\Element\TextBreak
     * with $withoutP). That is not where OOXML puts a break, and PhpWord's
     * own reader proves it: Reader\Word2007\AbstractPart::readRun() only
     * descends into `w:r`/`w:ins`/`w:del`/`w:smartTag`/`w:hyperlink`, so the
     * break is dropped on the way back in and the two lines around it
     * silently concatenate. Wrapping it in its own `w:r` is the canonical
     * form, and the reader reassembles it as a `hardBreak`.
     *
     * A page break is `<w:br w:type="page"/>` and is already written inside a
     * run, so the attribute-less needle here cannot match one.
     */
    private function reattachInlineBreaks(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }
        $xml = $zip->getFromName('word/document.xml');
        if (is_string($xml) && str_contains($xml, '<w:br/>')) {
            $zip->addFromString('word/document.xml', str_replace('<w:br/>', '<w:r><w:br/></w:r>', $xml));
        }
        $zip->close();
    }

    private function newPhpWord(DocxContext $context): PhpWord
    {
        // PhpWord writes text into document.xml AS GIVEN unless output escaping is on: a paragraph saying
        // "Smith & Sons" or "a < b" made a file Word refuses to open (found by R2-3c's letterhead test). With it on,
        // every run - body, headings, links, tables, headers, footers, the letterhead - is escaped in one place.
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName($context->font('body'));
        // Settings directly, which is all PhpWord::setDefaultFontSize() does:
        // its docblock says int, and a fractional body size (10.5pt) is valid.
        Settings::setDefaultFontSize($context->size('body', 11.0));

        // addTitleStyle() has to run BEFORE any addTitle(): PhpWord's Title
        // element only records its `Heading{n}` paragraph style if that style
        // is already registered, and without the pStyle nothing downstream —
        // Word's navigation pane, its TOC field, or DocxImporter — can tell
        // the paragraph was a heading.
        $headingColour = $context->colour('heading', '1F2023');
        // headingCase is a style token the CSS surface applies as
        // text-transform (see App\Styles\CssBuilder); Word's equivalent is
        // the run-level allCaps property, so an upper-case style prints the
        // same way in a .docx as it does on screen.
        $allCaps = ($context->tokens['headingCase'] ?? 'none') === 'upper';
        foreach ([1 => 'h1', 2 => 'h2', 3 => 'h3', 4 => 'h3', 5 => 'h3', 6 => 'h3'] as $level => $token) {
            $word->addTitleStyle($level, [
                'name' => $context->font('heading'),
                'size' => $context->size($token, 14.0),
                'bold' => true,
                'color' => $headingColour,
                'allCaps' => $allCaps,
            ], ['spaceBefore' => 240, 'spaceAfter' => 120, 'keepNext' => true]);
        }

        $word->addNumberingStyle(DocxBlockWriter::BULLET_STYLE, ['type' => 'hybridMultilevel', 'levels' => [
            ['format' => 'bullet', 'text' => "\u{2022}", 'left' => 360, 'hanging' => 360, 'tabPos' => 360, 'font' => 'Symbol'],
            ['format' => 'bullet', 'text' => "\u{25E6}", 'left' => 720, 'hanging' => 360, 'tabPos' => 720, 'font' => 'Symbol'],
        ]]);
        $word->addNumberingStyle(DocxBlockWriter::NUMBER_STYLE, ['type' => 'multilevel', 'levels' => [
            ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
            ['format' => 'lowerLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
        ]]);

        return $word;
    }

    /** @return array<string,mixed> */
    private function sectionStyle(PageSetup $setup, DocxContext $context): array
    {
        [$width, $height] = self::PAGE_SIZES[$setup->size] ?? self::PAGE_SIZES['A4'];
        if ($setup->orientation === 'landscape') {
            [$width, $height] = [$height, $width];
        }

        return [
            'pageSizeW' => $width,
            'pageSizeH' => $height,
            'orientation' => $setup->orientation,
            'marginTop' => $context->twips($setup->margins['top'], 1417),
            'marginRight' => $context->twips($setup->margins['right'], 1134),
            'marginBottom' => $context->twips($setup->margins['bottom'], 1417),
            'marginLeft' => $context->twips($setup->margins['left'], 1134),
        ];
    }

    /** @param PmNode $json */
    private function containsToc(array $json): bool
    {
        $found = false;
        $this->schema->walk($json, function (array $node) use (&$found): void {
            if (($node['type'] ?? '') === 'toc') {
                $found = true;
            }
        });

        return $found;
    }
}
