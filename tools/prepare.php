<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/common.php';
$source = safety_source();
$patch = dirname(__DIR__) . '/patches/oom-lifecycle.patch';
$hashes = json_decode(file_get_contents(dirname(__DIR__) . '/patches/source-hashes.json'), true, 16, JSON_THROW_ON_ERROR);
if (hash_file('sha256', $patch) !== $hashes['patch_sha256']) { throw new RuntimeException('Patch checksum mismatch'); }
$original = $patched = 0;
foreach ($hashes['files'] as $path => $expected) {
    $actual = hash_file('sha256', $source . '/' . $path);
    $original += (int)($actual === $expected['original_sha256']);
    $patched += (int)($actual === $expected['patched_sha256']);
}
$count = count($hashes['files']);
if ($patched === $count) { echo "All $count patched source hashes already match\n"; exit(0); }
if ($original !== $count) { throw new RuntimeException('Source differs from the pinned snapshot or is partially patched'); }
foreach ([true, false] as $dry) {
    $args = ['patch', '--batch', '--forward', '-p1', '-i', $patch];
    if ($dry) { $args[] = '--dry-run'; }
    $p = proc_open($args, [STDIN, STDOUT, STDERR], $pipes, $source);
    if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException('Patch application failed'); }
}
foreach ($hashes['files'] as $path => $expected) {
    if (hash_file('sha256', $source . '/' . $path) !== $expected['patched_sha256']) {
        throw new RuntimeException('Patched source checksum mismatch: ' . $path);
    }
}
echo "Applied patch; all $count resulting source hashes match\n";
