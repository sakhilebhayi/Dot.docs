<?php

namespace App\Console\Commands;

use App\Files\FilesService;
use App\Files\UniqueName;
use App\Models\Document;
use App\Models\Files\Folder;
use App\Models\Files\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move Dot.Doc's own folder system into the shared Dot.Files tree.
 *
 * DATA ONLY - this command owns no schema. By the time it runs, migration
 * 2026_09_08_000002 has already moved Dot.Doc's legacy `folders` aside to
 * `document_folders_legacy` and given the shared tree the `folders` name,
 * so every model this touches points at a table that exists. A command
 * that renamed tables as a side effect of a data migration (the first WIP's
 * shape) made `--dry-run` a lie and made the whole thing unrunnable twice.
 *
 * Every legacy folder becomes a `folders`+`objects` pair under its team's
 * root, parent-first so nesting survives; then every document -
 * soft-deleted ones included, so a restore still lands somewhere - gets an
 * `objects` row under the folder it was filed in, or the team root.
 * Personal (team-less) folders and documents go under the OWNER'S personal
 * team root, which Jetstream's CreateNewUser guarantees exists.
 *
 * Idempotent by construction: a document that already has a node is left
 * alone, roots are firstOrCreate, and a second run after the legacy table
 * has been dropped simply files anything created since. `--dry-run` reports
 * the plan and writes nothing at all, which is what makes it safe to look
 * before running it against a shared production database.
 *
 * 2026_09_08_000003 calls this command before dropping `documents.folder_id`
 * and the legacy table, so `php artisan migrate` is the whole procedure -
 * there is no two-step ops dance to get wrong.
 */
class AdoptFilesTree extends Command
{
    protected $signature = 'dot:files:adopt-tree {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Adopt Dot.Doc folders and documents into the shared Dot.Files tree';

    private const LEGACY_TABLE = 'document_folders_legacy';

    public function handle(FilesService $files): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! Schema::hasTable('objects') || ! Schema::hasTable('folders')) {
            $this->error('The shared tree tables are not there yet - run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $legacyTable = $this->findLegacyTable();

        $foldersAdopted = 0;
        $roots = [];

        /** @var array<int, int> $folderMap legacy folder id => new Obj id */
        $folderMap = [];

        if ($legacyTable !== null) {
            [$foldersAdopted, $folderMap] = $this->adoptFolders($files, $legacyTable, $dryRun, $roots);
        }

        $documentsFiled = $this->fileDocuments($files, $folderMap, $dryRun, $roots);

        $this->line(($dryRun ? 'Would adopt' : 'Adopted').' '.$foldersAdopted.' folder(s).');
        $this->line(($dryRun ? 'Would file' : 'Filed').' '.$documentsFiled.' document(s).');
        $this->line(($dryRun ? 'Would touch' : 'Touched').' '.count($roots).' team root(s).');

        return self::SUCCESS;
    }

    /**
     * The table Dot.Doc's legacy folders can be read from, or null when
     * there are none left to adopt (a fresh install, or a second run after
     * 2026_09_08_000003 dropped it).
     */
    private function findLegacyTable(): ?string
    {
        if (Schema::hasTable(self::LEGACY_TABLE)) {
            return self::LEGACY_TABLE;
        }

        if (Schema::hasTable('folders') && ! Schema::hasColumn('folders', 'uuid')) {
            return 'folders';
        }

        return null;
    }

    /**
     * @param  array<int, Obj>  $roots  team id => root Obj, filled as teams are touched
     * @return array{0:int,1:array<int,int>} adopted count, legacy folder id => new Obj id
     */
    private function adoptFolders(FilesService $files, string $legacyTable, bool $dryRun, array &$roots): array
    {
        $rows = DB::table($legacyTable)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)
            ->keyBy('id')
            ->all();

        $map = [];
        $adopted = 0;

        // Parent-first: a folder is only adopted once its parent has been,
        // so nesting survives whatever order the ids happen to be in.
        $pending = array_keys($rows);
        $progress = true;

        while ($pending !== [] && $progress) {
            $progress = false;
            $deferred = [];

            foreach ($pending as $id) {
                $row = $rows[$id];
                $team = $this->teamFor($row['team_id'] ?? null, $row['owner_id'] ?? null);

                if ($team === null) {
                    continue; // An orphan with no reachable team has nowhere to go.
                }

                $parentId = $row['parent_id'] ?? null;
                $parentObj = null;

                if ($parentId !== null) {
                    if (! isset($map[$parentId])) {
                        if (isset($rows[$parentId])) {
                            $deferred[] = $id;

                            continue;
                        }
                        // Parent is gone: file it at the team root instead of losing it.
                    } else {
                        $parentObj = Obj::find($map[$parentId]);
                    }
                }

                $root = $this->rootFor($files, $team, $dryRun, $roots);
                $parentObj ??= $root;
                $adopted++;
                $progress = true;

                if ($dryRun || $parentObj === null) {
                    continue;
                }

                $name = UniqueName::for($parentObj, (string) ($row['name'] ?? 'Folder'));
                $folder = Folder::create(['name' => $name, 'team_id' => $team->id]);
                $obj = Obj::create([
                    'objectable_type' => 'folder',
                    'objectable_id' => $folder->id,
                    'parent_id' => $parentObj->id,
                    'team_id' => $team->id,
                ]);
                $map[$id] = $obj->id;
            }

            $pending = $deferred;
        }

        return [$adopted, $map];
    }

    /**
     * @param  array<int,int>  $folderMap
     * @param  array<int, Obj>  $roots
     */
    private function fileDocuments(FilesService $files, array $folderMap, bool $dryRun, array &$roots): int
    {
        $filed = 0;
        $hasLegacyColumn = Schema::hasColumn('documents', 'folder_id');

        Document::withTrashed()->with('owner')->orderBy('id')->chunkById(200, function ($documents) use ($files, $folderMap, $dryRun, &$roots, &$filed, $hasLegacyColumn) {
            foreach ($documents as $document) {
                if ($document->node()->exists()) {
                    continue;
                }

                $team = $this->teamFor($document->team_id, $document->owner_id);
                if ($team === null) {
                    continue;
                }

                $root = $this->rootFor($files, $team, $dryRun, $roots);
                $filed++;

                if ($dryRun || $root === null) {
                    continue;
                }

                $legacyFolderId = $hasLegacyColumn ? $document->getAttribute('folder_id') : null;
                $parent = ($legacyFolderId !== null && isset($folderMap[$legacyFolderId]))
                    ? (Obj::find($folderMap[$legacyFolderId]) ?? $root)
                    : $root;

                $files->registerDocument($document, $parent);
            }
        });

        return $filed;
    }

    /** @param array<int, Obj> $roots */
    private function rootFor(FilesService $files, Team $team, bool $dryRun, array &$roots): ?Obj
    {
        if (array_key_exists($team->id, $roots)) {
            return $roots[$team->id];
        }

        return $roots[$team->id] = $dryRun ? null : $files->root($team);
    }

    /**
     * The team a legacy row belongs in: its own, or - for a personal row -
     * the owner's personal team, which Jetstream's CreateNewUser guarantees.
     */
    private function teamFor(?int $teamId, ?int $ownerId): ?Team
    {
        if ($teamId !== null) {
            return Team::find($teamId);
        }

        if ($ownerId === null) {
            return null;
        }

        $owner = User::find($ownerId);

        return $owner?->personalTeam();
    }
}
