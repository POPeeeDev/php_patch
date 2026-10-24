<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
$branches = array_slice($argv, 1) ?: ['8.2', '8.3', '8.4', '8.5'];
$package = dirname(__DIR__);
$anyBuildFailed = false;
$anyTestFailed = false;
foreach ($branches as $branch) {
    release_manifest($branch);
    $rows = [['unpatched', 'release', 'all'], ['official', 'release', 'all'], ['candidate', 'release', 'all'],
        ['official', 'sanitized', 'all'], ['candidate', 'sanitized', 'all']];
    $summary = [];
    foreach ($rows as [$profile, $variant, $phase]) {
        $paths = release_paths($branch, $profile, $variant);
        if (!is_file($paths['record'])) {
            $p = proc_open([PHP_BINARY, '-n', "$package/tools/release-build.php", $branch, $profile, $variant], [STDIN, STDOUT, STDERR], $pipes, $package);
            $exit = is_resource($p) ? proc_close($p) : 126;
            if ($exit !== 0) {
                $anyBuildFailed = true;
                $summary["$profile-$variant"] = ['build_exit' => $exit, 'tests' => 'not-run'];
                release_json($paths['root'] . "/$branch/matrix.json", $summary);
                continue;
            }
        }
        $p = proc_open([PHP_BINARY, '-n', "$package/tools/release-test.php", $branch, $profile, $variant, $phase], [STDIN, STDOUT, STDERR], $pipes, $package);
        $exit = is_resource($p) ? proc_close($p) : 126;
        $anyTestFailed = $anyTestFailed || $exit !== 0;
        $summary["$profile-$variant"] = ['build_exit' => 0, 'test_exit' => $exit];
        release_json($paths['root'] . "/$branch/matrix.json", $summary);
    }
}
// Retain expected baseline failures rather than converting a completed matrix into a green result.
exit($anyBuildFailed || $anyTestFailed ? 1 : 0);
