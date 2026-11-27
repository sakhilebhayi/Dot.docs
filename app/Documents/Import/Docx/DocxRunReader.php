<?php

namespace App\Documents\Import\Docx;

use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Style\Font;

/**
 * Inline content: PHPWord text, links and line breaks -> `text` and
 * `hardBreak` nodes, with the marks a run's character formatting carries.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 * @phpstan-import-type PmMark from DocumentSchema
 */
final class DocxRunReader
{
    /**
     * @param  list<PmNode>  $inline
     * @return PmNode
     */
    public function paragraph(array $inline): array
    {
        return ['type' => 'paragraph', 'content' => $inline];
    }

    /**
     * A run's children, flattened (a ListItemRun is a TextRun too).
     *
     * @return list<PmNode>
     */
    public function inlineChildren(TextRun $container): array
    {
        $out = [];
        foreach ($container->getElements() as $child) {
            $out = array_merge($out, $this->inlineNodes($child));
        }

        return $out;
    }

    /** @return list<PmNode> */
    public function inlineNodes(AbstractElement $element): array
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

            return $this->runOrText($element->getText(), $marks);
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
     * The inline nodes of a `Title` or `Link` text, which PHPWord holds as
     * either a string or a TextRun (its getText() docblocks say string only):
     * a run keeps its own formatting, a string takes $marks.
     *
     * @param  list<PmMark>  $marks
     * @return list<PmNode>
     */
    public function runOrText(mixed $text, array $marks): array
    {
        return $text instanceof TextRun
            ? $this->inlineChildren($text)
            : $this->textNodes(is_scalar($text) ? (string) $text : '', $marks);
    }

    /**
     * @param  list<PmMark>  $marks
     * @return list<PmNode>
     */
    public function textNodes(string $text, array $marks): array
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
    public function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The marks a run's character formatting carries. This reads back every
     * property `Export\Docx\DocxRunWriter::writeText()` writes — the two sides
     * are one contract (see .ai/rules/documents-io.md), and a mark this did
     * not read was a mark a round trip silently dropped.
     *
     * @param  bool  $withColour  false for a hyperlink run, whose colour is
     *                            link styling rather than an authored one
     * @return list<PmMark>
     */
    public function fontMarks(mixed $font, bool $withColour = true): array
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
        // (string): PhpWord types its font getters as string, but a property
        // the reader never set is null.
        $underline = (string) $font->getUnderline();
        if ($underline !== '' && $underline !== Font::UNDERLINE_NONE) {
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
            // (string): an unset colour is null whatever the docblock says.
            $value = (string) $value;
            if ($value !== '' && strtolower($value) !== 'none') {
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
