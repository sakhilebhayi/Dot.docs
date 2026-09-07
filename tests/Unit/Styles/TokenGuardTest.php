<?php

namespace Tests\Unit\Styles;

use App\Styles\TokenGuard;
use Tests\TestCase;

/**
 * Round 1 finding: CssBuilder interpolated Document Style token values into
 * CSS without validation — a team-owned style's tokens are arbitrary JSON,
 * so a malicious value (e.g. in fonts.body) could break out of a CSS
 * declaration and inject arbitrary rules/selectors. TokenGuard is the
 * narrow allow-list every token value passes through before it reaches a
 * generated <style> tag.
 */
class TokenGuardTest extends TestCase
{
    public const BREAKOUT = "X'; } * { background:url(https://evil) } .y{";

    public function test_colour_accepts_valid_hex_forms(): void
    {
        foreach (['#fff', '#ffff', '#1f2023', '#1f2023ff'] as $valid) {
            $this->assertSame($valid, TokenGuard::colour($valid, '#000000'));
        }
    }

    public function test_colour_rejects_non_hex_and_breakout_payloads(): void
    {
        $this->assertSame('#000000', TokenGuard::colour('red', '#000000'));
        $this->assertSame('#000000', TokenGuard::colour(self::BREAKOUT, '#000000'));
        $this->assertStringNotContainsString('evil', TokenGuard::colour(self::BREAKOUT, '#000000'));
    }

    public function test_font_name_accepts_ordinary_font_names(): void
    {
        foreach (['Source Sans 3', "O'Brien Slab", 'JetBrains Mono'] as $valid) {
            $this->assertSame($valid, TokenGuard::fontName($valid, 'Fallback'));
        }
    }

    public function test_font_name_rejects_breakout_payload(): void
    {
        $this->assertSame('Fallback', TokenGuard::fontName(self::BREAKOUT, 'Fallback'));
        $this->assertStringNotContainsString('evil', TokenGuard::fontName(self::BREAKOUT, 'Fallback'));
    }

    public function test_length_accepts_every_supported_unit(): void
    {
        foreach (['11pt', '10px', '25mm', '2cm', '0.6em', '1.5rem', '100%'] as $valid) {
            $this->assertSame($valid, TokenGuard::length($valid, '0pt'));
        }
    }

    public function test_length_rejects_breakout_payload(): void
    {
        $this->assertSame('0pt', TokenGuard::length(self::BREAKOUT, '0pt'));
        $this->assertStringNotContainsString('evil', TokenGuard::length(self::BREAKOUT, '0pt'));
    }

    public function test_number_accepts_a_finite_float_in_range(): void
    {
        $this->assertSame(1.45, TokenGuard::number(1.45, 1.0));
        $this->assertSame(0.8, TokenGuard::number(0.8, 1.0));
        $this->assertSame(3.0, TokenGuard::number(3.0, 1.0));
    }

    public function test_number_rejects_out_of_range_non_numeric_and_non_finite_values(): void
    {
        $this->assertSame(1.0, TokenGuard::number(0.79, 1.0));
        $this->assertSame(1.0, TokenGuard::number(3.01, 1.0));
        $this->assertSame(1.0, TokenGuard::number('1.45', 1.0));
        $this->assertSame(1.0, TokenGuard::number(NAN, 1.0));
        $this->assertSame(1.0, TokenGuard::number(INF, 1.0));
    }

    public function test_font_import_accepts_a_clean_google_fonts_css2_url(): void
    {
        // Semicolons must be percent-encoded (%3B accepted, raw ';' is not
        // - see the rejection test below) to list multiple weights.
        $url = 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400%3B600&display=swap';
        $this->assertSame($url, TokenGuard::fontImport($url, ''));
    }

    public function test_font_import_rejects_wrong_host_and_breakout_characters(): void
    {
        $this->assertSame('', TokenGuard::fontImport('https://evil.example.com/css2?family=X', ''));
        $this->assertSame('', TokenGuard::fontImport('https://fonts.googleapis.com/css2?family=X"><script>', ''));
        $this->assertSame('', TokenGuard::fontImport("https://fonts.googleapis.com/css2?family=X') } .y{ background:url(https://evil", ''));
        $this->assertSame('', TokenGuard::fontImport('https://fonts.googleapis.com/css2?family=X:wght@400;700', ''));
    }
}
