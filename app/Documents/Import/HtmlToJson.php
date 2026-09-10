<?php

namespace App\Documents\Import;

use App\Documents\Schema\DocumentSchema;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Converts a legacy HTML document blob into Dot.Doc JSON
 * (see App\Documents\Schema\DocumentSchema).
 */
class HtmlToJson
{
    private const BLOCK_WRAPPING_PARENTS = ['li', 'td', 'th', 'blockquote'];

    /**
     * URL schemes a link may carry. An .html import is a stranger's file and
     * a legacy blob is whatever was stored years ago, so an href is treated
     * exactly as MarkdownImporter's commonmark treats one
     * (`allow_unsafe_links => false`): anything else — `javascript:`,
     * `data:`, `vbscript:` — loses the ATTRIBUTE, never the text.
     */
    private const LINK_SCHEMES = ['http', 'https', 'mailto'];

    /** Schemes an `<img src>` may carry; `/`-relative paths are also allowed. */
    private const IMAGE_SCHEMES = ['http', 'https'];

    public function convert(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        $content = [];
        if ($body !== null) {
            foreach ($body->childNodes as $child) {
                $node = $this->convertBlockNode($child);
                if ($node !== null) {
                    $content[] = $node;
                }
            }
        }

        $doc = ['type' => 'doc', 'content' => $content];

        // normalise() after ensureIds(): HTML routinely carries structurally
        // thin nodes (an empty <table>, a <ul> with no <li>, an <li> that
        // opens with a nested list) which DocumentSchema::validate() accepts
        // but ProseMirror's content expressions do not — and the editor runs
        // with enableContentCheck, so one of them opens the whole document
        // read-only.
        $schema = new DocumentSchema;

        return $schema->normalise($schema->ensureIds($doc));
    }

