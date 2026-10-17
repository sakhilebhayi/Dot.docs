<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What `php artisan sync:report` prints: the counters of SyncMetrics for the
 * last N days, read as an answer to "is Phase 3 needed?".
 *
 * Reads only the table (ids, days, kinds, counts), so the report can never
 * hold a name, a title or a word of a document.
 *
 * Two reading rules the numbers depend on:
 *  - A CLASH is a `lost_both_live` offer: two people changed the same block
 *    within one poll of each other and the later saver's version was offered
 *    back. Draft and suggestion merges are not concurrent editing and are
 *    counted apart.
 *  - TIME with company comes from `peers_*_secs`, which every tab counts for
 *    itself. Two people in a document each count the same half hour, so the
 *    time of a document-day is the busiest PERSON's count, not the sum (two
 *    tabs of one person add up, so the figure is an upper bound there). It is
 *    people PRESENT with the document open in the editor, a reader included,
 *    not people typing.
 */
class SyncMetricsReport
{
    private const PRESENT = ['peers_1_secs', 'peers_2_secs', 'peers_3plus_secs'];

    private const THREE_OR_MORE = ['peers_2_secs', 'peers_3plus_secs'];

    private const SOURCES = ['live', 'draft', 'suggestion'];

    /**
     * @return array<string, mixed>
     */
    public function build(int $days, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subDays($days - 1);

        $rows = DB::table('sync_metrics')
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<=', $today->toDateString())
            ->get(['document_id', 'user_id', 'day', 'kind', 'count']);

        $perDay = [];
        for ($d = $from; $d <= $today; $d = $d->addDay()) {
            $perDay[$d->toDateString()] = [];
        }

        $totals = [];
        $eventDocuments = [];
        $eventUsers = [];
        $clashDocuments = [];
        $deletionDocuments = [];
        // [day][document][user] => seconds with company / with three or more people
        $company = [];
        $crowd = [];

        foreach ($rows as $row) {
            $kind = $row->kind;
            $count = (int) $row->count;
            $day = (string) $row->day;
            $perDay[$day][$kind] = ($perDay[$day][$kind] ?? 0) + $count;
            $totals[$kind] = ($totals[$kind] ?? 0) + $count;

            if (! str_starts_with($kind, 'peers_')) {
                $eventDocuments[$row->document_id] = true;
                $eventUsers[$row->user_id ?? 'gone'] = true;
            }
            if ($kind === 'lost_both_live') {
                $clashDocuments[$row->document_id] = true;
            }
            if ($kind === 'lost_deleted_live') {
                $deletionDocuments[$row->document_id] = true;
            }
            $person = $row->user_id ?? 'gone';
            if (in_array($kind, self::PRESENT, true)) {
                $company[$day][$row->document_id][$person] = ($company[$day][$row->document_id][$person] ?? 0) + $count;
            }
            if (in_array($kind, self::THREE_OR_MORE, true)) {
                $crowd[$day][$row->document_id][$person] = ($crowd[$day][$row->document_id][$person] ?? 0) + $count;
            }
        }

        $perDay = array_map(fn (array $kinds) => $this->inKindOrder($kinds), $perDay);
        $totals = $this->inKindOrder($totals);

        $merges = $this->merges($totals);

        $hoursPerDay = [];
        $secondsWithCompany = 0;
        $companyDocuments = [];
        foreach (array_keys($perDay) as $day) {
            $seconds = 0;
            foreach ($company[$day] ?? [] as $documentId => $byPerson) {
                $busiest = max($byPerson);
                $seconds += $busiest;
                $companyDocuments[$documentId] = true;
            }
            $secondsWithCompany += $seconds;
            $hoursPerDay[$day] = round($seconds / 3600, 2);
        }
        $secondsCrowded = 0;
        foreach ($crowd as $documents) {
            foreach ($documents as $byPerson) {
                $secondsCrowded += max($byPerson);
            }
        }

        $minutes = (int) round($secondsWithCompany / 60);
        $clashes = $totals['lost_both_live'] ?? 0;
        $liveMerges = $merges['by_source']['live']['total'];

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'per_day' => $perDay,
            'totals' => $totals,
            'merges' => $merges,
            'events' => ['documents' => count($eventDocuments), 'users' => count($eventUsers)],
            'clashes' => [
                'count' => $clashes,
                'documents' => count($clashDocuments),
                'per_100_live_merges' => $liveMerges > 0 ? round($clashes / $liveMerges * 100, 1) : null,
            ],
            'deletions' => ['count' => $totals['lost_deleted_live'] ?? 0, 'documents' => count($deletionDocuments)],
            'concurrency' => [
                'hours_per_day' => $hoursPerDay,
                'minutes_two_or_more' => $minutes,
                'minutes_three_or_more' => (int) round($secondsCrowded / 60),
                'documents' => count($companyDocuments),
                'clashes_per_hour' => $secondsWithCompany > 0 ? round($clashes / ($secondsWithCompany / 3600), 1) : null,
            ],
            'summary' => $this->summary($days, $clashes, count($clashDocuments), $minutes),
        ];
    }

    /** @param array<string, int> $totals */
    private function merges(array $totals): array
    {
        $bySource = [];
        $total = 0;
        $withLost = 0;
        foreach (self::SOURCES as $source) {
            $clean = $totals["merge_{$source}_clean"] ?? 0;
            $lost = $totals["merge_{$source}_lost"] ?? 0;
            $bySource[$source] = ['total' => $clean + $lost, 'with_lost' => $lost];
            $total += $clean + $lost;
            $withLost += $lost;
        }

        return [
            'total' => $total,
            'with_lost' => $withLost,
            'share_with_lost' => $total > 0 ? round($withLost / $total, 4) : null,
            'by_source' => $bySource,
        ];
    }

    /** The one line the owner reads to decide on Phase 3. */
    private function summary(int $days, int $clashes, int $documents, int $minutes): string
    {
        $period = $days === 1 ? 'the last day' : "the last {$days} days";

        return sprintf(
            'In %s: %d same-paragraph %s across %d %s; %d %s with two or more editors at once.',
            $period,
            $clashes,
            $clashes === 1 ? 'clash' : 'clashes',
            $documents,
            $documents === 1 ? 'document' : 'documents',
            $minutes,
            $minutes === 1 ? 'minute' : 'minutes',
        );
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function inKindOrder(array $counts): array
    {
        $ordered = [];
        foreach (SyncMetrics::KINDS as $kind) {
            if (isset($counts[$kind])) {
                $ordered[$kind] = $counts[$kind];
            }
        }

        return $ordered;
    }
}
