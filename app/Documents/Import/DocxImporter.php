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

    /** Directory (relative to the `public` disk) images from THIS import are written to. */
    private string $mediaDirectory = '';

    public function __construct(private DocumentSchema $schema = new DocumentSchema) {}

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

        $this->mediaDirectory = 'documents/'.($mediaKey ?? (string) Str::uuid());

        $content = [];
        foreach (IOFactory::load($path)->getSections() as $section) {
            array_push($content, ...$this->convertElements($section->getElements()));
        }

        $doc = ['type' => 'doc', 'content' => $content];

        return $this->schema->normalise($this->schema->ensureIds($doc));
    }

    /**
     * Convert a run of sibling PHPWord elements into block nodes.
     *
     * Two pieces of state make this a loop rather than a map: consecutive
     * list items are merged into a single list node, and a `PageBreak` is
     * followed in the reader's output by an echo of the very same `w:p` as a
     * break-only paragraph (Reader\Word2007\Document::readWPNode() emits
     * both), which would otherwise land as a stray empty paragraph.
     *
     * @param  array<int,AbstractElement>  $elements
     * @return list<array<string,mixed>>
     */
    private function convertElements(array $elements): array
    {
        $out = [];
        /** @var array{type:string,key:string,items:list<array<string,mixed>>}|null $list */
        $list = null;
        $afterPageBreak = false;

        $flush = function () use (&$list, &$out): void {
            if ($list !== null) {
                $out[] = ['type' => $list['type'], 'content' => $list['items']];
                $list = null;
            }
        };

        foreach ($elements as $element) {
            if ($element instanceof ListItemRun || $element instanceof ListItem) {
                $afterPageBreak = false;
                $item = $this->listItem($element);
                if ($list !== null && $list['key'] !== $item['key']) {
                    $flush();
                }
                $list ??= ['type' => $item['type'], 'key' => $item['key'], 'items' => []];
                $list['items'][] = $item['node'];

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
     * A list item plus the key that decides whether it continues the list
     * before it: two adjacent lists that share a type but not a numbering
     * definition are two lists, not one.
     *
     * @return array{type:string,key:string,node:array<string,mixed>}
     */
    private function listItem(ListItemRun|ListItem $element): array
    {
        $style = $element->getStyle();
        $numStyleName = $style instanceof \PhpOffice\PhpWord\Style\ListItem ? (string) $style->getNumStyle() : '';
        $ordered = $this->isOrderedNumbering($numStyleName, $style);

        $inline = $element instanceof ListItemRun
            ? $this->inlineChildren($element)
            : $this->textNodes($element->getTextObject()->getText(), $this->fontMarks($element->getTextObject()->getFontStyle()));

        return [
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'key' => ($ordered ? 'ol:' : 'ul:').$numStyleName,
            'node' => ['type' => 'listItem', 'content' => [$this->paragraph($inline)]],
        ];
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

        if ($style instanceof \PhpOffice\PhpWord\Style\ListItem) {
            return in_array($style->getListType(), [
                \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER,
                \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER_NESTED,
                \PhpOffice\PhpWord\Style\ListItem::TYPE_ALPHANUM,
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

        foreach ($run->getElements() as $child) {
            if ($child instanceof Image) {
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
        } elseif ($out === []) {
            // Nothing but line breaks (or nothing at all) is Word's way of
            // writing an empty paragraph; keeping the hardBreaks would render
            // as blank lines nobody typed.
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
     * Extract an embedded image onto the public disk and reference it by the
     * `/storage/...` path HtmlRenderer accepts. An image PHPWord could read
     * but this app will not serve (anything outside IMAGE_EXTENSIONS) is
     * skipped rather than stored under a wrong extension.
     *
     * @return list<array<string,mixed>>
     */
    private function convertImage(Image $image): array
    {
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
        $binary = @hex2bin((string) preg_replace('/\s+/', '', $hex));

        return $binary === false || $binary === '' ? null : $binary;
    }

    /** @return list<array<string,mixed>> */
    private function convertUnknown(AbstractElement $element): array
    {
        $text = method_exists($element, 'getText') ? $element->getText() : null;
        if ($text instanceof TextRun) {
            return [$this->paragraph($this->inlineChildren($text))];
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
            $marks = $this->fontMarks($element->getFontStyle());
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

    /** @return list<array<string,mixed>> */
    private function fontMarks(mixed $font): array
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

        return $marks;
    }
}
