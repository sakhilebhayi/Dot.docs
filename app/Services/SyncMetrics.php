<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Counters for the co-editing layer: how often the editor page merged, what
 * a merge could not keep, how often a save failed and how many people had a
 * document open together. They exist to answer one question for the owner:
 * is true same-sentence co-typing (Phase 3 of the co-editing roadmap) worth
 * building? Read with `php artisan sync:report`.
 *
 * Counts and kinds ONLY. A row is (document id, user id, day, kind, count);
 * nothing a person wrote, no title, no name, no address, no tab id. The kinds
 * are a CLOSED list in ONE file, resources/js/editor/sync/metricKinds.json,
 * which the editor bundle imports and this class reads: an unknown kind is
 * dropped (the known ones sent with it are kept), so the table can never
 * carry anything the list does not name. Query builder, not a model, like PresenceService: bookkeeping that
 * nothing else loads.
 *
 * The audit log is NOT used for this on purpose: it is an access log with a
 * row per event and these counters arrive by the thousand.
 */
class SyncMetrics
{
    /** The most one flush may add to one kind: an hour of seconds. Nothing real comes near it. */
    public const MAX_COUNT = 3600;

    /** A flush that names every kind is under 900 bytes; anything bigger is not ours. */
    public const MAX_BODY_BYTES = 1024;

    /** Rows older than this many days are deleted by sync:prune-metrics. */
    public const KEEP_DAYS = 120;

    /**
     * Requests a minute per person AND document (the `sync-metrics` limiter in AppServiceProvider). A tab sends one
     * flush about 5 s after it opens, then at most one a minute, then one on leaving; 12 was fine for that until
     * the early flush, and ten tabs restored at once would have used most of it. Over the limit the answer is a
     * 429, which the page drops silently: a limit that is hit loses counts, so it is set well above real use.
     */
    public const PER_MINUTE = 30;

    private const TABLE = 'sync_metrics';

    /** The one file the editor bundle reads as well (METRIC_KINDS in sync/metrics.js). */
    public const KINDS_FILE = 'js/editor/sync/metricKinds.json';

    /**
     * Every kind the page may send, from the ONE file both sides read. `peers_*_secs` are seconds this tab saw that
     * many OTHER people (distinct users) present in the document; every other kind counts events. Time alone is
     * deliberately not a kind. See the file's neighbours in resources/js/editor/sync/ for what each name means.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        static $kinds = null;

        return $kinds ??= json_decode(file_get_contents(resource_path(self::KINDS_FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Add `$counts` (kind => whole number) to today's rows for this person
     * and document. ONE statement: the unique key turns a second flush into
     * an addition, and two flushes arriving together cannot collide.
     *
     * @param  array<string, int>  $counts  known kinds only, already validated
     */
    public function record(int $documentId, int $userId, array $counts, ?CarbonInterface $at = null): void
    {
        $day = ($at ?? now())->toDateString();

        // Always in the same order, so two flushes that arrive together lock their rows in the same order and
        // cannot deadlock each other on PostgreSQL.
        ksort($counts);

        $rows = [];
        foreach ($counts as $kind => $count) {
            $rows[] = ['document_id' => $documentId, 'user_id' => $userId, 'day' => $day, 'kind' => $kind, 'count' => $count];
        }

        if ($rows === []) {
            return;
        }

        $column = DB::getQueryGrammar()->wrap(self::TABLE.'.count');
        $incoming = DB::getQueryGrammar()->wrap('excluded.count');

        DB::table(self::TABLE)->upsert(
            $rows,
            ['document_id', 'user_id', 'day', 'kind'],
            ['count' => DB::raw("{$column} + {$incoming}")],
        );
    }

    /** Delete rows older than `$days` days. Returns how many went. */
    public function prune(int $days = self::KEEP_DAYS): int
    {
        return DB::table(self::TABLE)
            ->where('day', '<', now()->subDays($days)->toDateString())
            ->delete();
    }
}
