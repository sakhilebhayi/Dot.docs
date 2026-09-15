<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A folder was purely organizational -- it never changed who could see a
     * document (that stays governed entirely by DocumentPolicy). Its own
     * visibility mirrored Document's owner_id/team_id scoping: personal
     * (team_id null) or team-wide.
     *
     * This table is RETIRED by 2026_09_08_000003; it is kept here, guarded,
     * only so an existing installation's history still replays. The guard
     * has to tell two different `folders` tables apart, because this
     * migration runs chronologically BEFORE any 2026_09_08_* one:
     *
     * - a `folders` WITH `owner_id` is this one, already created (a re-run);
     * - a `folders` WITHOUT it is the SHARED Dot.Files table, created by a
     *   Dot.Files instance that migrated against the same database first
     *   (tell the two apart by SHAPE, never by name - see
     *   .ai/rules/files.md).
     *
     * Either way there is nothing to create. In the shared case there is
     * also nothing to adopt - Dot.Files made that table, so it never held a
     * Dot.Doc folder - and Dot.Doc's own legacy folder code is gone as of
     * this branch, so skipping leaves no feature half-wired. Creating
     * regardless is what crashed `php artisan migrate` mid-batch with
     * "table folders already exists" (tests/Feature/Files/SharedTreeMigrateTest).
     */
    public function up(): void
    {
        if (Schema::hasTable('folders')) {
            return;
        }

        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['team_id', 'parent_id']);
            $table->index(['owner_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only ever drop the LEGACY table. A `folders` carrying a `uuid` column
     * is the shared Dot.Files one, which this migration did not create.
     */
    public function down(): void
    {
        if (Schema::hasTable('folders') && Schema::hasColumn('folders', 'uuid')) {
            return;
        }

        Schema::dropIfExists('folders');
    }
};
