<?php

namespace App\Console\Commands;

use App\Support\BackupCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PharData;
use SQLite3;
use Throwable;

/**
 * Nightly copy of the production SQLite file, same shape as Dot.Central's
 * central:backup-database: the SQLite online backup API gives a consistent
 * copy even with writers active, the newest N dated copies are kept, and
 * the command is a no-op on any other database driver. The copy lands in
 * storage/backups, outside the web root.
 *
 * EA-6 (enterprise audit O-01): every copy is checked with
 * PRAGMA integrity_check before it counts; the uploaded files
 * (storage/app) are archived beside it; and with BACKUP_PASSPHRASE set both
 * are encrypted (App\Support\BackupCipher) and the plain copies removed.
 * docs/programme/RESTORE_RUNBOOK.md says how to bring one back
 * (doc:restore-backup). Copying them off the host is the owner's step.
 */
class BackupSqliteDatabase extends Command
{
    protected $signature = 'doc:backup-database {--keep= : How many dated copies to keep (default config backup.keep)} {--no-files : Skip the uploads archive}';

    protected $description = 'Copy the SQLite database to storage/backups with the SQLite online backup API, keeping the newest N copies.';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->warn('Database connection is not sqlite; nothing to back up.');

            return self::SUCCESS;
        }

        $source = (string) config('database.connections.sqlite.database');

        if ($source === ':memory:' || ! is_file($source)) {
            $this->warn("No SQLite file at {$source}; nothing to back up.");

            return self::SUCCESS;
        }

        $directory = storage_path('backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $target = $directory.'/doc-'.now()->format('Y-m-d').'.sqlite';

        DB::connection('sqlite')->getPdo();

        if (class_exists(SQLite3::class)) {
            $from = new SQLite3($source, SQLITE3_OPEN_READONLY);
            $to = new SQLite3($target);
            $copied = $from->backup($to);
            // The copy inherits WAL mode; make it one self-contained file.
            $to->exec('PRAGMA journal_mode=DELETE');
            $to->close();
            $from->close();

            if (! $copied) {
                $this->error('SQLite backup API reported failure.');

                return self::FAILURE;
            }
        } elseif (! copy($source, $target)) {
            $this->error("Could not copy {$source} to {$target}.");

            return self::FAILURE;
        }

        $check = $this->integrity($target);
        // Opening the copy can leave SQLite's side files; it is a plain
        // rollback-journal file by now, so they hold nothing.
        @unlink($target.'-shm');
        @unlink($target.'-wal');
        if ($check !== 'ok') {
            @unlink($target);
            $this->error("The copy failed its integrity check ({$check}); it was removed.");

            return self::FAILURE;
        }

        $written = [$target];

        $archiveFailed = false;
        if (! $this->option('no-files')) {
            try {
                $archive = $this->archiveUploads($directory);
                if ($archive !== null) {
                    $written[] = $archive;
                }
            } catch (Throwable $e) {
                // The database copy above is kept; the run still FAILS, so the
                // scheduler's log and the exit code show it (EA-6 review).
                $this->error('Uploads archive failed: '.$e->getMessage());
                $archiveFailed = true;
            }
        }

        $passphrase = (string) config('backup.passphrase');
        if ($passphrase === '') {
            $this->warn('BACKUP_PASSPHRASE is not set: the backups are NOT encrypted.');
        } else {
            foreach ($written as $i => $file) {
                try {
                    BackupCipher::encryptFile($file, $file.'.enc', $passphrase);
                } catch (Throwable $e) {
                    @unlink($file.'.enc');
                    $this->error('Encryption failed: '.$e->getMessage());

                    return self::FAILURE;
                }
                @unlink($file);
                $written[$i] = $file.'.enc';
            }
        }

        $keep = (int) ($this->option('keep') ?: config('backup.keep')) ?: 14;
        $keep = max(1, $keep);
        $this->prune($directory, 'doc-*.sqlite*', $keep);
        $this->prune($directory, 'doc-files-*.tar.gz*', $keep);

        foreach ($written as $file) {
            $this->info('Backup written to '.$file.' ('.number_format((float) filesize($file)).' bytes)');
        }

        return $archiveFailed ? self::FAILURE : self::SUCCESS;
    }

    /** "ok", or what SQLite found wrong with the copy. */
    private function integrity(string $file): string
    {
        if (! class_exists(SQLite3::class)) {
            return 'ok';
        }

        $db = new SQLite3($file, SQLITE3_OPEN_READONLY);
        $result = (string) $db->querySingle('PRAGMA integrity_check');
        $db->close();

        return $result;
    }

    /**
     * storage/app (uploads, document images, the files tree) as a .tar.gz,
     * or null when there is nothing to archive. storage/backups is not
     * inside storage/app, so backups are never archived into themselves.
     */
    private function archiveUploads(string $directory): ?string
    {
        $source = storage_path('app');
        if (! is_dir($source)) {
            return null;
        }

        $tar = $directory.'/doc-files-'.now()->format('Y-m-d').'.tar';
        @unlink($tar);
        @unlink($tar.'.gz');

        // Regular files only: a symlink (public/storage points back into the
        // app) is skipped rather than followed or failing the archive.
        $files = new \CallbackFilterIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)),
            fn (\SplFileInfo $file) => $file->isFile() && ! $file->isLink(),
        );

        try {
            $phar = new PharData($tar);
            $phar->buildFromIterator($files, $source);
            $phar->compress(\Phar::GZ);
            unset($phar);
        } finally {
            @unlink($tar);
        }

        return is_file($tar.'.gz') ? $tar.'.gz' : null;
    }

    private function prune(string $directory, string $pattern, int $keep): void
    {
        $files = glob($directory.'/'.$pattern) ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
