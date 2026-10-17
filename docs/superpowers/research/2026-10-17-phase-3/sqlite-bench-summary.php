<?php
// Summarise the per-process JSON files of one bench scenario.
[$self, $label, $dir] = $argv;
$all = ['appendMs' => [], 'requestMs' => [], 'checkpointMs' => []]; $tot = ['requests' => 0, 'appendOk' => 0, 'appendRejected' => 0, 'busyErrors' => 0, 'otherErrors' => 0, 'checkpoints' => 0]; $procs = 0; $err = null;
foreach (glob("$dir/*.json") as $f) { $s = json_decode(file_get_contents($f), true); $procs++; foreach ($all as $k => $_) $all[$k] = array_merge($all[$k], $s[$k]); foreach ($tot as $k => $_) $tot[$k] += $s[$k]; $err ??= $s['lastError'] ?? null; }
function q(array $a, float $p): ?float { if (!$a) return null; sort($a); return round($a[min(count($a) - 1, (int) floor($p * count($a)))], 1); }
$o = ['scenario' => $label, 'processes' => $procs] + $tot;
foreach ($all as $k => $a) $o[$k] = ['n' => count($a), 'p50' => q($a, .5), 'p95' => q($a, .95), 'p99' => q($a, .99), 'max' => $a ? round(max($a), 1) : null];
if ($err) $o['lastOtherError'] = $err;
echo json_encode($o), "\n";
