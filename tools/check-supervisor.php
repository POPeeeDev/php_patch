<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
require_once dirname(__DIR__) . '/bench/common.php';
$package = dirname(__DIR__);
$out = $package . '/work/supervisor-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
mkdir($out, 0755, true);
$binary = $argv[1] ?? "$out/limit";
if (!isset($argv[1])) {
    release_command([safety_cc(), '-O2', '-Wall', '-Wextra', '-D_DEFAULT_SOURCE', "$package/bench/limit.c", '-o', $binary], $package);
}
putenv('GUARD_LIMIT_BINARY=' . $binary);
$report = ['schema' => 'php-guard-supervisor-check/1', 'binary_sha256' => hash_file('sha256', $binary), 'checks' => []];
foreach ([
    'private-group' => [['/bin/sh', '-c', 'test "$(ps -o pgid= -p $$)" -eq "$$"'], 0, 0, 'completed'],
    'exit-137' => [['/bin/sh', '-c', 'exit 137'], 137, 0, 'completed'],
    'signal-9' => [['/bin/sh', '-c', 'kill -KILL $$'], 137, 9, 'completed'],
    'timeout' => [['/bin/sh', '-c', 'sleep 10'], 124, 9, 'timeout'],
    'descendant-timeout' => [['/bin/sh', '-c', 'sleep 10 & wait'], 124, 9, 'timeout'],
] as $id => [$command, $exit, $signal, $termination]) {
    $r = safety_run($id, $command, $out, 2);
    $r['pass'] = $r['exit'] === $exit && ($r['metrics']['signal'] ?? -1) === $signal && $r['termination'] === $termination;
    unset($r['out'], $r['err']); $report['checks'][$id] = $r;
}
$failures = [];
for ($i = 0; $i < 1000; ++$i) {
    $r = safety_run("launch-$i", ['/usr/bin/true'], $out, 2);
    if ($r['exit'] !== 0 || $r['termination'] !== 'completed') { $failures[] = $r; }
}
$report['launch_stress'] = ['attempts' => 1000, 'failures' => $failures];
$report['pass'] = $failures === [] && !in_array(false, array_column($report['checks'], 'pass'), true);
release_json("$out/report.json", $report);
echo "report=$out/report.json\n";
exit($report['pass'] ? 0 : 1);
