<?php

namespace App\Brand;

use App\Models\BrandKit;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;

/**
 * A company's brand kit, and what it does to its documents (R2-3c / F-13; owner decisions 2026-11-27: new documents
 * on, a per-document switch; letterhead in PDF, print and Word).
 *
 * A kit is ONE per company. A document uses it when its `brand_kit_id` points at its own company's kit: new documents
 * get it, existing ones are switched on in Settings. Using it overlays the document's style (StyleEngine::resolve):
 * heading and accent colours, heading and body fonts, and the page footer - so the editor, the published page, the
 * PDF and the Word file all follow. The letterhead (logo, name, contact line) and the disclaimer are added by the
 * PDF/print and Word renderers, never on screen.
 */
class Brand
{
    /**
     * The fonts a kit may choose: each is on Google Fonts (the CSP allows fonts.googleapis.com / gstatic.com).
     *
     * @var list<string>
     */
    public const FONTS = ['Source Sans 3', 'Source Serif 4', 'Inter', 'Roboto', 'Open Sans', 'Lato', 'Work Sans', 'IBM Plex Sans', 'IBM Plex Serif', 'Merriweather', 'Lora', 'Playfair Display'];

    /** The mono face every style keeps for code. */
    private const MONO = 'JetBrains Mono';

    /** Contrast a heading or accent colour must reach on the white page (WCAG AA for text). */
    public const MIN_CONTRAST = 4.5;

    public const LOGO_DISK = 'local';

    public static function forTeam(?Team $team): ?BrandKit
    {
        return $team === null ? null : BrandKit::where('team_id', $team->id)->first();
    }

    /** The kit this document uses, or null: switched on, and its OWN company's kit (a moved document does not carry another company's brand). */
    public static function forDocument(Document $doc): ?BrandKit
    {
        if ($doc->brand_kit_id === null || $doc->team_id === null) {
            return null;
        }

        return BrandKit::where('id', $doc->brand_kit_id)->where('team_id', $doc->team_id)->first();
    }

    /** $style with the kit's colours, fonts and footer laid over it. Not saved: a fresh, unsaved copy. */
    public static function apply(DocumentStyle $style, BrandKit $kit): DocumentStyle
    {
        $tokens = $style->tokens;
        $colours = is_array($kit->colours) ? $kit->colours : [];
        $fonts = is_array($kit->fonts) ? $kit->fonts : [];

        foreach (['heading', 'accent'] as $key) {
            if (self::isColour($colours[$key] ?? null)) {
                $tokens['colours'][$key] = $colours[$key];
            }
        }

        $heading = in_array($fonts['heading'] ?? null, self::FONTS, true) ? $fonts['heading'] : null;
        $body = in_array($fonts['body'] ?? null, self::FONTS, true) ? $fonts['body'] : null;
        if ($heading !== null || $body !== null) {
            $tokens['fonts']['heading'] = $heading ?? ($tokens['fonts']['heading'] ?? 'Source Sans 3');
            $tokens['fonts']['body'] = $body ?? ($tokens['fonts']['body'] ?? 'Source Serif 4');
            $tokens['fonts']['mono'] = self::MONO;
            $tokens['fonts']['import'] = self::fontImport([$tokens['fonts']['heading'], $tokens['fonts']['body'], self::MONO]);
        }

        $footer = is_array($kit->footer) ? trim((string) ($kit->footer['text'] ?? '')) : '';
        if ($footer !== '') {
            $tokens['page']['footer'] = $footer;
        }

        return new DocumentStyle([
            'key' => $style->key,
            'name' => $style->name,
            'category' => $style->category,
            'tokens' => $tokens,
            'is_system' => $style->is_system,
            'team_id' => $style->team_id,
        ]);
    }

    /** One Google Fonts stylesheet for these families. Only names from FONTS (and the mono) ever reach the URL. */
    public static function fontImport(array $families): string
    {
        $parts = [];
        foreach (array_unique($families) as $family) {
            if (in_array($family, self::FONTS, true) || $family === self::MONO) {
                $parts[] = 'family='.str_replace(' ', '+', $family).':wght@400%3B600%3B700';
            }
        }

        return 'https://fonts.googleapis.com/css2?'.implode('&', $parts).'&display=swap';
    }

    public static function isColour(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** WCAG contrast of a #rrggbb colour against white. */
    public static function contrastOnWhite(string $hex): float
    {
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));

        return round(1.05 / ($l + 0.05), 2);
    }

    /** The logo as a data: URI for the PDF (dompdf may not fetch anything), or null. */
    public static function logoDataUri(BrandKit $kit): ?string
    {
        $path = self::logoPath($kit);
        if ($path === null) {
            return null;
        }

        $mime = mime_content_type($path) ?: '';
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /** The logo file on disk, or null. Only inside this kit's own folder. */
    public static function logoPath(BrandKit $kit): ?string
    {
        if (blank($kit->logo_path) || ! str_starts_with((string) $kit->logo_path, 'brand/'.$kit->team_id.'/')) {
            return null;
        }

        $disk = Storage::disk(self::LOGO_DISK);

        return $disk->exists((string) $kit->logo_path) ? $disk->path((string) $kit->logo_path) : null;
    }

    /** The contact line under the letterhead name: the parts that are filled, in a fixed order. */
    public static function contactLine(BrandKit $kit): string
    {
        $contact = is_array($kit->contact) ? $kit->contact : [];

        return implode(' · ', array_values(array_filter(array_map(
            fn (string $key) => trim((string) ($contact[$key] ?? '')),
            ['address', 'phone', 'email', 'website']
        ))));
    }
}
