<?php

namespace App\Documents\Import;

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
 */
class MarkdownImporter
{
    public function __construct(private HtmlToJson $htmlToJson = new HtmlToJson) {}

    /** @return array<string,mixed> Dot.Doc JSON */
    public function import(string $markdown): array
    {
        return $this->htmlToJson->convert($this->toHtml($markdown));
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
