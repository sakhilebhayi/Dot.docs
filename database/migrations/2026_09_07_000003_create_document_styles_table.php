<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX document_styles_system_key_unique ON document_styles (key) WHERE team_id IS NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS document_styles_system_key_unique');
        }

        Schema::dropIfExists('document_styles');
    }
};
