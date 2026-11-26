<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-2 (enterprise audit S-03, owner decisions 2026-11-26): deleting an
 * account must not destroy what colleagues rely on.
 *
 * These four foreign keys to `users` used to CASCADE, so deleting one account
 * hard-deleted every document it owned (other companies' copies included),
 * every version it wrote on anyone's document, its comments, and the team
 * templates it made - silently, with no audit row. They now SET NULL:
 *
 * - documents.owner_id: App\Actions\Jetstream\DeleteUser hands a company
 *   document to its team's owner BEFORE the account goes, and soft-deletes a
 *   personal one; only those trashed personal documents ever reach a null
 *   owner, and doc:purge-deleted-accounts removes them after 30 days.
 * - document_versions.created_by and comments.user_id: kept, shown as
 *   "Former member".
 * - document_templates.created_by (already nullable): a team template stays.
 *
 * On SQLite Laravel rebuilds each table to change a foreign key; rows are
 * copied as they are.
 */
return new class extends Migration
{
    /** @var array<string, string> table => column */
    private const KEYS = [
        'documents' => 'owner_id',
        'document_versions' => 'created_by',
        'comments' => 'user_id',
        'document_templates' => 'created_by',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropForeign([$column]);
            });

            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->foreignId($column)->nullable()->change();
                $t->foreign($column)->references('id')->on('users')->nullOnDelete();
                // The cascade never needed one; the lookups by author and owner do.
                $t->index($column);
            });
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropForeign([$column]);
                $t->dropIndex([$column]);
            });

            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                // Rows whose user is gone cannot satisfy NOT NULL again: going
                // back needs them removed or reassigned by hand first.
                if ($table !== 'document_templates') {
                    $t->foreignId($column)->nullable(false)->change();
                }
                $t->foreign($column)->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
