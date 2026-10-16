<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per open editor tab per document. Short-lived by design: a row
     * that has not been refreshed for PresenceService::TTL_SECONDS no longer
     * counts, and the next arrival on that document deletes it.
     */
    public function up(): void
    {
        Schema::create('document_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tab_id', 64);
            $table->timestamp('last_seen_at')->index();
            $table->unique(['document_id', 'tab_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_presences');
    }
};
