<?php
// Phase 3 evidence: is a document the editors converge on a fixed point of the
// server's write path (DocumentSchema::ensureIds() then normalise())? If the server
// changed text or structure, step positions computed against the editor's copy
// would not apply to the stored checkpoint. Attribute-only changes keep positions.
// Usage: php normalise-fixed-point.php <repo>/app/Documents/Schema final-docs.json
[$self, $schemaDir, $file] = $argv;
require $schemaDir.'/BlockId.php';
require $schemaDir.'/DocumentSchema.php';
use App\Documents\Schema\DocumentSchema;

$schema = new DocumentSchema();
$docs = json_decode(file_get_contents($file), true);

// The editor emits align:null and {} where PHP writes nothing and []; level both.
function level(mixed $n): mixed {
    if (!is_array($n)) return $n;
    if (array_is_list($n)) return array_map('level', $n);
    if (isset($n['attrs']) && is_array($n['attrs'])) {
        foreach ($n['attrs'] as $k => $v) { if ($v === null) unset($n['attrs'][$k]); }
        if (($n['type'] ?? '') === 'toc') unset($n['attrs']['entries']);
        if (($n['type'] ?? '') === 'crossRef') unset($n['attrs']['label']);
        foreach (['vars', 'setup'] as $k) if (isset($n['attrs'][$k]) && $n['attrs'][$k] === []) unset($n['attrs'][$k]);
        if ($n['attrs'] === []) unset($n['attrs']);
    }
    if (isset($n['content']) && $n['content'] === []) unset($n['content']);
    foreach ($n as $k => $v) if (is_array($v)) $n[$k] = level($v);
    ksort($n);
    return $n;
}
// Shape: types, text and nesting only (what decides step positions).
function shape(mixed $n): mixed {
    if (!is_array($n)) return $n;
    if (array_is_list($n)) return array_map('shape', $n);
    $out = ['t' => $n['type'] ?? '?'];
    if (isset($n['text'])) $out['x'] = $n['text'];
    if (!empty($n['content'])) $out['c'] = shape($n['content']);
    return $out;
}
$same = 0; $attrOnly = 0; $structural = 0; $invalid = 0; $examples = [];
foreach ($docs as $row) {
    $in = $row['doc'];
    $out = $schema->normalise($schema->ensureIds($in));
    $errors = $schema->validate($out);
    if ($errors) { $invalid++; $examples[] = ['seed' => $row['seed'], 'errors' => array_slice($errors, 0, 3)]; }
    if (json_encode(level($in)) === json_encode(level($out))) { $same++; continue; }
    if (json_encode(shape($in)) === json_encode(shape($out))) { $attrOnly++; } else { $structural++; $examples[] = ['seed' => $row['seed'], 'structural' => true]; }
}
echo json_encode(['documents' => count($docs), 'fixedPoint' => $same, 'attributeOnlyChange' => $attrOnly, 'textOrStructureChange' => $structural, 'invalidAfterNormalise' => $invalid, 'examples' => array_slice($examples, 0, 5)], JSON_PRETTY_PRINT), "\n";
