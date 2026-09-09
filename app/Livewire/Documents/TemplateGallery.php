<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentTemplate;
use Illuminate\Support\Str;
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
        return ['all', 'resume', 'proposal', 'notes', 'blog', 'general'];
    }

    #[Computed]
    public function templates()
    {
        $user = auth()->user();

        return DocumentTemplate::query()
            ->where(function ($q) use ($user) {
                $q->where('is_global', true);

                if ($user->currentTeam) {
                    $q->orWhere('team_id', $user->currentTeam->id);
                }

                $q->orWhere('created_by', $user->id);
            })
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
        $template = DocumentTemplate::where('id', $templateId)
            ->where(function ($q) use ($user) {
                $q->where('is_global', true)
                    ->orWhere('created_by', $user->id);

                if ($user->currentTeam) {
                    $q->orWhere('team_id', $user->currentTeam->id);
                }
            })
            ->firstOrFail();

        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => $template->name,
            'content' => $template->content,
            'owner_id' => $user->id,
            'team_id' => $user->currentTeam?->id,
            'version' => 1,
        ]);

        $this->redirect(route('documents.edit', $document->uuid), navigate: true);
    }

    public function render()
    {
        return view('livewire.documents.template-gallery');
    }
}
