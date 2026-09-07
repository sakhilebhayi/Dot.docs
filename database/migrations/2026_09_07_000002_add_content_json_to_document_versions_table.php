<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->jsonb('content_json')->nullable();
            $table->string('label', 120)->nullable();
            $table->string('kind', 20)->default('auto');
            $table->text('summary')->nullable();
            $table->unsignedInteger('word_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn(['content_json', 'label', 'kind', 'summary', 'word_count']);
        });
    }
};
