<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2-3b / U-06: a person who opens a link to a document they cannot read can ask for access. One row per request;
 * the owner (or anyone who may share the document) grants it, as a role, or declines it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 16)->default('pending'); // pending, granted, declined
            $table->string('role', 16)->nullable(); // the role granted
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['requester_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
