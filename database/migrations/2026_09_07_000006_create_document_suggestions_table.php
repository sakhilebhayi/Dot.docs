<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('block_id', 8)->index();
            $table->string('author_type', 10);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('operation', 60);
            $table->jsonb('patch');
            $table->text('rationale')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_suggestions');
    }
};
