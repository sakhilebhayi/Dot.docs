<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->jsonb('fonts')->nullable();
            $table->jsonb('colours')->nullable();
            $table->jsonb('letterhead')->nullable();
            $table->jsonb('footer')->nullable();
            $table->text('disclaimer')->nullable();
            $table->jsonb('contact')->nullable();
            $table->string('default_style_key', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_kits');
    }
};
