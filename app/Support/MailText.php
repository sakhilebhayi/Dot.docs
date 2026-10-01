<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Somebody else's words, made safe to put in an email this app sends.
 */
class MailText
{
    /**
     * For a MailMessage line. Wrap the finished line in an HtmlString.
     *
     * Mail lines are rendered as Markdown, so a comment or a display name
     * containing `[text](url)` would otherwise arrive as a live link of the
     * writer's choosing. Every character that is not a letter, a digit or a
     * space is turned into a numeric HTML entity: Markdown prints an entity
     * as the literal character and never reads it as syntax, and Laravel
     * decodes entities when it builds the text/plain part - so both parts
     * show the plain words. (Backslash-escaping would fix the HTML part and
     * leave the backslashes visible in the plain-text one.)
     */
    public static function plain(string $text, ?int $limit = null): string
    {
        $text = self::clean($text, $limit);

        return (string) preg_replace_callback(
            '/[^\p{L}\p{N} ]/u',
            fn (array $match) => '&#'.mb_ord($match[0]).';',
            $text,
        );
    }

    /**
     * For a subject line, which is plain text: no markup to escape, but it
     * is the first thing a mail client shows, so it is bounded and stripped
     * of line breaks and invisible control characters.
     */
    public static function subject(string $text, int $limit): string
    {
        return self::clean($text, $limit);
    }

    private static function clean(string $text, ?int $limit): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace('/\p{C}+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $limit === null ? $text : Str::limit($text, $limit);
    }
}
