<?php

namespace App\Livewire\Files;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Models\Files\Obj;
use App\Models\Team;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The shared Dot.Files tree, browsed one folder at a time.
 *
 * "Expand" is navigation, not an inline disclosure: every row is a button
 * that changes which folder is open, and breadcrumbs walk back up. That is
 * fully keyboard-operable with no tree widget to build, and it is why there
 * is no drag-and-drop here either - Move is a sheet-based folder picker,
 * which IS the keyboard-first pattern rather than a fallback bolted onto
 * one.
 *
 * currentTeam appears in exactly one place, resolveTeam(), and only to pick
 * WHICH workspace to open when no folder was named. Everything after that
 * derives from the parent object, which is authorised through ObjPolicy -
 * so a node from another team cannot be reached by putting its uuid in the
 * query string.
 */
class Navigator extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'folder', except: '')]
    public string $parentUuid = '';

    public bool $showFolderSheet = false;

    public string $newFolderName = '';

    public bool $showCreateSheet = false;

    public string $newTitle = '';

    /** The node being renamed, or '' when the rename sheet is closed. */
    public string $renamingUuid = '';

    public string $renameName = '';

    /** The node being moved, or '' when the move sheet is closed. */
    public string $movingUuid = '';

    public function open(string $uuid = ''): void
    {
        $this->parentUuid = $uuid;
        $this->reset(['renamingUuid', 'renameName', 'movingUuid']);
    }

    /**
     * The folder whose contents are on screen: the one named in the query
     * string, or this workspace's root.
     */
    #[Computed]
    public function parent(): Obj
    {
        $root = app(FilesService::class)->root($this->resolveTeam());

        if ($this->parentUuid === '') {
            return $root;
        }

        $node = Obj::where('uuid', $this->parentUuid)->first();

        if ($node === null || ! $node->isFolder() || ! auth()->user()->can('view', $node)) {
            return $root;
        }

        return $node;
    }

    /** @return Collection<int, Obj> */
    #[Computed]
    public function rows(): Collection
    {
        return app(FilesService::class)->children($this->parent());
    }

    /**
     * The trail from the root down to and including the open folder.
     *
     * @return list<Obj>
     */
    #[Computed]
    public function crumbs(): array
    {
        $parent = $this->parent();

        return [...$parent->ancestors(), $parent];
    }

    /**
     * Every folder in this team, deepest path first, for the Move picker.
     *
     * @return list<array{obj:Obj,label:string}>
     */
    #[Computed]
    public function folderChoices(): array
    {
        $folders = Obj::where('team_id', $this->parent()->team_id)
            ->where('objectable_type', 'folder')
            ->with('objectable')
            ->get();

        $byId = $folders->keyBy('id');
        $choices = [];

        foreach ($folders as $folder) {
            $trail = [$folder->name()];
            $node = $folder;

            while ($node->parent_id !== null && isset($byId[$node->parent_id])) {
                $node = $byId[$node->parent_id];
                array_unshift($trail, $node->name());
            }

            $choices[] = ['obj' => $folder, 'label' => implode(' / ', $trail)];
        }

        usort($choices, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $choices;
    }

    public function createFolder(): void
    {
        $this->validate(['newFolderName' => 'required|string|max:255']);

        $this->guarded(fn () => app(FilesService::class)
            ->createFolder($this->parent(), $this->newFolderName, auth()->user()), 'newFolderName');

        $this->showFolderSheet = false;
        $this->newFolderName = '';
        unset($this->rows);
    }

    public function createDocument(): void
    {
        $this->validate(['newTitle' => 'required|string|max:255']);

        $parent = $this->parent();
        $this->authorize('create', [Obj::class, $parent]);

        $document = app(DocumentStore::class)->create(auth()->user(), $this->newTitle, null, [
            'team_id' => $parent->team_id,
            'is_public' => false,
        ], $parent);

        $this->showCreateSheet = false;
        $this->newTitle = '';

        $this->redirect(route('documents.edit', $document->uuid));
    }

    /**
     * Import reuses the editor's own importer rather than a second upload
     * path: it opens a new document filed HERE, and the file goes into it
     * from the editor's Import menu.
     */
    public function importHere(): void
    {
        $parent = $this->parent();
        $this->authorize('create', [Obj::class, $parent]);

        $document = app(DocumentStore::class)->create(auth()->user(), 'Imported document', null, [
            'team_id' => $parent->team_id,
            'is_public' => false,
        ], $parent);

        session()->flash('status', 'Choose the file to import from the editor\'s Import menu.');

        $this->redirect(route('documents.edit', $document->uuid));
    }

    public function startRenaming(string $uuid): void
    {
        $node = $this->node($uuid);
        $this->authorize('rename', $node);

        $this->renamingUuid = $node->uuid;
        $this->renameName = $node->name();
    }

    public function cancelRenaming(): void
    {
        $this->reset(['renamingUuid', 'renameName']);
        $this->resetErrorBag('renameName');
    }

    public function renameObject(): void
    {
        if ($this->renamingUuid === '') {
            return;
        }

        $node = $this->node($this->renamingUuid);

        if (! $this->guarded(fn () => app(FilesService::class)
            ->renameObject($node, $this->renameName, auth()->user()), 'renameName')) {
            return;
        }

        $this->cancelRenaming();
        unset($this->rows);
    }

    public function startMoving(string $uuid): void
    {
        $node = $this->node($uuid);
        $this->authorize('move', $node);

        $this->movingUuid = $node->uuid;
    }

    public function cancelMoving(): void
    {
        $this->reset('movingUuid');
        $this->resetErrorBag('object');
    }

    public function moveTo(string $destinationUuid): void
    {
        if ($this->movingUuid === '') {
            return;
        }

        $node = $this->node($this->movingUuid);
        $destination = $this->node($destinationUuid);

        if (! $this->guarded(fn () => app(FilesService::class)
            ->moveObject($node, $destination, auth()->user()), 'object')) {
            return;
        }

        $this->cancelMoving();
        unset($this->rows);
    }

    public function deleteObject(string $uuid): void
    {
        $node = $this->node($uuid);

        $this->guarded(fn () => app(FilesService::class)->deleteObject($node, auth()->user()), 'object');

        unset($this->rows);
    }

    /** A 10-minute signed link to read a file inline. */
    public function fileUrl(Obj $node): string
    {
        return URL::temporarySignedRoute('files.view', now()->addMinutes(10), [
            'file' => $node->objectable?->uuid,
        ]);
    }

    public function render()
    {
        return view('livewire.files.navigator');
    }

    /**
     * Run a FilesService call, turning its refusals into errors on the field
     * that caused them rather than a 422 the person never sees.
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

    private function node(string $uuid): Obj
    {
        $node = Obj::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $node);

        return $node;
    }

    /**
     * The workspace to open when no folder was named. This is the ONLY
     * currentTeam read in the tree code - FilesService takes its team from
     * the parent object, so nothing downstream depends on this being right.
     */
    private function resolveTeam(): Team
    {
        $user = auth()->user();

        return $user->currentTeam ?? $user->personalTeam();
    }
}
