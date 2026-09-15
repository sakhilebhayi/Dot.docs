<?php

namespace App\Documents\Import;

use App\Documents\Schema\DocumentSchema;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown -> Dot.Doc JSON (see App\Documents\Schema\DocumentSchema).
 *
 * Markdown has no structure HtmlToJson cannot already read, so this is a
 * two-hop conversion: commonmark renders to HTML, HtmlToJson walks that HTML
 * into JSON (and runs ensureIds() + normalise() on the way out). The only
 * thing worth configuring is which extensions are on: pipe TABLES are not
 * part of core CommonMark, and without TableExtension a table silently
 * degrades into a paragraph of pipes.
 *
 * Two conventions Markdown has no syntax for are applied afterwards by
 * VariableTagger: `{{ key }}` becomes a `variable` node (which is exactly
 * what MarkdownExporter writes for one, so a variable survives a .md round
 * trip) and a paragraph reading only `[[toc]]` becomes a `toc` node. See
 * .ai/rules/documents-io.md.
 */
class MarkdownImporter
{
    public function __construct(
        private HtmlToJson $htmlToJson = new HtmlToJson,
        private VariableTagger $tagger = new VariableTagger,
        private DocumentSchema $schema = new DocumentSchema,
    ) {}

    /** @return array<string,mixed> Dot.Doc JSON */
    public function import(string $markdown): array
    {
        $json = $this->tagger->tag($this->htmlToJson->convert($this->toHtml($markdown)));

        // ensureIds() again because the tagger mints `toc` nodes, which are
        // blocks and must carry an id; normalise() because every importer
        // owes DocumentStore JSON that has already passed both.
        return $this->schema->normalise($this->schema->ensureIds($json));
    }

    public function toHtml(string $markdown): string
    {
        // Raw HTML in the source is stripped rather than passed through, and
        // unsafe links are dropped: imported files are untrusted input, and
        // whatever survives here is stored as document content.
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TaskListExtension);

        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}
