<?php
declare(strict_types=1);

function release_manifest(string $branch): array {
    if (!in_array($branch, ['8.2', '8.3', '8.4', '8.5'], true)) { throw new RuntimeException('Branch must be 8.2, 8.3, 8.4, or 8.5'); }
    return json_decode(file_get_contents(dirname(__DIR__) . "/releases/$branch/manifest.json"), true, 32, JSON_THROW_ON_ERROR);
}
function release_paths(string $branch, string $profile, string $variant = 'release'): array {
    release_manifest($branch);
    if (!in_array($profile, ['unpatched', 'official', 'candidate'], true) || !in_array($variant, ['release', 'sanitized'], true)) {
        throw new RuntimeException('Unknown profile or variant');
    }
    $root = getenv('RELEASE_WORK_ROOT') ?: dirname(__DIR__) . '/work/releases';
    if (!str_starts_with($root, '/')) { throw new RuntimeException('RELEASE_WORK_ROOT must be absolute'); }
    $row = "$root/$branch/$profile";
    return ['root' => $root, 'row' => $row, 'source' => "$row/source", 'build' => "$row/$variant-build",
        'logs' => "$row/$variant-logs", 'receipt' => "$row/prepared.json", 'record' => "$row/$variant-build.json"];
}
function release_jobs(): int {
    $value = getenv('RELEASE_JOBS') ?: '4';
    if (!preg_match('/^[1-9][0-9]?$/D', $value) || (int)$value > 32) { throw new RuntimeException('RELEASE_JOBS must be 1..32'); }
    return (int)$value;
}
function release_command(array $args, string $cwd, ?string $log = null): string {
    $descriptor = $log === null ? ['pipe', 'w'] : ['file', $log, 'w'];
    $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => $descriptor,
        2 => $log === null ? STDERR : ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($p)) { throw new RuntimeException('Cannot launch command'); }
    $out = $log === null ? stream_get_contents($pipes[1]) : '';
    if ($log === null) { fclose($pipes[1]); }
    $exit = proc_close($p);
    if ($exit !== 0) { throw new RuntimeException("Command exited $exit: " . implode(' ', $args) . ($log ? "; log: $log" : '')); }
    return $out;
}
function release_json(string $path, array $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
