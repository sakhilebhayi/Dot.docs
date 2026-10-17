<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Counts of co-editing events, one row per document, person, day and kind
     * (App\Services\SyncMetrics, read by `php artisan sync:report`). Only
     * numbers and ids: no text, title, name or address ever goes in. The
     * unique key makes a flush an upsert that adds to the row. Additive: a
     * new table, nothing existing is touched. Plain Blueprint types, so it
     * runs unchanged on SQLite and PostgreSQL.
     */
    public function up(): void
    {
        Schema::create('sync_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Kept (as null) if the person is deleted: the count outlives the account, the account does not.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('day');
            $table->string('kind', 32);
            $table->unsignedBigInteger('count')->default(0);
            $table->unique(['document_id', 'user_id', 'day', 'kind']);
            // The prune and the report both walk by day.
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_metrics');
    }
};
