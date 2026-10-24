<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
require_once dirname(__DIR__) . '/bench/common.php';
$branch = $argv[1] ?? ''; $profile = $argv[2] ?? ''; $variant = $argv[3] ?? 'release'; $phase = $argv[4] ?? 'all';
if (!in_array($phase, ['all', 'smoke', 'suite', 'regressions', 'followup'], true)) { throw new RuntimeException('Phase must be all, smoke, suite, regressions, or followup'); }
$paths = release_paths($branch, $profile, $variant); $m = release_manifest($branch); $package = dirname(__DIR__);
$source = $paths['source']; $build = $paths['build']; $php = "$build/sapi/cli/php";
if (!is_file($paths['record']) || !is_executable($php)) { throw new RuntimeException('Build this row first'); }
$buildRecord = json_decode(file_get_contents($paths['record']), true, 32, JSON_THROW_ON_ERROR);
foreach ($buildRecord['binary_sha256'] as $binary => $expectedHash) {
    if (hash_file('sha256', "$build/sapi/$binary") !== $expectedHash) {
        throw new RuntimeException('Built executable changed: ' . $binary);
    }
}
$out = $paths['row'] . "/$variant-tests/" . gmdate('Ymd\THis\Z') . '-' . $phase . '-' . bin2hex(random_bytes(3));
mkdir($out, 0755, true);
putenv('PHP_SOURCE=' . $source);
if (!is_dir($package . '/work')) { mkdir($package . '/work', 0755, true); }
release_command([safety_cc(), '-O2', '-Wall', '-Wextra', '-D_DEFAULT_SOURCE', $package . '/bench/limit.c', '-o', "$out/limit"], $package);
putenv('GUARD_LIMIT_BINARY=' . $out . '/limit');
$report = ['schema' => 'php-release-comparison/1', 'branch' => $branch, 'profile' => $profile, 'variant' => $variant,
    'phase' => $phase, 'build' => $buildRecord, 'supervisor_sha256' => hash_file('sha256', "$out/limit"),
    'tests' => [], 'scope' => ['general_memory_safety_proof' => false, 'exploit_prevention_demonstrated' => false,
        'upstream_fixes_in_candidate' => true, 'posix_nts_only' => true, 'leak_sanitizer' => 'disabled',
        'sanitizer_custom_allocator_blind_spots' => 'malloc-for-probes-and-suites;Zend-for-memory-limit-and-service-tests', 'service_load' => 'one-worker-sequential-local-only']];
