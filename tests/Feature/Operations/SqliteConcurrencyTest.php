<?php

namespace Tests\Feature\Operations;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Several editors saving one document at once, on a real SQLite FILE in WAL
 * mode - the production setup (EA-7 / O-09). The rest of the suite runs on an
 * in-memory database with one connection, where "database is locked" cannot
 * happen; here four separate PHP processes race. Every save must either land
 * or be refused as stale (the optimistic version check); none may fail
 * otherwise, the version must count exactly the saves that landed, and the
 * file must pass its integrity check.
 */
class SqliteConcurrencyTest extends TestCase
{
    public function test_concurrent_saves_from_separate_processes_never_fail_with_a_lock_error(): void
    {
        $file = sys_get_temp_dir().'/doc-concurrency-'.uniqid().'.sqlite';
        touch($file);

        config()->set('database.connections.sqlite.database', $file);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame('wal', strtolower((string) DB::selectOne('PRAGMA journal_mode')->journal_mode));

        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Raced');
        $start = (int) $doc->fresh()->version;

        $env = array_merge(getenv(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array', 'APP_ENV' => 'testing']);
        $script = base_path('tests/fixtures/concurrent-saver.php');
        $procs = [];
        foreach (range(1, 4) as $n) {
            $procs[] = proc_open([PHP_BINARY, $script, (string) $doc->id, (string) $user->id, '25', "p{$n}"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $outputs[] = $pipes;
        }

        $saved = 0;
        $stale = 0;
        $errors = [];
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($outputs[$i][1]);
            $err = stream_get_contents($outputs[$i][2]);
            proc_close($proc);
            $result = json_decode(trim((string) $out), true);
            $this->assertIsArray($result, "child {$i} printed no result: {$out} {$err}");
            $saved += $result['saved'];
            $stale += $result['stale'];
            $errors = array_merge($errors, $result['errors']);
        }

        $this->assertSame([], $errors, 'no save may fail other than as stale');
        $this->assertSame(100, $saved + $stale);
        $this->assertGreaterThan(0, $saved);
        $this->assertSame($start + $saved, (int) Document::findOrFail($doc->id)->version, 'the version counts exactly the saves that landed');
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);

        DB::purge('sqlite');
        @unlink($file);
        @unlink($file.'-wal');
        @unlink($file.'-shm');
    }
}
