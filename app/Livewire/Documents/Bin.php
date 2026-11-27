<?php

namespace App\Livewire\Documents;

use App\Audit\AuditLogger;
use App\Files\FilesService;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use App\Search\CompanyDocuments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The bin (R2-3 / F-09): documents deleted in the company the person is standing in, and the way back.
 *
 * Deleting a document has always been a soft delete (FilesService::deleteObject), but nothing could bring one back.
 * Listed: what the person may restore, which is what they may delete - the owner, or an admin of its company.
 * Restoring puts the document back with all of its versions and files it at the top of the company's Files, since
 * the folder it was in may have changed or gone since.
 */
#[Title('Bin')]
class Bin extends Component
{
    public ?string $restored = null;

    /** @return Collection<int, Document> */
    #[Computed]
    public function documents(): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;

        return CompanyDocuments::scope(Document::onlyTrashed(), $team?->id, $user->id)
            ->select(['id', 'uuid', 'title', 'owner_id', 'team_id', 'version', 'deleted_at'])
            // team: the restore check reads it for every row.
            ->with(['owner:id,name', 'team'])
            ->latest('deleted_at')
            ->limit(200)
            ->get()
            ->filter(fn (Document $doc) => $user->can('restore', $doc))
            ->values();
    }

    public function restore(string $uuid): void
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;

        $document = CompanyDocuments::scope(Document::onlyTrashed(), $team?->id, $user->id)
            ->where('uuid', $uuid)
            ->first();
        // Not in this company's bin: the same 404 as no such document.
        abort_if($document === null, 404);
        Gate::authorize('restore', $document);

        $files = app(FilesService::class);

        $wasPublic = (bool) $document->is_public;

        DB::transaction(function () use ($document, $files, $user) {
            $document->restore();
            // Back as private: deleting took a published link down, and restoring must not quietly put it back up.
            if ($document->is_public) {
                $document->forceFill(['is_public' => false])->saveQuietly();
            }

            $owner = $document->owner;
            $home = $document->team instanceof Team ? $document->team : ($owner instanceof User ? $owner->personalTeam() : null);
            if ($home instanceof Team) {
                $files->registerDocument($document, $files->root($home));
            }

            app(AuditLogger::class)->record('document.restored', $document, [], $user);
        });

        unset($this->documents);
        $this->restored = '“'.($document->title ?: 'Untitled').'” is back, at the top of Files.'
            .($wasPublic ? ' Its public link is off; publish it again from Sharing if it should be.' : '');
    }

    public function render()
    {
        return view('livewire.documents.bin')->layout('layouts.app');
    }
}
