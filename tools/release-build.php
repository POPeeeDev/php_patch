<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/releases.php';
$branch = $argv[1] ?? '';
$profile = $argv[2] ?? '';
$variant = $argv[3] ?? 'release';
$m = release_manifest($branch);
$p = release_paths($branch, $profile, $variant);
$package = dirname(__DIR__);
$mirror = realpath(getenv('PHP_GIT') ?: '') ?: throw new RuntimeException('Set PHP_GIT to an upstream Git checkout');
if (!is_dir($p['logs'])) { mkdir($p['logs'], 0755, true); }
if (!is_file($p['receipt'])) {
    if (file_exists($p['source'])) { throw new RuntimeException('Unrecorded source exists; use a fresh RELEASE_WORK_ROOT'); }
    $revision = $profile === 'official' ? $m['official_revision'] : $m['affected_revision'];
    release_command(['git', 'clone', '--shared', '--no-checkout', '--quiet', $mirror, $p['source']], $package);
    release_command(['git', '-c', 'core.autocrlf=false', 'checkout', '--detach', $revision], $p['source']);
    if ($profile === 'candidate') {
        foreach (['upstream-release.patch' => 'upstream_patch_sha256', 'lifecycle-guard.patch' => 'guard_patch_sha256'] as $name => $key) {
            $patch = "$package/releases/$branch/$name";
            if (hash_file('sha256', $patch) !== $m[$key]) { throw new RuntimeException('Patch checksum mismatch'); }
            release_command(['git', 'apply', '--check', $patch], $p['source']);
            release_command(['git', 'apply', $patch], $p['source']);
            release_command(['git', 'add', '-A'], $p['source']);
            if ($name === 'upstream-release.patch' && trim(release_command(['git', 'write-tree'], $p['source'])) !== $m['official_tree']) {
                throw new RuntimeException('Upstream update did not reproduce the complete official release tree');
            }
        }
        foreach ($m['guard_files'] as $file => $hashes) {
            if (hash_file('sha256', $p['source'] . '/' . $file) !== $hashes['candidate_sha256']) { throw new RuntimeException('Guard source hash mismatch'); }
        }
    }
    release_json($p['receipt'], ['branch' => $branch, 'profile' => $profile, 'manifest' => $m,
        'starting_revision' => $revision, 'prepared_tree' => trim(release_command(['git', 'write-tree'], $p['source']))]);
}
$receipt = json_decode(file_get_contents($p['receipt']), true, 32, JSON_THROW_ON_ERROR);
if ($receipt['manifest'] !== $m) { throw new RuntimeException('Manifest changed: use a fresh RELEASE_WORK_ROOT'); }
if (trim(release_command(['git', 'write-tree'], $p['source'])) !== $receipt['prepared_tree']) {
    throw new RuntimeException('Prepared source index changed');
}
release_command(['git', 'diff', '--exit-code', '--quiet'], $p['source']);
if (in_array('--prepare-only', array_slice($argv, 4), true)) {
    echo "$branch $profile: source prepared and checked\n";
    exit(0);
}
if (is_file($p['record'])) { throw new RuntimeException('Completed build exists: preserve it and use another RELEASE_WORK_ROOT'); }
if (!is_dir($p['build'])) { mkdir($p['build'], 0755, true); }
// Patch order must not make vendored generated files older than their inputs.
// Equal tracked-file timestamps preserve the verified release contents.
$sourceTime = time() - 2;
foreach (explode("\0", release_command(['git', 'ls-files', '-z'], $p['source'])) as $file) {
    if ($file !== '' && !is_link($p['source'] . '/' . $file) && !touch($p['source'] . '/' . $file, $sourceTime)) {
        throw new RuntimeException('Cannot normalize source timestamp: ' . $file);
    }
}
echo "$branch $profile $variant: buildconf\n";
release_command([$p['source'] . '/buildconf', '--force'], $p['source'], $p['logs'] . '/buildconf.log');
$options = ['--disable-all', '--enable-cli', '--enable-fpm', '--enable-cgi', '--disable-phpdbg', '--without-pear',
    '--enable-session', '--enable-filter', '--enable-tokenizer', '--enable-ctype', '--enable-fileinfo', '--enable-posix',
    '--enable-sockets', '--enable-pcntl', '--enable-shmop', '--enable-sysvmsg', '--enable-sysvsem', '--enable-sysvshm', '--enable-zend-test'];
if ($variant === 'sanitized') { array_push($options, '--enable-debug', '--enable-address-sanitizer', '--enable-undefined-sanitizer'); }
echo "$branch $profile $variant: configure\n";
release_command(['env', 'CPPFLAGS=', 'CFLAGS=' . ($variant === 'sanitized' ? '-O1 -g' : '-O2 -g'),
    $p['source'] . '/configure', ...$options], $p['build'], $p['logs'] . '/configure.log');
echo "$branch $profile $variant: make\n";
release_command([getenv('MAKE') ?: 'make', '-j' . release_jobs()], $p['build'], $p['logs'] . '/make.log');
release_command(['git', 'diff', '--exit-code', '--quiet'], $p['source']);
$binaries = [];
foreach (['cli/php', 'fpm/php-fpm', 'cgi/php-cgi'] as $binary) {
    $file = $p['build'] . '/sapi/' . $binary;
    if (!is_executable($file)) { throw new RuntimeException('Missing built executable'); }
    $binaries[$binary] = hash_file('sha256', $file);
}
release_json($p['record'], ['schema' => 'php-release-build/1', 'branch' => $branch, 'profile' => $profile,
    'variant' => $variant, 'source' => $receipt, 'configure' => $options, 'jobs' => release_jobs(),
    'binary_sha256' => $binaries, 'platform' => php_uname('s') . '-' . php_uname('m'), 'utc' => gmdate('c')]);
echo "$branch $profile $variant: built CLI, CGI, FPM\n";
