<?php

namespace App\Livewire\Documents;

use App\Audit\AuditLogger;
use App\Documents\DocumentStore;
use App\Documents\Import\HtmlToJson;
use App\Documents\Outline\Outline;
use App\Events\DocumentUpdated;
use App\Events\UserJoinedDocument;
use App\Events\UserLeftDocument;
use App\Files\FilesService;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Models\Files\Obj;
use App\Print\HeaderFooterBands;
use App\Print\PageSetup;
use App\Services\PresenceService;
use App\Styles\StyleEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Editor extends Component
{
    use AuthorizesRequests;

    public Document $document;

    public string $title = '';

    /** Current document content as Dot.Doc JSON (see App\Documents\Schema\DocumentSchema) */
    public array $contentJson = [];

    public bool $saved = false;

    public array $activeUsers = [];

    /** Suggestion / track-changes mode */
    public bool $suggestionMode = false;

    /** Pending suggestions (not yet accepted/rejected) */
    public array $pendingSuggestions = [];

    /** Whether comment sidebar is open */
    public bool $commentSidebarOpen = false;

    /** Whether the editor is showing the print/PDF stylesheet instead of the canvas one (see StyleEngine::css()) */
    public bool $printPreview = false;

    /** Whether the Move sheet - the tree's folder picker - is open. */
    public bool $showMoveSheet = false;

    public function mount(string $uuid): void
    {
        $this->document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $this->document);

        $this->title = $this->document->title;
        $this->contentJson = app(DocumentStore::class)->json($this->document);

        $presence = app(PresenceService::class);
        $presence->join($this->document, Auth::user());
        $this->activeUsers = $presence->getMemberList($this->document->id);

        try {
            UserJoinedDocument::dispatch($this->document, Auth::user());
        } catch (\Throwable) {
            // Broadcasting unavailable — continue without real-time presence
        }

        $this->loadPendingSuggestions();

        // mount() runs once per page load, not on every Livewire round trip,
        // so this is one row per opening of the document rather than one per
        // keystroke. See .ai/rules/audit.md for the action vocabulary.
        app(AuditLogger::class)->record('document.viewed', $this->document);
    }

    /**
     * @return array{ok:bool,version:int} whether the document was stored, and
     *                                    the version it is now at. $wire
     *                                    actions resolve with the return
     *                                    value, so the editor bridge awaits
     *                                    both: it keeps the offline draft when
     *                                    `ok` is false (a rejected save must
     *                                    not quietly lose the writer's work)
     *                                    and stamps `version` onto the draft
     *                                    as its `baseVersion`, which is what
     *                                    decides on the next load whether the
     *                                    draft is still restorable or somebody
     *                                    else has saved since.
     */
    public function saveContent(array $content): array
    {
        $this->authorize('update', $this->document);

        $this->resetErrorBag('content');

        try {
            $this->document = app(DocumentStore::class)->save($this->document, $content, Auth::user());
        } catch (InvalidArgumentException $e) {
            $this->addError('content', $e->getMessage());
            $this->saved = false;

            return ['ok' => false, 'version' => $this->document->version];
        }
        $this->contentJson = $this->document->content_json;
        $this->saved = true;

        try {
            DocumentUpdated::dispatch($this->document, Auth::user(), $this->document->content, $this->document->content_json, $this->document->version);
        } catch (\Throwable) {
            // Broadcasting unavailable — continue without real-time sync
        }
        app(PresenceService::class)->heartbeat($this->document, Auth::user());

        return ['ok' => true, 'version' => $this->document->version];
    }

    /**
     * Heading/figure numbers, the table of contents, and the resolved page
     * shape for the document as it is currently stored. Numbering and page
     * setup are both authoritative on the server (numbering depends on the
     * style's numbering tokens, see .ai/rules/styles.md; page setup merges
     * the document's own override over its style, see App\Print\PageSetup),
     * so the editor asks for both after every save instead of computing
     * either on its own.
     *
     * `headerSegments`/`footerSegments` are pre-split by
     * App\Print\HeaderFooterBands — the SAME class PrintRenderer uses for
     * the PDF export — so the live pagination view (resources/js/editor/
     * pagination/bands.js) never re-parses a `{{ }}` template itself. Each
     * segment is either literal text (already fully substituted) or one of
     * the two live fields ('PAGE'/'NUMPAGES'), which the client fills in
     * per page from its own computed page index and total.
     *
     * `figures` and `tables` are what the cross-reference picker offers
     * besides headings - a figure or a table is referenced by its number and
     * found by its caption, so both travel together.
     *
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
    public function outline(): array
    {
        // Called straight from JS on every save round trip, so it carries its
        // own authorisation rather than trusting mount()'s.
        $this->authorize('view', $this->document);

        $style = $this->document->resolvedStyle() ?? DocumentStyle::resolve('report');
        $result = app(Outline::class)->build($this->document->content_json ?? [], $style?->tokens['numbering'] ?? []);

        // PageSetup::fromDocument() requires a non-null DocumentStyle;
        // StyleEngine::resolve() is the guaranteed-non-null resolver
        // render() already uses two lines below in this same class, so
        // page setup and CSS are resolved from the same style either way.
        $resolvedStyle = app(StyleEngine::class)->resolve($this->document);
        $setup = PageSetup::fromDocument($this->document, $resolvedStyle);

        $vars = array_merge($this->document->variables ?? [], [
            'title' => $this->document->title,
            'date' => now()->format('Y-m-d'),
            'team' => $this->document->team?->name ?? '',
        ]);
        $bands = app(HeaderFooterBands::class);

        return [
            'numbers' => $result->numbers,
            'toc' => $result->toc,
            'figures' => $result->figures,
            'tables' => $result->tables,
            'pageSetup' => $setup->toArray(),
            'headerSegments' => $bands->segments($setup->header, $vars),
            'footerSegments' => $bands->segments($setup->footer, $vars),
        ];
    }

    /**
     * Suggestion / track-changes mode is rebuilt against the JSON document
     * in Phase 3. This flag is kept as a no-op toggle for the toolbar UI.
     */
    public function toggleSuggestionMode(): void
    {
        $this->suggestionMode = ! $this->suggestionMode;
    }

    public function toggleCommentSidebar(): void
    {
        $this->commentSidebarOpen = ! $this->commentSidebarOpen;
    }

    /** Swaps $styleCss (see render()) between the canvas and print stylesheets, e.g. to preview @page margins/header/footer before exporting. */
    public function togglePrintPreview(): void
    {
        $this->printPreview = ! $this->printPreview;
    }

    public function acceptSuggestion(int $suggestionId): void
    {
        $this->authorize('update', $this->document);

        $suggestion = AiSuggestion::where('document_id', $this->document->id)
            ->whereNull('accepted_at')
            ->findOrFail($suggestionId);

        $json = app(HtmlToJson::class)->convert($suggestion->suggestion_text);
        $this->document = app(DocumentStore::class)->save($this->document, $json, Auth::user(), [
            'version' => 'named',
            'label' => 'Accepted suggestion',
        ]);
        $this->contentJson = $this->document->content_json;

        $suggestion->update(['accepted_at' => now()]);
        $this->loadPendingSuggestions();
        $this->saved = true;

        $this->dispatch('suggestion-accepted', content: $this->contentJson);
    }

    public function rejectSuggestion(int $suggestionId): void
    {
        $this->authorize('update', $this->document);

        AiSuggestion::where('document_id', $this->document->id)
            ->whereNull('accepted_at')
            ->findOrFail($suggestionId)
            ->delete();

        $this->loadPendingSuggestions();
    }

    public function saveTitle(): void
    {
        $this->authorize('update', $this->document);
        $this->validate(['title' => 'required|string|max:255']);
        $this->document->update(['title' => $this->title]);
        $this->saved = true;
    }

    /**
     * Switch the document's style. Valid keys are the fourteen system
     * styles or a team-owned custom style of the same key. Re-saves the
     * document through DocumentStore so heading/figure numbering is
     * rebuilt against the new style's numbering rules.
     */
    public function setStyle(string $key): void
    {
        $this->authorize('update', $this->document);

        $engine = app(StyleEngine::class);
        $valid = in_array($key, StyleEngine::systemKeys(), true)
            || DocumentStyle::where('key', $key)->where('team_id', $this->document->team_id)->exists();

        if (! $valid) {
            $this->addError('style', 'Unknown style');

            return;
        }

        $this->document->style_key = $key;
        $this->document = app(DocumentStore::class)->save($this->document, $this->document->content_json, Auth::user(), ['version' => 'none']);
        $this->contentJson = $this->document->content_json;

        $this->dispatch('style-changed', css: $engine->css($engine->resolve($this->document), 'canvas'));
    }

    /**
     * Where this document is filed, root first - the location chip in the
     * bench. Empty when the document has no tree node at all, which only a
     * factory-made account with no team can produce.
     *
     * @return list<Obj>
     */
    #[Computed]
    public function locationCrumbs(): array
    {
        $node = $this->node();

        if ($node === null) {
            return [];
        }

        $parent = $node->parent;

        return $parent === null ? [] : [...$parent->ancestors(), $parent];
    }

    /**
     * Folders this document can be moved into, taken from the node's OWN
     * team - never the session's current team.
     *
     * @return list<array{id:int,uuid:string,label:string,depth:int}>
     */
    #[Computed]
    public function folderChoices(): array
    {
        $node = $this->node();

        return $node === null ? [] : app(FilesService::class)->folderChoices($node->team_id);
    }

    /**
     * File the document somewhere else. The sheet is the SAME APG pattern
     * the documents index uses for rename - Escape closes it and returns
     * focus - and it is the only move affordance: no drag-and-drop, so
     * there is nothing a keyboard cannot reach.
     */
    public function moveTo(int $destinationId): void
    {
        $this->authorize('update', $this->document);

        $node = $this->node();
        $destination = Obj::find($destinationId);

        if ($node === null || $destination === null || ! $destination->isFolder() || $destination->team_id !== $node->team_id) {
            $this->addError('location', 'That folder is not available for this document.');

            return;
        }

        try {
            app(FilesService::class)->moveObject($node, $destination, Auth::user());
        } catch (ValidationException $e) {
            $this->addError('location', collect($e->errors())->flatten()->first() ?? 'That move is not allowed.');

            return;
        }

        $this->showMoveSheet = false;
        unset($this->locationCrumbs, $this->folderChoices);

        session()->flash('status', 'Filed in '.$destination->name().'.');
    }

    /** This document's node in the shared tree, filed at its workspace root if it has none. */
    private function node(): ?Obj
    {
        $node = $this->document->node()->first();

        if ($node !== null) {
            return $node;
        }

        $team = $this->document->team ?? $this->document->owner?->personalTeam();

        if ($team === null) {
            return null;
        }

        $files = app(FilesService::class);

        return $files->registerDocument($this->document, $files->root($team));
    }

    public function heartbeat(): void
    {
        app(PresenceService::class)->heartbeat($this->document, Auth::user());
        $this->activeUsers = app(PresenceService::class)->getMemberList($this->document->id);
    }

    public function leaving(): void
    {
        $presence = app(PresenceService::class);
        $presence->leave($this->document, Auth::user());
        try {
            UserLeftDocument::dispatch($this->document, Auth::user());
        } catch (\Throwable) {
            // Broadcasting unavailable
        }
    }

    private function loadPendingSuggestions(): void
    {
        $this->pendingSuggestions = AiSuggestion::where('document_id', $this->document->id)
            ->whereNull('accepted_at')
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'user' => $s->user->name,
                'created_at' => $s->created_at->diffForHumans(),
                'excerpt' => Str::limit(strip_tags($s->suggestion_text), 80),
            ])
            ->toArray();
    }

    public function render(): View
    {
        $engine = app(StyleEngine::class);
        $styleCss = $engine->css($engine->resolve($this->document), $this->printPreview ? 'print' : 'canvas');

        return view('livewire.documents.editor', [
            'styleCss' => $styleCss,
            // Seeds window.DotDoc.setOutline() at mount. Without it every page
            // load paints its headings unnumbered until the first outline()
            // round trip answers - the TOC and cross-references hide the gap
            // (they fall back to the entries/label the server stamped into the
            // JSON) but a heading number is decoration only, with nothing to
            // fall back to.
            'outline' => $this->outline(),
        ])->title($this->document->title);
    }
}
