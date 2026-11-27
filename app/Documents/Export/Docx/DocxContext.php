<?php

namespace App\Documents\Export\Docx;

use PhpOffice\PhpWord\Shared\Converter;

/**
 * What one DOCX export knows about the document it is writing: the resolved
 * DocumentStyle tokens, the document's variables, the Outline numbers, and
 * the two facts that change how individual elements are written ($hasToc,
 * $mediaOwner). Built once per export by DocxExporter and shared by every
 * writer, together with the token lookups they all use.
 *
 * $hasToc is whether this document contains a `toc` node. PhpWord's TOC
 * writer reads every registered Title back with
 * `writeText($title->getText())`, which TypeErrors on the TextRun
 * DocxBlockWriter::writeHeading() would rather use — so a document with a
 * table of contents writes plain-string headings instead.
 */
final class DocxContext
{
    /**
     * @param  array<string,mixed>  $tokens  the resolved DocumentStyle tokens
     * @param  array<string,string>  $variables  the document's variables, as text
     * @param  array<string,string>  $numbers  block id => outline number
     * @param  string|null  $mediaOwner  the uuid whose /media/ folder this export may read (R2-1; DocumentMedia)
     */
    public function __construct(
        public readonly array $tokens = [],
        public readonly array $variables = [],
        public readonly array $numbers = [],
        public readonly bool $hasToc = false,
        public readonly ?string $mediaOwner = null,
    ) {}

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
    public function escape(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    }

    public function font(string $key): string
    {
        $fonts = is_array($this->tokens['fonts'] ?? null) ? $this->tokens['fonts'] : [];
        $value = $fonts[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : 'Calibri';
    }

    /**
     * A size token in points. Converter::cssToPoint() hands back null for a
     * value it cannot parse (its docblock says float), which the `<= 0` test
     * already sends to the default: (float) null is 0.
     */
    public function size(string $key, float $default): float
    {
        $sizes = is_array($this->tokens['sizes'] ?? null) ? $this->tokens['sizes'] : [];
        $value = $sizes[$key] ?? null;
        if (! is_string($value) && ! is_numeric($value)) {
            return $default;
        }
        $points = (float) Converter::cssToPoint((string) $value);

        return $points <= 0 ? $default : round($points, 1);
    }

    public function colour(string $key, string $default): string
    {
        $colours = is_array($this->tokens['colours'] ?? null) ? $this->tokens['colours'] : [];
        $value = $colours[$key] ?? null;

        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtoupper(substr($value, 1))
            : $default;
    }

    /**
     * A CSS length in twips. Converter::cssToTwip() of an unparseable length
     * is 0 (null arithmetic), never null, so the `<= 0` test is the whole
     * guard.
     */
    public function twips(string $length, int $default): int
    {
        $twips = Converter::cssToTwip($length);

        return (int) $twips <= 0 ? $default : (int) round((float) $twips);
    }
}
