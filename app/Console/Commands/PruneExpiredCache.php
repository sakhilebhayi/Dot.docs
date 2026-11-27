<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * R2-11: the database cache store only drops an expired row when that key is read again, so rows for keys nobody asks
 * for again (rate-limit counters, one-off locks) stayed for ever. This deletes the expired ones from the cache and
 * cache-lock tables in small batches, so the write lock is never held for long. Nothing to do for any other store.
 */
class PruneExpiredCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete expired rows from the database cache and cache-lock tables';

    private const BATCH = 500;

    public function handle(): int
    {
        $store = (array) config('cache.stores.'.config('cache.default'), []);

        if (($store['driver'] ?? null) !== 'database') {
            $this->info('The cache store is not the database: nothing to prune.');

            return self::SUCCESS;
        }

        $table = (string) ($store['table'] ?? 'cache');
        $tables = [
            [$store['connection'] ?? null, $table],
            [$store['lock_connection'] ?? $store['connection'] ?? null, (string) ($store['lock_table'] ?? $table.'_locks')],
        ];

        $removed = 0;
        foreach ($tables as [$connection, $name]) {
            $removed += $this->prune(is_string($connection) ? $connection : null, $name);
        }

        $this->info("Deleted {$removed} expired cache rows.");

        return self::SUCCESS;
    }

    private function prune(?string $connection, string $table): int
    {
        $db = DB::connection($connection);

        if (! $db->getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $removed = 0;
        $now = now()->getTimestamp();

        do {
            $keys = $db->table($table)->where('expiration', '<', $now)->limit(self::BATCH)->pluck('key')->all();
            if ($keys !== []) {
                $removed += $db->table($table)->whereIn('key', $keys)->delete();
            }
        } while (count($keys) === self::BATCH);

        return $removed;
    }
}
