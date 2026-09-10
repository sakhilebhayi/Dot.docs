<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Dot.Files tree, shared.
 *
 * `objects` is the tree (one row per node: a folder, a file, or a Dot.Doc
 * document), `files` and `folders` are the two blob/label types Dot.Files
 * owns. Dot.Doc reads and writes the same three tables so a folder made
 * here IS a Dot.Files folder - there is no sync, there is one table set.
 *
 * Every table is guarded with Schema::hasTable() the same way this repo's
 * copies of `users`/`teams` are (Dot.Brain adr/ADR-0013): whichever app
 * migrates first against the shared `infodot` database creates them, the
 * rest skip.
 *
 * `folders` is the awkward one. Dot.Doc already ships a table of that name
 * with a DIFFERENT shape (owner_id/team_id/parent_id/name, its own legacy
 * folder system). The guard here can only tell the two apart by shape, so
 * it tests for the shared table's `uuid` column: no `folders` at all means
 * create it; a `folders` WITH `uuid` is the shared one and is left alone;
 * a `folders` WITHOUT `uuid` is Dot.Doc's legacy table, which is not this
 * migration's to touch - `dot:files:adopt-tree` (run by the next
 * migration) moves it aside and forwards its rows.
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

        if (! Schema::hasTable('folders')) {
            Schema::create('folders', function (Blueprint $table) {
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

        // Only ever drop the SHARED folders table. A `folders` without a
        // uuid column is Dot.Doc's legacy one, which this migration never
        // created and must not remove.
        if (Schema::hasTable('folders') && Schema::hasColumn('folders', 'uuid')) {
            Schema::drop('folders');
        }
    }
};
