<?php
// Phase 3 evidence: the step-append transaction on SQLite under concurrent PHP
// processes, the way PHP-FPM runs them (a new connection per request, busy_timeout
// 5000, BEGIN IMMEDIATE as config/database.php sets it). Each simulated request:
// session row write (SESSION_DRIVER=database), presence upsert every 5 s, the quiet
// version read, the compare-and-append transaction, the read of steps since.
// One typist per document also writes a checkpoint every CHECKPOINT_S seconds
// (content_json + content + search_text, ~DOC_KB, holding the lock FILL_MS extra).
// Readers poll without appending.
// Usage: php sqlite-bench.php setup <db> <journal> <docs> <docKB>
//        php sqlite-bench.php run <db> <role> <doc> <who> <seconds> <out> [intervalMs] [checkpointS] [fillMs] [docKB]
$mode = $argv[1];
function connect(string $db): PDO {
    $pdo = new PDO('sqlite:'.$db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}
if ($mode === 'setup') {
    [, , $db, $journal, $docs, $docKB] = $argv;
    @unlink($db); @unlink($db.'-wal'); @unlink($db.'-shm'); @unlink($db.'-journal');
    $pdo = connect($db);
    $pdo->exec('PRAGMA journal_mode = '.($journal === 'wal' ? 'WAL' : 'DELETE'));
    $pdo->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, uuid TEXT, version INTEGER NOT NULL, step_epoch INTEGER NOT NULL DEFAULT 1, step_seq INTEGER NOT NULL DEFAULT 0, checkpoint_seq INTEGER NOT NULL DEFAULT 0, content_json TEXT, content TEXT, search_text TEXT, word_count INTEGER, updated_at TEXT)');
    $pdo->exec('CREATE TABLE document_steps (id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE, epoch INTEGER NOT NULL, seq INTEGER NOT NULL, client_id TEXT NOT NULL, user_id INTEGER NOT NULL, step TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE UNIQUE INDEX document_steps_doc_epoch_seq ON document_steps (document_id, epoch, seq)');
    $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, user_id INTEGER, payload TEXT, last_activity INTEGER)');
    $pdo->exec('CREATE TABLE document_presences (id INTEGER PRIMARY KEY, document_id INTEGER, user_id INTEGER, tab_id TEXT, last_seen_at TEXT)');
    $pdo->exec('CREATE UNIQUE INDEX dp_doc_tab ON document_presences (document_id, tab_id)');
    $blob = str_repeat('{"type":"paragraph","attrs":{"id":"abcdEFGH"},"content":[{"type":"text","text":"words words words"}]},', (int) ($docKB * 1024 / 95));
    $ins = $pdo->prepare('INSERT INTO documents (id, uuid, version, content_json, content, search_text, word_count, updated_at) VALUES (?, ?, 1, ?, ?, ?, 1000, ?)');
    for ($d = 1; $d <= $docs; $d++) $ins->execute([$d, "doc-$d", $blob, $blob, substr($blob, 0, 40000), date('c')]);
    for ($s = 1; $s <= 40; $s++) $pdo->prepare('INSERT INTO sessions VALUES (?, ?, ?, ?)')->execute(["s$s", $s, str_repeat('x', 600), time()]);
    echo json_encode(['journal' => $pdo->query('PRAGMA journal_mode')->fetchColumn()]), "\n";
    exit;
}
[, , $db, $role, $doc, $who, $seconds, $outFile] = $argv;
$interval = (int) ($argv[8] ?? 1500); $checkpointS = (int) ($argv[9] ?? 10); $fillMs = (int) ($argv[10] ?? 5); $docKB = (int) ($argv[11] ?? 100);
mt_srand(crc32($who));
$stepJson = '{"stepType":"replace","from":1234,"to":1234,"slice":{"content":[{"type":"text","text":"abcde"}]}}';
$cpBlob = str_repeat('{"type":"paragraph","attrs":{"id":"abcdEFGH"},"content":[{"type":"text","text":"words words words"}]},', (int) ($docKB * 1024 / 95));
$myEpoch = 1; $mySeq = 0; $lastPresence = 0; $lastCheckpoint = microtime(true);
$stats = ['role' => $role, 'who' => $who, 'requests' => 0, 'appendOk' => 0, 'appendRejected' => 0, 'busyErrors' => 0, 'otherErrors' => 0, 'checkpoints' => 0, 'appendMs' => [], 'requestMs' => [], 'checkpointMs' => []];
$end = microtime(true) + $seconds;
$next = microtime(true) + mt_rand(0, $interval) / 1000;
$retryNow = false;
while (microtime(true) < $end) {
    if (!$retryNow) { $sleep = $next - microtime(true); if ($sleep > 0) usleep((int) ($sleep * 1e6)); $next += ($interval + mt_rand(-300, 300)) / 1000; }
    $retryNow = false;
    $t0 = microtime(true);
    try {
        $pdo = connect($db); // a new connection per request, as PHP-FPM does
        $pdo->prepare('UPDATE sessions SET payload = ?, last_activity = ? WHERE id = ?')->execute([str_repeat('y', 600), time(), "s$who"]);
        if (time() - $lastPresence >= 5) {
            $pdo->prepare('INSERT INTO document_presences (document_id, user_id, tab_id, last_seen_at) VALUES (?, ?, ?, ?) ON CONFLICT (document_id, tab_id) DO UPDATE SET last_seen_at = excluded.last_seen_at')->execute([$doc, $who, "tab-$who", date('c')]);
            $lastPresence = time();
        }
        $row = $pdo->query("SELECT id, version, step_epoch, step_seq FROM documents WHERE id = $doc")->fetch(PDO::FETCH_ASSOC);
        if ($role === 'typist') {
            $k = mt_rand(1, 12);
            $ta = microtime(true);
            $pdo->exec('BEGIN IMMEDIATE');
            $cur = $pdo->query("SELECT step_epoch, step_seq FROM documents WHERE id = $doc")->fetch(PDO::FETCH_ASSOC);
            if ((int) $cur['step_epoch'] === $myEpoch && (int) $cur['step_seq'] === $mySeq) {
                $ins = $pdo->prepare('INSERT INTO document_steps (document_id, epoch, seq, client_id, user_id, step, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $now = date('c');
                for ($i = 1; $i <= $k; $i++) $ins->execute([$doc, $myEpoch, $mySeq + $i, "c$who", $who, $stepJson, $now]);
                $pdo->prepare('UPDATE documents SET step_seq = ? WHERE id = ? AND step_seq = ?')->execute([$mySeq + $k, $doc, $mySeq]);
                $pdo->exec('COMMIT');
                $stats['appendOk']++; $mySeq += $k;
            } else {
                $pdo->exec('ROLLBACK');
                $stats['appendRejected']++; $retryNow = true; // pull, rebase, resend at once
            }
            $stats['appendMs'][] = (microtime(true) - $ta) * 1000;
        }
        $since = $pdo->prepare('SELECT seq, client_id, step FROM document_steps WHERE document_id = ? AND epoch = ? AND seq > ? ORDER BY seq LIMIT 1000');
        $since->execute([$doc, $myEpoch, $mySeq]);
        $got = $since->fetchAll(PDO::FETCH_ASSOC);
        if ($got) $mySeq = (int) end($got)['seq'];
        if ($role === 'typist' && $checkpointS > 0 && microtime(true) - $lastCheckpoint >= $checkpointS) {
            $tc = microtime(true);
            $pdo->exec('BEGIN IMMEDIATE');
            $cp = (int) $pdo->query("SELECT checkpoint_seq FROM documents WHERE id = $doc")->fetchColumn();
            if ($cp < $mySeq) {
                usleep($fillMs * 1000); // DocumentStore::fill() runs inside the transaction
                $pdo->prepare('UPDATE documents SET content_json = ?, content = ?, search_text = ?, version = version + 1, checkpoint_seq = ?, updated_at = ? WHERE id = ? AND checkpoint_seq < ?')
                    ->execute([$cpBlob, $cpBlob, substr($cpBlob, 0, 40000), $mySeq, date('c'), $doc, $mySeq]);
            }
            $pdo->exec('COMMIT');
            $stats['checkpoints']++; $stats['checkpointMs'][] = (microtime(true) - $tc) * 1000; $lastCheckpoint = microtime(true);
        }
        $pdo = null;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'busy')) $stats['busyErrors']++; else { $stats['otherErrors']++; $stats['lastError'] = $e->getMessage(); }
        try { if (isset($pdo) && $pdo && $pdo->inTransaction()) $pdo->exec('ROLLBACK'); } catch (Throwable) {}
        $pdo = null;
    }
    $stats['requests']++;
    $stats['requestMs'][] = (microtime(true) - $t0) * 1000;
    if ($retryNow) usleep(60000); // the client's rebase and round trip before it sends again (not timed)
}
file_put_contents($outFile, json_encode($stats));
