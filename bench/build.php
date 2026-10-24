<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
$source = safety_source();
[$limits, $receipt] = safety_policy();
$hashes = json_decode(file_get_contents(dirname(__DIR__) . '/patches/source-hashes.json'), true, 16, JSON_THROW_ON_ERROR);
foreach ($hashes['files'] as $path => $expected) {
    if (hash_file('sha256', $source . '/' . $path) !== $expected['patched_sha256']) {
        throw new RuntimeException('Prepare the pinned source with tools/prepare.php before building');
    }
}
$base = safety_build_root();
if (!str_starts_with($base, '/')) { throw new RuntimeException('PHP_BUILD_ROOT must be absolute'); }
$work = dirname(__DIR__) . '/work';
if (!is_dir($work)) { mkdir($work, 0755, true); }
$p = proc_open([safety_cc(), '-O2', '-Wall', '-Wextra', '-D_DEFAULT_SOURCE', __DIR__ . '/limit.c', '-o', $work . '/limit'],
    [STDIN, STDOUT, STDERR], $pipes);
if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException('Supervisor compilation failed'); }
$profiles = ['upstream' => [0, false], 'strict' => [1, false], 'recoverable' => [2, false],
    'sanitized' => [2, true], 'sanitized-upstream' => [0, true]];
$arguments = array_slice($argv, 1);
$incremental = in_array('--incremental', $arguments, true);
$selected = array_values(array_filter($arguments, static fn($arg) => $arg !== '--incremental')) ?: array_keys($profiles);
$recordDir = $work . '/build-records';
if (!is_dir($recordDir)) { mkdir($recordDir, 0755, true); }
foreach ($selected as $profile) {
    if (!isset($profiles[$profile])) { throw new RuntimeException('Unknown build profile'); }
    [$mode, $sanitized] = $profiles[$profile];
    $build = $base . '/' . $profile;
    if (!is_dir($build)) { mkdir($build, 0755, true); }
    if (!is_file($source . '/configure')) { throw new RuntimeException('Generate configure with PHP buildconf first; see BUILD.md'); }
    $options = ['--disable-all', '--enable-cli', '--enable-fpm', '--disable-cgi', '--disable-phpdbg', '--without-pear',
        '--enable-session', '--enable-filter', '--enable-tokenizer', '--enable-ctype', '--enable-fileinfo', '--enable-posix',
        '--enable-sockets', '--enable-pcntl', '--enable-shmop', '--enable-sysvmsg', '--enable-sysvsem', '--enable-sysvshm'];
    if ($sanitized) { array_push($options, '--enable-debug', '--enable-address-sanitizer', '--enable-undefined-sanitizer'); }
    $commands = [
        'configure' => ['env', 'CPPFLAGS=-DZEND_OOM_GUARD_MODE=' . $mode, 'CFLAGS=' . ($sanitized ? '-O1 -g' : '-O2 -g'),
            $source . '/configure', ...$options],
        'make' => [getenv('MAKE') ?: 'make', '-j' . $limits['TestWorkers']],
    ];
    if ($incremental) {
        if (!is_file($build . '/Makefile') || !str_contains(file_get_contents($build . '/config.nice'), '-DZEND_OOM_GUARD_MODE=' . $mode)) {
            throw new RuntimeException('Incremental build requires a matching configured profile');
        }
        unset($commands['configure']);
    }
    foreach ($commands as $step => $command) {
        echo "$profile: $step\n";
        $log = $recordDir . '/' . $profile . '-' . $step . '.log';
        $p = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $build);
        if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException("$profile $step failed; inspect work/build-records"); }
    }
    $record = ['profile' => $profile, 'mode' => $mode, 'sanitized' => $sanitized,
        'php_sha256' => hash_file('sha256', "$build/sapi/cli/php"), 'fpm_sha256' => hash_file('sha256', "$build/sapi/fpm/php-fpm"),
        'source_revision' => $hashes['revision'], 'source_hashes' => $hashes['files'], 'configuration' => $options,
        'config_receipt' => $receipt, 'incremental' => $incremental, 'utc' => gmdate('c')];
    file_put_contents($recordDir . '/' . $profile . '-build.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "$profile: built CLI and FPM\n";
}
