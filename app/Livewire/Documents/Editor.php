<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Documents\Import\HtmlToJson;
use App\Documents\Outline\Outline;
use App\Events\DocumentUpdated;
use App\Events\UserJoinedDocument;
use App\Events\UserLeftDocument;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Services\PresenceService;
use App\Styles\StyleEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
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
    }

    public function saveContent(array $content): void
    {
        $this->authorize('update', $this->document);

        try {
            $this->document = app(DocumentStore::class)->save($this->document, $content, Auth::user());
        } catch (InvalidArgumentException $e) {
            $this->addError('content', $e->getMessage());

            return;
        }
        $this->contentJson = $this->document->content_json;
        $this->saved = true;

        try {
            DocumentUpdated::dispatch($this->document, Auth::user(), $this->document->content, $this->document->content_json, $this->document->version);
        } catch (\Throwable) {
            // Broadcasting unavailable — continue without real-time sync
        }
        app(PresenceService::class)->heartbeat($this->document, Auth::user());
    }

    /**
     * Heading/figure numbers and the table of contents for the document as it
     * is currently stored. Numbering is authoritative on the server (it
     * depends on the style's numbering tokens - see .ai/rules/styles.md), so
     * the editor asks for it after every save instead of computing its own.
     *
     * @return array{numbers: array<string,string>, toc: list<array{id:string,level:int,text:string,number:string}>}
     */
    public function outline(): array
    {
        $style = $this->document->resolvedStyle() ?? DocumentStyle::resolve('report');
        $result = app(Outline::class)->build($this->document->content_json ?? [], $style?->tokens['numbering'] ?? []);

        return ['numbers' => $result->numbers, 'toc' => $result->toc];
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
        ]);
    }
}
