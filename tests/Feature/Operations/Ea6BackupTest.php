<?php

namespace Tests\Feature\Operations;

use App\Jobs\QueueHeartbeat;
use App\Support\BackupCipher;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/** EA-6 (enterprise audit O-01..O-05): backups are checked, encrypted, restorable, and watched. */
class Ea6BackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/doc-ea6-'.uniqid();
        mkdir($this->dir.'/backups', 0777, true);
        mkdir($this->dir.'/app/public', 0777, true);
        file_put_contents($this->dir.'/app/public/upload.txt', 'an uploaded file');

        $db = new \SQLite3($this->dir.'/database.sqlite');
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('create table documents (id integer primary key, title text)');
        $db->exec('create table users (id integer primary key)');
        $db->exec("insert into documents (title) values ('Board pack'), ('Minutes')");
        $db->exec('insert into users default values');
        $db->close();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $this->dir.'/database.sqlite');
        $this->app->useStoragePath($this->dir);
    }

    public function test_an_encrypted_backup_holds_no_plain_copy_and_restores_with_the_passphrase(): void
    {
        config()->set('backup.passphrase', 'a long test passphrase that is not real');

        $this->artisan('doc:backup-database')->assertSuccessful();

        $day = now()->format('Y-m-d');
        $this->assertFileExists($this->dir."/backups/doc-{$day}.sqlite.enc");
        $this->assertFileExists($this->dir."/backups/doc-files-{$day}.tar.gz.enc");
        $this->assertFileDoesNotExist($this->dir."/backups/doc-{$day}.sqlite");
        $this->assertFileDoesNotExist($this->dir."/backups/doc-{$day}.sqlite-shm");
        $this->assertStringNotContainsString('Board pack', (string) file_get_contents($this->dir."/backups/doc-{$day}.sqlite.enc"));

        $this->artisan('doc:restore-backup', ['file' => "doc-{$day}.sqlite.enc"])
            ->expectsOutputToContain('integrity ok, 2 documents, 1 users')
            ->assertSuccessful();
    }

    public function test_the_wrong_passphrase_or_a_damaged_file_is_refused_loudly(): void
    {
        $plain = $this->dir.'/plain.bin';
        file_put_contents($plain, random_bytes(200000));
        BackupCipher::encryptFile($plain, $plain.'.enc', 'right');

        try {
            BackupCipher::decryptFile($plain.'.enc', $this->dir.'/out.bin', 'wrong');
            $this->fail('wrong passphrase accepted');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->dir.'/out.bin');
        }

        $bytes = (string) file_get_contents($plain.'.enc');
        $bytes[strlen($bytes) - 10] = chr(ord($bytes[strlen($bytes) - 10]) ^ 1);
        file_put_contents($plain.'.enc', $bytes);
        $this->expectException(RuntimeException::class);
        BackupCipher::decryptFile($plain.'.enc', $this->dir.'/out.bin', 'right');
    }

    public function test_restore_never_writes_over_the_live_database(): void
    {
        $this->artisan('doc:backup-database')->assertSuccessful();
        $day = now()->format('Y-m-d');

        $this->artisan('doc:restore-backup', ['file' => "doc-{$day}.sqlite", '--to' => $this->dir.'/database.sqlite'])
            ->expectsOutputToContain('Refusing to write over the live database')
            ->assertFailed();
    }

    public function test_the_probe_turns_red_when_the_nightly_backup_is_overdue(): void
    {
        $this->artisan('doc:backup-database')->assertSuccessful();
        foreach (['dot.scheduler_heartbeat', QueueHeartbeat::KEY] as $key) {
            cache()->forever($key, now()->toIso8601String());
        }

        $this->getJson('/up/scheduler')->assertOk()->assertJsonPath('status', 'ok');

        foreach (glob($this->dir.'/backups/doc-*') ?: [] as $file) {
            touch($file, Carbon::now()->subHours(27)->getTimestamp());
        }

        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('status', 'stale');
    }

    public function test_a_symlink_in_the_uploads_is_skipped_not_fatal(): void
    {
        symlink($this->dir.'/app/public', $this->dir.'/app/public-link');

        $this->artisan('doc:backup-database')->assertSuccessful();

        $tar = $this->dir.'/backups/doc-files-'.now()->format('Y-m-d').'.tar.gz';
        $this->assertFileExists($tar);
        $names = array_map(fn ($f) => $f->getPathname(), iterator_to_array(new \RecursiveIteratorIterator(new \PharData($tar)), false));
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_ends_with($n, 'public/upload.txt')));
        $this->assertEmpty(array_filter($names, fn ($n) => str_contains($n, 'public-link')));
    }

    public function test_an_empty_backup_keep_setting_keeps_fourteen(): void
    {
        config()->set('backup.keep', 0);
        foreach (range(1, 20) as $i) {
            touch($this->dir.'/backups/doc-2020-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.sqlite');
        }

        $this->artisan('doc:backup-database', ['--no-files' => true])->assertSuccessful();

        $this->assertCount(14, glob($this->dir.'/backups/doc-*.sqlite') ?: []);
    }
}
