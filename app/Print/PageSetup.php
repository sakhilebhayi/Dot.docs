<?php

namespace App\Print;

use App\Models\Document;
use App\Models\DocumentStyle;
use App\Styles\TokenGuard;

/**
 * A document's resolved print/PDF page shape: paper size, orientation,
 * margins and the header/footer templates. fromDocument() merges the
 * document's own $doc->page_setup (Task 1's per-document override, set via
 * DocumentSettings::savePageSetup()) OVER its DocumentStyle's 'page' tokens
 * (see DocumentStyleSeeder) - the document always wins when it sets a
 * value, and unknown/invalid size or orientation values fall back to the
 * defaults below rather than reaching dompdf's @page rule unvalidated.
 */
final class PageSetup
{
    /** @var list<string> */
    public const SIZES = ['A4', 'A3', 'Letter'];

    /** @var list<string> */
    public const ORIENTATIONS = ['portrait', 'landscape'];

    private const DEFAULT_SIZE = 'A4';

    private const DEFAULT_ORIENTATION = 'portrait';

    /** @var array{top:string,right:string,bottom:string,left:string} */
    private const DEFAULT_MARGINS = ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'];

    /**
     * @param  array{top:string,right:string,bottom:string,left:string}  $margins
     */
    public function __construct(
        public readonly string $size,
        public readonly string $orientation,
        public readonly array $margins,
        public readonly string $header,
        public readonly string $footer,
    ) {}

    public static function fromDocument(Document $doc, DocumentStyle $style): self
    {
        /** @var array<string,mixed> $styleTokens */
        $styleTokens = $style->tokens['page'] ?? [];
        /** @var array<string,mixed> $override */
        $override = $doc->page_setup ?? [];

        $merged = array_merge($styleTokens, $override);

        $size = $merged['size'] ?? null;
        $size = (is_string($size) && in_array($size, self::SIZES, true)) ? $size : self::DEFAULT_SIZE;

        $orientation = $merged['orientation'] ?? null;
        $orientation = (is_string($orientation) && in_array($orientation, self::ORIENTATIONS, true))
            ? $orientation
            : self::DEFAULT_ORIENTATION;

        $styleMargins = is_array($styleTokens['margins'] ?? null) ? $styleTokens['margins'] : [];
        $overrideMargins = is_array($override['margins'] ?? null) ? $override['margins'] : [];
        $margins = array_merge($styleMargins, $overrideMargins);

        $resolvedMargins = [
            'top' => TokenGuard::length($margins['top'] ?? null, self::DEFAULT_MARGINS['top']),
            'right' => TokenGuard::length($margins['right'] ?? null, self::DEFAULT_MARGINS['right']),
            'bottom' => TokenGuard::length($margins['bottom'] ?? null, self::DEFAULT_MARGINS['bottom']),
            'left' => TokenGuard::length($margins['left'] ?? null, self::DEFAULT_MARGINS['left']),
        ];

        $header = is_string($merged['header'] ?? null) ? $merged['header'] : '';
        $footer = is_string($merged['footer'] ?? null) ? $merged['footer'] : '';

        return new self($size, $orientation, $resolvedMargins, $header, $footer);
    }

    /** @return array{size:string,orientation:string,margins:array{top:string,right:string,bottom:string,left:string},header:string,footer:string} */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'orientation' => $this->orientation,
            'margins' => $this->margins,
            'header' => $this->header,
            'footer' => $this->footer,
        ];
    }
}
