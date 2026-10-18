<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\InfoDot\ResourceRegistry;

/**
 * Keeps InfoDot's pointer to a document in step with the document: created in a linked
 * company, moved to another company, trashed, restored or erased. Always after the response,
 * so saving a document never waits for (or fails because of) InfoDot.
 */
class InfoDotResourceObserver
{
    public function created(Document $document): void
    {
        $this->register($document);
    }

    public function updated(Document $document): void
    {
        // Autosave updates a document constantly; only a change of company matters here.
        if ($document->wasChanged('team_id')) {
            $this->register($document);
        }
    }

    public function restored(Document $document): void
    {
        $this->register($document);
    }

    public function deleted(Document $document): void
    {
        $this->remove($document);
    }

    public function forceDeleted(Document $document): void
    {
        $this->remove($document);
    }

    protected function register(Document $document): void
    {
        $uuid = (string) $document->uuid;
        $teamId = $document->team_id;

        dispatch(fn () => app(ResourceRegistry::class)->registerPointer($uuid, $teamId))->afterResponse();
    }

    protected function remove(Document $document): void
    {
        if ($document->infodot_resource_uuid === null) {
            return;
        }

        $uuid = (string) $document->uuid;
        dispatch(fn () => app(ResourceRegistry::class)->remove($uuid))->afterResponse();
    }
}
