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
 * are a CLOSED list that resources/js/editor/sync/metrics.js sends from
 * (METRIC_KINDS there; a test holds the two equal): an unknown kind is
 * refused whole, so the table can never carry anything the list does not
 * name. Query builder, not a model, like PresenceService: bookkeeping that
 * nothing else loads.
 *
 * The audit log is NOT used for this on purpose: it is an access log with a
 * row per event and these counters arrive by the thousand.
 */
class SyncMetrics
{
    /**
     * Every kind the page may send, with its meaning. `peers_*_secs` are
     * seconds this tab saw that many OTHER people (distinct users) present
     * in the document; every other kind counts events.
     *
     * @var list<string>
     */
    public const KINDS = [
        // (a) merges the page performed, and whether anything was left over.
        'merge_live_clean', 'merge_live_lost',
        'merge_draft_clean', 'merge_draft_lost',
        'merge_suggestion_clean', 'merge_suggestion_lost',
        // (b) lost-row offers: both = same-paragraph clash (D1), deleted = deletion against typing (D2).
        'lost_both_live', 'lost_deleted_live',
        'lost_both_draft', 'lost_deleted_draft',
        'lost_both_suggestion', 'lost_deleted_suggestion',
        // ...and what the writer did with them.
        'lost_put_back', 'lost_dismissed',
        // (c) the conflict notice, by why it was raised.
        'conflict_impossible', 'conflict_attempts', 'conflict_expiry',
        // (d) a merge in which the echo rule kept the tab's own stored words.
        'echo_resolved',
        // (e) a save sent again after a failed request, and the back-off level each failure reached (6 = the 60 s cap).
        'save_resent',
        'backoff_1', 'backoff_2', 'backoff_3', 'backoff_4', 'backoff_5', 'backoff_6',
        // (f) read only downgrades: a 403 (provisional), then the server's confirmation.
        'readonly_provisional', 'readonly_confirmed',
        // (g) seconds with 0, 1, 2 or 3+ other people present.
        'peers_0_secs', 'peers_1_secs', 'peers_2_secs', 'peers_3plus_secs',
    ];

    /** The most one flush may add to one kind: an hour of seconds. Nothing real comes near it. */
    public const MAX_COUNT = 3600;

    /** A flush that names every kind is under 900 bytes; anything bigger is not ours. */
    public const MAX_BODY_BYTES = 1024;

    /** Rows older than this many days are deleted by sync:prune-metrics. */
    public const KEEP_DAYS = 120;

    private const TABLE = 'sync_metrics';

    /**
     * Add `$counts` (kind => whole number) to today's rows for this person
     * and document. ONE statement: the unique key turns a second flush into
     * an addition, and two flushes arriving together cannot collide.
     *
     * @param  array<string, int>  $counts  already validated against KINDS
     */
    public function record(int $documentId, int $userId, array $counts, ?CarbonInterface $at = null): void
    {
        $day = ($at ?? now())->toDateString();

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
