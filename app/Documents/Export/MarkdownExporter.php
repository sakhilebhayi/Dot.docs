<?php

namespace App\Documents\Export;

use App\Documents\Schema\DocumentSchema;

/**
 * Dot.Doc JSON -> Markdown.
 *
 * This walks the JSON directly instead of rendering HTML and running it
 * through league/html-to-markdown: that converter has no table handler, so a
 * document's tables come out as one run-on line of cell text, and the
 * document-only nodes (`crossRef`, `variable`, `toc`) have no HTML shape it
 * could recognise either. Walking the JSON is also the only way to keep
 * block order stable, which matters because the export is a round-trip
 * partner for MarkdownImporter.
 */
class MarkdownExporter
{
    private const BULLET = '- ';

    public function __construct(private DocumentSchema $schema = new DocumentSchema) {}

    /** @param array<string,mixed> $json Dot.Doc JSON */
    public function export(array $json): string
    {
        $content = $json['content'] ?? [];
        $blocks = $this->blocks(is_array($content) ? $content : []);

        return $blocks === [] ? '' : implode("\n\n", $blocks)."\n";
    }

    /**
     * @param  array<int,mixed>  $nodes
     * @return list<string> one entry per emitted block, blank-line separated by the caller
     */
    private function blocks(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $block = $this->block($node);
            if ($block !== null && trim($block) !== '') {
                $out[] = $block;
            }
        }

        return $out;
    }

    private function block(array $node): ?string
    {
        return match ($node['type'] ?? '') {
            'heading' => $this->heading($node),
            'paragraph' => $this->inline($node['content'] ?? []),
            'bulletList' => $this->list($node, false),
            'orderedList' => $this->list($node, true),
            'taskList' => $this->taskList($node),
            'blockquote' => $this->prefixLines(implode("\n\n", $this->blocks($node['content'] ?? [])), '> '),
            'callout' => $this->prefixLines(implode("\n\n", $this->blocks($node['content'] ?? [])), '> '),
            'codeBlock' => $this->codeBlock($node),
            'horizontalRule' => '---',
            'image' => $this->image($node),
            'figure' => implode("\n\n", $this->blocks($node['content'] ?? [])),
            'caption' => '*'.$this->inline($node['content'] ?? []).'*',
            'table' => $this->table($node),
            'columns', 'column' => implode("\n\n", $this->blocks($node['content'] ?? [])),
            // A table of contents is generated from the document's own
            // headings; in Markdown it would be a stale copy of them.
            'toc', 'pageBreak', 'sectionBreak' => null,
            default => $this->inline($node['content'] ?? []),
        };
    }

    private function heading(array $node): string
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));

        return str_repeat('#', $level).' '.$this->inline($node['content'] ?? []);
    }

    private function codeBlock(array $node): string
    {
        $language = $node['attrs']['language'] ?? '';
        $language = is_string($language) ? $language : '';

        return '```'.$language."\n".$this->schema->plainText($node)."\n".'```';
    }

    private function image(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        $src = is_string($attrs['src'] ?? null) ? $attrs['src'] : '';
        $alt = is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '';

        return $src === '' ? '' : '!['.$alt.']('.$src.')';
    }

    private function list(array $node, bool $ordered): string
    {
        $start = (int) ($node['attrs']['start'] ?? 1);
        $lines = [];
        $index = max(1, $start);
        foreach ($node['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $marker = $ordered ? $index.'. ' : self::BULLET;
            $lines[] = $this->listItem($item, $marker);
            $index++;
        }

        return implode("\n", $lines);
    }

    private function taskList(array $node): string
    {
        $lines = [];
        foreach ($node['content'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $lines[] = $this->listItem($item, ($item['attrs']['checked'] ?? false) ? '- [x] ' : '- [ ] ');
        }

        return implode("\n", $lines);
    }

    /**
     * A list item's first block sits on the marker line; every later block
     * (a second paragraph, a nested list) is indented under it by the width
     * of the marker, which is what keeps a nested list nested when the
     * Markdown is parsed back.
     */
    private function listItem(array $item, string $marker): string
    {
        $blocks = $this->blocks($item['content'] ?? []);
        if ($blocks === []) {
            return rtrim($marker);
        }

        $indent = str_repeat(' ', mb_strlen($marker));
        $first = array_shift($blocks);
        $line = $marker.$first;
        foreach ($blocks as $block) {
            $line .= "\n".$this->prefixLines($block, $indent);
        }

        return $line;
    }

    private function prefixLines(string $text, string $prefix): string
    {
        return implode("\n", array_map(
            fn (string $line): string => rtrim($prefix.$line),
            explode("\n", $text)
        ));
    }

    /**
     * A GFM pipe table. Markdown has no body-only table: the first row is
     * always the header, so a table whose first row is not marked as one is
     * still promoted to the header row rather than losing that row to a
     * synthetic blank one.
     */
    private function table(array $node): string
    {
        $rows = [];
        foreach ($node['content'] ?? [] as $row) {
            if (! is_array($row) || ($row['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $cells = [];
            foreach ($row['content'] ?? [] as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $cells[] = $this->cellText($cell);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $lines = [];
        foreach ($rows as $index => $cells) {
            $cells = array_pad($cells, $width, '');
            $lines[] = '| '.implode(' | ', $cells).' |';
            if ($index === 0) {
                $lines[] = '| '.implode(' | ', array_fill(0, $width, '---')).' |';
            }
        }

        return implode("\n", $lines);
    }

    /** A cell collapses to one line: a pipe table row cannot contain one. */
    private function cellText(array $cell): string
    {
        $blocks = $this->blocks($cell['content'] ?? []);
        $text = implode(' ', array_map(fn (string $b): string => str_replace("\n", ' ', $b), $blocks));

        return trim(str_replace('|', '\\|', $text));
    }

    /** @param array<int,mixed> $nodes */
    private function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $out .= match ($node['type'] ?? '') {
                'text' => $this->marked($node),
                'hardBreak' => "  \n",
                // The label is what the reader sees on the page; a Markdown
                // file has nowhere to resolve a block id against.
                'crossRef' => (string) ($node['attrs']['label'] ?? '?'),
                'variable' => '{{ '.($node['attrs']['key'] ?? '').' }}',
                'image' => $this->image($node),
                default => $this->inline($node['content'] ?? []),
            };
        }

        return $out;
    }

    private function marked(array $node): string
    {
        $text = (string) ($node['text'] ?? '');
        if ($text === '') {
            return '';
        }

        $marks = [];
        foreach ($node['marks'] ?? [] as $mark) {
            if (is_array($mark) && isset($mark['type'])) {
                $marks[$mark['type']] = $mark;
            }
        }

        // Applied innermost-first, so a bold link comes out as [**text**](href)
        // rather than **[text**](href).
        if (isset($marks['code'])) {
            $text = '`'.$text.'`';
        }
        if (isset($marks['italic'])) {
            $text = '*'.$text.'*';
        }
        if (isset($marks['bold'])) {
            $text = '**'.$text.'**';
        }
        if (isset($marks['strike'])) {
            $text = '~~'.$text.'~~';
        }
        if (isset($marks['link'])) {
            $href = $marks['link']['attrs']['href'] ?? '';
            $text = '['.$text.']('.(is_string($href) ? $href : '').')';
        }

        return $text;
    }
}
