<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-5 / P-04: foreignId()->constrained() makes no index on SQLite (or
 * PostgreSQL), so every lookup by document, team or user below was a full
 * table scan - version history, comments, the webhook check on every save,
 * the team membership check on every poll. Adding an index on SQLite is a
 * plain CREATE INDEX: no table rebuild, rows untouched. Each one is skipped
 * when an index with the same name already exists, so a partial earlier run
 * cannot fail this one.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> table => list of column lists */
    private const INDEXES = [
        'documents' => [['team_id', 'updated_at'], ['folder_id'], ['created_at']],
        'document_versions' => [['document_id', 'version_number']],
        'comments' => [['document_id', 'created_at'], ['parent_id']],
        'document_webhooks' => [['document_id']],
        'document_suggestions' => [['document_id']],
        'ai_suggestions' => [['document_id'], ['user_id']],
        'team_user' => [['user_id']],
        'document_collaborators' => [['user_id']],
        'notifications' => [['created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $this->name($table, $columns))) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $this->name($table, $columns))) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $columns)));
                }
            }
        }
    }

    /** @param  list<string>  $columns */
    private function name(string $table, array $columns): string
    {
        return 'ea5_'.$table.'_'.implode('_', $columns).'_index';
    }
};
