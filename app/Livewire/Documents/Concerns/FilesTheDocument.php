<?php

namespace App\Livewire\Documents\Concerns;

use App\Files\FilesService;
use App\Models\Document;
use App\Models\Files\Obj;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * Where the open document is filed in the shared Dot.Files tree, and moving it (split out of Editor, R2-10).
 */
trait FilesTheDocument
{
    /**
     * Where this document is filed, root first - the location chip in the
     * bench. Empty when the document has no tree node at all, which only a
     * factory-made account with no team can produce.
     *
     * @return list<Obj>
     */
    #[Computed]
    public function locationCrumbs(): array
    {
        $node = $this->node();

        if ($node === null) {
            return [];
        }

        $parent = $node->parent;

        return $parent === null ? [] : [...$parent->ancestors(), $parent];
    }

    /**
     * Folders this document can be moved into, taken from the node's OWN
     * team - never the session's current team.
     *
     * @return list<array{id:int,uuid:string,label:string,depth:int}>
     */
    #[Computed]
    public function folderChoices(): array
    {
        $node = $this->node();

        return $node === null ? [] : app(FilesService::class)->folderChoices($node->team_id);
    }

    /**
     * File the document somewhere else. The sheet is the SAME APG pattern
     * the documents index uses for rename - Escape closes it and returns
     * focus - and it is the only move affordance: no drag-and-drop, so
     * there is nothing a keyboard cannot reach.
     */
    public function moveTo(int $destinationId): void
    {
        $this->authorize('update', $this->document);

        $node = $this->node();
        $destination = Obj::find($destinationId);

        if ($node === null || $destination === null || ! $destination->isFolder() || $destination->team_id !== $node->team_id) {
            $this->addError('location', 'That folder is not available for this document.');

            return;
        }

        try {
            app(FilesService::class)->moveObject($node, $destination, $this->actor());
        } catch (ValidationException $e) {
            $this->addError('location', collect($e->errors())->flatten()->first() ?? 'That move is not allowed.');

            return;
        }

        $this->showMoveSheet = false;
        unset($this->locationCrumbs, $this->folderChoices);

        // Said in the page's banner, now (R2-9): a session flash from a Livewire action is never shown by that
        // action's own response and surfaced, stale, on some later page instead.
        $this->dispatch('banner-message', style: 'success', message: 'Filed in '.$destination->name().'.');
    }

    /** This document's node in the shared tree, filed at its workspace root if it has none. */
    private function node(): ?Obj
    {
        $node = $this->document->node()->first();

        if ($node !== null) {
            return $node;
        }

        $team = $this->document->team ?? $this->document->owner?->personalTeam();

        if ($team === null) {
            return null;
        }

        $files = app(FilesService::class);

        return $files->registerDocument($this->document, $files->root($team));
    }
}
