<?php

namespace App\Console\Commands;

use App\Actions\Jetstream\DeleteUser;
use App\Models\Document;
use App\Models\Files\Obj;
use Illuminate\Console\Command;

/**
 * Removes for good the personal documents of deleted accounts once they have
 * been recoverable for 30 days (EA-2, owner decision 2026-11-26).
 *
 * Only documents that are BOTH in the bin AND without an owner are touched:
 * the owner becomes null only when the owning account is deleted (migration
 * 2026_11_26_000001), and DeleteUser hands every company document to its
 * team's owner first. Ordinary binned documents of people who still have an
 * account are never purged here.
 *
 * Runs after the nightly backup (02:35), so the last copy before a purge
 * still holds what it removes.
 */
class PurgeDeletedAccountDocuments extends Command
{
    protected $signature = 'doc:purge-deleted-accounts {--days='.DeleteUser::RECOVERABLE_DAYS.' : Keep them recoverable this many days}';

    protected $description = 'Permanently delete the personal documents of deleted accounts after 30 days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $purged = 0;

        Document::onlyTrashed()
            ->whereNull('owner_id')
            ->where('deleted_at', '<=', now()->subDays($days))
            ->chunkById(100, function ($documents) use (&$purged) {
                foreach ($documents as $document) {
                    Obj::where('objectable_type', 'document')->where('objectable_id', $document->id)->delete();
                    $document->forceDelete();
                    $purged++;
                }
            });

        $this->info("Purged {$purged} document(s) of deleted accounts.");

        return self::SUCCESS;
    }
}
