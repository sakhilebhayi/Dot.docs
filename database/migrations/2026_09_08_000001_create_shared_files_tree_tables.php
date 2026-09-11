<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Dot.Files tree, shared.
 *
 * `objects` is the tree (one row per node: a folder, a file, or a Dot.Doc
 * document), `files` and the folder table are the two blob/label types
 * Dot.Files owns. Dot.Doc reads and writes the same three tables so a
 * folder made here IS a Dot.Files folder - there is no sync, there is one
 * table set.
 *
 * Every table is guarded with Schema::hasTable() the same way this repo's
 * copies of `users`/`teams` are (Dot.Brain adr/ADR-0013): whichever app
 * migrates first against the shared `infodot` database creates them, the
 * rest skip.
 *
 * The folder table is created here under the NON-COLLIDING name
 * `tree_folders`, not `folders`, because Dot.Doc already ships a `folders`
 * table of its own with a completely different shape
 * (owner_id/team_id/parent_id/name, no uuid). Creating the shared one over
 * that name here is what broke the suite in the first WIP: the legacy
 * `Folder` model kept inserting owner_id into a table that no longer had
 * the column. The sequence is instead:
 *
 *   000001  create objects + files + tree_folders   (legacy `folders` untouched)
 *   000002  move legacy `folders` aside, rename tree_folders -> folders
 *   000003  adopt legacy rows into the tree, drop documents.folder_id and the
 *           legacy table
 *
 * which is the only ordering in which `php artisan migrate` runs cleanly
 * and repeatably from an empty database AND from a database that already
 * carries the legacy folder system. See .ai/rules/files.md.
 *
 * `team_id` cascades on delete on all three, unlike Dot.Files' plain
 * constrained() - a team root folder must not be able to block Jetstream's
 * team deletion. Constraints are local; the COLUMNS are what the two apps
 * share.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('objects')) {
            Schema::create('objects', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->morphs('objectable');
                $table->foreignId('parent_id')->nullable()->constrained('objects')->nullOnDelete();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->timestamps();

                $table->index('parent_id');
            });
        }

        if (! Schema::hasTable('files')) {
            Schema::create('files', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->index();
                $table->string('name');
                $table->unsignedBigInteger('size');
                $table->string('path');
                $table->string('mime_type')->nullable();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        // Already sharing a database with a Dot.Files that migrated first?
        // Then `folders` is ALREADY the shared table (it has a uuid column)
        // and there is nothing to stage.
        $sharedFoldersExist = Schema::hasTable('folders') && Schema::hasColumn('folders', 'uuid');

        if (! $sharedFoldersExist && ! Schema::hasTable('tree_folders')) {
            Schema::create('tree_folders', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->index();
                $table->string('name');
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('objects');
        Schema::dropIfExists('files');
        Schema::dropIfExists('tree_folders');

        // Only ever drop a SHARED folders table. A `folders` without a uuid
        // column is Dot.Doc's legacy one, which this migration never created
        // and must not remove.
        if (Schema::hasTable('folders') && Schema::hasColumn('folders', 'uuid')) {
            Schema::drop('folders');
        }
    }
};
