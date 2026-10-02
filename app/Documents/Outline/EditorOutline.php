<?php

namespace App\Documents\Outline;

use App\Models\Document;
use App\Models\DocumentStyle;
use App\Print\HeaderFooterBands;
use App\Print\PageSetup;
use App\Styles\StyleEngine;

/**
 * Everything the editor needs to know about a stored document that it
 * cannot work out for itself: heading and figure numbers, the table of
 * contents, and the resolved page shape.
 *
 * Numbering and page setup are both authoritative on the server (numbering
 * depends on the style's numbering tokens, see .ai/rules/styles.md; page
 * setup merges the document's own override over its style, see
 * App\Print\PageSetup), so the editor asks for both rather than computing
 * either. Two callers: the Livewire Editor component (at mount and after its
 * own save) and DocumentSyncController (when it hands a newer document to a
 * tab that is behind) - one class so the two can never disagree.
 *
 * `headerSegments`/`footerSegments` are pre-split by HeaderFooterBands, the
 * SAME class PrintRenderer uses for the PDF export, so the live pagination
 * view never re-parses a `{{ }}` template itself.
 */
class EditorOutline
{
    public function __construct(
        private Outline $outline,
        private StyleEngine $styles,
        private HeaderFooterBands $bands,
    ) {}

    /**
     * @return array{
     *     numbers: array<string,string>,
     *     toc: list<array{id:string,level:int,text:string,number:string}>,
     *     figures: list<array{id:string,number:string,text:string}>,
     *     tables: list<array{id:string,number:string,text:string}>,
     *     pageSetup: array{size:string,orientation:string,margins:array{top:string,right:string,bottom:string,left:string},header:string,footer:string},
     *     headerSegments: list<array{type:'text'|'field',value:string}>,
     *     footerSegments: list<array{type:'text'|'field',value:string}>,
     * }
     */
    public function of(Document $document): array
    {
        $style = $document->resolvedStyle() ?? DocumentStyle::resolve('report');
        $result = $this->outline->build($document->content_json ?? [], $style?->tokens['numbering'] ?? []);

        // PageSetup::fromDocument() requires a non-null DocumentStyle;
        // StyleEngine::resolve() is the guaranteed-non-null resolver.
        $setup = PageSetup::fromDocument($document, $this->styles->resolve($document));

        $vars = array_merge($document->variables ?? [], [
            'title' => $document->title,
            'date' => now()->format('Y-m-d'),
            'team' => $document->team->name ?? '',
        ]);

        return [
            'numbers' => $result->numbers,
            'toc' => $result->toc,
            'figures' => $result->figures,
            'tables' => $result->tables,
            'pageSetup' => $setup->toArray(),
            'headerSegments' => $this->bands->segments($setup->header, $vars),
            'footerSegments' => $this->bands->segments($setup->footer, $vars),
        ];
    }
}
