<?php

namespace App\Console\Commands;

use App\Services\SyncMetrics;
use Illuminate\Console\Command;

class PruneSyncMetrics extends Command
{
    protected $signature = 'sync:prune-metrics {--days=120 : Delete counts older than this many days}';

    protected $description = 'Delete co-editing counters older than 120 days';

    public function handle(SyncMetrics $metrics): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $this->info('Deleted '.$metrics->prune($days).' rows.');

        return self::SUCCESS;
    }
}
