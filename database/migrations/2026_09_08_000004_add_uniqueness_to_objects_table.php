<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two uniqueness guarantees the tree needs from the DATABASE, not from PHP.
 *
 * `FilesService::root()` and `registerDocument()` both resolve a node with a
 * query and then create one if it was missing. Two concurrent first-time
 * callers - two tabs opening the workspace the moment after a team is made,
 * a page load racing the adopt command - can both pass that check before
 * either commits, and the result is permanent: two roots for one team (which
 * one is "the" root becomes ambiguous, and the loser shows up as a phantom
 * entry in every folder picker), or two live nodes for one document. No
 * amount of checking in PHP closes that; a constraint does, and both call
 * sites now catch the violation and re-fetch instead of raising.
 *
 * 1. `objects (objectable_type, objectable_id)` unique - one live node per
 *    thing, whichever product filed it. Flagged as missing in this task's own
 *    report; it is what makes registerDocument() safe.
 * 2. One ROOT per team: unique on `team_id` WHERE `parent_id IS NULL` AND
 *    `objectable_type = 'folder'`. A partial index, because only the roots
 *    are unique - a team has any number of non-root folders.
 *
 * The partial index follows the `document_styles_system_key_unique`
 * precedent (2026_09_07_000003) of a raw guarded statement, extended to
 * sqlite: the syntax is identical there, it is the driver the test suite
 * runs on, and a constraint nothing can exercise is a constraint nobody
 * finds out is wrong. MySQL has no partial indexes, so it keeps the PHP
 * check alone.
 *
 * Guarded on `objects` existing at all, like every migration in this set -
 * the tables are shared with Dot.Files and whichever app migrates first
 * creates them. If this fails on an existing database with "could not
 * create unique index", the data already carries duplicates: find and merge
 * them before migrating, rather than dropping the constraint.
 */
return new class extends Migration
{
    private const OBJECTABLE_INDEX = 'objects_objectable_unique';

    private const ROOT_INDEX = 'objects_team_root_unique';

    public function up(): void
    {
        if (! Schema::hasTable('objects')) {
            return;
        }

        if (! Schema::hasIndex('objects', self::OBJECTABLE_INDEX)) {
            Schema::table('objects', function (Blueprint $table) {
                $table->unique(['objectable_type', 'objectable_id'], self::OBJECTABLE_INDEX);
            });
        }

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::ROOT_INDEX.' ON objects (team_id)'
                ." WHERE parent_id IS NULL AND objectable_type = 'folder'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('objects')) {
            return;
        }

        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS '.self::ROOT_INDEX);
        }

        if (Schema::hasIndex('objects', self::OBJECTABLE_INDEX)) {
            Schema::table('objects', function (Blueprint $table) {
                $table->dropUnique(self::OBJECTABLE_INDEX);
            });
        }
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
