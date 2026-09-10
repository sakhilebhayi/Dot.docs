<?php

namespace App\Documents\Import;

use App\Documents\Schema\DocumentSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Numbering;
use ZipArchive;

/**
 * DOCX -> Dot.Doc JSON (see App\Documents\Schema\DocumentSchema).
 *
 * This walks PHPWord's element tree rather than going through its HTML
 * writer: the HTML writer flattens `Title` into a styled paragraph and
 * `ListItemRun` into an indented paragraph, so headings and lists — the two
 * structures an import exists to preserve — would be lost. See the mapping
 * table in .ai/rules/documents-io.md.
 */
class DocxImporter
{
    /** Image MIME types accepted out of a .docx, and the extension each is stored under. */
    private const IMAGE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Largest picture, in bytes, this importer will extract out of one .docx. */
    public const MAX_IMAGE_BYTES = 5_242_880;

    /** Most pictures this importer will extract out of one .docx. */
    public const MAX_IMAGES = 50;

    /** Largest single UNCOMPRESSED archive entry, in bytes, this importer will open. */
    public const MAX_ENTRY_BYTES = 52_428_800;

    /** Largest total UNCOMPRESSED size, in bytes, of every entry in one archive. */
    public const MAX_TOTAL_BYTES = 104_857_600;

    /** Directory (relative to the `public` disk) images from THIS import are written to. */
    private string $mediaDirectory = '';

    /** Pictures already extracted during THIS import, against $maxImages. */
    private int $imageCount = 0;

    /**
     * The caps are constructor arguments, not constants, so a caller (and the
     * test suite) can bound a single import harder without a 5 MB fixture.
     */
    public function __construct(
        private DocumentSchema $schema = new DocumentSchema,
        private int $maxImageBytes = self::MAX_IMAGE_BYTES,
        private int $maxImages = self::MAX_IMAGES,
        private int $maxEntryBytes = self::MAX_ENTRY_BYTES,
        private int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ) {}

    /**
     * @param  string  $path  a readable .docx on the local filesystem
     * @param  string|null  $mediaKey  the owning document's uuid — images are extracted to
     *                                 `documents/{mediaKey}/` on the public disk
     * @return array<string,mixed> Dot.Doc JSON
     */
    public function import(string $path, ?string $mediaKey = null): array
    {
        // PhpOffice\PhpWord\Style is a static, process-wide registry, and the
        // reader pushes this file's numbering definitions into it under names
        // ("PHPWordList{numId}") that collide with any file read — or written
        // — earlier in the same process. Without this reset a second import
        // resolves its list types against the first document's numbering.
        Style::resetStyles();

        $this->guardArchiveSize($path);

        $this->mediaDirectory = 'documents/'.($mediaKey ?? (string) Str::uuid());
        $this->imageCount = 0;

        $content = [];
        foreach (IOFactory::load($path)->getSections() as $section) {
            array_push($content, ...$this->convertElements($section->getElements()));
        }

        $doc = ['type' => 'doc', 'content' => $content];

        return $this->schema->normalise($this->schema->ensureIds($doc));
    }

    /**
     * Refuse a decompression bomb BEFORE PhpWord unpacks it.
     *
     * IOFactory::load() unzips the whole archive and DOM-parses it with no
     * size guard of its own, and a PHP memory-limit fatal cannot be caught —
     * so a file inside DocumentImportController's 20 MB upload cap, filled
     * with highly compressible XML, takes the worker down instead of getting
     * the 422 the import path promises. ZipArchive reads each entry's
     * declared uncompressed size straight out of the central directory
     * without inflating anything, which is what makes this cheap enough to
     * run on every import. (A lying central directory buys an attacker
     * little: libzip stops reading an entry at the size it declared.)
     *
     * Not a zip at all is NOT this method's business — IOFactory's own
     * failure is already answered with a 422 by the controller.
     */
    private function guardArchiveSize(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return;
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

            if ($size > $this->maxEntryBytes || $total > $this->maxTotalBytes) {
                $zip->close();

                abort(422, 'This file is too large to import.');
            }
        }

