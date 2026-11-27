<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * EA-9 / R2-9: a new SQLite database is created in incremental auto-vacuum mode, an existing one is left alone until
 * the owner converts it by hand, and nothing a deploy runs (the migrations) ever VACUUMs.
 */
class VacuumDatabaseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().'/doc-vacuum-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->file);
        config(['database.connections.vac' => array_merge(config('database.connections.sqlite'), ['database' => $this->file])]);
    }

    protected function tearDown(): void
    {
        DB::purge('vac');
        foreach (['', '-wal', '-shm'] as $suffix) {
            File::delete($this->file.$suffix);
        }

        parent::tearDown();
    }

    public function test_a_new_database_is_created_in_incremental_mode(): void
    {
        DB::connection('vac')->statement('CREATE TABLE t (x)');

        $this->assertSame(2, (int) DB::connection('vac')->selectOne('PRAGMA auto_vacuum')->auto_vacuum);
    }

    public function test_an_existing_database_is_untouched_until_converted_by_hand_then_reclaims_space(): void
    {
        // An existing file made without the setting (in WAL, like production's), with free pages in it.
        $pdo = new \PDO('sqlite:'.$this->file);
        $pdo->exec('PRAGMA journal_mode = wal');
        $pdo->exec('CREATE TABLE t (x)');
        $pdo->exec('INSERT INTO t SELECT randomblob(1000) FROM (WITH RECURSIVE c(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM c WHERE i < 500) SELECT * FROM c)');
        $pdo->exec('DELETE FROM t');
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo = null;
        $hash = md5_file($this->file);

        // Connecting with the app's settings changes nothing in the file.
        $this->assertSame(0, (int) DB::connection('vac')->selectOne('PRAGMA auto_vacuum')->auto_vacuum);
        $this->assertGreaterThan(0, (int) DB::connection('vac')->selectOne('PRAGMA freelist_count')->freelist_count);
        $this->assertSame($hash, md5_file($this->file));

        // Reporting writes nothing; --incremental refuses until converted; --convert asks first.
        $this->artisan('doc:vacuum', ['--database' => 'vac'])->expectsOutputToContain('auto_vacuum: none')->assertSuccessful();
        $this->artisan('doc:vacuum', ['--database' => 'vac', '--incremental' => true])->assertFailed();
        $this->artisan('doc:vacuum', ['--database' => 'vac', '--convert' => true])
            ->expectsConfirmation('VACUUM rewrites the whole file and blocks every save until it ends. Back up first (doc:backup-database). Run it now?', 'no')
            ->assertFailed();
        $this->assertSame(0, (int) DB::connection('vac')->selectOne('PRAGMA auto_vacuum')->auto_vacuum);

        $this->artisan('doc:vacuum', ['--database' => 'vac', '--convert' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(2, (int) DB::connection('vac')->selectOne('PRAGMA auto_vacuum')->auto_vacuum);
        $this->assertSame(0, (int) DB::connection('vac')->selectOne('PRAGMA freelist_count')->freelist_count);

        // From now on deleted rows leave free pages that --incremental hands back.
        DB::connection('vac')->statement('INSERT INTO t SELECT randomblob(1000) FROM (WITH RECURSIVE c(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM c WHERE i < 500) SELECT * FROM c)');
        DB::connection('vac')->statement('DELETE FROM t');
        $this->assertGreaterThan(0, (int) DB::connection('vac')->selectOne('PRAGMA freelist_count')->freelist_count);
        $this->artisan('doc:vacuum', ['--database' => 'vac', '--incremental' => true])->assertSuccessful();
        $this->assertSame(0, (int) DB::connection('vac')->selectOne('PRAGMA freelist_count')->freelist_count);
    }

    public function test_no_migration_vacuums_or_changes_the_auto_vacuum_mode(): void
    {
        foreach (File::allFiles(database_path('migrations')) as $migration) {
            $this->assertDoesNotMatchRegularExpression('/\bvacuum\b/i', $migration->getContents(), $migration->getFilename().' mentions VACUUM: a migration runs on every deploy.');
        }
    }
}
