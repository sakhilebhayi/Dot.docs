<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-54 phase 2. Additive only.
 *
 * dotsso_revocations: subjects whose central session ended (back-channel logout). Still-open local sessions are
 * closed on their next request whatever the session driver. Times are milliseconds so a sign-in a moment after a
 * sign-out is never mistaken for the old session.
 *
 * dotsso_claims: one row per emailed claim code. The code is stored as an HMAC, never in clear.
 *
 * The link from a local user to the InfoDot user id (users.infodot_user_uuid) is the PLATFORM's own migration,
 * because the users table belongs to the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dotsso_revocations')) {
            Schema::create('dotsso_revocations', function (Blueprint $table) {
                $table->id();
                $table->string('sub', 64)->index();
                $table->unsignedBigInteger('revoked_at_ms');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dotsso_claims')) {
            Schema::create('dotsso_claims', function (Blueprint $table) {
                $table->id();
                $table->string('claim_id', 40)->unique();
                $table->string('user_key', 64);
                $table->string('email', 255)->index();
                $table->string('sub', 64);
                $table->string('code_hash', 64);
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dotsso_claims');
        Schema::dropIfExists('dotsso_revocations');
    }
};
