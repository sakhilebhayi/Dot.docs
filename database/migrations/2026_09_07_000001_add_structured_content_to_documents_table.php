<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('search_text')->nullable()->after('content');
            $table->jsonb('content_json')->nullable()->after('content');
            $table->binary('ydoc_state')->nullable()->after('content_json');
            $table->unsignedSmallInteger('schema_version')->default(1)->after('ydoc_state');
            $table->string('style_key', 40)->default('report')->after('schema_version');
            $table->foreignId('brand_kit_id')->nullable()->after('style_key');
            $table->jsonb('page_setup')->nullable()->after('brand_kit_id');
            $table->jsonb('variables')->nullable()->after('page_setup');
            $table->unsignedSmallInteger('health_score')->nullable()->after('variables');
            $table->timestamp('health_checked_at')->nullable()->after('health_score');
            $table->string('review_state', 20)->default('draft')->after('health_checked_at');
            $table->string('slug', 80)->nullable()->unique()->after('review_state');
            $table->unsignedInteger('word_count')->default(0)->after('slug');
            $table->unsignedInteger('view_count')->default(0)->after('word_count');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title,'') || ' ' || coalesce(search_text,''))) STORED");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE documents DROP COLUMN IF EXISTS search_vector');
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['content_json', 'search_text', 'ydoc_state', 'schema_version', 'style_key', 'brand_kit_id', 'page_setup', 'variables', 'health_score', 'health_checked_at', 'review_state', 'slug', 'word_count', 'view_count']);
        });
    }
};
