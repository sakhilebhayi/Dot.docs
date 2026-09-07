<?php

namespace App\Observers;

use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DocumentObserver
{
    /**
     * Assign a UUID before the document is first saved.
     */
    public function creating(Document $document): void
    {
        $document->uuid = (string) Str::uuid();
    }

    /**
     * Bust caches whenever the document changes. Version snapshotting and
     * webhook firing now live in App\Documents\DocumentStore, the single
     * write path for document content.
     */
    public function updated(Document $document): void
    {
        $this->bustDocumentCache($document);
    }

    public function deleted(Document $document): void
    {
        $this->bustDocumentCache($document);
    }

    private function bustDocumentCache(Document $document): void
    {
        // Forget all per-user permission cache entries for this document.
        // We iterate over users who have a relationship with this document.
        $userIds = collect([$document->owner_id])
            ->merge($document->collaborators()->pluck('user_id'))
            ->unique();

        foreach ($userIds as $userId) {
            Cache::forget("doc.view.{$userId}.{$document->id}");
            Cache::forget("doc.update.{$userId}.{$document->id}");
        }

        Cache::forget("doc.content.{$document->uuid}");
    }
}
