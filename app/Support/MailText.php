<?php

namespace App\Support;

use Illuminate\Support\Str;

class MailText
{
    /**
     * Somebody else's text, made safe to drop into a MailMessage line.
     *
     * Mail lines are rendered as Markdown, so a comment or a display name
     * containing `[text](url)` would otherwise arrive as a live link of the
     * writer's choosing, inside an email that carries this app's name. Tags
     * are stripped, whitespace collapsed, and every Markdown-significant
     * character backslash-escaped so the words arrive as plain words.
     */
    public static function plain(string $text, ?int $limit = null): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($limit !== null) {
            $text = Str::limit($text, $limit);
        }

        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/', '\\\\$1', $text);
    }
}
