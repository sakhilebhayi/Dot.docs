<?php

namespace Tests\Feature\Files;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan migrate`, run against a database a Dot.Files instance
 * migrated FIRST.
 *
 * This is the scenario every guard in the 2026_09_08_* migrations claims to
 * support, and the only honest way to test it is to run the real command
 * against a real, empty database that already carries the shared tables -
 * not to require() a migration or two by hand. So these cases build a
 * throwaway file-backed sqlite database on its own connection, pre-seed the
 * three shared tables the way Dot.Files leaves them (uuid-bearing `folders`
 * included), and run the whole migration set at it from an empty
 * `migrations` table.
 *
 * No RefreshDatabase: the point is an empty database with no migrations
 * recorded, which the shared in-memory test database is the opposite of.
 */
class SharedTreeMigrateTest extends TestCase
{
    private const CONNECTION = 'shared_tree_migrate';

    private string $database = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = storage_path('framework/testing/shared-tree-migrate-'.getmypid().'.sqlite');

        @unlink($this->database);
        touch($this->database);

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge(self::CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        @unlink($this->database);

        parent::tearDown();
    }

    /**
     * What a Dot.Files instance leaves behind when it migrates first. The
     * COLUMNS are what the two products share; the constraints are each
     * app's own, so they are left off here deliberately - these tables
     * arrive before `users` or `teams` exist at all.
     */
    private function seedSharedTables(): void
    {
        $schema = Schema::connection(self::CONNECTION);

        $schema->create('objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('objectable_type');
            $table->unsignedBigInteger('objectable_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('team_id');
            $table->timestamps();
        });

        $schema->create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('team_id');
            $table->timestamps();
        });

        $schema->create('folders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->string('name');
            $table->unsignedBigInteger('team_id');
            $table->timestamps();
        });
    }

    private function migrate(): string
    {
        $exit = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, 'php artisan migrate did not complete: '.$output);

        return $output;
    }

    /**
     * The regression this exists for: Dot.Doc's own
     * 2026_08_10_161301_create_folders_table runs chronologically BEFORE
     * any 2026_09_08_* migration, so an unguarded Schema::create('folders')
     * there fails with "table folders already exists" the moment the shared
     * table is present - leaving the batch half applied.
     */
    public function test_migrate_runs_clean_against_a_database_dot_files_migrated_first(): void
    {
        $this->seedSharedTables();

        $this->migrate();

        $schema = Schema::connection(self::CONNECTION);

        $this->assertTrue($schema->hasTable('objects'));
        $this->assertTrue($schema->hasColumn('folders', 'uuid'), 'the shared folders table survived');
        $this->assertFalse($schema->hasColumn('folders', 'owner_id'), 'Dot.Doc never created its legacy shape over it');
        $this->assertFalse($schema->hasColumn('documents', 'folder_id'), 'the legacy column is retired');
        $this->assertFalse($schema->hasTable('tree_folders'), 'nothing was staged: the shared table was already there');
        $this->assertFalse($schema->hasTable('document_folders_legacy'), 'there were no legacy folders to move aside');
    }

    /** The ordinary case still has to work: an empty database, nothing shared yet. */
    public function test_migrate_runs_clean_against_an_empty_database(): void
    {
        $this->migrate();

        $schema = Schema::connection(self::CONNECTION);

        $this->assertTrue($schema->hasTable('objects'));
        $this->assertTrue($schema->hasColumn('folders', 'uuid'));
        $this->assertFalse($schema->hasColumn('documents', 'folder_id'));
        $this->assertFalse($schema->hasTable('tree_folders'));
    }
}
