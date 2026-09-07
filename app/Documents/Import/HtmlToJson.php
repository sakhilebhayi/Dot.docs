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

        return (new DocumentSchema)->ensureIds($doc);
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
            $tag === 'ul' => ['type' => 'bulletList', 'content' => $this->convertListItems($node)],
            $tag === 'ol' => $this->convertOrderedList($node),
            $tag === 'blockquote' => ['type' => 'blockquote', 'content' => $this->convertBlockChildrenWrapped($node)],
            $tag === 'pre' => ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => $node->textContent]]],
            $tag === 'hr' => ['type' => 'horizontalRule'],
            $tag === 'img' => ['type' => 'image', 'attrs' => array_filter([
                'src' => $node->getAttribute('src') ?: null,
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
            $mark['attrs'] = ['href' => $node->getAttribute('href')];
        }
        $childMarks = [...$marks, $mark];

        $out = [];
        foreach ($node->childNodes as $child) {
            $out = array_merge($out, $this->convertInlineNode($child, $childMarks));
        }

        return $out;
    }
}
