<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
$package = dirname(__DIR__);
$destination = $argv[1] ?? throw new RuntimeException('Usage: php tools/release-export.php NEW_OUTPUT_DIRECTORY');
if (file_exists($destination)) { throw new RuntimeException('Use a new output directory; existing evidence is never replaced'); }
$work = release_paths('8.2', 'candidate')['root'];
$replace = [$work => 'work/releases', $package => '.', PHP_BINARY => '<PHP_CONTROLLER>'];
foreach ([getenv('PHP_GIT'), getenv('HOME'), sys_get_temp_dir()] as $value) {
    if (!$value) { continue; }
    $label = $value === getenv('PHP_GIT') ? '<PHP_GIT>' : ($value === getenv('HOME') ? '<HOME>' : '<TMP>');
    $replace[$value] = $label;
    if (realpath($value)) { $replace[realpath($value)] = $label; }
}
uksort($replace, static fn($a, $b) => strlen($b) <=> strlen($a));
function normalized(string $text): string {
    global $replace;
    $text = str_replace(array_keys($replace), array_values($replace), $text);
    foreach ($replace as $path => $label) { $text = str_replace(str_replace('/', '\\/', $path), $label, $text); }
    $user = getenv('USER');
    if ($user) { $text = str_replace($user, '<USER>', $text); }
    return $text;
}
function export_file(string $from, string $to): void {
    if (!is_dir(dirname($to))) { mkdir(dirname($to), 0755, true); }
    file_put_contents($to, normalized(file_get_contents($from)));
}
$rows = []; $sources = [];
foreach (['8.2', '8.3', '8.4', '8.5'] as $branch) {
    foreach ([['unpatched', 'release'], ['official', 'release'], ['candidate', 'release'], ['official', 'sanitized'], ['candidate', 'sanitized']] as [$profile, $variant]) {
        $p = release_paths($branch, $profile, $variant);
        $build = json_decode(file_get_contents($p['record']), true, 32, JSON_THROW_ON_ERROR);
        if (trim(release_command(['git', 'write-tree'], $p['source'])) !== $build['source']['prepared_tree']) {
            throw new RuntimeException('Source index changed since preparation');
        }
        release_command(['git', 'diff', '--exit-code', '--quiet'], $p['source']);
        foreach ($build['binary_sha256'] as $binary => $hash) {
            if (hash_file('sha256', $p['build'] . '/sapi/' . $binary) !== $hash) { throw new RuntimeException('Tested binary changed'); }
        }
        $reports = glob($p['row'] . "/$variant-tests/*-all-*/report.json"); sort($reports, SORT_STRING);
        $latest = $reports ? end($reports) : false;
        if (!$latest) { throw new RuntimeException("Missing full run: $branch $profile $variant"); }
        $report = json_decode(file_get_contents($latest), true, 128, JSON_THROW_ON_ERROR);
        if (!isset($report['tests']['php-suite'])) { throw new RuntimeException("Incomplete full run: $latest"); }
        $id = "$branch/$profile-$variant";
        $suite = $report['tests']['php-suite'];
        $recordedStatuses = json_decode(file_get_contents(dirname($latest) . '/statuses.json'), true, 32, JSON_THROW_ON_ERROR);
        $smokes = glob($p['row'] . "/$variant-tests/*-smoke-*/report.json"); sort($smokes, SORT_STRING);
        $followup = $smokes ? end($smokes) : false;
        if ($followup && basename(dirname($followup)) < basename(dirname($latest))) { $followup = false; }
        $effective = $report['tests'];
        if ($followup) {
            $newer = json_decode(file_get_contents($followup), true, 128, JSON_THROW_ON_ERROR);
            $effective = array_replace($effective, $newer['tests']);
        }
        $rows[$id] = ['branch' => $branch, 'profile' => $profile, 'variant' => $variant, 'report' => "$id/report.json",
            'run_status' => array_count_values(array_column($effective, 'status')),
            'original_run_status' => array_count_values(array_column($report['tests'], 'status')),
            'smoke_followup' => $followup ? "$id/followup-smoke/report.json" : null, 'suite' => $suite['details'],
            'suite_termination' => $suite['termination'], 'suite_exit' => $suite['exit'],
            'recorded_status_counts' => array_count_values($recordedStatuses),
            'build_sha256' => $report['build']['binary_sha256'],
            'service' => $effective['service']['details']['measurement']['scenarios'] ?? null,
            'allocation_sweep' => $effective['allocation-sweep']['details']['measurement'] ?? null];
        if ($rows[$id]['service']) {
            foreach ($rows[$id]['service'] as &$scenario) { unset($scenario['samples']); }
            unset($scenario);
        }
        $sources[$id] = [dirname($latest), $p];
        $rows[$id]['prior_reports'] = [];
        foreach (array_merge($reports, $smokes) as $previous) {
            if ($previous === $latest || $previous === $followup) { continue; }
            $priorId = "$id/prior-runs/" . basename(dirname($previous));
            $sources[$priorId] = [dirname($previous), $p];
            $rows[$id]['prior_reports'][] = "$priorId/report.json";
        }
        if ($followup) { $sources["$id/followup-smoke"] = [dirname($followup), $p]; }
        $rows[$id]['diagnostic_followups'] = [];
        foreach (glob($p['row'] . "/$variant-tests/*-followup-*/report.json") as $diagnostic) {
            $diagnosticId = "$id/diagnostic-followups/" . basename(dirname($diagnostic));
            $sources[$diagnosticId] = [dirname($diagnostic), $p];
            $rows[$id]['diagnostic_followups'][] = "$diagnosticId/report.json";
        }
    }
}
mkdir($destination, 0755, true);
foreach ($sources as $id => [$source, $p]) {
    $failedAllocations = [];
    foreach (glob("$source/faults/faults-*/results.json") as $file) {
        foreach (json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR) as $index => $case) {
            if (!$case['pass']) { $failedAllocations[dirname($file) . '/' . sprintf('allocation-%s-%03d.log', $case['phase'], $index % 192 + 1)] = true; }
        }
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isLink() || !$file->isFile() || !in_array($file->getExtension(), ['json', 'log', 'txt', 'out', 'diff', 'md'], true)) { continue; }
        $path = $file->getPathname();
        if (preg_match('/\/allocation-(startup|body)-[0-9]+\.log$/', $path) && !isset($failedAllocations[$path])) { continue; }
        export_file($path, "$destination/$id/" . substr($path, strlen($source) + 1));
    }
    export_file($p['record'], "$destination/$id/build.json");
    foreach (glob($p['row'] . '/initial-build-failure/*.log') as $log) {
        export_file($log, "$destination/$id/initial-build-failure/" . basename($log));
    }
}
$comparisons = []; $securityRegressions = [];
foreach (['8.2', '8.3', '8.4', '8.5'] as $branch) {
    $manifest = release_manifest($branch); $securityFiles = [];
    $source = release_paths($branch, 'official')['source'];
    foreach ($manifest['upstream_security_commits'] as $commit) {
        foreach (explode("\n", trim(release_command(['git', 'diff-tree', '--no-commit-id', '--name-only', '-r', $commit], $source))) as $file) {
            if (str_ends_with($file, '.phpt') && is_file("$source/$file")) { $securityFiles[$file] = true; }
        }
    }
    ksort($securityFiles, SORT_STRING);
    foreach (['unpatched', 'official', 'candidate'] as $profile) {
        $statuses = json_decode(file_get_contents($sources["$branch/$profile-release"][0] . '/statuses.json'), true, 32, JSON_THROW_ON_ERROR);
        $selected = [];
        foreach ($securityFiles as $file => $_) { $selected[$file] = $statuses[$file] ?? 'NOT_RECORDED'; }
        $securityRegressions[$branch][$profile] = ['counts' => array_count_values($selected), 'tests' => $selected];
    }
    foreach (['release', 'sanitized'] as $variant) {
        $a = json_decode(file_get_contents($sources["$branch/official-$variant"][0] . '/statuses.json'), true, 32, JSON_THROW_ON_ERROR);
        $b = json_decode(file_get_contents($sources["$branch/candidate-$variant"][0] . '/statuses.json'), true, 32, JSON_THROW_ON_ERROR);
        $new = []; $shared = []; $unpaired = []; $resolved = [];
        foreach ($b as $file => $status) {
            if (!in_array($status, ['FAILED', 'BORKED', 'LEAKED'], true)) { continue; }
            if (($a[$file] ?? null) === $status) { $shared[$file] = $status; }
            elseif (!array_key_exists($file, $a)) { $unpaired[$file] = $status; }
            else { $new[$file] = ['official' => $a[$file], 'candidate' => $status]; }
        }
        foreach ($a as $file => $status) {
            if (in_array($status, ['FAILED', 'BORKED', 'LEAKED'], true) && isset($b[$file]) && $b[$file] !== $status) {
                $resolved[$file] = ['official' => $status, 'candidate' => $b[$file]];
            }
        }
        $comparisons["$branch/$variant"] = ['new_adverse' => $new, 'shared_adverse' => $shared,
            'official_adverse_differences' => $resolved, 'candidate_adverse_without_official_observation' => $unpaired,
            'both_suites_completed' => $rows["$branch/official-$variant"]['suite_termination'] === 'completed' &&
                $rows["$branch/candidate-$variant"]['suite_termination'] === 'completed',
            'paired_recorded_tests' => count(array_intersect_key($a, $b))];
    }
}
$summary = ['schema' => 'php-release-matrix-summary/1', 'utc' => gmdate('c'), 'rows' => $rows, 'candidate_vs_official' => $comparisons,
    'security_commit_regressions' => $securityRegressions,
    'export' => ['private_paths' => 'replaced-with-logical-placeholders', 'passing_allocation_logs' => 'omitted;all-case-results-retained',
        'prepared_sources_and_binary_hashes_rechecked' => true,
        'failed_allocation_logs' => 'retained', 'failures_and_timeouts' => 'not-removed']];
file_put_contents("$destination/summary.json", normalized(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . "\n");
$table = "# Release matrix counts\n\nThese are observed outcomes, not a universal safety claim.\n\n" .
    "| Branch/profile | Variant | Passed | Skipped | Failed | Borked | Warned | Expected fail | Termination |\n|---|---|---:|---:|---:|---:|---:|---:|---|\n";
foreach ($rows as $row) {
    $c = $row['suite']['counts'];
    $table .= "| {$row['branch']} {$row['profile']} | {$row['variant']} | " . implode(' | ', array_map(static fn($key) => (string)($c[$key] ?? 'unknown'),
        ['Tests passed', 'Tests skipped', 'Tests failed', 'Tests borked', 'Tests warned', 'Expected fail'])) . " | {$row['suite_termination']} |\n";
}
$table .= "\n[Full summary](summary.json). Each row directory retains its report, upstream statuses, and failure artifacts.\n" .
    "Timed-out rows have unknown final totals; recorded partial status counts are in the summary. Missing observations are not passes.\n";
file_put_contents("$destination/README.md", $table);
echo "Exported " . count($rows) . " complete report rows to $destination\n";
