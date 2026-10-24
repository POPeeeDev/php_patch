<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$manifest = $root . '/SHA256SUMS';
if (!is_file($manifest)) { throw new RuntimeException('SHA256SUMS is missing'); }
$count = 0;
foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (!preg_match('/^([a-f0-9]{64})  ([^\r\n]+)$/', $line, $m)) { throw new RuntimeException('Malformed checksum record'); }
    $relative = $m[2];
    if (str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
        throw new RuntimeException('Invalid manifest path');
    }
    if (!is_file($root . '/' . $relative) || hash_file('sha256', $root . '/' . $relative) !== $m[1]) {
        throw new RuntimeException('Checksum mismatch: ' . $relative);
    }
    $count++;
}
echo "Verified $count package files\n";
