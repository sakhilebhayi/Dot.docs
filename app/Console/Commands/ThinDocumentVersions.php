<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * R2-5 / P-02: version history no longer grows without limit. Kept for ever: every version that is not automatic
 * (named, restore, "before restoring", imports) and each document's newest version. Automatic versions: all of them
 * for `docs.versions.keep_all_days`, then the last of each day until `daily_until_days`, then the last of each week.
 *
 * Works one document at a time and deletes in small batches, so the write lock is never held for long; a document
 * being edited meanwhile only ever gains newer versions, which are inside the keep-all window.
 */
class ThinDocumentVersions extends Command
{
    protected $signature = 'documents:thin-versions {--dry-run : Count what would go, remove nothing}';

    protected $description = 'Thin old automatic versions: all for a week, one a day to 90 days, then one a week';

    private const BATCH = 200;

    public function handle(): int
    {
        $keepAll = now()->subDays(max(1, (int) config('docs.versions.keep_all_days', 7)));
        $dailyUntil = now()->subDays(max(1, (int) config('docs.versions.daily_until_days', 90)));
        $dry = (bool) $this->option('dry-run');
        $removed = 0;
        $documents = 0;

        DB::table('document_versions')
            ->where('kind', 'auto')
            ->where('created_at', '<', $keepAll)
            ->distinct()
            ->orderBy('document_id')
            ->pluck('document_id')
            ->each(function (int $documentId) use ($keepAll, $dailyUntil, $dry, &$removed, &$documents) {
                $doomed = $this->doomed($documentId, $keepAll, $dailyUntil);
                if ($doomed === []) {
                    return;
                }
                $documents++;
                $removed += count($doomed);
                if (! $dry) {
                    foreach (array_chunk($doomed, self::BATCH) as $ids) {
                        DB::table('document_versions')->whereIn('id', $ids)->delete();
                    }
                }
            });

        $this->info(sprintf('%s %d automatic version(s) across %d document(s).', $dry ? 'Would remove' : 'Removed', $removed, $documents));

        return self::SUCCESS;
    }

    /**
     * The ids of one document's automatic versions older than the keep-all line that are not the last of their day
     * (or, past the daily line, of their week), never the document's newest version.
     *
     * @return list<int>
     */
    private function doomed(int $documentId, Carbon $keepAll, Carbon $dailyUntil): array
    {
        $newest = DB::table('document_versions')->where('document_id', $documentId)->max('version_number');

        $rows = DB::table('document_versions')
            ->where('document_id', $documentId)
            ->where('kind', 'auto')
            ->where('created_at', '<', $keepAll)
            ->orderBy('created_at')
            ->orderBy('version_number')
            ->get(['id', 'version_number', 'created_at']);

        $lastOfPeriod = [];
        foreach ($rows as $row) {
            $at = Carbon::parse($row->created_at);
            $period = $at->lt($dailyUntil) ? 'w'.$at->format('o-W') : 'd'.$at->format('Y-m-d');
            $lastOfPeriod[$period] = (int) $row->id; // ordered oldest first: the last write wins
        }
        $keep = array_flip(array_values($lastOfPeriod));

        $doomed = [];
        foreach ($rows as $row) {
            if (! isset($keep[(int) $row->id]) && (int) $row->version_number !== (int) $newest) {
                $doomed[] = (int) $row->id;
            }
        }

        return $doomed;
    }
}
