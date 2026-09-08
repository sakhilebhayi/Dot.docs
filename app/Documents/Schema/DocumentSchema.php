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

    /** Alignments HtmlRenderer will put in a `style` attribute. */
    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public const MIN_COLUMNS = 2;

    public const MAX_COLUMNS = 4;

    /**
     * Nodes whose ProseMirror content expression demands at least one child
     * (`table` is `tableRow+`, the lists are `listItem+`/`taskItem+`). An
     * empty one is not a thin document, it is an invalid one: with
     * `enableContentCheck` the editor refuses to open it and the whole
     * document goes read-only, so drop the empty husk instead.
     */
    private const DROP_WHEN_EMPTY = ['table', 'tableRow', 'bulletList', 'orderedList', 'taskList'];

    /**
     * Nodes whose content expression demands at least one BLOCK child. These
     * hold the writer's words, so they are repaired with an empty paragraph
     * rather than dropped.
     */
    private const FILL_WHEN_EMPTY = ['listItem', 'taskItem', 'tableCell', 'tableHeader', 'blockquote', 'callout', 'column'];

    /** `listItem`/`taskItem` are `paragraph block*` — the FIRST child must be a paragraph. */
    private const PARAGRAPH_FIRST = ['listItem', 'taskItem'];

    /**
     * Clean the attributes that end up inside a `style` attribute, and repair
     * the structure, before either is stored.
     *
     * `paragraph`/`heading`.align and `columns`.count are interpolated into
     * CSS by HtmlRenderer and by the editor's own renderHTML. The renderers
     * whitelist them too, but a document saved through the API, restored from
     * a version, or broadcast between two editors would otherwise carry the
     * bad value around for ever — normalise once, on the way in, so it never
     * persists.
     *
     * The structural repairs exist for the same reason in the other
     * direction: `DocumentSchema::validate()` is happy with an empty `table`
     * or a `listItem` holding no paragraph, but ProseMirror's content
     * expressions are not, and the editor is built with
     * `enableContentCheck: true` — so a legacy document containing one of
     * those opens READ-ONLY. Repairing here (on every create, save and read)
     * is what keeps such a document editable.
     */
    public function normalise(array $doc): array
    {
        $doc['content'] = $this->normaliseChildren($doc['content'] ?? null);

        // `doc` is `block+`: a document with no blocks at all fails the
        // editor's content check exactly like an empty table does.
        if ($doc['content'] === []) {
            $doc['content'] = [$this->emptyParagraph()];
        }

        return $doc;
    }

    /**
     * Normalise a list of child nodes, dropping anything that is not an array
     * and anything a repair decided to remove.
     *
     * @return list<array>
     */
    private function normaliseChildren(mixed $content): array
    {
        $out = [];
        foreach (is_array($content) ? $content : [] as $child) {
            $repaired = is_array($child) ? $this->normaliseNode($child) : null;
            if ($repaired !== null) {
                $out[] = $repaired;
            }
        }

        return $out;
    }

    private function emptyParagraph(): array
    {
        return ['type' => 'paragraph', 'attrs' => ['id' => BlockId::generate()], 'content' => []];
    }

    /** @return array|null null when the node cannot be repaired and must be dropped */
    private function normaliseNode(array $node): ?array
    {
        $type = $node['type'] ?? '';

        if (($type === 'paragraph' || $type === 'heading') && array_key_exists('align', $node['attrs'] ?? [])) {
            $align = $node['attrs']['align'];
            $align = is_string($align) ? strtolower(trim($align)) : null;
            if (in_array($align, self::ALIGNMENTS, true)) {
                $node['attrs']['align'] = $align;
            } else {
                unset($node['attrs']['align']);
            }
        }

        if (array_key_exists('content', $node)) {
            $node['content'] = $this->normaliseChildren($node['content']);
        }

        $children = is_array($node['content'] ?? null) ? $node['content'] : [];

        if ($children === [] && in_array($type, self::DROP_WHEN_EMPTY, true)) {
            return null;
        }

        if ($children === [] && in_array($type, self::FILL_WHEN_EMPTY, true)) {
            $node['content'] = [$this->emptyParagraph()];

            return $node;
        }

        if (in_array($type, self::PARAGRAPH_FIRST, true) && ($children[0]['type'] ?? '') !== 'paragraph') {
            $node['content'] = [$this->emptyParagraph(), ...$children];

            return $node;
        }

        if ($type === 'figure') {
            return $this->normaliseFigure($node, $children);
        }

        if ($type === 'columns') {
            return $this->normaliseColumns($node, $children);
        }

        return $node;
    }

    /**
     * `figure` is `(image | table) caption` — exactly two children, in that
     * order. Anything else is repaired around the media if there is media to
     * repair around, and dropped when there is not.
     *
     * @param  list<array>  $children
     */
    private function normaliseFigure(array $node, array $children): ?array
    {
        $media = null;
        $caption = null;
        foreach ($children as $child) {
            $childType = $child['type'] ?? '';
            if ($media === null && ($childType === 'image' || $childType === 'table')) {
                $media = $child;
            } elseif ($caption === null && $childType === 'caption') {
                $caption = $child;
            }
        }

        if ($media === null) {
            return null;
        }

        $node['content'] = [$media, $caption ?? ['type' => 'caption', 'attrs' => ['id' => BlockId::generate()], 'content' => []]];

        return $node;
    }

    /**
     * `columns` is `column{2,4}`: the child count must equal attrs.count, or
     * ProseMirror rejects the node. Extra columns are merged into the last
     * one kept (never dropped — they hold the writer's blocks); a short
     * document is padded with empty columns.
     *
     * @param  list<array>  $children
     */
    private function normaliseColumns(array $node, array $children): array
    {
        $columns = [];
        $loose = [];
        foreach ($children as $child) {
            if (($child['type'] ?? '') === 'column') {
                $columns[] = $child;
            } else {
                $loose[] = $child;
            }
        }

        $count = $node['attrs']['count'] ?? count($columns);
        $count = is_numeric($count) ? (int) $count : self::MIN_COLUMNS;
        $count = max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, $count));
        $node['attrs']['count'] = $count;

        if ($loose !== []) {
            $columns[] = ['type' => 'column', 'attrs' => ['id' => BlockId::generate()], 'content' => $loose];
        }

        while (count($columns) > $count) {
            $extra = array_pop($columns);
            $last = count($columns) - 1;
            $columns[$last]['content'] = [
                ...(is_array($columns[$last]['content'] ?? null) ? $columns[$last]['content'] : []),
                ...(is_array($extra['content'] ?? null) ? $extra['content'] : []),
            ];
        }

        while (count($columns) < $count) {
            $columns[] = ['type' => 'column', 'attrs' => ['id' => BlockId::generate()], 'content' => [$this->emptyParagraph()]];
        }

        $node['content'] = $columns;

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
