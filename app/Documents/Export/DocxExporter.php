<?php

namespace App\Documents\Export;

use App\Documents\Outline\Outline;
use App\Documents\Schema\DocumentSchema;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Print\PageSetup;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\TOC;
use Throwable;
use ZipArchive;

/**
 * Dot.Doc JSON -> a .docx file, styled from the document's resolved
 * DocumentStyle tokens (see DocumentStyleSeeder) and its PageSetup.
 *
 * The export is the round-trip partner of DocxImporter: what this writer
 * emits as a `Heading{n}` paragraph style, a numbered `ListItemRun`, or a
 * `tblHeader` row is exactly what that importer reads back. See the mapping
 * table in .ai/rules/documents-io.md before changing either side.
 */
class DocxExporter
{
    /** Page sizes in twips (w, h), portrait. */
    private const PAGE_SIZES = [
        'A4' => [11906, 16838],
        'A3' => [16838, 23811],
        'Letter' => [12240, 15840],
    ];

    /** A running band segment that is literal text, not a Word field. */
    private const BAND_TEXT = 'text';

    /** A running band segment that is one of the two Word fields this writer emits. */
    private const BAND_FIELD = 'field';

    private const BULLET_STYLE = 'DotDocBullet';

    private const NUMBER_STYLE = 'DotDocNumber';

    /**
     * The `image.attrs.src` values this writer will embed: a path under the
     * public disk, every segment starting with an alphanumeric (so no `.`
     * or `..` segment can appear), ending in an image extension this app
     * stores. `DocumentSchema` puts no format constraint on the src and
     * content reaches the store straight from the autosave endpoint, so this
     * is where a hand-written traversal src is refused.
     */
    private const IMAGE_SRC = '#^/storage/[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*\.(?:png|jpe?g|gif|webp)$#i';

    /** @var array<string,string> block id => outline number */
    private array $numbers = [];

    /** @var array<string,string> */
    private array $variables = [];

    /** @var array<string,mixed> */
    private array $tokens = [];

    /**
     * Whether this document contains a `toc` node.
     *
     * PhpWord's TOC writer reads every registered Title back with
     * `writeText($title->getText())`, which TypeErrors on the TextRun
     * writeHeading() would rather use — so a document with a table of
     * contents writes plain-string headings instead.
     */
    private bool $hasToc = false;

    public function __construct(
        private Outline $outline = new Outline,
        private DocumentSchema $schema = new DocumentSchema,
    ) {}

