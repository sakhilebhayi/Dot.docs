<?php

namespace App\Livewire\Files;

use App\Models\Files\Obj;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * What every page standing in the shared tree needs, in one place.
 *
 * Two components browse the tree - the documents ledger
 * (App\Livewire\Documents\Index) and the Files navigator - and they had
 * their own copies of all three of these. They are small, which is exactly
 * why they drift: a fix to the fallback rule or the refusal wording on one
 * page would quietly not apply to the other.
 */
trait BrowsesTheTree
{
    /**
     * The workspace to open when no folder was named.
     *
     * This is the ONLY currentTeam read in the tree code. FilesService takes
     * its team from the node it was handed, so nothing downstream depends on
     * this being right - it only decides which root a page opens on. Null
     * for an account with no team at all: impossible through Jetstream's
     * CreateNewUser, reachable from a factory, and each caller decides
     * whether that is an empty page or a refusal.
     */
    protected function workspaceTeam(): ?Team
    {
        $user = auth()->user();

        return $user->currentTeam ?? $user->personalTeam();
    }

    /**
     * The folder whose contents to show: the node that was asked for, or the
     * workspace root.
     *
     * A node that is missing, is not a folder, or belongs to a team the
     * signed-in person is not in falls back to their own root rather than
     * 403-ing. A folder id outlives the folder - it is in bookmarks, in
     * links people paste to each other, in a query string from before a
     * reorganisation - and landing somewhere sensible is the right answer to
     * a stale one. A FOREIGN id discloses nothing by falling back either:
     * the page then shows the viewer's own root, never the other team's.
     */
    protected function folderOrRoot(?Obj $node, Obj $root): Obj
    {
        if ($node === null || ! $node->isFolder() || auth()->user()?->can('view', $node) !== true) {
            return $root;
        }

        return $node;
    }

    /**
     * Run a FilesService call, turning its refusals into an error on the
     * field that caused them rather than a 422 nobody sees.
     */
    protected function guarded(callable $call, string $field): bool
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
