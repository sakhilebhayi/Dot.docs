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
 * Reading rules the numbers depend on:
 *  - What is counted is PARAGRAPHS OFFERED BACK (`lost_both_live`): after two
 *    people changed the same block within the autosave and poll delay of each
 *    other, the later saver's version of it was offered back. One merge can
 *    offer several paragraphs back, so a paragraph count is not a count of
 *    clashes between people; `merge_live_lost` (live merges that left
 *    something over, of any kind) is the most merges those paragraphs can
 *    have come from. Draft and suggestion merges are not concurrent editing
 *    and are counted apart.
 *  - TIME TOGETHER is approximate. Every tab counts for itself the seconds it
 *    saw other people present (`peers_*_secs`), by poll, so each arrival or
 *    departure is off by a few tens of seconds (the presence row lives 30 s
 *    and a poll is 1.5 to 10 s). Two people each count the same half hour, so
 *    the time of a document-day is the busiest PERSON's count, not the sum:
 *    that is too low when two separate pairs shared the document at different
 *    times of the day, and too high when one person has two tabs open (the two
 *    tabs add up) or when deleted accounts, whose rows share a null user, are
 *    merged. It counts PEOPLE IN THE DOCUMENT (a reader, an idle open tab),
 *    not people typing, and time alone is not collected at all.
 *  - Days are calendar days in the app timezone (UTC), and today is partial.
 *  - A rate per hour is given only from MIN_MINUTES_FOR_A_RATE minutes
 *    together: a few minutes make a rate that means nothing.
 */
class SyncMetricsReport
{
    private const PRESENT = ['peers_1_secs', 'peers_2_secs', 'peers_3plus_secs'];

    private const THREE_OR_MORE = ['peers_2_secs', 'peers_3plus_secs'];

    private const SOURCES = ['live', 'draft', 'suggestion'];

    /** No rate per hour of time together below this many minutes in the window. */
    public const MIN_MINUTES_FOR_A_RATE = 60;

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
        $offeredDocuments = [];
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
                $offeredDocuments[$row->document_id] = true;
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
        $paragraphs = $totals['lost_both_live'] ?? 0;
        $liveMerges = $merges['by_source']['live']['total'];
        $liveWithLoss = $merges['by_source']['live']['with_lost'];

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'timezone' => (string) config('app.timezone'),
            'per_day' => $perDay,
            'totals' => $totals,
            'merges' => $merges,
            'events' => ['documents' => count($eventDocuments), 'users' => count($eventUsers)],
            'offered_back' => [
                'paragraphs' => $paragraphs,
                'documents' => count($offeredDocuments),
                // The most merges those paragraphs can have come from: live merges that left something over.
                'in_at_most_merges' => $liveWithLoss,
                'live_merges_with_a_loss_per_100' => $liveMerges > 0 ? round($liveWithLoss / $liveMerges * 100, 1) : null,
            ],
            'deletions' => ['count' => $totals['lost_deleted_live'] ?? 0, 'documents' => count($deletionDocuments)],
            'concurrency' => [
                'hours_per_day' => $hoursPerDay,
                'minutes_two_or_more' => $minutes,
                'minutes_three_or_more' => (int) round($secondsCrowded / 60),
                'documents' => count($companyDocuments),
                'minutes_needed_for_a_rate' => self::MIN_MINUTES_FOR_A_RATE,
                'offered_back_per_hour' => $minutes >= self::MIN_MINUTES_FOR_A_RATE
                    ? round($paragraphs / ($secondsWithCompany / 3600), 1)
                    : null,
            ],
            'summary' => $this->summary($days, $paragraphs, $liveWithLoss, count($offeredDocuments), $minutes),
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
    private function summary(int $days, int $paragraphs, int $merges, int $documents, int $minutes): string
    {
        $period = $days === 1 ? 'the last day' : "the last {$days} days";

        return sprintf(
            'In %s: %d %s (in at most %d live %s) across %d %s; %d %s with two or more people in the document at once (readers and idle open tabs count).',
            $period,
            $paragraphs,
            $paragraphs === 1 ? 'paragraph offered back after a same-paragraph clash' : 'paragraphs offered back after same-paragraph clashes',
            $merges,
            $merges === 1 ? 'merge' : 'merges',
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
        foreach (SyncMetrics::kinds() as $kind) {
            if (isset($counts[$kind])) {
                $ordered[$kind] = $counts[$kind];
            }
        }

        return $ordered;
    }
}
