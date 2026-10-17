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
        $this->line("Co-editing report, {$report['from']} to {$report['to']}");
        $this->line('Editors here means people with the document open in the editor (a reader counts); times are the busiest person\'s count.');
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
            $clash = $report['clashes'];
            $rate = $clash['per_100_live_merges'] === null ? 'n/a' : "{$clash['per_100_live_merges']} per 100 live merges";
            $this->line("Same-paragraph clashes: {$clash['count']} in {$this->documents($clash['documents'])} ({$rate}).");
            $this->line("Deletions against typing: {$report['deletions']['count']} in {$this->documents($report['deletions']['documents'])}.");
            $concurrency = $report['concurrency'];
            $perHour = $concurrency['clashes_per_hour'] === null ? 'n/a' : "{$concurrency['clashes_per_hour']} clashes per hour together";
            $this->line("Time with two or more editors: {$this->minutes($concurrency['minutes_two_or_more'])} in {$this->documents($concurrency['documents'])} ({$perHour}); three or more: {$this->minutes($concurrency['minutes_three_or_more'])}.");
            $hours = [];
            foreach ($concurrency['hours_per_day'] as $day => $value) {
                if ($value > 0) {
                    $hours[] = substr($day, 5).' '.$value.' h';
                }
            }
            $this->line('Hours per day with two or more editors: '.($hours === [] ? 'none' : implode(', ', $hours)).'.');
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
