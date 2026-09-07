<?php

namespace App\Styles;

/**
 * Validates every free-form Document Style token value before CssBuilder
 * interpolates it into a CSS string. A team-owned DocumentStyle's tokens
 * are arbitrary JSON supplied by a team member (unlike the fourteen system
 * styles, which are literal PHP arrays this codebase controls), so a
 * malicious or malformed value must never reach the generated <style> tag
 * verbatim — it could break out of a CSS declaration and inject arbitrary
 * rules/selectors. Every accept-pattern here is deliberately narrow;
 * anything that doesn't match is replaced with the caller-supplied
 * fallback (in practice, the equivalent 'report' style token).
 */
class TokenGuard
{
    public static function colour(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return $value;
        }

        return $fallback;
    }

    public static function fontName(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match("/^[A-Za-z0-9 \\-']{1,60}$/", $value)) {
            return $value;
        }

        return $fallback;
    }

    public static function length(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^\d+(\.\d+)?(pt|px|mm|cm|em|rem|%)$/', $value)) {
            return $value;
        }

        return $fallback;
    }

    /** Accepts a finite float within [$min, $max] (defaults to the 0.8-3 line-height range). */
    public static function number(mixed $value, float $fallback, float $min = 0.8, float $max = 3.0): float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            if (is_finite($number) && $number >= $min && $number <= $max) {
                return $number;
            }
        }

        return $fallback;
    }

    public static function fontImport(mixed $value, string $fallback): string
    {
        if (
            is_string($value)
            && str_starts_with($value, 'https://fonts.googleapis.com/css2?')
            && ! preg_match('/[<"\'\);]/', $value)
        ) {
            return $value;
        }

        return $fallback;
    }
}
