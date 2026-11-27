<?php

namespace App\Livewire\Documents\Concerns;

use App\Documents\DocumentStore;
use App\Documents\Import\HtmlToJson;
use App\Models\AiSuggestion;
use Illuminate\Support\Str;

/**
 * The editor's queue of AI suggestions waiting for a decision (split out of Editor, R2-10).
 */
trait ReviewsSuggestions
{
    public function acceptSuggestion(int $suggestionId): void
    {
        $this->authorize('update', $this->document);

        $suggestion = AiSuggestion::where('document_id', $this->document->id)
            ->whereNull('accepted_at')
            ->findOrFail($suggestionId);

        // No base is stated: accepting replaces whatever is stored, which
        // may be somebody else's save from a moment ago. The store keeps
        // that first (see DocumentStore::save()).
        $json = app(HtmlToJson::class)->convert($suggestion->suggestion_text);
        $this->document = app(DocumentStore::class)->save($this->document, $json, $this->actor(), [
            'version' => 'named',
            'label' => 'Accepted suggestion',
            'keepReplacedAs' => 'Before accepted suggestion',
        ]);
        $suggestion->update(['accepted_at' => now()]);
        $this->loadPendingSuggestions();
        $this->saved = true;

        // `version` so the page can move its base up: this save came from
        // the page itself, and its next autosave must not be refused as stale.
        $this->dispatch('suggestion-accepted', content: $this->document->content_json, version: $this->document->version);
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

    private function loadPendingSuggestions(): void
    {
        $this->pendingSuggestions = AiSuggestion::where('document_id', $this->document->id)
            ->whereNull('accepted_at')
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'user' => $s->user->name ?? 'Former member',
                'created_at' => $s->created_at->diffForHumans(),
                'excerpt' => Str::limit(strip_tags($s->suggestion_text), 80),
            ])
            ->toArray();
    }
}
