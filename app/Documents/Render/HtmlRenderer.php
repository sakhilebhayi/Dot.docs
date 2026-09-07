<?php

namespace App\Documents\Render;

/**
 * Renders a Dot.Doc JSON document (see App\Documents\Schema\DocumentSchema)
 * into an HTML string for the editor, share, or print surface.
 */
class HtmlRenderer
{
    public function render(array $doc, RenderContext $ctx): string
    {
        $content = $doc['content'] ?? [];

        return $this->renderNodes(is_array($content) ? $content : [], $ctx, null);
    }

    /** @param array<int,array<string,mixed>> $nodes */
    private function renderNodes(array $nodes, RenderContext $ctx, ?string $parentId): string
    {
        return implode('', array_map(fn (array $n) => $this->renderNode($n, $ctx, $parentId), $nodes));
    }

    private function renderChildren(array $node, RenderContext $ctx, ?string $parentId = null): string
    {
        $content = $node['content'] ?? [];

        return $this->renderNodes(is_array($content) ? $content : [], $ctx, $parentId);
    }

    private function renderNode(array $node, RenderContext $ctx, ?string $parentId): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'paragraph' => $this->renderParagraph($node, $ctx),
            'heading' => $this->renderHeading($node, $ctx),
            'bulletList' => $this->wrapBlock('ul', $node, $ctx, $this->renderChildren($node, $ctx)),
            'orderedList' => $this->renderOrderedList($node, $ctx),
            'listItem' => $this->wrapBlock('li', $node, $ctx, $this->renderChildren($node, $ctx)),
            'taskList' => $this->wrapBlock('ul', $node, $ctx, $this->renderChildren($node, $ctx), ['class' => 'tasks']),
            'taskItem' => $this->renderTaskItem($node, $ctx),
            'blockquote' => $this->renderBlockquote($node, $ctx),
            'codeBlock' => $this->renderCodeBlock($node, $ctx),
            'horizontalRule' => $this->renderVoidBlock('hr', $node),
            'image' => $this->renderImage($node),
            'figure' => $this->renderFigure($node, $ctx),
            'caption' => $this->renderCaption($node, $ctx, $parentId),
            'table' => $this->renderTable($node, $ctx),
            'tableRow' => $this->wrapBlock('tr', $node, $ctx, $this->renderChildren($node, $ctx)),
            'tableHeader' => $this->renderTableCell('th', $node, $ctx),
            'tableCell' => $this->renderTableCell('td', $node, $ctx),
            'toc' => $this->renderToc($node, $ctx),
            'pageBreak' => $this->renderVoidBlock('div', $node, ['class' => 'page-break']),
            'sectionBreak' => $this->renderSectionBreak($node),
            'callout' => $this->renderCallout($node, $ctx),
            'columns' => $this->renderColumns($node, $ctx),
            'column' => $this->wrapBlock('div', $node, $ctx, $this->renderChildren($node, $ctx), ['class' => 'column']),
            'crossRef' => $this->renderCrossRef($node, $ctx),
            'variable' => $this->renderVariable($node, $ctx),
            'hardBreak' => '<br>',
            'text' => $this->renderText($node),
            default => '',
        };
    }

    private function esc(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function id(array $node): string
    {
        return $this->esc($node['attrs']['id'] ?? '');
    }

    /** @param array<string,string> $extraAttrs */
    private function wrapBlock(string $tag, array $node, RenderContext $ctx, string $inner, array $extraAttrs = []): string
    {
        $attrs = ' data-id="'.$this->id($node).'"';
        foreach ($extraAttrs as $name => $value) {
            $attrs = " {$name}=\"{$this->esc($value)}\"".$attrs;
        }

        return "<{$tag}{$attrs}>{$inner}</{$tag}>";
    }

    /** @param array<string,string> $extraAttrs */
    private function renderVoidBlock(string $tag, array $node, array $extraAttrs = []): string
    {
        $attrs = '';
        foreach ($extraAttrs as $name => $value) {
            $attrs .= " {$name}=\"{$this->esc($value)}\"";
        }
        $attrs .= ' data-id="'.$this->id($node).'"';

        return "<{$tag}{$attrs}></{$tag}>";
    }

    private function renderParagraph(array $node, RenderContext $ctx): string
    {
        $align = $node['attrs']['align'] ?? null;
        $style = $align ? ' style="text-align:'.$this->esc($align).'"' : '';

        return '<p data-id="'.$this->id($node).'"'.$style.'>'.$this->renderChildren($node, $ctx).'</p>';
    }

    private function renderHeading(array $node, RenderContext $ctx): string
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));
        $id = $node['attrs']['id'] ?? '';
        $num = isset($ctx->numbers[$id]) ? '<span class="num">'.$this->esc($ctx->numbers[$id]).'</span>' : '';

        return "<h{$level} data-id=\"{$this->esc($id)}\">{$num}".$this->renderChildren($node, $ctx)."</h{$level}>";
    }

    private function renderOrderedList(array $node, RenderContext $ctx): string
    {
        $start = $node['attrs']['start'] ?? null;
        $extra = $start !== null ? ['start' => (string) $start] : [];

        return $this->wrapBlock('ol', $node, $ctx, $this->renderChildren($node, $ctx), $extra);
    }

    private function renderTaskItem(array $node, RenderContext $ctx): string
    {
        $checked = ($node['attrs']['checked'] ?? false) ? 'true' : 'false';

        return $this->wrapBlock('li', $node, $ctx, $this->renderChildren($node, $ctx), ['data-checked' => $checked]);
    }

    private function renderBlockquote(array $node, RenderContext $ctx): string
    {
        return $this->wrapBlock('blockquote', $node, $ctx, $this->renderChildren($node, $ctx));
    }

    private function renderCodeBlock(array $node, RenderContext $ctx): string
    {
        $language = $node['attrs']['language'] ?? null;
        $class = $language ? ' class="language-'.$this->esc($language).'"' : '';

        return '<pre data-id="'.$this->id($node)."\"><code{$class}>".$this->renderChildren($node, $ctx).'</code></pre>';
    }

    private function isValidImageSrc(mixed $src): bool
    {
        if (! is_string($src) || $src === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $src) === 1 && filter_var($src, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        return str_starts_with($src, '/');
    }

    private function renderImage(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        $html = '';
        if ($this->isValidImageSrc($attrs['src'] ?? null)) {
            $html .= ' src="'.$this->esc($attrs['src']).'"';
        }
        if (isset($attrs['alt'])) {
            $html .= ' alt="'.$this->esc($attrs['alt']).'"';
        }
        if (isset($attrs['width'])) {
            $html .= ' width="'.$this->esc($attrs['width']).'"';
        }

        return "<img{$html}>";
    }

    private function renderFigure(array $node, RenderContext $ctx): string
    {
        $id = $node['attrs']['id'] ?? '';
        $kind = $node['attrs']['kind'] ?? 'default';
        $inner = $this->renderChildren($node, $ctx, $id);

        return '<figure data-id="'.$this->esc($id).'" class="figure figure-'.$this->esc($kind).'">'.$inner.'</figure>';
    }

    private function renderCaption(array $node, RenderContext $ctx, ?string $parentId): string
    {
        $number = $parentId !== null ? ($ctx->numbers[$parentId] ?? null) : null;
        $num = $number !== null ? '<span class="num">Figure '.$this->esc($number).'</span>' : '';

        return "<figcaption>{$num}".$this->renderChildren($node, $ctx).'</figcaption>';
    }

    private function renderTable(array $node, RenderContext $ctx): string
    {
        $inner = $this->renderChildren($node, $ctx);

        return '<table class="doc-table" data-id="'.$this->id($node).'"><tbody>'.$inner.'</tbody></table>';
    }

    private function renderTableCell(string $tag, array $node, RenderContext $ctx): string
    {
        $attrs = $node['attrs'] ?? [];
        $extra = ' data-id="'.$this->id($node).'"';
        if (isset($attrs['colspan'])) {
            $extra .= ' colspan="'.$this->esc($attrs['colspan']).'"';
        }
        if (isset($attrs['rowspan'])) {
            $extra .= ' rowspan="'.$this->esc($attrs['rowspan']).'"';
        }

        return "<{$tag}{$extra}>".$this->renderChildren($node, $ctx)."</{$tag}>";
    }

    private function renderToc(array $node, RenderContext $ctx): string
    {
        $depth = $node['attrs']['depth'] ?? 3;
        $items = implode('', array_map(function (array $entry): string {
            $level = $entry['level'] ?? 1;
            $href = $this->esc($entry['id'] ?? '');
            $number = $this->esc($entry['number'] ?? '');
            $text = $this->esc($entry['text'] ?? '');

            return '<li class="toc-level-'.$this->esc($level)."\"><a href=\"#{$href}\"><span class=\"num\">{$number}</span>{$text}</a></li>";
        }, $ctx->toc));

        return '<nav class="toc" data-id="'.$this->id($node).'" data-depth="'.$this->esc($depth)."\"><ol>{$items}</ol></nav>";
    }

    private function renderSectionBreak(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        $setup = $attrs;
        unset($setup['id']);
        $json = $this->esc(json_encode($setup));

        return '<div class="section-break" data-id="'.$this->id($node)."\" data-setup='{$json}'></div>";
    }

    private function renderCallout(array $node, RenderContext $ctx): string
    {
        $tone = $node['attrs']['tone'] ?? 'info';

        return $this->wrapBlock('aside', $node, $ctx, $this->renderChildren($node, $ctx), ['class' => 'callout callout-'.$tone]);
    }

    private function renderColumns(array $node, RenderContext $ctx): string
    {
        $count = $node['attrs']['count'] ?? count($node['content'] ?? []);
        $inner = $this->renderChildren($node, $ctx);

        return '<div class="columns" data-id="'.$this->id($node).'" style="--cols:'.$this->esc($count).'">'.$inner.'</div>';
    }

    private function renderCrossRef(array $node, RenderContext $ctx): string
    {
        $attrs = $node['attrs'] ?? [];
        $targetId = $this->esc($attrs['targetId'] ?? '');
        $label = $attrs['label'] ?? null;
        if ($label !== null && $label !== '') {
            return "<a class=\"xref\" href=\"#{$targetId}\">".$this->esc($label).'</a>';
        }

        $kindLabel = match ($attrs['kind'] ?? 'heading') {
            'figure' => 'Figure',
            'table' => 'Table',
            default => 'Section',
        };
        $number = $ctx->numbers[$attrs['targetId'] ?? ''] ?? null;
        if ($number !== null) {
            return "<a class=\"xref\" href=\"#{$targetId}\">".$this->esc("{$kindLabel} {$number}").'</a>';
        }

        return "<a class=\"xref xref-broken\" href=\"#{$targetId}\">?</a>";
    }

    private function renderVariable(array $node, RenderContext $ctx): string
    {
        $key = $node['attrs']['key'] ?? '';

        return $this->esc($ctx->vars[$key] ?? "{{{$key}}}");
    }

    private function renderText(array $node): string
    {
        $html = $this->esc($node['text'] ?? '');
        foreach ($node['marks'] ?? [] as $mark) {
            $html = $this->wrapMark($mark, $html);
        }

        return $html;
    }

    private function wrapMark(array $mark, string $html): string
    {
        return match ($mark['type'] ?? '') {
            'bold' => "<strong>{$html}</strong>",
            'italic' => "<em>{$html}</em>",
            'underline' => "<u>{$html}</u>",
            'strike' => "<s>{$html}</s>",
            'code' => "<code>{$html}</code>",
            'highlight' => "<mark>{$html}</mark>",
            'subscript' => "<sub>{$html}</sub>",
            'superscript' => "<sup>{$html}</sup>",
            'textStyle' => $this->wrapTextStyle($mark, $html),
            'link' => $this->wrapLink($mark, $html),
            default => $html,
        };
    }

    private function wrapTextStyle(array $mark, string $html): string
    {
        $color = $mark['attrs']['color'] ?? null;
        if (is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1) {
            return '<span style="color:'.$this->esc($color).'">'.$html.'</span>';
        }

        return $html;
    }

    private function wrapLink(array $mark, string $html): string
    {
        $href = $mark['attrs']['href'] ?? '';
        if (! is_string($href) || preg_match('#^(https?://|mailto:)#i', $href) !== 1) {
            return $html;
        }

        return '<a href="'.$this->esc($href).'" rel="noopener noreferrer">'.$html.'</a>';
    }
}
