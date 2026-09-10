<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The starter templates (StarterTemplateSeeder) are shipped with the product,
 * not authored by a user, so a global template has no creator to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
        });
    }
};
