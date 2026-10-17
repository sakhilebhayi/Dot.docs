<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the queue is actually being drained, not just filled: the
 * scheduler dispatches this every five minutes and only a worker that
 * runs it can refresh the timestamp /up/scheduler reads.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const KEY = 'dot.queue_heartbeat';

    public function handle(): void
    {
        Cache::forever(self::KEY, now()->toIso8601String());
    }
}
