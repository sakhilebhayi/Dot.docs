<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Retired by 2026_09_08_000003 along with the table it points at; kept,
     * guarded, so an existing installation's history still replays.
     *
     * Skipped entirely when `folders` is the SHARED Dot.Files table (it
     * carries a `uuid` column - see .ai/rules/files.md): there is no legacy
     * folder system to reference in that case, and hanging a foreign key off
     * a table the other product owns only to drop it again three migrations
     * later is DDL nobody asked for.
     */
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'folder_id')) {
            return;
        }

        if (Schema::hasTable('folders') && Schema::hasColumn('folders', 'uuid')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            // nullOnDelete: deleting a folder should orphan its documents
            // back to the root, never take the documents down with it.
            $table->foreignId('folder_id')->nullable()->after('team_id')->constrained('folders')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'folder_id')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
    }
};
