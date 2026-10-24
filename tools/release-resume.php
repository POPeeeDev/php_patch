<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
$package = dirname(__DIR__);
$failed = false;
foreach (array_slice($argv, 1) ?: ['8.2', '8.3', '8.4', '8.5'] as $branch) {
    foreach ([['unpatched', 'release'], ['official', 'release'], ['candidate', 'release'], ['official', 'sanitized'], ['candidate', 'sanitized']] as [$profile, $variant]) {
        $paths = release_paths($branch, $profile, $variant);
        $reports = glob($paths['row'] . "/$variant-tests/*-all-*/report.json"); sort($reports, SORT_STRING);
        $report = $reports ? json_decode(file_get_contents(end($reports)), true, 128, JSON_THROW_ON_ERROR) : [];
        if (isset($report['tests']['php-suite'])) {
            $build = json_decode(file_get_contents($paths['record']), true, 32, JSON_THROW_ON_ERROR);
            foreach ($build['binary_sha256'] as $binary => $hash) {
                if (hash_file('sha256', $paths['build'] . '/sapi/' . $binary) !== $hash) { throw new RuntimeException('Recorded binary changed'); }
            }
            $statuses = array_column($report['tests'], 'status');
            $failed = $failed || count(array_filter($statuses, static fn($s) => $s !== 'pass')) > 0;
            echo "$branch $profile $variant: retaining completed attempt (including failures/timeouts)\n";
            continue;
        }
        if (!is_file($paths['record'])) {
            $p = proc_open([PHP_BINARY, '-n', "$package/tools/release-build.php", $branch, $profile, $variant], [STDIN, STDOUT, STDERR], $pipes, $package);
            if (!is_resource($p) || proc_close($p) !== 0) { $failed = true; continue; }
        }
        $p = proc_open([PHP_BINARY, '-n', "$package/tools/release-test.php", $branch, $profile, $variant, 'all'], [STDIN, STDOUT, STDERR], $pipes, $package);
        if (!is_resource($p) || proc_close($p) !== 0) { $failed = true; }
    }
}
exit($failed ? 1 : 0);
