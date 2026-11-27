<?php

namespace App\Livewire\Documents\Concerns;

use App\Files\FilesService;
use App\Models\Files\Obj;
use Illuminate\Contracts\View\View;

/**
 * The documents page's folder actions: make, rename and delete a folder in the shared Dot.Files tree (split out of Index, R2-10).
 */
trait ManagesFolders
{
    public bool $showFolderModal = false;

    public string $newFolderName = '';

    /** The folder node being renamed, or null when the rename sheet is closed. */
    public ?int $renamingFolderId = null;

    public string $renameFolderName = '';

    public function createFolder(): void
    {
        $this->validate(['newFolderName' => 'required|string|max:255']);

        $parent = $this->currentNode();

        if ($parent === null) {
            $this->addError('newFolderName', 'There is no workspace to make a folder in.');

            return;
        }

        if (! $this->guarded(fn () => app(FilesService::class)
            ->createFolder($parent, $this->newFolderName, $this->actor()), 'newFolderName')) {
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
            ->renameObject($node, $name, $this->actor()), 'renameFolderName')) {
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

        $this->guarded(fn () => app(FilesService::class)->deleteObject($node, $this->actor()), 'folder');

        unset($this->subfolders);
    }

    private function folderNode(int $id): Obj
    {
        $node = Obj::findOrFail($id);

        abort_unless($node->isFolder(), 404);

        return $node;
    }
}
