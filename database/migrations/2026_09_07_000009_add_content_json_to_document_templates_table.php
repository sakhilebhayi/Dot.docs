<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->jsonb('content_json')->nullable();
            $table->string('style_key', 40)->default('report');
            $table->jsonb('page_setup')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['content_json', 'style_key', 'page_setup']);
        });
    }
};
