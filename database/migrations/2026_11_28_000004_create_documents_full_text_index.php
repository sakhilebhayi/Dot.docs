<?php

use App\Search\FullTextIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * R2-5 / P-07: full-text search on SQLite (FTS5) in place of LIKE over every document's whole text. See
 * App\Search\FullTextIndex. Does nothing on PostgreSQL (which has its own tsvector column) or on a SQLite built
 * without FTS5, where search keeps working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(FullTextIndex::class)->install();
    }

    public function down(): void
    {
        app(FullTextIndex::class)->uninstall();
    }
};
