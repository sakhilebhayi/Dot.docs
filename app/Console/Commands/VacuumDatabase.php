<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * EA-9 / R2-9: free space in the SQLite file. Never scheduled and never run by a deploy: the owner runs it by hand,
 * following docs/programme/RESTORE_RUNBOOK.md ("Reclaim free space").
 *
 * - no option: report the auto-vacuum mode, the file's pages and how many of them are free. Writes nothing.
 * - --incremental: hand free pages back to the disk (PRAGMA incremental_vacuum). Needs the database in incremental
 *   mode; quick, and only takes the write lock for as long as it moves the free pages.
 * - --convert: the one-off conversion of an existing file to incremental mode: sets the mode and runs VACUUM, which
 *   rewrites the WHOLE file under an exclusive lock (every save and page waits) and needs as much free disk again as
 *   the file. Asks first; --force skips the question.
 */
class VacuumDatabase extends Command
{
    protected $signature = 'doc:vacuum
        {--incremental : Return the free pages to the disk (database must be in incremental mode)}
        {--convert : One-off: switch an existing database to incremental auto-vacuum (runs VACUUM; rewrites the file)}
        {--force : With --convert, do not ask first}
        {--database= : The connection (default: the default connection)}';

    protected $description = 'Report, reclaim or convert the SQLite file\'s free space (auto-vacuum); never run by a deploy';

    private const MODES = [0 => 'none', 1 => 'full', 2 => 'incremental'];

    public function handle(): int
    {
        $db = DB::connection($this->option('database') ?: null);

        if ($db->getDriverName() !== 'sqlite') {
            $this->error('doc:vacuum is for SQLite databases only.');

            return self::FAILURE;
        }

        $this->report($db);

        if ($this->option('convert')) {
            if ($this->mode($db) === 2) {
                $this->info('Already incremental: nothing to convert.');

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->confirm('VACUUM rewrites the whole file and blocks every save until it ends. Back up first (doc:backup-database). Run it now?')) {
                $this->warn('Nothing done.');

                return self::FAILURE;
            }

            $db->statement('PRAGMA auto_vacuum = INCREMENTAL');
            $db->statement('VACUUM');
            $this->info('Converted.');
            $this->report($db);

            return $this->mode($db) === 2 ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('incremental')) {
            if ($this->mode($db) !== 2) {
                $this->error('The database is not in incremental auto-vacuum mode: run doc:vacuum --convert once first.');

                return self::FAILURE;
            }

            // select(), not statement(): SQLite frees the pages as the pragma's result is stepped through, and a
            // statement that is executed but never read to the end stops part of the way.
            $db->select('PRAGMA incremental_vacuum');
            $this->info('Free pages returned to the disk.');
            $this->report($db);
        }

        return self::SUCCESS;
    }

    private function mode(Connection $db): int
    {
        return (int) $db->selectOne('PRAGMA auto_vacuum')->auto_vacuum;
    }

    private function report(Connection $db): void
    {
        $pages = (int) $db->selectOne('PRAGMA page_count')->page_count;
        $free = (int) $db->selectOne('PRAGMA freelist_count')->freelist_count;
        $size = (int) $db->selectOne('PRAGMA page_size')->page_size;

        $this->line(sprintf(
            'auto_vacuum: %s; %d pages of %d bytes (%.1f MB), %d free (%.1f MB)',
            self::MODES[$this->mode($db)] ?? 'unknown', $pages, $size, $pages * $size / 1048576, $free, $free * $size / 1048576,
        ));
    }
}
