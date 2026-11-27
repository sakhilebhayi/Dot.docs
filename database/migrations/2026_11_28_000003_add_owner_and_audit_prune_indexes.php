<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2-5 / P-04, P-12: the indexes EA-5 left out. `documents.owner_id` is read by every document list, search and
 * policy check ("mine" first); `audit_logs (action, created_at)` serves the nightly removal of old view records and
 * `document_versions (document_id, kind, created_at)` the version thinning. A plain CREATE INDEX on SQLite: no table
 * rebuild, rows untouched; skipped when it already exists.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private const INDEXES = [
        'documents' => [['owner_id', 'updated_at']],
        'audit_logs' => [['action', 'created_at']],
        'document_versions' => [['document_id', 'kind', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $this->name($table, $columns);
                if (Schema::hasTable($table) && Schema::hasColumns($table, $columns) && ! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $this->name($table, $columns);
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    /** @param  list<string>  $columns */
    private function name(string $table, array $columns): string
    {
        return 'r25_'.$table.'_'.implode('_', $columns).'_index';
    }
};
