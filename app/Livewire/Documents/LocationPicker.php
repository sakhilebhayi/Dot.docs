<?php

namespace App\Livewire\Documents;

use App\Files\FilesService;
use App\Livewire\Files\BrowsesTheTree;
use App\Models\Files\Obj;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Where a new document gets filed — the smart-save sheet's location field.
 *
 * It is a component of its own, and it has ONE method callers use:
 * resolve(): Obj. Phase 3 adds recents, favourites and starred folders to
 * what it OFFERS (spec §5, §9) without any caller having to change, because
 * nothing outside it ever reads `selectedId` to mean "the location" — they
 * ask resolve() for the node.
 *
 * Browsing is drill-down, exactly the shape App\Livewire\Files\Navigator
 * already uses: opening a folder IS choosing it, and the crumbs walk back
 * up. That is one control per folder, fully keyboard-operable, with no tree
 * widget and no drag target (see .ai/rules/files.md, "Move is a sheet").
 *
 * Every id that arrives from the wire is authorised through ObjPolicy before
 * it becomes an answer: `selectedId` is a public property, so a client can
 * set it without ever calling selectFolder(), and resolve() is therefore the
 * gate rather than selectFolder() alone. A folder in another team is refused,
 * never silently accepted.
 */
class LocationPicker extends Component
{
    use AuthorizesRequests, BrowsesTheTree;

    /** The folder the page that opened this picker is standing in. */
    public ?int $currentFolderId = null;

    /** The folder currently chosen — also the one whose subfolders are listed. */
    public ?int $selectedId = null;

    public function mount(?int $currentFolderId = null): void
    {
        $this->currentFolderId = $currentFolderId;

        $root = $this->workspaceRoot();

        // A stale or foreign id lands on your OWN root rather than 403-ing:
        // the id arrives from a page whose own folder may have been moved or
        // deleted since it was rendered. BrowsesTheTree owns that rule so it
        // cannot drift away from the two pages that already apply it.
        $this->selectedId = $root === null
            ? null
            : $this->folderOrRoot($currentFolderId === null ? null : Obj::find($currentFolderId), $root)->id;
    }

    /**
     * Choose a folder — which is also how you walk into it.
     *
     * A node outside the signed-in person's team is REFUSED here rather than
     * quietly falling back, because unlike a stale bookmark this is a folder
     * the page claims to have just listed: if it is not yours, the request
     * did not come from the page.
     */
    public function selectFolder(int $objId): void
    {
        $obj = Obj::findOrFail($objId);
        $this->authorize('view', $obj);
        abort_unless($obj->isFolder(), 404);

        $this->selectedId = $obj->id;

        // The sheet that embeds this picker keeps its own copy of the answer
        // so it can name the destination in its own words; it is told, rather
        // than reading this component's state.
        $this->dispatch('location-chosen', objId: $obj->id);
    }

    /**
     * The chosen location. The ONE method callers use.
     */
    public function resolve(): Obj
    {
        abort_if($this->selectedId === null, 409, 'This account has no workspace to file anything in.');

        $obj = Obj::findOrFail($this->selectedId);
        $this->authorize('view', $obj);
        abort_unless($obj->isFolder(), 404);

        return $obj;
    }

    public function render()
    {
        $current = $this->openFolder();

        /** @var Collection<int, Obj> $folders */
        $folders = $current === null
            ? collect()
            : app(FilesService::class)->children($current)->filter(fn (Obj $obj) => $obj->isFolder())->values();

        return view('livewire.documents.location-picker', [
            'current' => $current,
            'folders' => $folders,
            'trail' => $current === null ? [] : [...$current->ancestors(), $current],
        ]);
    }

    /**
     * The folder whose contents are on screen, re-derived from the tree on
     * every render rather than trusted from the wire — and `selectedId` is
     * snapped back to it when they disagree, so a tampered id shows YOUR root
     * instead of leaving the sheet pointing at a folder it will not accept.
     */
    private function openFolder(): ?Obj
    {
        $root = $this->workspaceRoot();

        if ($root === null) {
            return null;
        }

        $open = $this->folderOrRoot($this->selectedId === null ? null : Obj::find($this->selectedId), $root);

        if ($open->id !== $this->selectedId) {
            $this->selectedId = $open->id;
        }

        return $open;
    }

    private function workspaceRoot(): ?Obj
    {
        $team = $this->workspaceTeam();

        return $team === null ? null : app(FilesService::class)->root($team);
    }
}
