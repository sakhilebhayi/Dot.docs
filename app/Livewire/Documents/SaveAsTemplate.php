<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Services\HtmlSanitizer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class SaveAsTemplate extends Component
{
    use AuthorizesRequests;

    public Document $document;

    public bool $show = false;

    public string $name = '';

    public string $category = 'general';

    public string $description = '';

    public bool $shareWithTeam = false;

    public array $categories = ['general', 'resume', 'proposal', 'notes', 'blog'];

    public function mount(Document $document): void
    {
        $this->document = $document;
        $this->name = $document->title;
    }

    public function open(): void
    {
        $this->show = true;
    }

    public function save(): void
    {
        $this->authorize('update', $this->document);

        $this->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:general,resume,proposal,notes,blog',
            'description' => 'nullable|string|max:500',
        ]);

        $sanitizer = app(HtmlSanitizer::class);

        DocumentTemplate::create([
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            // JSON is the template's content from here on; the sanitised HTML
            // stays beside it as the legacy column DocumentTemplate::contentJson()
            // falls back to and the gallery previews. The style and page setup
            // travel with it so a document made from the template looks like
            // the one it was made from.
            'content_json' => app(DocumentStore::class)->json($this->document),
            'style_key' => $this->document->style_key ?: 'report',
            'page_setup' => $this->document->page_setup,
            'content' => $sanitizer->clean($this->document->content ?? ''),
            'is_global' => false,
            'team_id' => $this->shareWithTeam ? auth()->user()->currentTeam?->id : null,
            'created_by' => auth()->id(),
        ]);

        $this->show = false;
        $this->dispatch('template-saved');
        session()->flash('status', 'Template saved successfully.');
    }

    public function render()
    {
        return view('livewire.documents.save-as-template');
    }
}
