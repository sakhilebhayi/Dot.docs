<?php

namespace App\Console\Commands;

use App\Support\BackupCipher;
use Illuminate\Console\Command;
use SQLite3;
use Throwable;

/**
 * Turns a backup back into a usable file (EA-6 / O-02), WITHOUT touching the
 * live database: it decrypts when needed, checks a database copy with
 * PRAGMA integrity_check, and writes the result to storage/restore/ (or
 * --to). Putting it live is a deliberate, separate step in
 * docs/programme/RESTORE_RUNBOOK.md.
 *
 *   php artisan doc:restore-backup storage/backups/doc-2026-11-27.sqlite.enc
 */
class RestoreBackup extends Command
{
    protected $signature = 'doc:restore-backup {file : A file in storage/backups (encrypted or not)} {--to= : Where to write the result}';

    protected $description = 'Decrypt and check a backup into storage/restore; never touches the live database';

    public function handle(): int
    {
        $file = is_string($this->argument('file')) ? $this->argument('file') : '';
        $file = is_file($file) ? $file : storage_path('backups/'.basename($file));

        if (! is_file($file)) {
            $this->error("No backup at {$file}.");

            return self::FAILURE;
        }

        $name = basename($file, '.enc');
        $to = is_string($this->option('to')) && $this->option('to') !== '' ? $this->option('to') : storage_path('restore/'.$name);
        $live = (string) config('database.connections.sqlite.database');

        if ($live !== '' && is_file($live) && realpath(dirname($to)) !== false && realpath($to) === realpath($live)) {
            $this->error('Refusing to write over the live database. Restore to another path first (see the runbook).');

            return self::FAILURE;
        }

        if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0750, true) && ! is_dir(dirname($to))) {
            $this->error('Cannot create '.dirname($to).'.');

            return self::FAILURE;
        }

        try {
            if (str_ends_with($file, '.enc')) {
                $passphrase = (string) config('backup.passphrase');
                if ($passphrase === '') {
                    $this->error('This backup is encrypted and BACKUP_PASSPHRASE is not set.');

                    return self::FAILURE;
                }
                BackupCipher::decryptFile($file, $to, $passphrase);
            } elseif (! copy($file, $to)) {
                $this->error("Could not copy {$file}.");

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (str_ends_with($name, '.sqlite') && class_exists(SQLite3::class)) {
            $db = new SQLite3($to, SQLITE3_OPEN_READONLY);
            $check = $db->querySingle('PRAGMA integrity_check');
            $check = is_string($check) ? $check : '';
            $count = fn (string $table) => (int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = '{$table}'")
                ? (int) $db->querySingle("SELECT count(*) FROM {$table}")
                : 0;
            $documents = $count('documents');
            $users = $count('users');
            $db->close();

            if ($check !== 'ok') {
                $this->error("Restored copy failed its integrity check: {$check}");

                return self::FAILURE;
            }

            $this->info("Restored to {$to}: integrity ok, {$documents} documents, {$users} users.");

            return self::SUCCESS;
        }

        $this->info("Restored to {$to} (".number_format((float) filesize($to)).' bytes).');

        return self::SUCCESS;
    }
}
