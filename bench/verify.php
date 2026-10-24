<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
[$limits, $receipt] = safety_policy();
$phase = $argv[1] ?? 'all';
if (!in_array($phase, ['all', 'model', 'native', 'sanitizers', 'faults', 'mutations', 'service', 'suite'], true)) {
    throw new RuntimeException('Unknown verification phase');
}
$out = getenv('GUARD_SAFETY_OUT') ?: dirname(__DIR__) . '/work/evidence/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
if (!is_dir($out)) { mkdir($out, 0700, true); }
$source = safety_source();
$controller = safety_controller();
$reportPath = "$out/report.json";
$report = is_file($reportPath) ? json_decode(file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR) :
    ['schema' => 'guard-general-memory-tests/1', 'limits' => $limits, 'results' => [], 'scope' => [
        'evidence' => 'dynamic-tests-and-bounded-lifecycle-model', 'general_memory_safety_proof' => 'not-established',
        'vendor_fixes' => 'required', 'production_deployment' => 'none',
        'leak_sanitizer' => 'disabled', 'platform' => PHP_OS_FAMILY . '-POSIX-NTS',
        'full_C_refinement' => 'open', 'sanitizer_custom_allocator_blind_spots' => 'explicit']];
file_put_contents("$out/config-receipt.json", json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
foreach (glob(dirname(__DIR__) . '/work/build-records/*-build.json') as $buildRecord) {
    $report['builds'][basename($buildRecord, '-build.json')] = json_decode(file_get_contents($buildRecord), true, 32, JSON_THROW_ON_ERROR);
}
foreach (['config.json', 'verify.php', 'common.php', '../models/RequestCleanup.tla', 'sanitizer_probe.c', 'allocation_probe.c',
    'service_probe.c', 'workload.php', 'mutations.php', 'fpm_bench.php', 'request.php'] as $file) {
    $report['artifact_sha256'][$file] = hash_file('sha256', __DIR__ . '/' . $file);
}
function record(array $run, bool $expected, array $details = []): void
{
    global $report, $reportPath, $out;
    if (is_file($reportPath)) { $report = json_decode(file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR); }
    $status = safety_result_status($run, $expected, $details['known_failures'] ?? 0);
    $entry = array_diff_key($run, ['out' => 1, 'err' => 1]);
    $entry['status'] = $status; $entry['details'] = $details;
    $report['results'][$run['id']] = $entry;
    $report['utc'] = gmdate('c'); $report['status'] = in_array('fail', array_column($report['results'], 'status'), true) ? 'failures-found' :
        (in_array('inconclusive', array_column($report['results'], 'status'), true) ? 'inconclusive' : 'tested-scoped-cases');
    file_put_contents($reportPath . '.' . getmypid() . '.tmp', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    rename($reportPath . '.' . getmypid() . '.tmp', $reportPath);
    $md = "# General memory-error and disruption tests\n\nStatus: **{$report['status']}**. Not a general memory-safety proof.\n\n" .
        "| Check | Result | Wall ms |\n|---|---|---:|\n";
    foreach ($report['results'] as $r) { $md .= '| ' . $r['id'] . ' | ' . $r['status'] . ' | ' . ($r['metrics']['wall_ms'] ?? '-') . " |\n"; }
    $md .= "\n[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.\n";
    file_put_contents("$out/report.md", $md);
    echo "{$run['id']}: $status\n";
}
function enabled(string $name): bool { global $phase; return $phase === 'all' || $phase === $name; }

if (enabled('model')) {
    $jar = getenv('TLA_JAR') ?: dirname(__DIR__) . '/tools/tla2tools.jar';
    if (!is_file($jar)) { throw new RuntimeException('Set TLA_JAR to the TLC distribution; see BUILD.md'); }
    foreach ([['recoverable', true, true, null], ['control-release-first', false, true, 'DetachBeforeRelease'],
        ['control-no-checkpoint', true, false, 'NoStaleConsumer']] as [$name, $detach, $checkpoint, $violation]) {
        $cfg = "$out/$name.cfg";
        file_put_contents($cfg, "SPECIFICATION Spec\nCONSTANTS Roots = 2\n Requests = 2\n Steps = 20\n DetachFirst = " .
            ($detach ? 'TRUE' : 'FALSE') . "\n Checkpoint = " . ($checkpoint ? 'TRUE' : 'FALSE') .
            "\nINVARIANTS TypeOK DetachBeforeRelease NoStaleConsumer CleanReuse\nCHECK_DEADLOCK FALSE\n" .
            ($violation === null ? "PROPERTIES RankDescent Termination\n" : ''));
        $r = safety_run('tla-' . $name, ['java', '-Xmx512m', '-cp', $jar, 'tlc2.TLC', '-workers', '1',
            '-seed', '1', '-fp', '0', '-noGenerateSpecTE', '-metadir', "$out/$name-states", '-config', $cfg, dirname(__DIR__) . '/models/RequestCleanup.tla'], $out);
        record($r, $violation === null ? $r['exit'] === 0 && str_contains($r['out'], 'No error has been found') :
            $r['exit'] !== 0 && str_contains($r['out'], "Invariant $violation is violated"),
            ['scope' => 'two-requests-two-roots-20-steps-vendor-safe-point-assumed', 'expected_violation' => $violation]);
    }
}

if (enabled('sanitizers')) {
    $build = dirname(safety_php('sanitized'), 3);
    $extension = "$out/guard_memory_probe.so";
    $include = array_map(static fn($p) => '-I' . $p, [$build, "$build/main", "$build/Zend", $source, "$source/main", "$source/Zend", "$source/TSRM"]);
    $r = safety_run('compile-sanitizer-controls', [safety_cc(), ...safety_shared_flags(), '-fPIC', '-O0', '-g',
        '-fsanitize=address,undefined', '-fno-sanitize-recover=undefined', '-DZEND_OOM_GUARD_MODE=2', ...$include,
        __DIR__ . '/sanitizer_probe.c', '-o', $extension], $out);
    record($r, $r['exit'] === 0 && is_file($extension));
    if (is_file($extension)) {
        foreach (['normal' => null, 'bounds' => 'heap-buffer-overflow', 'lifetime' => 'heap-use-after-free',
            'double-free' => 'double-free', 'invalid-free' => 'not malloc', 'overflow' => 'signed integer overflow'] as $kind => $message) {
            $r = safety_run('sanitizer-' . $kind, safety_env([safety_php('sanitized'), '-n', '-d', 'extension=' . $extension,
                '-r', 'guard_memory_fault($argv[1]); echo "GUARD_CONTROL_RETURNED";', $kind], ['USE_ZEND_ALLOC' => '0']), $out);
            record($r, $message === null ? $r['exit'] === 0 && str_contains($r['out'], 'GUARD_CONTROL_RETURNED') && !safety_diagnostic($r['err']) :
                $r['exit'] !== 0 && safety_diagnostic($r['err']) && str_contains($r['err'], $message) && !str_contains($r['out'], 'GUARD_CONTROL_RETURNED'),
                ['expected_diagnostic' => $message, 'scope' => 'instrumentation-sensitivity-not-guard-prevention']);
        }
    }
}

if (enabled('native')) {
    $php = safety_php('recoverable'); $build = dirname($php, 3);
    $include = array_map(static fn($p) => '-I' . $p, [$build, "$build/main", "$build/Zend", $source, "$source/main", "$source/Zend", "$source/TSRM"]);
    $extension = "$out/recoverable_native_probe.so";
    $r = safety_run('compile-recoverable-probe', [safety_cc(), ...safety_shared_flags(), '-fPIC', '-O1',
        '-DZEND_OOM_GUARD_MODE=2', ...$include, __DIR__ . '/lifecycle_probe.c', '-o', $extension], $out);
    record($r, $r['exit'] === 0 && is_file($extension));
    $r = safety_run('recoverable-soft-oom', safety_env([$php, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0',
        '-d', 'memory_limit=8M', '-d', 'memory_hard_limit_percent=-1', __DIR__ . '/oom.php']), $out);
    record($r, $r['exit'] === 255 && str_contains($r['out'], 'GUARD_SHUTDOWN_RAN') && !safety_diagnostic($r['err']),
        ['scope' => 'soft-OOM-preserves-vendor-request-shutdown']);
    foreach (['raw' => ['GUARD_NATIVE_PROBE' => ''], 'dirty' => ['GUARD_NATIVE_PROBE' => 'dirty-root']] as $kind => $env) {
        $body = $kind === 'raw' ? 'guard_oom_probe("raw-oom"); echo "GUARD_UNEXPECTED_RETURN";' : 'echo "GUARD_BODY_FINISHED";';
        $r = safety_run("recoverable-retire-$kind", safety_env([$php, '-n', '-d', 'extension=' . $extension, '-r', $body], $env), $out);
        record($r, $r['exit'] === 137 && !str_contains($r['out'], 'GUARD_UNEXPECTED_RETURN'),
            ['scope' => 'hard-allocation-failure-or-selected-dirty-root-still-retires']);
    }
}

if (enabled('faults')) {
    foreach (['upstream', 'strict', 'recoverable', 'sanitized-upstream', 'sanitized'] as $profile) {
        $selected = getenv('GUARD_FAULT_PROFILES');
        if ($selected !== false && !in_array($profile, explode(',', $selected), true)) { continue; }
        $sweep = []; $ok = true; $inconclusive = false; $knownFailures = 0; $start = hrtime(true);
        $dir = "$out/faults-$profile"; mkdir($dir, 0700); mkdir("$dir/sessions", 0700);
        $build = dirname(safety_php($profile), 3);
        $include = array_map(static fn($p) => '-I' . $p, [$build, "$build/main", "$build/Zend", $source, "$source/main", "$source/Zend", "$source/TSRM"]);
        $extension = "$dir/allocation_probe.so";
        $flags = str_starts_with($profile, 'sanitized') ? ['-fsanitize=address,undefined', '-fno-sanitize-recover=undefined'] : [];
        $compile = safety_run('compile-allocation-probe', [safety_cc(), ...safety_shared_flags(), '-fPIC', '-O1', '-g',
            ...$flags, ...$include, __DIR__ . '/allocation_probe.c', '-o', $extension], $dir);
        if ($compile['exit'] !== 0) { throw new RuntimeException('Cannot compile allocation probe'); }
        $alias = safety_run('aliased-owned-roots', safety_env([safety_php($profile), '-n', '-d', 'extension=' . $extension,
            '-r', 'error_clear_last(); guard_test_alias_roots(); error_clear_last(); if (!guard_test_roots_clear()) { exit(8); } echo "GUARD_ALIAS_CLEAR";'],
            ['USE_ZEND_ALLOC' => '0']), $dir);
        record(['id' => "aliased-roots-$profile", ...array_diff_key($alias, ['id' => 1])],
            $alias['exit'] === 0 && str_contains($alias['out'], 'GUARD_ALIAS_CLEAR') && !safety_diagnostic($alias['err']),
            ['scope' => 'two-valid-owned-references-to-one-allocation-detached-and-released']);
        $bounds = [];
        foreach (['startup', 'body'] as $faultPhase) {
          foreach (['array', 'session', 'callbacks'] as $kind) {
            for ($n = 64; $n <= $limits['MaxAllocationOrdinal']; $n *= 2) {
                $cal = safety_run("calibrate-$faultPhase-$kind-$n", safety_env([safety_php($profile), '-n', '-d', 'display_errors=0',
                    '-d', 'log_errors=0', '-d', 'extension=' . $extension, '-d', 'session.save_path=' . $dir . '/sessions',
                    __DIR__ . '/workload.php', $kind, $faultPhase === 'body' ? (string)$n : '0'],
                    ['USE_ZEND_ALLOC' => '0', 'USE_TRACKED_ALLOC' => '1', 'ZEND_MM_MAX_ALLOCS' => $faultPhase === 'startup' ? (string)$n : '0']), $dir, $limits['CaseSeconds']);
                if ($cal['exit'] === 0 && str_contains($cal['out'], 'GUARD_COMPLETE') && !safety_diagnostic($cal['err'])) { $bounds[$faultPhase][$kind] = $n; break; }
            }
            if (!isset($bounds[$faultPhase][$kind])) { $ok = false; $bounds[$faultPhase][$kind] = $limits['MaxAllocationOrdinal']; }
          }
        }
        foreach (['startup', 'body'] as $faultPhase) {
          for ($case = 1; $case <= $limits['AllocationCases']; $case++) {
            $kind = ['array', 'session', 'callbacks'][($case - 1) % 3];
            $index = intdiv($case - 1, 3);
            $n = 1 + (int)floor($index * ($bounds[$faultPhase][$kind] - 1) / 63);
            $r = safety_run(sprintf('allocation-%s-%03d', $faultPhase, $case), safety_env([safety_php($profile), '-n', '-d', 'display_errors=0',
                '-d', 'log_errors=0', '-d', 'extension=' . $extension, '-d', 'session.save_path=' . $dir . '/sessions',
                __DIR__ . '/workload.php', $kind, $faultPhase === 'body' ? (string)$n : '0'],
                ['USE_ZEND_ALLOC' => '0', 'USE_TRACKED_ALLOC' => '1', 'ZEND_MM_MAX_ALLOCS' => $faultPhase === 'startup' ? (string)$n : '0']), $dir, $limits['CaseSeconds']);
            $clean = !safety_diagnostic($r['out'] . $r['err']);
            $allowed = $profile === 'strict' ? [0, 137] : [0, 121];
            $pass = $r['termination'] === 'completed' && in_array($r['exit'], $allowed, true) && $clean;
            if ($r['exit'] === 0 && !str_contains($r['out'], 'GUARD_COMPLETE')) { $pass = false; }
            $inconclusive = $inconclusive || $r['termination'] !== 'completed' || in_array($r['exit'], [126, 127], true);
            if (!$pass && $r['termination'] === 'completed' && !in_array($r['exit'], [126, 127], true)) { ++$knownFailures; }
            $ok = $ok && $pass;
            $sweep[] = ['allocation' => $n, 'phase' => $faultPhase, 'kind' => $kind, 'exit' => $r['exit'], 'termination' => $r['termination'],
                'sanitizer_diagnostic' => !$clean, 'entered' => str_contains($r['out'] . $r['err'], 'GUARD_BODY_ENTERED'),
                'completed' => str_contains($r['out'], 'GUARD_COMPLETE'), 'shutdown' => str_contains($r['out'], 'GUARD_SHUTDOWN'), 'pass' => $pass];
          }
        }
        file_put_contents("$dir/results.json", json_encode($sweep, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        record(['id' => "fault-sweep-$profile", 'exit' => 0, 'termination' => $inconclusive ? 'some-timeouts-or-launch-failures' : 'completed',
            'metrics' => ['wall_ms' => (hrtime(true) - $start) / 1e6], 'out' => '', 'err' => '', 'log' => "faults-$profile/results.json"], $ok,
            ['cases' => count($sweep), 'passed' => count(array_filter($sweep, static fn($c) => $c['pass'])),
                'known_failures' => $knownFailures, 'has_inconclusive_cases' => $inconclusive,
                'entered' => count(array_filter($sweep, static fn($c) => $c['entered'])),
                'exits' => array_count_values(array_column($sweep, 'exit')), 'calibrated_upper_ordinals' => $bounds,
                'scope' => '64-spaced-ordinals-per-workload-per-startup-or-body-phase;121-is-upstream-injected-OOM-status']);
    }
}

if (enabled('service')) {
    $build = dirname(safety_php('recoverable'), 3);
    $extension = "$out/guard_service_probe.so";
    $include = array_map(static fn($p) => '-I' . $p, [$build, "$build/main", "$build/Zend", $source, "$source/main", "$source/Zend", "$source/TSRM"]);
    $compile = safety_run('compile-service-control', [safety_cc(), ...safety_shared_flags(), '-fPIC', '-O1',
        ...$include, __DIR__ . '/service_probe.c', '-o', $extension], $out);
    record($compile, $compile['exit'] === 0 && is_file($extension));
    foreach ([['upstream', 0], ['strict', 0], ['recoverable', 0], ['upstream', 1]] as [$profile, $maxRequests]) {
        $selected = getenv('GUARD_SERVICE_PROFILES');
        if ($selected !== false && !in_array("$profile:$maxRequests", explode(',', $selected), true)) { continue; }
        $id = "fpm-$profile-max$maxRequests";
        $measurement = "$out/$id-" . bin2hex(random_bytes(3));
        $r = safety_run($id, safety_env([$controller, '-n', __DIR__ . '/fpm_bench.php', $profile, $measurement, (string)$maxRequests, $extension]), $out, 120);
        $data = is_file("$measurement/result.json") ? json_decode(file_get_contents("$measurement/result.json"), true, 64, JSON_THROW_ON_ERROR) : null;
        $ok = $r['exit'] === 0 && is_array($data);
        if ($ok) {
            foreach ($data['scenarios'] as $name => $s) {
                $ok = $ok && $s['normal_failures'] === 0 && $s['unexpected_fault_successes'] === 0;
                if ($maxRequests === 0) {
                    $expectChanges = $name === 'hard' || ($name === 'soft' && $profile === 'strict') ? $s['fault_requests'] : 0;
                    $ok = $ok && $s['worker_changes'] === $expectChanges;
                }
            }
        }
        record($r, $ok, ['measurement' => $data === null ? null : array_map(static function ($s) { unset($s['samples']); return $s; }, $data['scenarios']),
            'scope' => 'local-single-worker-sequential-FastCGI-not-production-load']);
    }
}

if (enabled('mutations')) {
    foreach (['upstream', 'recoverable', 'sanitized-upstream', 'sanitized'] as $profile) {
        $selected = getenv('GUARD_MUTATION_PROFILES');
        if ($selected !== false && !in_array($profile, explode(',', $selected), true)) { continue; }
        foreach (['malloc', 'zend'] as $allocator) {
            $id = "mutations-$profile-$allocator";
            mkdir("$out/$id", 0700);
            $r = safety_run($id, safety_env([safety_php($profile), '-n', '-d', 'display_errors=0', '-d', 'log_errors=0',
                __DIR__ . '/mutations.php', "$out/$id"], ['USE_ZEND_ALLOC' => $allocator === 'zend' ? '1' : '0']), $out, 60);
            $data = json_decode(trim($r['out']), true);
            record($r, $r['exit'] === 0 && ($data['status'] ?? '') === 'completed' && !safety_diagnostic($r['err']), ['measurement' => $data]);
        }
    }
}

if (enabled('suite')) {
    $manifest = [];
    foreach (['Zend', 'tests', 'ext', 'sapi'] as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$source/$root", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) { if ($file->isFile() && $file->getExtension() === 'phpt') { $manifest[] = $file->getPathname(); } }
    }
    sort($manifest, SORT_STRING); file_put_contents("$out/all-source-tests.txt", implode("\n", $manifest) . "\n");
    foreach ([['upstream', 'zend'], ['recoverable', 'zend'], ['sanitized-upstream', 'malloc'], ['sanitized', 'malloc'],
        ['sanitized-upstream', 'zend'], ['sanitized', 'zend']] as [$profile, $allocator]) {
        $selected = getenv('GUARD_SUITES');
        if ($selected !== false && !in_array("$profile:$allocator", explode(',', $selected), true)) { continue; }
        $id = "suite-$profile-$allocator"; $php = safety_php($profile);
        $args = [$controller, '-n', $source . '/run-tests.php', '-q', '-n', '-j' . $limits['TestWorkers'], '--no-progress',
            '--set-timeout', '20', '-g', 'FAIL,XFAIL,BORK,WARN,LEAK,SKIP', '-p', $php,
            '-w', "$out/$id-failed.txt", '-W', "$out/$id-status.txt"];
        if ($allocator === 'malloc') { $args[] = '--asan'; }
        $env = ['NO_INTERACTION' => '1', 'REPORT_EXIT_STATUS' => '1', 'TEST_PHP_EXECUTABLE' => $php,
            'TEST_PHP_FPM_EXECUTABLE' => dirname($php, 2) . '/fpm/php-fpm', 'TEST_PHP_SRCDIR' => $source,
            'USE_ZEND_ALLOC' => $allocator === 'zend' ? '1' : '0'];
        if (str_starts_with($profile, 'sanitized')) { $env['SKIP_ASAN'] = '1'; }
        echo "$id starting; full build-discovered suite, no slow-test exclusion\n";
        $r = safety_run($id, safety_env($args, $env), $out, $limits['SuiteSeconds'], '', $source);
        $counts = [];
        foreach (['Number of tests', 'Tests skipped', 'Tests warned', 'Tests failed', 'Tests passed', 'Tests borked', 'Expected fail'] as $key) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*:\s*(\d+)/m', $r['out'], $m)) { $counts[$key] = (int)$m[1]; }
        }
        $adverse = [];
        foreach (file("$out/$id-status.txt", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$status, $path] = array_pad(explode("\t", $line, 2), 2, '');
            if (in_array($status, ['FAILED', 'BORKED', 'WARNED', 'LEAKED', 'XFAILED'], true)) {
                $relative = substr($path, strlen($source) + 1);
                $adverse[$relative] = $status;
                $target = "$out/$id-artifacts/" . dirname($relative);
                if (!is_dir($target)) { mkdir($target, 0700, true); }
                foreach (['.phpt', '.diff', '.out', '.exp', '.log', '.sh', '.php', '.skip.php', '.mem'] as $suffix) {
                    $artifact = substr($path, 0, -5) . $suffix;
                    if (is_file($artifact)) { copy($artifact, $target . '/' . basename($artifact)); }
                }
            }
        }
        record($r, $r['exit'] === 0 && ($counts['Tests passed'] ?? 0) > 1000 && ($counts['Tests failed'] ?? -1) === 0 &&
            ($counts['Tests borked'] ?? 0) === 0, ['counts' => $counts, 'source_phpt_inventory' => count($manifest),
            'selection' => 'upstream-runner-full-discovery-for-built-extensions', 'slow_tests_excluded' => false,
            'online_tests' => 'upstream-default-offline', 'allocator' => $allocator,
            'adverse_and_expected' => $adverse,
            'asan_heap_object_coverage' => str_starts_with($profile, 'sanitized') && $allocator === 'malloc' ? 'system-allocations' : 'not-full-Zend-suballocation-coverage']);
    }
    $comparison = [];
    foreach ([['suite-upstream-zend', 'suite-recoverable-zend'], ['suite-sanitized-upstream-malloc', 'suite-sanitized-malloc'],
        ['suite-sanitized-upstream-zend', 'suite-sanitized-zend']] as [$control, $candidate]) {
        if (!isset($report['results'][$control], $report['results'][$candidate])) { continue; }
        $a = $report['results'][$control]['details']['adverse_and_expected'] ?? [];
        $b = $report['results'][$candidate]['details']['adverse_and_expected'] ?? [];
        $new = []; $shared = [];
        foreach ($b as $file => $status) {
            if (in_array($status, ['FAILED', 'BORKED', 'LEAKED'], true)) {
                if (($a[$file] ?? null) === $status) { $shared[$file] = $status; }
                else { $new[$file] = ['control' => $a[$file] ?? 'not-adverse', 'candidate' => $status]; }
            }
        }
        $comparison[$candidate] = ['control' => $control, 'new_adverse_tests' => $new, 'shared_adverse_tests' => $shared];
    }
    file_put_contents("$out/comparison.json", json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
echo "report=$out/report.md\n";
exit($report['status'] === 'failures-found' ? 1 : ($report['status'] === 'inconclusive' ? 2 : 0));
