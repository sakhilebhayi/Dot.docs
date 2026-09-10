<?php

namespace App\Documents\Import;

/**
 * The post-pass that turns authored plain text into the two document nodes
 * Markdown has no syntax for.
 *
 * `{{ key }}` anywhere in a text run becomes a `variable` inline node (the
 * shape App\Documents\Render\HtmlRenderer::renderVariable() resolves against
 * a document's `variables`, and the shape MarkdownExporter writes back out),
 * and a paragraph whose whole text is `[[toc]]` becomes a `toc` block.
 *
 * It runs inside MarkdownImporter rather than in HtmlToJson: `{{ }}` and
 * `[[toc]]` are Markdown-authoring conventions, and an imported .html file or
 * a legacy content blob holding those characters means them literally.
 */
final class VariableTagger
{
    /**
     * A variable key: a letter first, then letters/digits/underscore. Anything
     * else inside the braces is left alone as ordinary text, so prose that
     * happens to contain `{{` does not silently lose characters.
     */
    private const KEY = '/\{\{\s*([A-Za-z][A-Za-z0-9_]{0,39})\s*\}\}/';

    private const TOC_MARKER = '[[toc]]';

    /**
     * @param  array<string,mixed>  $doc
     * @return array<string,mixed>
     */
    public function tag(array $doc): array
    {
        $doc['content'] = $this->tagChildren($doc['content'] ?? null);

        return $doc;
    }

    /** @return list<array<string,mixed>> */
    private function tagChildren(mixed $content): array
    {
        $out = [];
        foreach (is_array($content) ? $content : [] as $child) {
            if (! is_array($child)) {
                continue;
            }
            foreach ($this->tagNode($child) as $node) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return list<array<string,mixed>>
     */
    private function tagNode(array $node): array
    {
        if (($node['type'] ?? '') === 'text') {
            return $this->splitText($node);
        }

        if ($this->isTocMarker($node)) {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            // The paragraph's own block id is reused when it has one; a toc
            // with no id gets one from DocumentSchema::ensureIds() afterwards.
            $id = is_string($attrs['id'] ?? null) ? ['id' => $attrs['id']] : [];

            return [['type' => 'toc', 'attrs' => $id + ['depth' => 3]]];
        }

        if (array_key_exists('content', $node)) {
            $node['content'] = $this->tagChildren($node['content']);
        }

        return [$node];
    }

    /** @param array<string,mixed> $node */
    private function isTocMarker(array $node): bool
    {
        if (($node['type'] ?? '') !== 'paragraph') {
            return false;
        }

        $children = is_array($node['content'] ?? null) ? $node['content'] : [];
        $text = '';
        foreach ($children as $child) {
            if (($child['type'] ?? '') !== 'text') {
                return false;
            }
            $text .= $child['text'] ?? '';
        }

        return strtolower(trim($text)) === self::TOC_MARKER;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return list<array<string,mixed>>
     */
    private function splitText(array $node): array
    {
        $text = $node['text'] ?? '';
        if (! is_string($text) || ! str_contains($text, '{{')) {
            return [$node];
        }

        if (preg_match_all(self::KEY, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [$node];
        }

        $out = [];
        $offset = 0;
        foreach ($matches[0] as $i => [$whole, $at]) {
            if ($at > $offset) {
                $out[] = $this->textLike($node, substr($text, $offset, $at - $offset));
            }
            // A variable carries no marks: HtmlRenderer resolves it to a value
            // rather than wrapping it, so a mark here would be dropped anyway.
            $out[] = ['type' => 'variable', 'attrs' => ['key' => $matches[1][$i][0]]];
            $offset = $at + strlen($whole);
        }

        if ($offset < strlen($text)) {
            $out[] = $this->textLike($node, substr($text, $offset));
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    private function textLike(array $node, string $text): array
    {
        $node['text'] = $text;

        return $node;
    }
}
