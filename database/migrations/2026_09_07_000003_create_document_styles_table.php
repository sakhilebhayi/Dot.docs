<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_styles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('category', 40);
            $table->jsonb('tokens');
            $table->boolean('is_system')->default(false);
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['key', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_styles');
    }
};