        $zip->close();
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
     * @return list<array<string,mixed>>
     */
    private function convertElements(array $elements): array
    {
        $out = [];
        /** @var list<array{depth:int,type:string,key:string,node:array<string,mixed>}> $items */
        $items = [];
        $afterPageBreak = false;
        $inToc = false;

        $flush = function () use (&$items, &$out): void {
            if ($items !== []) {
                array_push($out, ...$this->buildLists($items));
                $items = [];
            }
        };

        foreach ($elements as $element) {
            if ($inToc) {
                if ($this->isTocEntry($element)) {
                    continue;
                }
                $inToc = false;
            }

            if ($element instanceof PreserveText && $this->tocDepth($element) !== null) {
                $flush();
                $out[] = ['type' => 'toc', 'attrs' => ['depth' => $this->tocDepth($element)]];
                $inToc = true;
                $afterPageBreak = false;

                continue;
            }

            if ($element instanceof ListItemRun || $element instanceof ListItem) {
                $afterPageBreak = false;
                $item = $this->listItem($element);
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
     * @return list<array<string,mixed>>
     */
    private function convertBlock(AbstractElement $element): array
    {
        return match (true) {
            $element instanceof Title => [$this->convertTitle($element)],
            $element instanceof Table => [$this->convertTable($element)],
            $element instanceof TextRun => $this->convertTextRun($element),
            $element instanceof Image => $this->convertImage($element),
            $element instanceof PageBreak => [['type' => 'pageBreak']],
            $element instanceof TextBreak => [$this->paragraph([])],
            default => $this->convertUnknown($element),
        };
    }

    /** @return array<string,mixed> */
    private function convertTitle(Title $title): array
    {
        $depth = (int) $title->getDepth();
        $text = $title->getText();

        return [
            'type' => 'heading',
            // `Title` (depth 0) is a document title, not a level-0 heading;
            // the schema's headings start at 1.
            'attrs' => ['level' => max(1, min(6, $depth === 0 ? 1 : $depth))],
            'content' => $text instanceof TextRun
                ? $this->inlineChildren($text)
                : $this->textNodes((string) $text, []),
        ];
    }

    /**
     * A list item, its Word indent level, and the key that decides whether it
     * continues the list before it: two adjacent lists that share a type but
     * not a numbering definition are two lists, not one.
     *
     * @return array{depth:int,type:string,key:string,node:array<string,mixed>}
     */
    private function listItem(ListItemRun|ListItem $element): array
    {
        $style = $element->getStyle();
        $numStyleName = $style instanceof Style\ListItem ? (string) $style->getNumStyle() : '';
        $ordered = $this->isOrderedNumbering($numStyleName, $style);

        $inline = $element instanceof ListItemRun
            ? $this->inlineChildren($element)
            : $this->textNodes($element->getTextObject()->getText(), $this->fontMarks($element->getTextObject()->getFontStyle()));

        return [
            // Word's `w:ilvl`, which the reader passes straight to
            // addListItemRun() — the ONLY record of nesting in the file.
            'depth' => max(0, (int) $element->getDepth()),
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'key' => ($ordered ? 'ol:' : 'ul:').$numStyleName,
            'node' => ['type' => 'listItem', 'content' => [$this->paragraph($inline)]],
        ];
    }

    /**
     * Rebuild the tree a flat run of Word list paragraphs describes.
     *
     * Word has no nested list element: nesting is a run of sibling paragraphs
     * each carrying its own indent level, and a sub-item is simply an item
     * with a deeper one. This is the inverse of `DocxExporter::writeList()`,
     * which flattens the tree the same way on the way out. Reading it back as
     * one flat list (what this did before) silently destroyed the structure
     * of every nested list on a full export -> re-import round trip.
     *
     * @param  list<array{depth:int,type:string,key:string,node:array<string,mixed>}>  $items
     * @return list<array<string,mixed>>
     */
    private function buildLists(array $items): array
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
     * @param  list<array{depth:int,type:string,key:string,node:array<string,mixed>}>  $items
     * @return array<string,mixed>
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
                    $nodes[] = ['type' => 'listItem', 'content' => [$this->paragraph([])]];
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
    private function isOrderedNumbering(string $numStyleName, mixed $style): bool
    {
        $numbering = $numStyleName === '' ? null : Style::getStyle($numStyleName);
        if ($numbering instanceof Numbering) {
            foreach ($numbering->getLevels() as $level) {
                $format = (string) $level->getFormat();

                return $format !== '' && $format !== 'bullet' && $format !== 'none';
            }
        }

        if ($style instanceof Style\ListItem) {
            return in_array($style->getListType(), [
                Style\ListItem::TYPE_NUMBER,
                Style\ListItem::TYPE_NUMBER_NESTED,
                Style\ListItem::TYPE_ALPHANUM,
            ], true);
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function convertTable(Table $table): array
    {
        $rows = [];
        foreach ($table->getRows() as $index => $row) {
            $rows[] = ['type' => 'tableRow', 'content' => $this->convertRowCells($row, $index === 0 && $this->isHeaderRow($row))];
        }

        return ['type' => 'table', 'content' => $rows];
    }

    private function isHeaderRow(Row $row): bool
    {
        $style = $row->getStyle();

        return $style !== null && method_exists($style, 'isTblHeader') && (bool) $style->isTblHeader();
    }

    /** @return list<array<string,mixed>> */
    private function convertRowCells(Row $row, bool $header): array
    {
        $cells = [];
        foreach ($row->getCells() as $cell) {
            $cells[] = [
                'type' => $header ? 'tableHeader' : 'tableCell',
                'content' => $this->convertElements($cell->getElements()),
            ];
        }

        return $cells;
    }

    /**
     * A `TextRun` is a paragraph, except that Word puts inline images inside
     * one too. An image is a BLOCK in this schema, so the run is split: the
     * inline text before an image becomes a paragraph, the image becomes its
     * own node, and the text after it starts a fresh paragraph.
     *
     * @return list<array<string,mixed>>
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
                    $out[] = $this->paragraph($inline);
                    $inline = [];
                }
                array_push($out, ...$this->convertImage($child));

                continue;
            }
            $inline = array_merge($inline, $this->inlineNodes($child));
        }

        if ($inline !== [] && ! $this->onlyHardBreaks($inline)) {
            $out[] = $this->paragraph($inline);
        } elseif ($out === [] && ! $hadImage) {
            // Nothing but line breaks (or nothing at all) is Word's way of
            // writing an empty paragraph; keeping the hardBreaks would render
            // as blank lines nobody typed. A run that held ONLY a picture
            // this importer refused (too big, or a type it will not serve) is
            // not an empty paragraph the writer typed, so it leaves nothing.
            $out[] = $this->paragraph([]);
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $inline */
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
    private function tocDepth(PreserveText $element): ?int
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
    private function isTocEntry(AbstractElement $element): bool
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

    /**
     * Extract an embedded image onto the public disk and reference it by the
     * `/storage/...` path HtmlRenderer accepts. An image PHPWord could read
     * but this app will not serve (anything outside IMAGE_EXTENSIONS) is
     * skipped rather than stored under a wrong extension.
     *
     * An upload is a stranger's file: a .docx is a zip, so a handful of
     * megabytes of it can carry far more picture than this app should write
     * to disk in one request. Both caps SKIP — a document whose pictures are
     * too big or too many still imports its text.
     *
     * @return list<array<string,mixed>>
     */
    private function convertImage(Image $image): array
    {
        if ($this->imageCount >= $this->maxImages) {
            return [];
        }

        $binary = $this->imageBinary($image);
        if ($binary === null) {
            return [];
        }

        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) && isset($info[2]) ? image_type_to_mime_type($info[2]) : null;
        if ($mime === null || ! isset(self::IMAGE_EXTENSIONS[$mime])) {
            return [];
        }

        $relative = $this->mediaDirectory.'/'.sha1($binary).'.'.self::IMAGE_EXTENSIONS[$mime];
        Storage::disk('public')->put($relative, $binary);
        $this->imageCount++;

        return [['type' => 'image', 'attrs' => ['src' => '/storage/'.$relative]]];
    }

    private function imageBinary(Image $image): ?string
    {
        // getImageStringData() hands back chunk_split()'d hex, which is the
        // only public accessor that works for both a zip:// source (an image
        // still inside the .docx) and a memory image.
        $hex = $image->getImageStringData();
        if (! is_string($hex) || $hex === '') {
            return null;
        }
        // Two hex characters per byte, plus a newline every 76 — so a hex
        // string this long cannot decode to anything within the cap, and the
        // decode (which doubles the memory again) is skipped entirely.
        if (strlen($hex) > $this->maxImageBytes * 3) {
            return null;
        }
        $binary = @hex2bin((string) preg_replace('/\s+/', '', $hex));

        if ($binary === false || $binary === '' || strlen($binary) > $this->maxImageBytes) {
            return null;
        }

        return $binary;
    }

    /** @return list<array<string,mixed>> */
    private function convertUnknown(AbstractElement $element): array
    {
        $text = method_exists($element, 'getText') ? $element->getText() : null;
        if ($text instanceof TextRun) {
            return [$this->paragraph($this->inlineChildren($text))];
        }
        // PreserveText (a Word field, e.g. a header's "{PAGE}") hands back an
        // array of chunks rather than a string.
        if (is_array($text)) {
            $text = implode('', array_filter($text, 'is_string'));
        }
        $text = $this->decode(is_scalar($text) ? (string) $text : '');

        return trim($text) === '' ? [] : [$this->paragraph([['type' => 'text', 'text' => $text]])];
    }

    /**
     * @param  list<array<string,mixed>>  $inline
     * @return array<string,mixed>
     */
    private function paragraph(array $inline): array
    {
        return ['type' => 'paragraph', 'content' => $inline];
    }

    /**
     * @param  TextRun|ListItemRun  $container
     * @return list<array<string,mixed>>
     */
    private function inlineChildren(AbstractElement $container): array
    {
        $out = [];
        foreach ($container->getElements() as $child) {
            $out = array_merge($out, $this->inlineNodes($child));
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function inlineNodes(AbstractElement $element): array
    {
        if ($element instanceof TextBreak) {
            return [['type' => 'hardBreak']];
        }

        if ($element instanceof Link) {
            // withColour: false — Word writes a hyperlink's blue as ordinary
            // character formatting, so reading it back as an authored
            // `textStyle` colour would stamp one onto every imported link.
            $marks = $this->fontMarks($element->getFontStyle(), false);
            $href = (string) $element->getSource();
            if ($href !== '') {
                $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
            }
            $text = $element->getText();

            return $text instanceof TextRun
                ? $this->inlineChildren($text)
                : $this->textNodes((string) $text, $marks);
        }

        if ($element instanceof Text) {
            return $this->textNodes($element->getText(), $this->fontMarks($element->getFontStyle()));
        }

        if ($element instanceof TextRun) {
            return $this->inlineChildren($element);
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            return is_scalar($text) ? $this->textNodes((string) $text, []) : [];
        }

        return [];
    }

    /**
     * @param  list<array<string,mixed>>  $marks
     * @return list<array<string,mixed>>
     */
    private function textNodes(string $text, array $marks): array
    {
        $text = $this->decode($text);
        if ($text === '') {
            return [];
        }
        $node = ['type' => 'text', 'text' => $text];
        if ($marks !== []) {
            $node['marks'] = $marks;
        }

        return [$node];
    }

    /**
     * PHPWord's reader runs every `w:t` through htmlspecialchars(), so text
     * arrives here as "Q&amp;A" and would otherwise be stored — and then
     * escaped a second time by HtmlRenderer — as literal "Q&amp;A".
     */
    private function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The marks a run's character formatting carries. This reads back every
     * property `DocxExporter::writeText()` writes — the two sides are one
     * contract (see .ai/rules/documents-io.md), and a mark this did not read
     * was a mark a round trip silently dropped.
     *
     * @param  bool  $withColour  false for a hyperlink run, whose colour is
     *                            link styling rather than an authored one
     * @return list<array<string,mixed>>
     */
    private function fontMarks(mixed $font, bool $withColour = true): array
    {
        if (! $font instanceof Font) {
            return [];
        }

        $marks = [];
        if ($font->isBold()) {
            $marks[] = ['type' => 'bold'];
        }
        if ($font->isItalic()) {
            $marks[] = ['type' => 'italic'];
        }
        $underline = $font->getUnderline();
        if (is_string($underline) && $underline !== '' && $underline !== Font::UNDERLINE_NONE) {
            $marks[] = ['type' => 'underline'];
        }
        if ($font->isStrikethrough() || $font->isDoubleStrikethrough()) {
            $marks[] = ['type' => 'strike'];
        }
        if ($this->isMonospaced($font->getName())) {
            $marks[] = ['type' => 'code'];
        }
        if ($this->isHighlighted($font)) {
            $marks[] = ['type' => 'highlight'];
        }
        if ($font->isSuperScript()) {
            $marks[] = ['type' => 'superscript'];
        }
        if ($font->isSubScript()) {
            $marks[] = ['type' => 'subscript'];
        }

        $colour = $withColour ? $font->getColor() : null;
        if (is_string($colour) && preg_match('/^[0-9a-fA-F]{6}$/', $colour) === 1) {
            $marks[] = ['type' => 'textStyle', 'attrs' => ['color' => '#'.strtolower($colour)]];
        }

        return $marks;
    }

    /**
     * Word's own highlight (`w:highlight`, which PhpWord's reader calls
     * fgColor) — what DocxExporter writes for a `highlight` mark. A run
     * shading colour (bgColor) counts too, since that is how some other
     * writers mark up highlighted text.
     */
    private function isHighlighted(Font $font): bool
    {
        foreach ([$font->getFgColor(), $font->getBgColor()] as $value) {
            if (is_string($value) && $value !== '' && strtolower($value) !== 'none') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a run's font is the monospaced one, i.e. a `code` mark. There
     * is no mono flag in OOXML, only the font name, and the name this app
     * writes comes from the document's own `fonts.mono` style token (which a
     * .docx does not carry back) — so this matches the families those tokens
     * and other writers actually use.
     */
    private function isMonospaced(mixed $name): bool
    {
        return is_string($name) && preg_match('/mono|consolas|courier|menlo|monaco/i', $name) === 1;
    }
}