    /**
     * @param  array<string,mixed>  $json  Dot.Doc JSON
     * @return string absolute path to a temporary .docx file — the caller owns it
     */
    public function export(array $json, Document $doc, DocumentStyle $style): string
    {
        // PhpOffice\PhpWord\Style is a process-wide static registry, so the
        // heading/numbering styles of a previous export would otherwise win
        // over this document's tokens (setStyleValues() keeps the first
        // definition of a name and ignores later ones).
        Style::resetStyles();

        $this->tokens = is_array($style->tokens) ? $style->tokens : [];
        $this->variables = array_map(
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            is_array($doc->variables) ? $doc->variables : []
        );

        $result = $this->outline->build($json, $this->tokens['numbering'] ?? []);
        $this->numbers = $result->numbers;
        $json = $this->outline->apply($json, $result);
        $this->hasToc = $this->containsToc($json);

        $setup = PageSetup::fromDocument($doc, $style);
        $word = $this->newPhpWord();
        $section = $word->addSection($this->sectionStyle($setup));
        $this->writeBands($section, $setup, $doc);

        $content = $json['content'] ?? [];
        $this->writeBlocks(is_array($content) ? $content : [], $section);

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

    private function newPhpWord(): PhpWord
    {
        $word = new PhpWord;
        $word->setDefaultFontName($this->font('body'));
        $word->setDefaultFontSize($this->size('body', 11.0));

        // addTitleStyle() has to run BEFORE any addTitle(): PhpWord's Title
        // element only records its `Heading{n}` paragraph style if that style
        // is already registered, and without the pStyle nothing downstream —
        // Word's navigation pane, its TOC field, or DocxImporter — can tell
        // the paragraph was a heading.
        $headingColour = $this->colour('heading', '1F2023');
        // headingCase is a style token the CSS surface applies as
        // text-transform (see App\Styles\CssBuilder); Word's equivalent is
        // the run-level allCaps property, so an upper-case style prints the
        // same way in a .docx as it does on screen.
        $allCaps = ($this->tokens['headingCase'] ?? 'none') === 'upper';
        foreach ([1 => 'h1', 2 => 'h2', 3 => 'h3', 4 => 'h3', 5 => 'h3', 6 => 'h3'] as $level => $token) {
            $word->addTitleStyle($level, [
                'name' => $this->font('heading'),
                'size' => $this->size($token, 14.0),
                'bold' => true,
                'color' => $headingColour,
                'allCaps' => $allCaps,
            ], ['spaceBefore' => 240, 'spaceAfter' => 120, 'keepNext' => true]);
        }

        $word->addNumberingStyle(self::BULLET_STYLE, ['type' => 'hybridMultilevel', 'levels' => [
            ['format' => 'bullet', 'text' => "\u{2022}", 'left' => 360, 'hanging' => 360, 'tabPos' => 360, 'font' => 'Symbol'],
            ['format' => 'bullet', 'text' => "\u{25E6}", 'left' => 720, 'hanging' => 360, 'tabPos' => 720, 'font' => 'Symbol'],
        ]]);
        $word->addNumberingStyle(self::NUMBER_STYLE, ['type' => 'multilevel', 'levels' => [
            ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
            ['format' => 'lowerLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
        ]]);

        return $word;
    }

    /** @return array<string,mixed> */
    private function sectionStyle(PageSetup $setup): array
    {
        [$width, $height] = self::PAGE_SIZES[$setup->size] ?? self::PAGE_SIZES['A4'];
        if ($setup->orientation === 'landscape') {
            [$width, $height] = [$height, $width];
        }

        return [
            'pageSizeW' => $width,
            'pageSizeH' => $height,
            'orientation' => $setup->orientation,
            'marginTop' => $this->twips($setup->margins['top'], 1417),
            'marginRight' => $this->twips($setup->margins['right'], 1134),
            'marginBottom' => $this->twips($setup->margins['bottom'], 1417),
            'marginLeft' => $this->twips($setup->margins['left'], 1134),
        ];
    }

    /**
     * The running header and footer. `{{ page }}`/`{{ pages }}` become Word's
     * own PAGE/NUMPAGES fields — a literal number would be wrong on every page
     * but one — and every other `{{ key }}` is substituted here, exactly as
     * PrintRenderer does for the PDF surface.
     */
    private function writeBands(Section $section, PageSetup $setup, Document $doc): void
    {
        $vars = array_merge($this->variables, [
            'title' => (string) $doc->title,
            'date' => now()->format('Y-m-d'),
            'team' => (string) ($doc->team?->name ?? ''),
        ]);

        $font = ['name' => $this->font('body'), 'size' => $this->size('small', 9.0), 'color' => $this->colour('muted', '5D5E5A')];

        $header = $this->band($setup->header, $vars);
        if ($header !== []) {
            $this->writeBand($section->addHeader(), $header, $font);
        }
        $footer = $this->band($setup->footer, $vars);
        if ($footer !== []) {
            $this->writeBand($section->addFooter(), $footer, $font, ['alignment' => 'center']);
        }
    }

    /**
     * Write one running band as a single paragraph of literal text runs and
     * Word fields.
     *
     * The fields are added with `addField()`, whose instruction this writer
     * builds from a fixed type name — NEVER from the band's text. PhpWord's
     * `PreserveText` (which addPreserveText() creates) splits whatever string
     * it is handed on every `{...}` and writes each match as a real field
     * instruction, so passing it a band that has had a document title or a
     * variable substituted into it turns `{INCLUDEPICTURE "http://…"}` in
     * that value into a genuine external-fetch field. band() has already
     * decided which segments are fields; a text segment's braces are just
     * characters and reach the file inside `w:t`.
     *
     * @param  Header|Footer  $band
     * @param  list<array{0:string,1:string}>  $segments
     * @param  array<string,mixed>  $font
     * @param  array<string,mixed>  $paragraph
     */
    private function writeBand(mixed $band, array $segments, array $font, array $paragraph = []): void
    {
        $run = $band->addTextRun($paragraph);

        foreach ($segments as [$kind, $value]) {
            if ($kind === self::BAND_FIELD) {
                $run->addField($value, [], [], null, $font);

                continue;
            }
            // escape() like every other text-writing path in this class: a
            // band's text is the page-setup template with $doc->variables
            // substituted into it, and both are user-controlled.
            $run->addText($this->escape($value), $font);
        }
    }

    /**
     * Split a band template into literal-text and field segments.
     *
     * The split happens on the app's OWN `{{ page }}`/`{{ pages }}`
     * placeholders, before any substitution, so a `{`/`}` that arrives inside
     * a substituted value can never be mistaken for one — see writeBand().
     *
     * @param  array<string,string>  $vars
     * @return list<array{0:string,1:string}>
     */
    private function band(string $template, array $vars): array
    {
        if (trim($template) === '') {
            return [];
        }

        $pieces = preg_split('/(\{\{\s*\w+\s*\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];
        foreach (is_array($pieces) ? $pieces : [] as $piece) {
            if (preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $piece, $m) !== 1) {
                $segments[] = [self::BAND_TEXT, $piece];

                continue;
            }
            $segments[] = match ($m[1]) {
                'page' => [self::BAND_FIELD, 'PAGE'],
                'pages' => [self::BAND_FIELD, 'NUMPAGES'],
                default => [self::BAND_TEXT, $vars[$m[1]] ?? ''],
            };
        }

        // An all-text band that substituted down to nothing is no band at all,
        // which is what the plain `$header !== ''` test used to say.
        $text = implode('', array_map(
            fn (array $s): string => $s[0] === self::BAND_TEXT ? $s[1] : '',
            $segments
        ));

        $hasField = array_filter($segments, fn (array $s): bool => $s[0] === self::BAND_FIELD) !== [];

        if (! $hasField && trim($text) === '') {
            return [];
        }

        return array_values(array_filter($segments, fn (array $s): bool => $s[0] === self::BAND_FIELD || $s[1] !== ''));
    }

    /**
     * @param  array<int,mixed>  $nodes
     * @param  Section|Cell  $container
     */
    private function writeBlocks(array $nodes, AbstractContainer $container): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->writeBlock($node, $container);
            }
        }
    }

    /** @param Section|Cell $container */
    private function writeBlock(array $node, AbstractContainer $container): void
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
            'image' => $this->writeImage($node, $container),
            'figure' => $this->writeBlocks($node['content'] ?? [], $container),
            'caption' => $this->writeCaption($node, $container),
            'table' => $this->writeTable($node, $container),
            'toc' => $this->writeToc($node, $container),
            'pageBreak', 'sectionBreak' => $this->writePageBreak($container),
            'columns', 'column' => $this->writeBlocks($node['content'] ?? [], $container),
            default => $this->writeParagraph($node, $container),
        };
    }

    /** @param Section|Cell $container */
    private function writeHeading(array $node, AbstractContainer $container): void
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));
        // The Outline number is written as literal text. Word's own heading
        // numbering lives in a linked list definition this writer does not
        // emit, so a numbered document that carried no number here would
        // export with its cross-references pointing at nothing.
        $number = $this->numbers[$node['attrs']['id'] ?? ''] ?? '';

        if (! $container instanceof Section && ! $container instanceof Cell) {
            $container->addText($this->escape($this->headingText($node, $number)), ['bold' => true, 'size' => $this->size('h3', 13.0)]);

            return;
        }

        if ($this->hasToc) {
            $container->addTitle($this->escape($this->headingText($node, $number)), $level);

            return;
        }

        // A DETACHED TextRun, not a plain string: PhpWord\Element\Title
        // accepts either, and only the run keeps the heading's own
        // italic/link marks that plainText() would flatten. The Word2007
        // writer walks it with its normal Container writer, so hyperlink
        // relation ids are still resolved at write time even though the run
        // was never added to a container itself. Its TOC writer does NOT —
        // see $hasToc.
        $run = new TextRun;
        if ($number !== '') {
            $run->addText($number.' ');
        }
        $this->writeInline($node['content'] ?? [], $run);
        $container->addTitle($run, $level);
    }

    private function headingText(array $node, string $number): string
    {
        return trim($number.' '.trim($this->schema->plainText($node)));
    }

    /** @param array<string,mixed> $json */
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

    /** @param Section|Cell $container */
    private function writeParagraph(array $node, AbstractContainer $container, array $paragraphStyle = []): void
    {
        $align = $node['attrs']['align'] ?? ($this->tokens['align'] ?? null);
        if (is_string($align) && in_array($align, DocumentSchema::ALIGNMENTS, true)) {
            $paragraphStyle['alignment'] = $align;
        }
        $run = $container->addTextRun($paragraphStyle);
        $this->writeInline($node['content'] ?? [], $run);
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
     * @param  Section|Cell  $container
     */
    private function writeList(array $node, AbstractContainer $container, bool $ordered, int $depth): void
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
                    $this->writeInline($child['content'] ?? [], $run);

                    continue;
                }
                $this->writeBlock($child, $container);
            }
            if ($run === null) {
                $container->addListItemRun($depth, $numStyle);
            }
        }
    }

    /** @param Section|Cell $container */
    private function writeQuote(array $node, AbstractContainer $container): void
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

    /** @param Section|Cell $container */
    private function writeCallout(array $node, AbstractContainer $container): void
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

    /** @param Section|Cell $container */
    private function writeCodeBlock(array $node, AbstractContainer $container): void
    {
        $run = $container->addTextRun(['shading' => ['fill' => 'F3F1EA'], 'spaceBefore' => 120, 'spaceAfter' => 120]);
        $font = ['name' => $this->font('mono'), 'size' => $this->size('small', 9.0)];
        foreach (explode("\n", $this->schema->plainText($node)) as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }
            $run->addText($line, $font);
        }
    }

    private function writeRule(AbstractContainer $container): void
    {
        $container->addTextRun([
            'borderBottomSize' => 6,
            'borderBottomColor' => $this->colour('rule', 'D5D1C7'),
            'spaceBefore' => 120,
            'spaceAfter' => 120,
        ]);
    }

    /** @param Section|Cell $container */
    private function writeCaption(array $node, AbstractContainer $container): void
    {
        $this->writeParagraph($node, $container, ['spaceAfter' => 240]);
    }

    private function writePageBreak(AbstractContainer $container): void
    {
        // PageBreak is a Section-only element; inside a table cell the
        // nearest honest equivalent is an empty paragraph.
        if ($container instanceof Section) {
            $container->addPageBreak();

            return;
        }
        $container->addTextBreak();
    }

    private function writeToc(array $node, AbstractContainer $container): void
    {
        if (! $container instanceof Section) {
            return;
        }
        $depth = (int) ($node['attrs']['depth'] ?? 3);
        $container->addTOC(
            ['name' => $this->font('heading'), 'size' => $this->size('body', 11.0)],
            ['tabLeader' => TOC::TAB_LEADER_DOT],
            1,
            max(1, min(9, $depth))
        );
    }

    /**
     * Only images this application actually stores are embedded: a remote
     * `https://` src would make the export fetch an arbitrary URL server-side
     * on every download, and a missing local file must not abort the whole
     * document.
     *
     * @param  Section|Cell  $container
     */
    private function writeImage(array $node, AbstractContainer $container): void
    {
        $src = $node['attrs']['src'] ?? null;
        if (! is_string($src)) {
            return;
        }
        // A detached run (writeHeading's Title run) is not in the document's
        // element tree, so an image added to it would never reach the media
        // collection and would write a picture reference pointing at nothing.
        if ($container->getPhpWord() === null) {
            return;
        }
        $path = $this->publicDiskPath($src);
        if ($path === null) {
            return;
        }

        $style = [];
        $width = $node['attrs']['width'] ?? null;
        if (is_numeric($width) && (float) $width > 0) {
            $style['width'] = Converter::pixelToPoint((float) $width);
        }

        try {
            $container->addImage($path, $style);
        } catch (Throwable) {
            // An unreadable or unsupported picture is not a reason to fail
            // the export; the rest of the document still exports.
        }
    }

    /**
     * The absolute path an `image.attrs.src` names inside the public disk, or
     * null when it names anything else — a remote URL, a file type this app
     * does not store, or a path that climbs out of the disk.
     *
     * Both checks are load-bearing. The pattern refuses `.`/`..` segments and
     * anything but an image extension before a path is built at all;
     * realpath() then proves that what the src actually resolves to is still
     * under the disk root, which is the only check a symlink cannot walk
     * around. A src that fails is SKIPPED — the rest of the document still
     * exports — never thrown, because this is stored content and an export
     * that 500s is worse than an export missing a picture.
     */
    private function publicDiskPath(string $src): ?string
    {
        if (preg_match(self::IMAGE_SRC, $src) !== 1) {
            return null;
        }

        $root = realpath(Storage::disk('public')->path(''));
        $path = realpath(Storage::disk('public')->path(substr($src, strlen('/storage/'))));
        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? $path : null;
    }

    /** @param Section|Cell $container */
    private function writeTable(array $node, AbstractContainer $container): void
    {
        $tableTokens = is_array($this->tokens['table'] ?? null) ? $this->tokens['table'] : [];
        $table = $container->addTable([
            'borderSize' => 6,
            'borderColor' => $this->colour('rule', 'D5D1C7'),
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

    private function rowIsHeader(array $row): bool
    {
        foreach ($row['content'] ?? [] as $cell) {
            if (is_array($cell) && ($cell['type'] ?? '') === 'tableHeader') {
                return true;
            }
        }

        return false;
    }

    private function writeCell(array $cell, Cell $wordCell, bool $header): void
    {
        foreach ($cell['content'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }
            if ($header && ($child['type'] ?? '') === 'paragraph') {
                $run = $wordCell->addTextRun();
                $this->writeInline($child['content'] ?? [], $run, ['bold' => true]);

                continue;
            }
            $this->writeBlock($child, $wordCell);
        }
    }

    /**
     * @param  array<int,mixed>  $nodes
     * @param  array<string,mixed>  $inherited  font properties every run in this container carries
     */
    private function writeInline(array $nodes, TextRun $run, array $inherited = []): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            match ($node['type'] ?? '') {
                'text' => $this->writeText($node, $run, $inherited),
                'hardBreak' => $run->addTextBreak(),
                'crossRef' => $run->addText($this->escape((string) ($node['attrs']['label'] ?? '?')), $inherited),
                'variable' => $run->addText($this->escape($this->variableText($node)), $inherited),
                'image' => $this->writeImage($node, $run),
                default => $this->writeInline($node['content'] ?? [], $run, $inherited),
            };
        }
    }

    private function variableText(array $node): string
    {
        $key = (string) ($node['attrs']['key'] ?? '');

        return $this->variables[$key] ?? '{{ '.$key.' }}';
    }

    /** @param array<string,mixed> $inherited */
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
                'code' => $font['name'] = $this->font('mono'),
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
            $run->addLink($href, $this->escape($text), $font);

            return;
        }

        $run->addText($this->escape($text), $font);
    }

    /**
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

    /**
     * PHPWord escapes run text for XML itself, so text is handed over raw.
     * What it does NOT do is drop the control characters XML forbids
     * outright — one of those in stored content produces a .docx Word
     * refuses to open, and document JSON comes from importers and the wire.
     * DocumentSchema::normalise() strips them on the way IN; this is the
     * belt for content stored before it did.
     *
     * Not a /u pattern: every byte matched is ASCII, and a unicode pattern
     * returns null — dropping the whole run — on text that is not valid
     * UTF-8.
     */
    private function escape(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    }

    private function font(string $key): string
    {
        $fonts = is_array($this->tokens['fonts'] ?? null) ? $this->tokens['fonts'] : [];
        $value = $fonts[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : 'Calibri';
    }

    private function size(string $key, float $default): float
    {
        $sizes = is_array($this->tokens['sizes'] ?? null) ? $this->tokens['sizes'] : [];
        $value = $sizes[$key] ?? null;
        if (! is_string($value) && ! is_numeric($value)) {
            return $default;
        }
        $points = Converter::cssToPoint((string) $value);

        return $points === null || (float) $points <= 0 ? $default : round((float) $points, 1);
    }

    private function colour(string $key, string $default): string
    {
        $colours = is_array($this->tokens['colours'] ?? null) ? $this->tokens['colours'] : [];
        $value = $colours[$key] ?? null;

        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtoupper(substr($value, 1))
            : $default;
    }

    private function twips(string $length, int $default): int
    {
        $twips = Converter::cssToTwip($length);

        return $twips === null || (int) $twips <= 0 ? $default : (int) round((float) $twips);
    }
}
