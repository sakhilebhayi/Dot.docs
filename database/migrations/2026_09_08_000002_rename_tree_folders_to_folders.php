<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Hand the name `folders` over to the shared tree.
 *
 * Two guarded renames, in this order and no other:
 *
 * 1. Dot.Doc's legacy `folders` (owner_id/team_id/parent_id/name, no uuid)
 *    moves aside to `document_folders_legacy`. `documents.folder_id`'s
 *    foreign key follows the table, so the legacy rows stay readable and
 *    intact - 000003 is what reads and then drops them.
 * 2. `tree_folders` (staged by 000001) takes the freed name.
 *
 * This runs BEFORE adoption, not after, because App\Models\Files\Folder
 * names exactly one table - `folders` - and adoption writes through that
 * model (and through FilesService, which does too). The task brief had the
 * rename last; that ordering cannot work, because the adopt step in between
 * would have had no table the model could reach.
 *
 * Both halves are guarded, so this is a no-op against a database where a
 * Dot.Files instance already created the shared `folders`, and against a
 * re-run.
 */
return new class extends Migration
{
    private const LEGACY = 'document_folders_legacy';

    public function up(): void
    {
        $legacyInTheWay = Schema::hasTable('folders') && ! Schema::hasColumn('folders', 'uuid');

        if ($legacyInTheWay && ! Schema::hasTable(self::LEGACY)) {
            Schema::rename('folders', self::LEGACY);
        }

        if (Schema::hasTable('tree_folders') && ! Schema::hasTable('folders')) {
            Schema::rename('tree_folders', 'folders');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('folders') && Schema::hasColumn('folders', 'uuid') && ! Schema::hasTable('tree_folders')) {
            Schema::rename('folders', 'tree_folders');
        }

        if (Schema::hasTable(self::LEGACY) && ! Schema::hasTable('folders')) {
            Schema::rename(self::LEGACY, 'folders');
        }
    }
};
