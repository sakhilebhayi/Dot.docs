<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Retire Dot.Doc's own folder system, once its contents are safely in the
 * shared tree.
 *
 * The adoption runs INSIDE this migration rather than as a separate ops
 * step: `php artisan migrate` is then the whole procedure - in CI, in dev
 * and in production - and there is no window in which `documents.folder_id`
 * has been dropped but the tree has not been filled. `dot:files:adopt-tree`
 * is idempotent, so re-running migrations is harmless.
 *
 * Order matters and is the reverse of the obvious one: the foreign key on
 * `documents.folder_id` followed the legacy table through 000002's rename,
 * so the COLUMN has to go before the table it now points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('dot:files:adopt-tree');

        if (Schema::hasColumn('documents', 'folder_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('folder_id');
            });
        }

        Schema::dropIfExists('document_folders_legacy');
    }

    /**
     * Irreversible in the sense that matters: the column comes back empty,
     * because the tree - not this column - is where a document's location
     * lives from here on.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'folder_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->unsignedBigInteger('folder_id')->nullable()->after('team_id');
            });
        }
    }
};
