<?php

namespace App\Documents\Render;

use App\Documents\DocumentStore;
use App\Documents\Outline\Outline;
use App\Models\Document;
use App\Styles\StyleEngine;

/**
 * A document's stored JSON with its outline applied, plus the RenderContext
 * carrying the same numbering - the pair HtmlRenderer::render() takes.
 *
 * This is the build DocumentStore::fill() does for the editor, repeated for
 * every read-only surface (export, published page): headings numbered against
 * the document's OWN style tokens (see .ai/rules/styles.md), cross-reference
 * labels resolved, the table of contents filled in and the document's
 * variables put where HtmlRenderer::renderVariable() looks for them.
 */
final class NumberedRender
{
    public function __construct(
        private DocumentStore $store,
        private Outline $outline,
        private StyleEngine $styles,
        private HtmlRenderer $renderer,
    ) {}

    /**
     * @return array{0:array<string,mixed>,1:RenderContext}
     */
    public function prepare(Document $document, RenderContext $ctx): array
    {
        $style = $this->styles->resolve($document);
        $json = $this->store->json($document);
        $result = $this->outline->build($json, $style->tokens['numbering'] ?? []);

        $ctx->numbers = $result->numbers;
        $ctx->kinds = $result->kinds;
        $ctx->toc = $result->toc;
        $ctx->vars = $document->variables ?? [];

        return [$this->outline->apply($json, $result), $ctx];
    }

    public function html(Document $document, RenderContext $ctx): string
    {
        return $this->renderer->render(...$this->prepare($document, $ctx));
    }
}
