<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\Team;
use App\Search\DocumentSearch;
use App\Services\TagRepository;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The documents ledger, standing in the shared Dot.Files tree.
 *
 * `$folderId` is an `objects` row id, not the id of the folder record it
 * points at, and null means "the workspace root". Everything the page shows
 * - subfolders, the document list, the breadcrumb - is derived from that
 * one node through App\Files\FilesService, so a folder made here is the
 * same row Dot.Files lists, and Dot.Doc has no folder table of its own any
 * more (see .ai/rules/files.md).
 *
 * currentTeam is read in exactly one place, workspaceTeam(), and only to
 * decide WHICH root to open. Every later call takes its team from the node
 * itself, which is what keeps a uuid/id in the query string from reaching
 * another team's rows.
 */
class Index extends Component
{
    use AuthorizesRequests;

    /**
     * How many search hits the list will consider. DocumentSearch ranks and
     * caps; the list then paginates within that window, so this is the depth
     * of the result set a person can page through, not a page size.
     */
    private const SEARCH_LIMIT = 200;

    public string $search = '';

    public string $filter = 'all'; // all | mine | shared | team

    /** The open folder's `objects` row id, or null for the workspace root. */
    public ?int $folderId = null;

    public ?int $tagId = null;

    public bool $showCreateModal = false;

    public string $newTitle = '';

    public bool $showFolderModal = false;

    public string $newFolderName = '';

    /** The folder node being renamed, or null when the rename sheet is closed. */
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