    private function convertBlockNode(DOMNode $node): ?array
    {
        if ($node instanceof DOMText) {
            $text = trim($node->wholeText);

            return $text === '' ? null : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $node->wholeText]]];
        }

        if (! $node instanceof DOMElement) {
            return null;
        }

        $tag = strtolower($node->tagName);

        return match (true) {
            in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) => [
                'type' => 'heading',
                'attrs' => ['level' => max(1, min(6, (int) substr($tag, 1)))],
                'content' => $this->convertInlineChildren($node),
            ],
            $tag === 'p' => ['type' => 'paragraph', 'content' => $this->convertInlineChildren($node)],
            $tag === 'ul' => $this->convertUnorderedList($node),
            $tag === 'ol' => $this->convertOrderedList($node),
            $tag === 'blockquote' => ['type' => 'blockquote', 'content' => $this->convertBlockChildrenWrapped($node)],
            $tag === 'pre' => ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => $node->textContent]]],
            $tag === 'hr' => ['type' => 'horizontalRule'],
            $tag === 'img' => ['type' => 'image', 'attrs' => array_filter([
                'src' => $this->safeUrl($node->getAttribute('src'), self::IMAGE_SCHEMES),
                'alt' => $node->getAttribute('alt') ?: null,
            ])],
            $tag === 'table' => ['type' => 'table', 'content' => $this->convertTable($node)],
            default => $this->convertUnknownBlock($node),
        };
    }

    private function convertUnknownBlock(DOMElement $node): ?array
    {
        $text = trim($node->textContent);
        if ($text === '') {
            return null;
        }

        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $node->textContent]]];
    }

    /**
     * A `<ul>` is a bullet list unless its items carry checkboxes, which is
     * how commonmark's TaskListExtension renders `- [x]` — the shape
     * MarkdownExporter writes a `taskList` back out as. Without this the
     * checkbox state was lost on every Markdown round trip, and the `<input>`
     * simply vanished into the item's text.
     *
     * @return array<string,mixed>
     */
    private function convertUnorderedList(DOMElement $node): array
    {
        $items = $this->convertListItems($node);
        $checks = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $checks[] = $this->taskState($child);
            }
        }

        if (! in_array(true, array_map('is_bool', $checks), true)) {
            return ['type' => 'bulletList', 'content' => $items];
        }

        foreach ($items as $index => $item) {
            $items[$index] = [
                'type' => 'taskItem',
                'attrs' => ['checked' => ($checks[$index] ?? false) === true],
                'content' => $item['content'],
            ];
        }

        return ['type' => 'taskList', 'content' => $items];
    }

    /** The checked state of an `<li>`'s task checkbox, or null when it has none. */
    private function taskState(DOMElement $item): ?bool
    {
        foreach ($item->getElementsByTagName('input') as $input) {
            if ($input instanceof DOMElement && strtolower($input->getAttribute('type')) === 'checkbox') {
                return $input->hasAttribute('checked');
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function convertOrderedList(DOMElement $node): array
    {
        $doc = ['type' => 'orderedList'];
        if ($node->hasAttribute('start')) {
            $start = (int) $node->getAttribute('start');
            if ($start > 1) {
                $doc['attrs'] = ['start' => $start];
            }
        }
        $doc['content'] = $this->convertListItems($node);

        return $doc;
    }

    /** @return list<array<string,mixed>> */
    private function convertListItems(DOMElement $list): array
    {
        $items = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $items[] = ['type' => 'listItem', 'content' => $this->convertBlockChildrenWrapped($child)];
            }
        }

        return $items;
    }

    /**
     * Converts the block children of a node that may also contain bare text
     * (li / td / th / blockquote), wrapping any bare text run in a paragraph.
     *
     * @return list<array<string,mixed>>
     */
    private function convertBlockChildrenWrapped(DOMElement $node): array
    {
        $out = [];
        $pendingInline = [];

        $flush = function () use (&$pendingInline, &$out): void {
            if ($pendingInline !== []) {
                $out[] = ['type' => 'paragraph', 'content' => $pendingInline];
                $pendingInline = [];
            }
        };

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->wholeText) === '') {
                    continue;
                }
                $pendingInline[] = ['type' => 'text', 'text' => $child->wholeText];

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::BLOCK_WRAPPING_PARENTS, true) || in_array($tag, ['p', 'ul', 'ol', 'blockquote', 'pre', 'hr', 'img', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $flush();
                $converted = $this->convertBlockNode($child);
                if ($converted !== null) {
                    $out[] = $converted;
                }

                continue;
            }
            $pendingInline = array_merge($pendingInline, $this->convertInlineNode($child));
        }
        $flush();

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function convertTable(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $cellTag = strtolower($cell->tagName);
                if ($cellTag === 'th') {
                    $cells[] = ['type' => 'tableHeader', 'content' => $this->convertBlockChildrenWrapped($cell)];
                } elseif ($cellTag === 'td') {
                    $cells[] = ['type' => 'tableCell', 'content' => $this->convertBlockChildrenWrapped($cell)];
                }
            }
            $rows[] = ['type' => 'tableRow', 'content' => $cells];
        }

        return $rows;
    }

    /**
     * $url when it is one this app will hand back to a browser, null
     * otherwise. A URL with no scheme is kept only when it is a plain
     * document-root path (`/storage/...`, `/images/...`) — a relative or
     * protocol-relative one has no meaning once stored, and a scheme-looking
     * prefix that is not in $schemes (`javascript:`, `data:`) is exactly what
     * this exists to refuse.
     *
     * @param  list<string>  $schemes
     */
    private function safeUrl(string $url, array $schemes): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*):#', $url, $m) === 1) {
            return in_array(strtolower($m[1]), $schemes, true) ? $url : null;
        }

        return str_starts_with($url, '/') || str_starts_with($url, '#') ? $url : null;
    }

    /** @return list<array<string,mixed>> */
    private function convertInlineChildren(DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            $out = array_merge($out, $this->convertInlineNode($child));
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function convertInlineNode(DOMNode $node, array $marks = []): array
    {
        if ($node instanceof DOMText) {
            if ($node->wholeText === '') {
                return [];
            }
            $text = ['type' => 'text', 'text' => $node->wholeText];
            if ($marks !== []) {
                $text['marks'] = $marks;
            }

            return [$text];
        }

        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'br') {
            return [['type' => 'hardBreak']];
        }

        $markType = match ($tag) {
            'strong', 'b' => 'bold',
            'em', 'i' => 'italic',
            'u' => 'underline',
            's', 'del' => 'strike',
            'code' => 'code',
            'mark' => 'highlight',
            'a' => 'link',
            default => null,
        };

        if ($markType === null) {
            // Unknown inline element: descend, keeping accumulated marks, as plain text.
            $out = [];
            foreach ($node->childNodes as $child) {
                $out = array_merge($out, $this->convertInlineNode($child, $marks));
            }

            return $out;
        }

        $mark = ['type' => $markType];
        if ($markType === 'link') {
            $href = $this->safeUrl($node->getAttribute('href'), self::LINK_SCHEMES);
            if ($href === null) {
                // Keep the words, drop the link: an unsafe href stored here
                // is rendered into an <a> by HtmlRenderer on every view.
                $out = [];
                foreach ($node->childNodes as $child) {
                    $out = array_merge($out, $this->convertInlineNode($child, $marks));
                }

                return $out;
            }
            $mark['attrs'] = ['href' => $href];
        }
        $childMarks = [...$marks, $mark];

        $out = [];
        foreach ($node->childNodes as $child) {
            $out = array_merge($out, $this->convertInlineNode($child, $childMarks));
        }

        return $out;
    }
}
