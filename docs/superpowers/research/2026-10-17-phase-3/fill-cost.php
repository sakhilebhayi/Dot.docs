<?php
// Phase 3 evidence: how long DocumentStore::fill()'s work takes (outline build +
// stamp, HTML render, plain text, word count) - the work save() does INSIDE its
// write transaction (DocumentStore.php fill() call inside DB::transaction) - and
// normalise()+validate() (outside it), for generated documents of several sizes.
// Boots the app from a checkout with an in-memory SQLite database; touches no file.
// Usage: php fill-cost.php /path/to/checkout
[$self, $root] = $argv;
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'stderr', 'MAIL_MAILER' => 'array'] as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; $_SERVER[$k] = $v; }
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Documents\Outline\Outline;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;

$schema = app(DocumentSchema::class); $outline = app(Outline::class); $renderer = app(HtmlRenderer::class);
$id = fn () => App\Documents\Schema\BlockId::generate();
$words = explode(' ', 'the quarterly figures show steady growth across every region while costs held flat and the board asked for a clearer view of risk');
function sentence($words, $n) { $o = []; for ($i = 0; $i < $n; $i++) $o[] = $words[($i * 7 + $n) % count($words)]; return ucfirst(implode(' ', $o)).'.'; }
function makeDoc($paras, $words, $id) {
    $c = [['type' => 'toc', 'attrs' => ['id' => $id(), 'depth' => 3, 'entries' => []]]];
    for ($i = 0; $i < $paras; $i++) {
        if ($i % 12 === 0) $c[] = ['type' => 'heading', 'attrs' => ['id' => $id(), 'level' => 1 + ($i / 12) % 2], 'content' => [['type' => 'text', 'text' => 'Section '.$i]]];
        if ($i % 40 === 20) { $rows = []; for ($r = 0; $r < 4; $r++) { $cells = []; for ($k = 0; $k < 3; $k++) $cells[] = ['type' => 'tableCell', 'attrs' => ['id' => $id()], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => $id()], 'content' => [['type' => 'text', 'text' => "r$r c$k"]]]]]; $rows[] = ['type' => 'tableRow', 'attrs' => ['id' => $id()], 'content' => $cells]; } $c[] = ['type' => 'table', 'attrs' => ['id' => $id()], 'content' => $rows]; }
        $c[] = ['type' => 'paragraph', 'attrs' => ['id' => $id()], 'content' => [['type' => 'text', 'text' => sentence($words, 18).' '.sentence($words, 22)], ['type' => 'text', 'text' => ' bold words', 'marks' => [['type' => 'bold']]]]];
    }
    return ['type' => 'doc', 'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []], 'content' => $c];
}
$out = [];
foreach ([50, 250, 1000, 2500] as $paras) {
    $doc = makeDoc($paras, $words, $id);
    $runs = []; $pre = [];
    for ($r = 0; $r < 7; $r++) {
        $t0 = hrtime(true);
        $json = $schema->normalise($schema->ensureIds($doc)); $schema->validate($json);
        $t1 = hrtime(true);
        $res = $outline->build($json, []); $stamped = $outline->apply($json, $res);
        $ctx = RenderContext::editor(); $ctx->numbers = $res->numbers; $ctx->kinds = $res->kinds; $ctx->toc = $res->toc; $ctx->vars = [];
        $html = $renderer->render($stamped, $ctx); $text = $schema->plainText($stamped); $wc = $schema->wordCount($stamped);
        $t2 = hrtime(true);
        $pre[] = ($t1 - $t0) / 1e6; $runs[] = ($t2 - $t1) / 1e6;
    }
    sort($runs); sort($pre);
    $out[] = ['paragraphs' => $paras, 'words' => $wc, 'jsonBytes' => strlen(json_encode($doc)), 'htmlBytes' => strlen($html), 'normaliseValidateMsMedian' => round($pre[3], 1), 'fillMsMedian' => round($runs[3], 1), 'fillMsMax' => round(end($runs), 1)];
}
echo json_encode(['php' => PHP_VERSION, 'opcache' => (bool) ini_get('opcache.enable_cli'), 'results' => $out], JSON_PRETTY_PRINT), "\n";
