<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * InfoDot gives every registered document a stable reference (OWNER-D-000012) and a
     * resource uuid. Both are kept here so the document can show its reference and so a
     * delete can tell InfoDot which pointer to drop. Nothing else about the document ever
     * leaves this app. Additive and nullable, so it runs unchanged on SQLite.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'infodot_reference')) {
                $table->string('infodot_reference', 32)->nullable();
            }
            if (! Schema::hasColumn('documents', 'infodot_resource_uuid')) {
                $table->uuid('infodot_resource_uuid')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['infodot_reference', 'infodot_resource_uuid']);
        });
    }
};
