<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\Folder;
use App\Services\TagRepository;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public string $search = '';

    public string $filter = 'all'; // all | mine | shared | team

    public ?int $folderId = null;

    public ?int $tagId = null;

    public bool $showCreateModal = false;

    public string $newTitle = '';

    public bool $showFolderModal = false;

    public string $newFolderName = '';

    /** The folder being renamed, or null when the rename sheet is closed. */
    public ?int $renamingFolderId = null;

    public string $renameFolderName = '';

    public int $perPage = 12;

    public function mount(): void
    {
        $this->folderId = request()->integer('folder') ?: null;
        $this->tagId = request()->integer('tag') ?: null;

        // The navigator rail links straight to a filter (Shared with me), so
        // the query string seeds it once at mount.
        $filter = (string) request()->query('filter', 'all');
        $this->filter = in_array($filter, ['all', 'mine', 'shared', 'team'], true) ? $filter : 'all';
    }

    public function updatingSearch(): void
    {
        $this->perPage = 12;
    }

    public function updatingFilter(): void
    {
        $this->perPage = 12;
    }

    public function loadMore(): void
    {
        $this->perPage += 12;
    }

    public function openFolder(?int $folderId): void
    {
        $this->folderId = $folderId;
        $this->tagId = null;
        $this->perPage = 12;
    }

    public function filterByTag(?int $tagId): void
    {
        $this->tagId = $tagId;
        $this->perPage = 12;
    }

    /**
     * The team/personal scope the current view operates in -- folders and
     * tags are always resolved against this, the same duality Document
     * itself uses (a team's shared space, or the signed-in user's own).
     */
    private function currentTeamId(): ?int
    {
        return auth()->user()->currentTeam?->id;
    }

    #[Computed]
    public function currentFolder()
    {
        if (! $this->folderId) {
            return null;
        }

        $folder = Folder::find($this->folderId);

        return ($folder && auth()->user()->can('view', $folder)) ? $folder : null;
    }

    #[Computed]
    public function breadcrumbs(): array
    {
        return $this->currentFolder ? [...$this->currentFolder->breadcrumbs(), $this->currentFolder] : [];
    }

    #[Computed]
    public function subfolders()
    {
        $teamId = $this->currentTeamId();

        return Folder::where('parent_id', $this->folderId)
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when(! $teamId, fn ($q) => $q->whereNull('team_id')->where('owner_id', auth()->id()))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availableTags()
    {
        return app(TagRepository::class)->availableFor(auth()->user(), $this->currentTeamId());
    }

    #[Computed]
    public function documents()
    {
        $user = auth()->user();

        return Document::query()
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $user->id));

                if ($user->currentTeam) {
                    $q->orWhere('team_id', $user->currentTeam->id);
                }
            })
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('content', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filter === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            ->when($this->filter === 'shared', fn ($q) => $q->whereHas('collaborators', fn ($q) => $q->where('user_id', $user->id)))
            ->when($this->filter === 'team', fn ($q) => $user->currentTeam ? $q->where('team_id', $user->currentTeam->id) : $q)
            ->when($this->tagId, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('tags.id', $this->tagId)))
            // A search or tag filter searches the whole space, not just
            // the current folder -- otherwise finding something means
            // already knowing which folder it's in.
            ->when(! $this->search && ! $this->tagId, fn ($q) => $q->where('folder_id', $this->folderId))
            ->latest()
            ->paginate($this->perPage);
    }

    public function createDocument(): void
    {
        $this->validate(['newTitle' => 'required|string|max:255']);

        $document = app(DocumentStore::class)->create(auth()->user(), $this->newTitle, null, [
            'folder_id' => $this->folderId,
            'is_public' => false,
        ]);

        $this->showCreateModal = false;
        $this->newTitle = '';

        $this->redirect(route('documents.edit', $document->uuid));
    }

    public function createFolder(): void
    {
        $this->authorize('create', Folder::class);
        $this->validate(['newFolderName' => 'required|string|max:255']);

        Folder::create([
            'owner_id' => auth()->id(),
            'team_id' => $this->currentTeamId(),
            'parent_id' => $this->folderId,
            'name' => $this->newFolderName,
        ]);

        $this->showFolderModal = false;
        $this->newFolderName = '';
    }

    /**
     * Open the rename sheet. The old view called the browser's native prompt()
     * from an inline onclick, which is unstyled, untranslatable and untestable.
     */
    public function startRenamingFolder(int $folderId): void
    {
        $folder = Folder::findOrFail($folderId);
        $this->authorize('update', $folder);

        $this->renamingFolderId = $folder->id;
        $this->renameFolderName = $folder->name;
    }

    public function cancelRenamingFolder(): void
    {
        $this->renamingFolderId = null;
        $this->renameFolderName = '';
    }

    public function renameFolder(?int $folderId = null, ?string $name = null): void
    {
        $folderId ??= $this->renamingFolderId;
        if ($folderId === null) {
            return;
        }

        $folder = Folder::findOrFail($folderId);
        $this->authorize('update', $folder);

        $name = trim($name ?? $this->renameFolderName);
        if ($name === '') {
            $this->addError('renameFolderName', 'Give the folder a name.');

            return;
        }

        $folder->update(['name' => $name]);
        $this->cancelRenamingFolder();
    }

    public function deleteFolder(int $folderId): void
    {
        $folder = Folder::findOrFail($folderId);
        $this->authorize('delete', $folder);

        // Documents inside are orphaned to the root (folder_id
        // nullOnDelete in the migration), never deleted with the folder.
        $folder->delete();
    }

    public function render()
    {
        return view('livewire.documents.index')
            ->layout('layouts.app')
            ->title('Documents');
    }
}
