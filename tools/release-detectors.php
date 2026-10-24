<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
require_once dirname(__DIR__) . '/bench/common.php';
$branch = $argv[1] ?? '';
$package = dirname(__DIR__);
$statuses = [];
foreach (['official', 'candidate'] as $profile) {
    $p = release_paths($branch, $profile, 'sanitized');
    $reports = glob($p['row'] . '/sanitized-tests/*-all-*/report.json'); sort($reports, SORT_STRING);
    if (!$reports) { throw new RuntimeException('Run the instrumented native checks first'); }
    $input = end($reports);
    $original = json_decode(file_get_contents($input), true, 128, JSON_THROW_ON_ERROR);
    foreach ($original['build']['binary_sha256'] as $binary => $hash) {
        if (hash_file('sha256', $p['build'] . '/sapi/' . $binary) !== $hash) { throw new RuntimeException('Binary changed'); }
    }
    $out = $p['row'] . '/sanitized-detectors/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
    mkdir($out, 0755, true);
    release_command([safety_cc(), '-O2', '-Wall', '-Wextra', '-D_DEFAULT_SOURCE', "$package/bench/limit.c", '-o', "$out/limit"], $package);
    putenv('GUARD_LIMIT_BINARY=' . $out . '/limit');
    $report = ['schema' => 'php-release-detector-diagnostic/1', 'branch' => $branch, 'profile' => $profile,
        'input_report' => $input, 'build' => $original['build'],
        'fixture_sha256' => hash_file('sha256', dirname($input) . '/sanitizer_probe.so'),
        'supervisor_sha256' => hash_file('sha256', "$out/limit"),
        'scope' => 'paired-detector-reporting-diagnostic;does-not-replace-full-run;not-guard-prevention', 'tests' => []];
    foreach (['original', 'without-symbolization'] as $mode) {
        foreach (['normal', 'bounds', 'lifetime', 'double-free', 'invalid-free', 'overflow'] as $kind) {
            $test = $original['tests']['detector-' . $kind] ?? throw new RuntimeException('Detector checks incomplete');
            $command = $test['command'];
            if ($mode === 'without-symbolization') {
                foreach ($command as &$arg) {
                    if (str_starts_with($arg, 'ASAN_OPTIONS=')) { $arg .= ':symbolize=0'; }
                    if (str_starts_with($arg, 'UBSAN_OPTIONS=')) { $arg = str_replace('print_stacktrace=1', 'print_stacktrace=0', $arg); }
                }
                unset($arg);
            }
            $r = safety_run("$mode-$kind", $command, $out, 20);
            $message = $test['details']['expected_diagnostic'];
            $ok = $message === null ? $r['exit'] === 0 && str_contains($r['out'], 'CONTROL_RETURNED') && !safety_diagnostic($r['err']) :
                $r['exit'] !== 0 && str_contains($r['err'], $message) && safety_diagnostic($r['err']) && !str_contains($r['out'], 'CONTROL_RETURNED');
            $r['diagnostic_observed'] = $message !== null && str_contains($r['err'], $message);
            $r['status'] = $r['termination'] === 'completed' ? ($ok ? 'pass' : 'fail') : 'inconclusive';
            $statuses[] = $r['status'];
            unset($r['out'], $r['err']); $report['tests'][$r['id']] = $r;
            release_json("$out/report.json", $report);
            echo "$branch $profile $mode $kind: {$r['status']}\n";
        }
    }
    echo "report=$out/report.json\n";
}
exit(in_array('fail', $statuses, true) ? 1 : (in_array('inconclusive', $statuses, true) ? 2 : 0));
