<?php

namespace Tests\Feature\Files;

use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one-way trip from Dot.Doc's own folder table into the shared tree.
 *
 * A test database has already finished that trip - migrations ran at
 * RefreshDatabase time - so each case here REWINDS the schema to the state
 * a real installation is in the moment before 2026_09_08_000002 runs (a
 * legacy `folders` holding the name, the shared table parked at
 * `tree_folders`, `documents.folder_id` present), seeds a realistic legacy
 * world into it, and then runs the two migrations for real. Anything less
 * would only test the command against a schema the command never meets.
 */
class AdoptFilesTreeTest extends TestCase
{
    use RefreshDatabase;

    /** Put the schema back the way 2026_09_08_000001 leaves it. */
    private function rewindToLegacySchema(): void
    {
        Obj::query()->delete();
        DB::table('folders')->delete();

        Schema::rename('folders', 'tree_folders');

        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('team_id')->constrained('folders')->nullOnDelete();
        });
    }

    private function legacyFolder(User $owner, string $name, ?int $teamId = null, ?int $parentId = null): int
    {
        return DB::table('folders')->insertGetId([
            'owner_id' => $owner->id,
            'team_id' => $teamId,
            'parent_id' => $parentId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A document with no tree node, the way every row looked before this task. */
    private function legacyDocument(User $owner, ?int $teamId, ?int $folderId, string $title): Document
    {
        $document = Document::create([
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'content' => 'content',
            'owner_id' => $owner->id,
            'team_id' => $teamId,
            'version' => 1,
            'is_public' => false,
        ]);

        DB::table('documents')->where('id', $document->id)->update(['folder_id' => $folderId]);

        return $document;
    }

    private function runMigration(string $file): void
    {
        (require database_path('migrations/'.$file))->up();
    }

    private function rename(): void
    {
        $this->runMigration('2026_09_08_000002_rename_tree_folders_to_folders.php');
    }

    private function adoptAndDrop(): void
    {
        $this->runMigration('2026_09_08_000003_drop_document_folders_after_adoption.php');
    }

    public function test_the_migration_adopts_the_legacy_tree_and_only_then_drops_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->personalTeam();

        $this->rewindToLegacySchema();

        $reports = $this->legacyFolder($user, 'Reports');
        $twentySix = $this->legacyFolder($user, '2026', null, $reports);

        $nested = $this->legacyDocument($user, null, $twentySix, 'Buried deep');
        $loose = $this->legacyDocument($user, null, null, 'At the root');
        $teamDoc = $this->legacyDocument($user, $team->id, $reports, 'Team filing');

        $this->rename();
        $this->adoptAndDrop();

        // The legacy world is gone...
        $this->assertFalse(Schema::hasColumn('documents', 'folder_id'));
        $this->assertFalse(Schema::hasTable('document_folders_legacy'));
        $this->assertFalse(Schema::hasTable('tree_folders'));
        $this->assertTrue(Schema::hasColumn('folders', 'uuid'));

        // ...and everything that was in it is in the tree, nesting intact.
        $root = Obj::whereNull('parent_id')->where('team_id', $team->id)->firstOrFail();

        $reportsNode = $root->children()->get()->first(fn (Obj $o) => $o->name() === 'Reports');
        $this->assertNotNull($reportsNode);

        $twentySixNode = $reportsNode->children()->get()->first(fn (Obj $o) => $o->name() === '2026');
        $this->assertNotNull($twentySixNode);

        $this->assertSame($twentySixNode->id, $nested->fresh()->node->parent_id);
        $this->assertSame($root->id, $loose->fresh()->node->parent_id);
        $this->assertSame($reportsNode->id, $teamDoc->fresh()->node->parent_id);
    }

    public function test_a_soft_deleted_document_is_filed_too_so_a_restore_has_somewhere_to_land(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->rewindToLegacySchema();

        $trashed = $this->legacyDocument($user, null, null, 'In the bin');
        $trashed->delete();

        $this->rename();
        $this->adoptAndDrop();

        $this->assertNotNull(Document::withTrashed()->find($trashed->id)->node);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->rewindToLegacySchema();
        $this->legacyFolder($user, 'Reports');
        $this->legacyDocument($user, null, null, 'Untouched');

        $this->rename();

        $this->artisan('dot:files:adopt-tree', ['--dry-run' => true])
            ->expectsOutputToContain('Would adopt 1 folder(s).')
            ->expectsOutputToContain('Would file 1 document(s).')
            ->assertSuccessful();

        $this->assertSame(0, Obj::count());
        $this->assertSame(0, DB::table('folders')->count());
        $this->assertTrue(Schema::hasTable('document_folders_legacy'));
        $this->assertTrue(Schema::hasColumn('documents', 'folder_id'));
    }

    public function test_running_it_twice_files_each_document_once(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->rewindToLegacySchema();
        $folder = $this->legacyFolder($user, 'Reports');
        $document = $this->legacyDocument($user, null, $folder, 'Only once');

        $this->rename();
        $this->adoptAndDrop();

        $parentAfterFirstRun = $document->fresh()->node->parent_id;

        $this->artisan('dot:files:adopt-tree')->assertSuccessful();

        $this->assertSame(1, Obj::where('objectable_type', 'document')->where('objectable_id', $document->id)->count());
        $this->assertSame($parentAfterFirstRun, $document->fresh()->node->parent_id);
    }

    public function test_it_refuses_to_run_before_the_shared_folders_table_exists(): void
    {
        User::factory()->withPersonalTeam()->create();

        // Legacy `folders` is holding the name and the shared one is still
        // parked at tree_folders: adopting now would write shared rows into
        // the legacy table.
        $this->rewindToLegacySchema();

        $this->artisan('dot:files:adopt-tree')->assertFailed();

        $this->assertSame(0, Obj::count());
    }
}
