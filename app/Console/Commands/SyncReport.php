<?php

namespace App\Console\Commands;

use App\Services\SyncMetricsReport;
use Illuminate\Console\Command;

/**
 * The read-out for the Phase 3 decision (docs/superpowers/plans/
 * 2026-10-01-realtime-coediting-roadmap.md, "How to read the metrics").
 * Counts and ids only; it cannot print a name, a title or a word of a
 * document because none of them is in the table.
 */
class SyncReport extends Command
{
    protected $signature = 'sync:report {--days=14 : How many days back, today included} {--json : Print the report as JSON}';

    protected $description = 'Report how often co-editing needed a merge, what it could not keep, and how often people shared a document';

    public function handle(SyncMetricsReport $reports): int
    {
        $option = $this->option('days');
        if (! is_numeric($option) || (int) $option != $option || (int) $option < 1 || (int) $option > 366) {
            $this->error('--days must be a whole number from 1 to 366.');

            return self::FAILURE;
        }

        $report = $reports->build((int) $option);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->print($report);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function print(array $report): void
    {
        $this->line("Co-editing report, {$report['from']} to {$report['to']} ({$report['timezone']} calendar days; today is partial)");
        $this->line('People in the document means anyone with it open in the editor: readers and idle open tabs included.');
        $this->line('Time together is approximate: each tab counts for itself, the report takes the busiest person\'s count per document and day (too low when two separate pairs shared one, too high when one person has two tabs open).');
        $this->line('Each arrival or departure is placed to within a few tens of seconds, because presence is sampled by poll.');
        $this->newLine();

        if ($report['totals'] === []) {
            $this->line('Nothing recorded yet in this window. The page flushes its counters once a minute and when it is left.');
        } else {
            $this->perDayTable($report);
            $this->newLine();
            $merges = $report['merges'];
            $share = $merges['share_with_lost'] === null ? 'n/a' : number_format($merges['share_with_lost'] * 100, 1).'%';
            $this->line("Merges: {$merges['total']}, {$merges['with_lost']} of them left something over ({$share}).");
            foreach ($merges['by_source'] as $source => $counts) {
                $this->line("  {$source}: {$counts['total']} merges, {$counts['with_lost']} with lost entries");
            }
            $this->line('Documents with an event: '.$report['events']['documents'].'; people: '.$report['events']['users'].'.');
            $offered = $report['offered_back'];
            $share = $offered['live_merges_with_a_loss_per_100'] === null ? 'n/a' : "{$offered['live_merges_with_a_loss_per_100']} per 100 live merges";
            $this->line("Paragraphs offered back after a same-paragraph clash: {$offered['paragraphs']} in {$this->documents($offered['documents'])}, in at most {$offered['in_at_most_merges']} live merges ({$share} left something over).");
            $this->line("Deletions against typing: {$report['deletions']['count']} in {$this->documents($report['deletions']['documents'])}.");
            $concurrency = $report['concurrency'];
            $rate = $concurrency['offered_back_per_hour'] === null
                ? "too little time together to give a rate (needs {$concurrency['minutes_needed_for_a_rate']} minutes)"
                : "{$concurrency['offered_back_per_hour']} paragraphs offered back per hour together";
            $this->line("Time with two or more people in the document: about {$this->minutes($concurrency['minutes_two_or_more'])} in {$this->documents($concurrency['documents'])}; {$rate}; three or more people: about {$this->minutes($concurrency['minutes_three_or_more'])}.");
            $hours = [];
            foreach ($concurrency['hours_per_day'] as $day => $value) {
                if ($value > 0) {
                    $hours[] = substr($day, 5).' '.$value.' h';
                }
            }
            $this->line('Hours per day with two or more people in the document: '.($hours === [] ? 'none' : implode(', ', $hours)).'.');
        }

        $this->newLine();
        $this->line($report['summary']);
    }

    private function documents(int $count): string
    {
        return $count.($count === 1 ? ' document' : ' documents');
    }

    private function minutes(int $count): string
    {
        return $count.($count === 1 ? ' minute' : ' minutes');
    }

    /** @param array<string, mixed> $report */
    private function perDayTable(array $report): void
    {
        $days = array_keys($report['per_day']);
        $shown = count($days) <= 16 ? $days : [];
        $headers = ['kind', ...array_map(fn ($day) => substr($day, 5), $shown), 'total'];

        $rows = [];
        foreach ($report['totals'] as $kind => $total) {
            $rows[] = [$kind, ...array_map(fn ($day) => $report['per_day'][$day][$kind] ?? 0, $shown), $total];
        }

        $this->table($headers, $rows);
        if ($shown === []) {
            $this->line('(Per-day columns are left out above 16 days; use --json.)');
        }
    }
}