        unset($this->currentNode, $this->subfolders, $this->breadcrumbs);
    }

    public function filterByTag(?int $tagId): void
    {
        $this->tagId = $tagId;
        $this->perPage = 12;
    }

    /**
     * The node whose contents are on screen: the one named in the query
     * string, or this workspace's root. A node the signed-in user may not
     * view, or one that is not a folder, silently falls back to the root
     * rather than 403-ing a bookmark.
     */
    #[Computed]
    public function currentNode(): ?Obj
    {
        $team = $this->workspaceTeam();

        if ($team === null) {
            return null;
        }

        $files = app(FilesService::class);
        $root = $files->root($team);

        if ($this->folderId === null) {
            return $root;
        }

        $node = Obj::find($this->folderId);

        if ($node === null || ! $node->isFolder() || ! auth()->user()->can('view', $node)) {
            $this->folderId = null;

            return $root;
        }

        return $node;
    }

    /** The open folder, or null when standing at the workspace root. */
    #[Computed]
    public function currentFolder(): ?Obj
    {
        $node = $this->currentNode();

        return $node === null || $node->parent_id === null ? null : $node;
    }

    /**
     * The trail below the root, root itself excluded - the view renders the
     * root as its own "All documents" crumb.
     *
     * @return list<Obj>
     */
    #[Computed]
    public function breadcrumbs(): array
    {
        $node = $this->currentNode();

        if ($node === null) {
            return [];
        }

        return array_values(array_filter(
            [...$node->ancestors(), $node],
            fn (Obj $crumb) => $crumb->parent_id !== null,
        ));
    }

    /** @return Collection<int, Obj> */
    #[Computed]
    public function subfolders()
    {
        $node = $this->currentNode();

        if ($node === null) {
            return collect();
        }

        return app(FilesService::class)
            ->children($node)
            ->filter(fn (Obj $obj) => $obj->isFolder())
            ->values();
    }

    #[Computed]
    public function availableTags()
    {
        return app(TagRepository::class)->availableFor(auth()->user(), auth()->user()->currentTeam?->id);
    }

    #[Computed]
    public function documents()
    {
        $user = auth()->user();
        $search = app(DocumentSearch::class);
        $searching = $search->isSearchable($this->search);

        return Document::query()
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $user->id));

                if ($user->currentTeam) {
                    $q->orWhere('team_id', $user->currentTeam->id);
                }
            })
            // A search of two characters or more goes through DocumentSearch,
            // which is the tsvector query on pgsql and LIKE over
            // title/search_text on sqlite. The old inline LIKE searched
            // `content` - the rendered HTML - so a word split by a tag never
            // matched and a tag name did. Anything shorter than two
            // characters is not treated as a search at all: the list keeps
            // its folder/filter/tag behaviour.
            ->when($searching, fn ($q) => $q->whereIn(
                'id',
                $search->search($user, $this->search, self::SEARCH_LIMIT)->pluck('id'),
            ))
            ->when($this->filter === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            ->when($this->filter === 'shared', fn ($q) => $q->whereHas('collaborators', fn ($q) => $q->where('user_id', $user->id)))
            ->when($this->filter === 'team', fn ($q) => $user->currentTeam ? $q->where('team_id', $user->currentTeam->id) : $q)
            ->when($this->tagId, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('tags.id', $this->tagId)))
            // A search or tag filter searches the whole space, not just
            // the current folder -- otherwise finding something means
            // already knowing which folder it's in. Otherwise the list is
            // exactly what the tree says is filed in the open folder.
            ->when(
                ! $searching && ! $this->tagId && $this->currentNode() !== null,
                fn ($q) => $q->whereHas('node', fn ($q) => $q->where('parent_id', $this->currentNode()->id)),
            )
            ->latest()
            ->paginate($this->perPage);
    }

    public function createDocument(): void
    {
        $this->validate(['newTitle' => 'required|string|max:255']);

        $parent = $this->currentNode();

        if ($parent !== null) {
            $this->authorize('create', [Obj::class, $parent]);
        }

        $document = app(DocumentStore::class)->create(auth()->user(), $this->newTitle, null, [
            'is_public' => false,
        ], $parent);

        $this->showCreateModal = false;
        $this->newTitle = '';

        $this->redirect(route('documents.edit', $document->uuid));
    }

    public function createFolder(): void
    {
        $this->validate(['newFolderName' => 'required|string|max:255']);

        $parent = $this->currentNode();

        if ($parent === null) {
            $this->addError('newFolderName', 'There is no workspace to make a folder in.');

            return;
        }

        if (! $this->guarded(fn () => app(FilesService::class)
            ->createFolder($parent, $this->newFolderName, auth()->user()), 'newFolderName')) {
            return;
        }

        $this->showFolderModal = false;
        $this->newFolderName = '';

        unset($this->subfolders);
    }

    /**
     * Open the rename sheet. The old view called the browser's native prompt()
     * from an inline onclick, which is unstyled, untranslatable and untestable.
     */
    public function startRenamingFolder(int $folderId): void
    {
        $node = $this->folderNode($folderId);
        $this->authorize('rename', $node);

        $this->renamingFolderId = $node->id;
        $this->renameFolderName = $node->name();
    }

    public function cancelRenamingFolder(): void
    {
        $this->renamingFolderId = null;
        $this->renameFolderName = '';
        $this->resetErrorBag('renameFolderName');
    }

    public function renameFolder(?int $folderId = null, ?string $name = null): void
    {
        $folderId ??= $this->renamingFolderId;
        if ($folderId === null) {
            return;
        }

        $node = $this->folderNode($folderId);
        $this->authorize('rename', $node);

        $name = trim($name ?? $this->renameFolderName);
        if ($name === '') {
            $this->addError('renameFolderName', 'Give the folder a name.');

            return;
        }

        if (! $this->guarded(fn () => app(FilesService::class)
            ->renameObject($node, $name, auth()->user()), 'renameFolderName')) {
            return;
        }

        $this->cancelRenamingFolder();
        unset($this->subfolders, $this->breadcrumbs);
    }

    /**
     * Deleting a folder REFUSES while anything is filed in it - the tree is
     * shared with Dot.Files now, and cascading would take real documents and
     * files down with a piece of filing. The old behaviour (orphan the
     * documents to the root) silently rearranged a person's filing instead
     * of telling them what was in the way. See App\Files\FilesService.
     */
    public function deleteFolder(int $folderId): void
    {
        $node = $this->folderNode($folderId);
        $this->authorize('delete', $node);

        $this->guarded(fn () => app(FilesService::class)->deleteObject($node, auth()->user()), 'folder');

        unset($this->subfolders);
    }

    public function render()
    {
        return view('livewire.documents.index')
            ->layout('layouts.app')
            ->title('Documents');
    }

    /**
     * The workspace whose root this page opens on. The ONE currentTeam read
     * in the tree code; every team decision after it comes off a node.
     */
    private function workspaceTeam(): ?Team
    {
        $user = auth()->user();

        return $user->currentTeam ?? $user->personalTeam();
    }

    private function folderNode(int $id): Obj
    {
        $node = Obj::findOrFail($id);

        abort_unless($node->isFolder(), 404);

        return $node;
    }

    /**
     * Run a FilesService call, turning its refusals into an error on the
     * field that caused them rather than a 422 nobody sees.
     */
    private function guarded(callable $call, string $field): bool
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $this->addError($field, collect($e->errors())->flatten()->first() ?? 'That could not be done.');

            return false;
        }

        return true;
    }
}
