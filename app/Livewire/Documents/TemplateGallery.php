<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Models\DocumentTemplate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class TemplateGallery extends Component
{
    public bool $show = false;

    public string $activeCategory = 'all';

    /**
     * The navigator rail links to /documents?gallery=1, so the gallery has to
     * be open on arrival - server-side, not after a JavaScript round trip.
     */
    public function mount(): void
    {
        $this->show = request()->boolean('gallery');
    }

    #[On('open')]
    public function open(): void
    {
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    #[Computed]
    public function categories(): array
    {
        return ['all', 'resume', 'proposal', 'notes', 'blog', 'general', 'report'];
    }

    #[Computed]
    public function templates()
    {
        $user = auth()->user();

        return DocumentTemplate::query()
            ->visibleTo($user)
            ->when($this->activeCategory !== 'all', fn ($q) => $q->where('category', $this->activeCategory))
            ->orderBy('is_global', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function useTemplate(int $templateId): void
    {
        $user = auth()->user();

        // Scope to the same visibility rule as the templates() list: global,
        // the user's own team, or authored by the user. Prevents an
        // authenticated user from pulling another team's private template
        // content by guessing/incrementing the templateId argument.
        $template = DocumentTemplate::query()->visibleTo($user)->whereKey($templateId)->firstOrFail();

        // Content goes in as JSON through DocumentStore, which renders the
        // HTML, numbers the outline against the template's own style and
        // fills search_text/word_count - see .ai/rules/app.md. The style and
        // page setup travel with the content: a mining production report on
        // the default portrait `report` style is not the template.
        $attrs = ['style_key' => $template->style_key ?: 'report'];
        if (is_array($template->page_setup) && $template->page_setup !== []) {
            $attrs['page_setup'] = $template->page_setup;
        }

        $document = app(DocumentStore::class)->create($user, $template->name, $template->contentJson(), $attrs);

        $this->redirect(route('documents.edit', $document->uuid), navigate: true);
    }

    public function render()
    {
        return view('livewire.documents.template-gallery');
    }
}
