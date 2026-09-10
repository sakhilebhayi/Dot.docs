<?php

namespace App\Documents\Import;

use App\Documents\Schema\DocumentSchema;

/**
 * Plain text -> Dot.Doc JSON (see App\Documents\Schema\DocumentSchema).
 *
 * A .txt file has no markup to read, only layout: a blank line separates
 * paragraphs and a single newline is a line break inside one. It is
 * deliberately NOT run through MarkdownImporter — a plain-text file that
 * happens to start a line with "# " or "* " is not asking for a heading or
 * a bullet, and commonmark would silently rewrite the writer's text.
 */
class PlainTextImporter
{
    public function __construct(private DocumentSchema $schema = new DocumentSchema) {}

    /** @return array<string,mixed> Dot.Doc JSON */
    public function import(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $content = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $chunk) {
            $chunk = trim($chunk, "\n");
            if (trim($chunk) === '') {
                continue;
            }

            $inline = [];
            foreach (explode("\n", $chunk) as $index => $line) {
                if ($index > 0) {
                    $inline[] = ['type' => 'hardBreak'];
                }
                if ($line !== '') {
                    $inline[] = ['type' => 'text', 'text' => $line];
                }
            }
            $content[] = ['type' => 'paragraph', 'content' => $inline];
        }

        $doc = ['type' => 'doc', 'content' => $content];

        return $this->schema->normalise($this->schema->ensureIds($doc));
    }
}
