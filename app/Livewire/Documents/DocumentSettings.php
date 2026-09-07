<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Print\PageSetup;
use App\Services\TagRepository;
use App\Styles\StyleEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DocumentSettings extends Component
{
    use AuthorizesRequests;

    private const MARGIN_REGEX = '/^\d+(\.\d+)?(mm|cm|pt|in)$/';

    public Document $document;

    public string $title = '';

    public bool $isPublic = false;

    public bool $showDeleteConfirm = false;

    public string $transferEmail = '';

    public ?int $folderId = null;

    public string $newTagName = '';

    public string $pageSize = 'A4';

    public string $orientation = 'portrait';

    public string $marginTop = '25mm';

    public string $marginRight = '20mm';

    public string $marginBottom = '25mm';

    public string $marginLeft = '20mm';

    public string $header = '';

    public string $footer = '';

    public function mount(string $uuid): void
    {
        $this->document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $this->document);

        $this->title = $this->document->title;
        $this->isPublic = $this->document->is_public;
        $this->folderId = $this->document->folder_id;

        $setup = PageSetup::fromDocument($this->document, app(StyleEngine::class)->resolve($this->document));
        $this->pageSize = $setup->size;
        $this->orientation = $setup->orientation;
        $this->marginTop = $setup->margins['top'];
        $this->marginRight = $setup->margins['right'];
        $this->marginBottom = $setup->margins['bottom'];
        $this->marginLeft = $setup->margins['left'];
        $this->header = $setup->header;
        $this->footer = $setup->footer;
    }

    /**
     * page_setup is not document content (see .ai/rules/app.md's
     * DocumentStore-only-writer rule for content/content_json/etc.), so it
     * is written directly via ->update(). Header/footer templates can
     * reference $doc->variables (see PrintRenderer::band()), so content is
     * re-saved via DocumentStore::save() afterwards purely to re-render
     * those variables into content/content_json - 'version' => 'none'
     * means this never cuts a version snapshot on its own.
     */
    public function savePageSetup(): void
    {
        $this->authorize('update', $this->document);

        $this->validate([
            'pageSize' => 'required|in:A4,A3,Letter',
            'orientation' => 'required|in:portrait,landscape',
            'marginTop' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'marginRight' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'marginBottom' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'marginLeft' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'header' => 'nullable|string|max:200',
            'footer' => 'nullable|string|max:200',
        ]);

        $this->document->update([
            'page_setup' => [
                'size' => $this->pageSize,
                'orientation' => $this->orientation,
                'margins' => [
                    'top' => $this->marginTop,
                    'right' => $this->marginRight,
                    'bottom' => $this->marginBottom,
                    'left' => $this->marginLeft,
                ],
                'header' => $this->header,
                'footer' => $this->footer,
            ],
        ]);

        $this->document = app(DocumentStore::class)->save($this->document, $this->document->content_json, Auth::user(), ['version' => 'none']);

        session()->flash('status', 'Page setup saved.');
    }

    public function save(): void
    {
        $this->authorize('update', $this->document);
        $this->validate(['title' => 'required|string|max:255']);

        $this->document->update([
            'title' => $this->title,
            'is_public' => $this->isPublic,
        ]);

        $this->dispatch('settings-saved');
        session()->flash('status', 'Settings saved.');
    }

    /**
     * Every folder offered here belongs to the document's own scope
     * (its team, or the current user personally if it has none) --
     * a document can never be filed under a folder from a different
     * team/owner's space.
     */
    #[Computed]
    public function availableFolders()
    {
        return Folder::when($this->document->team_id, fn ($q) => $q->where('team_id', $this->document->team_id))
            ->when(! $this->document->team_id, fn ($q) => $q->whereNull('team_id')->where('owner_id', $this->document->owner_id))
            ->orderBy('name')
            ->get();
    }

    public function moveToFolder(): void
    {
        $this->authorize('update', $this->document);

        // The "No folder (root)" <option> submits an empty string, which
        // Livewire casts to 0 for a ?int property, not null -- normalize
        // it here, otherwise `folder_id => 0` hits the FK constraint
        // (folder ids start at 1) instead of clearing the folder.
        $folderId = $this->folderId ?: null;

        if ($folderId) {
            $folder = Folder::find($folderId);
            $inScope = $folder && (
                ($this->document->team_id && $folder->team_id === $this->document->team_id)
                || (! $this->document->team_id && $folder->owner_id === $this->document->owner_id)
            );

            if (! $inScope) {
                $this->addError('folderId', 'That folder is not available for this document.');

                return;
            }
        }

        $this->folderId = $folderId;
        $this->document->update(['folder_id' => $folderId]);
        session()->flash('status', 'Document moved.');
    }

    #[Computed]
    public function tags()
    {
        return $this->document->tags()->orderBy('name')->get();
    }

    public function addTag(): void
    {
        $this->authorize('update', $this->document);

        $name = trim($this->newTagName);
        if ($name === '') {
            return;
        }

        $tag = app(TagRepository::class)->findOrCreate(auth()->user(), $this->document->team_id, $name);
        $this->document->tags()->syncWithoutDetaching([$tag->id]);

        $this->newTagName = '';
    }

    public function removeTag(int $tagId): void
    {
        $this->authorize('update', $this->document);
        $this->document->tags()->detach($tagId);
    }

    public function transferOwnership(): void
    {
        $this->authorize('delete', $this->document);
        $this->validate(['transferEmail' => 'required|email|exists:users,email']);

        $newOwner = User::where('email', $this->transferEmail)->firstOrFail();
        $this->document->update(['owner_id' => $newOwner->id]);

        $this->transferEmail = '';
        session()->flash('status', 'Ownership transferred.');
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->document);
        $this->document->delete();

        $this->redirect(route('documents.index'));
    }

    public function render()
    {
        return view('livewire.documents.document-settings')
            ->layout('layouts.app');
    }
}
