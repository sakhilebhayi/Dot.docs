<?php

namespace App\Services\Guardian\Checks;

use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Facades\DB;

/** Database connectivity and latency probe. */
class DatabaseCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'database';
    }

    public function run(): CheckResult
    {
        $startedAt = microtime(true);

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            return CheckResult::critical('Database unreachable.', ['error' => $e::class]);
        }

        return CheckResult::healthy('Database answering.', [
            'driver' => (string) config('database.default'),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000.0),
        ]);
    }
}
