<?php

namespace App\Documents\Schema;

class DocumentSchema
{
    public const VERSION = 1;

    /** Block-level nodes that carry attrs.id */
    public const BLOCKS = [
        'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
        'blockquote', 'codeBlock', 'horizontalRule', 'image', 'figure', 'caption',
        'table', 'tableRow', 'tableHeader', 'tableCell', 'toc', 'pageBreak', 'sectionBreak',
        'callout', 'columns', 'column',
    ];

    public const INLINES = ['text', 'hardBreak', 'crossRef', 'variable'];

    public const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'link', 'highlight', 'subscript', 'superscript', 'textStyle'];

    public static function empty(): array
    {
        return ['type' => 'doc', 'attrs' => ['schema' => self::VERSION, 'style' => 'report', 'vars' => []], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()]],
        ]];
    }

    public function ensureIds(array $doc): array
    {
        $seen = [];
        $content = $doc['content'] ?? [];
        $doc['content'] = array_map(fn ($n) => $this->ensureNodeIds($n, $seen), is_array($content) ? $content : []);
        $doc['attrs'] = array_merge(['schema' => self::VERSION, 'style' => 'report', 'vars' => []], $doc['attrs'] ?? []);

        return $doc;
    }

    private function ensureNodeIds(array $node, array &$seen): array
    {
        if (in_array($node['type'] ?? '', self::BLOCKS, true)) {
            $id = $node['attrs']['id'] ?? null;
            if (! BlockId::isValid($id) || isset($seen[$id])) {
                do {
                    $id = BlockId::generate();
                } while (isset($seen[$id]));
            }
            $seen[$id] = true;
            $node['attrs'] = array_merge($node['attrs'] ?? [], ['id' => $id]);
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn ($c) => $this->ensureNodeIds($c, $seen), $node['content']);
        }

        return $node;
    }

    /** @return list<string> */
    public function validate(array $doc): array
    {
        $errors = [];
        $ids = [];
        if (($doc['type'] ?? null) !== 'doc') {
            $errors[] = 'Root must be doc';
        }
        $this->walk($doc, function (array $node) use (&$errors, &$ids): void {
            $type = $node['type'] ?? '';
            if ($type === 'doc') {
                return;
            }
            if (! in_array($type, self::BLOCKS, true) && ! in_array($type, self::INLINES, true)) {
                $errors[] = "Unknown node type {$type}";

                return;
            }
            if (in_array($type, self::BLOCKS, true)) {
                $id = $node['attrs']['id'] ?? null;
                if (! BlockId::isValid($id)) {
                    $errors[] = "Block {$type} has no valid id";
                } elseif (isset($ids[$id])) {
                    $errors[] = "Duplicate block id {$id}";
                }
                $ids[$id] = true;
            }
            foreach ($node['marks'] ?? [] as $mark) {
                if (! in_array($mark['type'] ?? '', self::MARKS, true)) {
                    $errors[] = 'Unknown mark type '.($mark['type'] ?? '?');
                }
            }
        });

        return array_values(array_unique($errors));
    }

    /** @param callable(array $node, array $path): void $fn */
    public function walk(array $node, callable $fn, array $path = []): void
    {
        $fn($node, $path);
        $content = $node['content'] ?? [];
        foreach (is_array($content) ? $content : [] as $i => $child) {
            $this->walk($child, $fn, [...$path, $i]);
        }
    }

    /** @return list<array{id:string,type:string,node:array,path:array}> */
    public function blocks(array $doc): array
    {
        $out = [];
        $this->walk($doc, function (array $node, array $path) use (&$out): void {
            if (in_array($node['type'] ?? '', self::BLOCKS, true) && isset($node['attrs']['id'])) {
                $out[] = ['id' => $node['attrs']['id'], 'type' => $node['type'], 'node' => $node, 'path' => $path];
            }
        });

        return $out;
    }

    public function plainText(array $node): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            return $node['text'] ?? '';
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        if ($type === 'variable') {
            return '{{'.($node['attrs']['key'] ?? '').'}}';
        }
        if ($type === 'crossRef') {
            return $node['attrs']['label'] ?? '?';
        }
        $parts = array_map(fn ($c) => $this->plainText($c), $node['content'] ?? []);
        $isBlock = in_array($type, self::BLOCKS, true) || $type === 'doc';
        $joined = implode($isBlock && $this->hasBlockChildren($node) ? "\n" : '', $parts);

        return $joined;
    }

    private function hasBlockChildren(array $node): bool
    {
        foreach ($node['content'] ?? [] as $c) {
            if (in_array($c['type'] ?? '', self::BLOCKS, true)) {
                return true;
            }
        }

        return false;
    }

    public function wordCount(array $doc): int
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $this->plainText($doc), $matches);

        return count($matches[0]);
    }
}