foreach (['tools/release-test.php', 'bench/releases.php', 'bench/common.php', 'bench/release_probe.c',
    'bench/fpm_bench.php', 'bench/service_probe.c', 'bench/request.php', 'bench/mutations.php', 'bench/oom.php', 'bench/limit.c',
    'bench/sanitizer_probe.c', 'bench/verify.php', 'bench/allocation_probe.c', 'bench/workload.php', 'bench/config.json'] as $file) {
    $report['artifact_sha256'][$file] = hash_file('sha256', "$package/$file");
}
function save_test(array $r, bool $ok, array $details = []): void {
    global $report, $out;
    $r['status'] = $r['termination'] === 'completed' ? ($ok ? 'pass' : 'fail') : 'inconclusive';
    unset($r['out'], $r['err']); $r['details'] = $details; $report['tests'][$r['id']] = $r;
    $report['utc'] = gmdate('c'); release_json("$out/report.json", $report);
    echo $r['id'] . ': ' . $r['status'] . "\n";
}
function run_test(string $id, array $args, int $seconds = 20, ?string $cwd = null, array $env = []): array {
    global $out, $package, $variant;
    $env += ['USE_ZEND_ALLOC' => $variant === 'sanitized' ? '0' : '1'];
    return safety_run($id, safety_env($args, $env), $out, $seconds, '', $cwd ?? $package);
}
if ($phase === 'all' || $phase === 'smoke') {
    $r = run_test('version', [$php, '-n', '-r', 'echo PHP_VERSION;']);
    $expectedVersion = $profile === 'unpatched' ? $m['affected_version'] : $m['official_version'];
    save_test($r, $r['exit'] === 0 && trim($r['out']) === $expectedVersion, ['reported' => trim($r['out'])]);
    $includes = array_map(static fn($v) => '-I' . $v, [$build, "$build/main", "$build/Zend", $source, "$source/main", "$source/Zend", "$source/TSRM"]);
    $flags = $variant === 'sanitized' ? ['-fsanitize=address,undefined', '-fno-sanitize-recover=undefined'] : [];
    $fixtures = ['release_probe', 'service_probe'];
    if ($variant === 'sanitized') { $fixtures[] = 'sanitizer_probe'; }
    foreach ($fixtures as $fixture) {
        $r = run_test("compile-$fixture", [safety_cc(), ...safety_shared_flags(), '-fPIC', '-O1', '-g', ...$flags,
            ...$includes, "$package/bench/$fixture.c", '-o', "$out/$fixture.so"]);
        save_test($r, $r['exit'] === 0);
        if ($r['exit'] !== 0) { throw new RuntimeException('Native probe compilation failed; retained report'); }
    }
    if ($variant === 'sanitized') {
        foreach (['normal' => null, 'bounds' => 'heap-buffer-overflow', 'lifetime' => 'heap-use-after-free',
            'double-free' => 'double-free', 'invalid-free' => 'not malloc', 'overflow' => 'signed integer overflow'] as $kind => $message) {
            $r = run_test("detector-$kind", [$php, '-n', '-d', "extension=$out/sanitizer_probe.so", '-r',
                'guard_memory_fault($argv[1]); echo "CONTROL_RETURNED";', $kind]);
            $ok = $message === null ? $r['exit'] === 0 && str_contains($r['out'], 'CONTROL_RETURNED') && !safety_diagnostic($r['err']) :
                $r['exit'] !== 0 && safety_diagnostic($r['err']) && str_contains($r['err'], $message) && !str_contains($r['out'], 'CONTROL_RETURNED');
            save_test($r, $ok, ['expected_diagnostic' => $message, 'scope' => 'detector-sensitivity-not-guard-prevention']);
        }
    }
    $args = [$php, '-n', '-d', "extension=$out/release_probe.so"];
    $r = run_test('guard-symbol', [...$args, '-r', 'echo guard_release_probe("policy") ? "guard" : "upstream";']);
    save_test($r, $r['exit'] === 0 && trim($r['out']) === ($profile === 'candidate' ? 'guard' : 'upstream'));
    $r = run_test('owned-alias-cleanup', [...$args, '-r',
        'error_clear_last(); guard_release_probe("alias"); error_clear_last(); exit(guard_release_probe("clear") ? 0 : 8);']);
    save_test($r, $r['exit'] === 0 && !safety_diagnostic($r['err']));
    if ($profile === 'candidate') {
        $r = run_test('dirty-checkpoint', [...$args, '-r', 'error_clear_last(); guard_release_probe("dirty"); echo "RETURNED";']);
        save_test($r, $r['exit'] === 137 && ($r['metrics']['signal'] ?? -1) === 0 && !str_contains($r['out'], 'RETURNED'),
            ['scope' => 'direct-checkpoint-call-with-valid-owned-roots-not-a-natural-CVE-trigger']);
    }
    // Impossible allocation exercises failure handling without consuming host memory.
    // ASan changes this path; its own intentional allocator diagnostics are not guard evidence.
    if ($variant === 'release') {
        $r = run_test('raw-allocation-failure', [...$args, '-r', 'guard_release_probe("raw"); echo "RETURNED";']);
        save_test($r, ($profile === 'candidate' ? $r['exit'] === 137 && ($r['metrics']['signal'] ?? -1) === 0 : in_array($r['exit'], [1, 134], true)) &&
            !str_contains($r['out'], 'RETURNED'), ['expected_terminal_failure' => true]);
    }
    $r = run_test('soft-oom', [$php, '-n', '-d', 'memory_limit=8M', '-d', 'memory_hard_limit_percent=-1',
        '-d', 'display_errors=0', '-d', 'log_errors=0', "$package/bench/oom.php"], 20, null, ['USE_ZEND_ALLOC' => '1']);
    save_test($r, $r['exit'] === 255 && str_contains($r['out'], 'GUARD_SHUTDOWN_RAN') && !safety_diagnostic($r['err']));
    foreach (['malloc', 'zend'] as $allocator) {
        $dir = "$out/mutations-$allocator"; mkdir($dir);
        $r = run_test("mutations-$allocator", [$php, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0',
            "$package/bench/mutations.php", $dir], 60, null, ['USE_ZEND_ALLOC' => $allocator === 'malloc' ? '0' : '1']);
        $data = json_decode(trim($r['out']), true);
        save_test($r, $r['exit'] === 0 && ($data['status'] ?? '') === 'completed' && !safety_diagnostic($r['err']), ['measurement' => $data]);
    }
    $r = run_test('service', [PHP_BINARY, '-n', "$package/bench/fpm_bench.php", $profile, "$out/fpm", '0', "$out/service_probe.so"],
        180, null, ['GUARD_FPM_BINARY' => "$build/sapi/fpm/php-fpm", 'USE_ZEND_ALLOC' => '1']);
    $service = is_file("$out/fpm/result.json") ? json_decode(file_get_contents("$out/fpm/result.json"), true, 64, JSON_THROW_ON_ERROR) : null;
    if (str_contains($r['out'] . $r['err'], 'Local FPM startup timed out')) { $r['termination'] = 'startup-timeout'; }
    $ok = $r['exit'] === 0 && is_array($service);
    foreach ($service['scenarios'] ?? [] as $name => $data) {
        $expectedChanges = $name === 'hard' && $profile !== 'unpatched' ? 10 : 0;
        $ok = $ok && $data['normal_failures'] === 0 && $data['unexpected_fault_successes'] === 0 && $data['worker_changes'] === $expectedChanges;
    }
    save_test($r, $ok && !safety_diagnostic($r['err']), ['measurement' => $service,
        'hard_case' => 'identical-64MiB-native-allocation;unpatched-has-no-second-limit']);
    if ($profile !== 'unpatched') {
        $legacyProfile = $variant === 'sanitized' ? ($profile === 'official' ? 'sanitized-upstream' : 'sanitized') :
            ($profile === 'official' ? 'upstream' : 'recoverable');
        $bridge = "$out/build-alias"; mkdir($bridge);
        if (!symlink($build, "$bridge/$legacyProfile")) { throw new RuntimeException('Cannot link fixture build directory'); }
        $faultOut = "$out/faults";
        $r = run_test('allocation-sweep', [PHP_BINARY, '-n', "$package/bench/verify.php", 'faults'], 900, null,
            ['PHP_BUILD_ROOT' => $bridge, 'GUARD_FAULT_PROFILES' => $legacyProfile, 'GUARD_SAFETY_OUT' => $faultOut]);
        $faultData = is_file("$faultOut/report.json") ? json_decode(file_get_contents("$faultOut/report.json"), true, 64, JSON_THROW_ON_ERROR) : null;
        $childStatuses = array_column($faultData['results'] ?? [], 'status');
        if (!in_array('fail', $childStatuses, true) && in_array('inconclusive', $childStatuses, true)) { $r['termination'] = 'child-inconclusive'; }
        save_test($r, $r['exit'] === 0 && ($faultData['status'] ?? '') === 'tested-scoped-cases',
            ['measurement' => $faultData, 'baseline_limitation' => 'allocation-counter API absent in unpatched releases']);
    }
}
if (in_array($phase, ['all', 'suite', 'regressions', 'followup'], true)) {
    // Use the identical official-release corpus for all three profiles, with isolated working copies.
    $corpus = $paths['row'] . "/$variant-corpus";
    if (!is_dir($corpus)) {
        release_command(['git', 'clone', '--shared', '--no-checkout', '--quiet', $source, $corpus], $package);
        release_command(['git', 'checkout', '--detach', $m['official_revision']], $corpus);
    }
    $inventory = [];
    foreach (['Zend', 'tests', 'ext', 'sapi'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$corpus/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) { if ($file->isFile() && $file->getExtension() === 'phpt') { $inventory[] = substr($file->getPathname(), strlen($corpus) + 1); } }
    }
    sort($inventory, SORT_STRING); file_put_contents("$out/inventory.txt", implode("\n", $inventory) . "\n");
    // The runner uses PHP constants such as E_ALL; its version must match the target.
    $args = [$php, '-n', "$corpus/run-tests.php", '-q', '-n', '-j' . ($phase === 'followup' ? 1 : release_jobs()), '--no-progress', '--set-timeout', '20',
        '-g', 'FAIL,XFAIL,BORK,WARN,LEAK,SKIP', '-p', $php, '-w', "$out/failed.txt", '-W', "$out/status.txt"];
    $selection = 'full-official-release-corpus;upstream-discovery-for-built-extensions';
    if ($phase === 'followup') {
        $comparison = [];
        foreach (['official', 'candidate'] as $other) {
            $otherPaths = release_paths($branch, $other, $variant);
            $prior = glob($otherPaths['row'] . "/$variant-tests/*-all-*/report.json"); sort($prior, SORT_STRING);
            if (!$prior) { throw new RuntimeException('Both complete full rows are required for a follow-up'); }
            $priorReport = json_decode(file_get_contents(end($prior)), true, 128, JSON_THROW_ON_ERROR);
            if (!isset($priorReport['tests']['php-suite'])) { throw new RuntimeException('Full row incomplete'); }
            $comparison[$other] = json_decode(file_get_contents(dirname(end($prior)) . '/statuses.json'), true, 32, JSON_THROW_ON_ERROR);
        }
        $selected = [];
        foreach (array_keys(array_intersect_key($comparison['official'], $comparison['candidate'])) as $file) {
            $a = $comparison['official'][$file] ?? null; $b = $comparison['candidate'][$file] ?? null;
            if ($a !== $b && (in_array($a, ['FAILED', 'BORKED', 'LEAKED'], true) || in_array($b, ['FAILED', 'BORKED', 'LEAKED'], true))) { $selected[] = $file; }
        }
        sort($selected, SORT_STRING);
        if (!$selected) { throw new RuntimeException('No adverse differences to follow up'); }
        file_put_contents("$out/followup.txt", implode("\n", $selected) . "\n");
        array_push($args, ...$selected);
        $selection = 'all-paired-adverse-status-differences-in-both-directions;diagnostic-only;does-not-replace-full-suite';
    }
    if ($phase === 'regressions') {
        $selected = [];
        foreach ($m['upstream_security_commits'] as $commit) {
            $changed = release_command(['git', 'diff-tree', '--no-commit-id', '--name-only', '-r', $commit], $source);
            foreach (explode("\n", trim($changed)) as $file) {
                if (str_ends_with($file, '.phpt') && is_file("$corpus/$file")) { $selected[$file] = true; }
            }
        }
        $selected = array_keys($selected); sort($selected, SORT_STRING);
        if ($selected === []) { throw new RuntimeException('Empty upstream regression corpus'); }
        file_put_contents("$out/regressions.txt", implode("\n", $selected) . "\n");
        array_push($args, ...$selected); $selection = 'every-existing-PHPT-changed-by-official-security-commit';
    }
    if ($variant === 'sanitized') { $args[] = '--asan'; }
    echo "$branch $profile $variant: $phase starting\n";
    $r = run_test('php-suite', $args, 2400, $corpus, ['NO_INTERACTION' => '1', 'REPORT_EXIT_STATUS' => '1',
        'TEST_PHP_EXECUTABLE' => $php, 'TEST_PHP_CGI_EXECUTABLE' => "$build/sapi/cgi/php-cgi", 'TEST_PHP_FPM_EXECUTABLE' => "$build/sapi/fpm/php-fpm",
        'TEST_PHP_SRCDIR' => $corpus, 'SKIP_ASAN' => $variant === 'sanitized' ? '1' : '0']);
    $counts = [];
    foreach (['Number of tests', 'Tests skipped', 'Tests warned', 'Tests failed', 'Tests passed', 'Tests borked', 'Expected fail'] as $key) {
        if (preg_match('/^' . preg_quote($key, '/') . '\s*:\s*(\d+)/m', $r['out'], $match)) { $counts[$key] = (int)$match[1]; }
    }
    $statuses = []; $adverse = []; $timeouts = [];
    foreach (is_file("$out/status.txt") ? file("$out/status.txt", FILE_IGNORE_NEW_LINES) : [] as $line) {
        [$status, $path] = array_pad(explode("\t", $line, 2), 2, '');
        $relative = str_starts_with($path, $corpus . '/') ? substr($path, strlen($corpus) + 1) : $path;
        $statuses[$relative] = $status;
        if (!in_array($status, ['FAILED', 'BORKED', 'WARNED', 'LEAKED', 'XFAILED'], true)) { continue; }
        $adverse[$relative] = $status; $base = substr($path, 0, -5);
        if (!str_starts_with($base, '/')) { $base = "$corpus/$base"; }
        foreach (['out', 'diff', 'log'] as $ext) {
            if (is_file("$base.$ext")) {
                $target = "$out/artifacts/" . substr($relative, 0, -5) . ".$ext";
                if (!is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
                copy("$base.$ext", $target);
                if (str_contains(file_get_contents("$base.$ext"), ' ** ERROR: process timed out **')) { $timeouts[$relative] = true; }
            }
        }
    }
    release_json("$out/statuses.json", $statuses);
    save_test($r, $r['exit'] === 0 && ($counts['Tests passed'] ?? 0) > 0 && ($counts['Tests failed'] ?? -1) === 0 &&
        ($counts['Tests borked'] ?? 0) === 0, ['counts' => $counts, 'adverse' => $adverse, 'timeouts' => array_keys($timeouts),
        'corpus_revision' => $m['official_revision'], 'corpus_inventory' => count($inventory), 'selection' => $selection,
        'test_timeouts_are_inconclusive_safety_results' => true, 'slow_tests_excluded' => false,
        'explicit_slow_test_exclusion' => false,
        'upstream_asan_and_perf_sensitive_skips' => $variant === 'sanitized',
        'suite_allocator' => $variant === 'sanitized' ? 'tracked-malloc-with-memory-limits' : 'zend',
        'controller' => 'tested-runtime']);
}
echo "report=$out/report.json\n";
$statuses = array_column($report['tests'], 'status');
exit(in_array('fail', $statuses, true) ? 1 : (in_array('inconclusive', $statuses, true) ? 2 : 0));
