<?php

// Child process for Tests\Feature\Operations\SqliteConcurrencyTest (EA-7 / O-09): boots the app against the
// file database named in DB_DATABASE and saves one document N times as fast as it can, the way several open
// editors would. Prints one JSON line: how many saves landed, were refused as stale, or failed otherwise.

use App\Documents\DocumentStore;
use App\Documents\StaleDocumentException;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $documentId, $userId, $rounds, $label] = $argv;
$store = $app->make(DocumentStore::class);
$user = User::findOrFail((int) $userId);
$result = ['saved' => 0, 'stale' => 0, 'errors' => []];

for ($i = 0; $i < (int) $rounds; $i++) {
    $doc = Document::findOrFail((int) $documentId);
    $json = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "{$label} save {$i}"]]]]];
    try {
        $store->save($doc, $json, $user, ['expectedVersion' => (int) $doc->version]);
        $result['saved']++;
    } catch (StaleDocumentException) {
        $result['stale']++;
    } catch (Throwable $e) {
        $result['errors'][] = $e::class.': '.substr($e->getMessage(), 0, 120);
    }
}

echo json_encode($result), PHP_EOL;
