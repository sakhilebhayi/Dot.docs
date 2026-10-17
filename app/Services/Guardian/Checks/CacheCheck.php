<?php

namespace App\Services\Guardian\Checks;

use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Facades\Cache;

/** Cache round-trip probe (the heartbeats and the error counter live here). */
class CacheCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'cache';
    }

    public function run(): CheckResult
    {
        try {
            $key = 'guardian:cache-probe:'.now()->timestamp;
            Cache::put($key, 'pong', 10);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'pong') {
                return CheckResult::critical('Cache read/write mismatch.');
            }
        } catch (\Throwable $e) {
            return CheckResult::critical('Cache unreachable.', ['error' => $e::class]);
        }

        return CheckResult::healthy('Cache round-trip succeeded.', ['driver' => (string) config('cache.default')]);
    }
}
