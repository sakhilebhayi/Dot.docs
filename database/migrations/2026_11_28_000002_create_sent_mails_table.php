<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2-4 / E-10: one row per email that actually left, keyed by the queued message's own reference. A retry of a job
 * whose email already went (the worker was killed after sending, a later step failed) finds the row and sends nothing.
 * No address and no content: the kind of mail and when it went. Rows older than 90 days are pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_mails', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 100)->unique();
            $table->string('kind', 150);
            $table->timestamp('sent_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_mails');
    }
};
