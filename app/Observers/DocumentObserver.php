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
        $this->freeSlug($document);
    }

    /**
     * A soft-deleted document must not permanently reserve its published
     * address: `documents.slug` has a plain unique index, not one scoped to
     * exclude `deleted_at`, so leaving the slug in place means a trashed
     * document blocks every future document from ever claiming that name
     * (and the ShareManager::saveSlug() "somebody has already taken that
     * address" message would be actively misleading about who).
     *
     * Runs through the query builder rather than $document->update(): a
     * mass-update Eloquent query does not fire model events, so this cannot
     * recurse into `updated`/`deleted` again, and it works via
     * `withTrashed()` because the row already carries `deleted_at` by the
     * time this observer method runs. Restore does not bring the slug back
     * - keeping that semantics simple is a deliberate choice, not an
     * oversight.
     */
    private function freeSlug(Document $document): void
    {
        if ($document->slug === null) {
            return;
        }

        Document::withTrashed()->whereKey($document->getKey())->update(['slug' => null]);
        $document->slug = null;
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
