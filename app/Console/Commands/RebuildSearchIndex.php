<?php

namespace App\Console\Commands;

use App\Search\FullTextIndex;
use Illuminate\Console\Command;

/** R2-5 / P-07: re-create the SQLite full-text index and its triggers, and fill it again from the documents table. */
class RebuildSearchIndex extends Command
{
    protected $signature = 'search:rebuild';

    protected $description = 'Re-create the full-text search index (SQLite FTS5) and refill it from the documents';

    public function handle(FullTextIndex $index): int
    {
        if (! $index->install()) {
            $this->warn('No full-text index on this database (not SQLite, or SQLite without FTS5): search uses LIKE.');

            return self::SUCCESS;
        }

        $this->info('Full-text index rebuilt.');

        return self::SUCCESS;
    }
}
