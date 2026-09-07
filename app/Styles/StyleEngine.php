<?php

namespace App\Styles;

use App\Models\Document;
use App\Models\DocumentStyle;
use Database\Seeders\DocumentStyleSeeder;

/**
 * Resolves a document's style and turns its token array into CSS for the
 * canvas (editor) and print (export/PDF) surfaces. See DocumentStyleSeeder
 * for the fourteen system styles and the full token shape.
 */
class StyleEngine
{
    /**
     * Team style (by the document's style_key) takes priority over the
     * system style of the same key; falls back to the 'report' system
     * style if the document's style_key doesn't resolve to anything.
     */
    public function resolve(Document $doc): DocumentStyle
    {
        return DocumentStyle::resolve($doc->style_key, $doc->team_id)
            ?? DocumentStyle::resolve('report')
            ?? $this->unseededReportFallback();
    }

    /**
     * DocumentStyleSeeder::STYLES is the source of truth for the 'report'
     * style, so we can build it in-memory when the database hasn't been
     * seeded yet (fresh test databases, a not-yet-migrated environment).
     */
    private function unseededReportFallback(): DocumentStyle
    {
        $report = DocumentStyleSeeder::STYLES['report'];

        return new DocumentStyle([
            'key' => 'report',
            'name' => $report['name'],
            'category' => $report['category'],
            'tokens' => $report['tokens'],
            'is_system' => true,
        ]);
    }

    public function css(DocumentStyle $style, string $mode = 'canvas'): string
    {
        return (new CssBuilder($style, $mode))->build();
    }

    /** @return list<string> The fourteen system style keys. */
    public static function systemKeys(): array
    {
        return array_keys(DocumentStyleSeeder::STYLES);
    }
}
