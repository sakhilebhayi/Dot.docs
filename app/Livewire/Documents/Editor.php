<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Events\DocumentUpdated;
use App\Events\UserJoinedDocument;
use App\Events\UserLeftDocument;
use App\Models\AiSuggestion;
use App\Models\Document;
use App\Services\PresenceService;
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

    public function acceptSuggestion(int $suggestionId): void
    {
        $this->authorize('update', $this->document);

        $suggestion = AiSuggestion::where('document_id', $this->document->id)
            ->whereNull('accepted_at')
            ->findOrFail($suggestionId);

        $this->document->update([
            'content' => $suggestion->suggestion_text,
            'version' => $this->document->version + 1,
        ]);

        $suggestion->update(['accepted_at' => now()]);
        $this->loadPendingSuggestions();
        $this->saved = true;

        $this->dispatch('suggestion-accepted', content: $suggestion->suggestion_text);
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
        return view('livewire.documents.editor');
    }
}
