<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Files\FilesService;
use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings back a personal document of a deleted account inside its 30 days
 * (EA-2) and gives it to a living account in one step - a document must never
 * be live with no owner (pages print the owner's name).
 *
 *   php artisan doc:restore-deleted-account-document {document uuid} {new owner's email}
 *
 * The document lands at the root of the new owner's personal team.
 */
class RestoreDeletedAccountDocument extends Command
{
    protected $signature = 'doc:restore-deleted-account-document {uuid : Document uuid} {email : Email of the account that will own it}';

    protected $description = 'Restore a deleted account\'s document (within 30 days) to another account';

    public function handle(AuditLogger $audit, FilesService $files): int
    {
        $document = Document::onlyTrashed()->whereNull('owner_id')->where('uuid', $this->argument('uuid'))->first();
        $owner = User::where('email', $this->argument('email'))->first();

        if (! $document instanceof Document) {
            $this->error('No deleted-account document with that uuid (already purged, or not from a deleted account).');

            return self::FAILURE;
        }

        $home = $owner?->personalTeam();

        if (! $owner instanceof User || $home === null) {
            $this->error('No account with that email, or it has no personal team.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($document, $owner, $home, $audit, $files) {
            $document->forceFill(['owner_id' => $owner->id, 'team_id' => $home->id])->saveQuietly();
            $document->restore();
            $files->registerDocument($document, $files->root($home));

            $audit->record('document.restored', $document, [
                'reason' => 'deleted_account_document_recovered',
                'to_user_id' => $owner->id,
            ], $owner);
        });

        $this->info("Restored \"{$document->title}\" to {$owner->email}.");

        return self::SUCCESS;
    }
}
