<?php
declare(strict_types=1);

function safety_policy(): array
{
    $path = __DIR__ . '/config.json';
    $limits = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $expected = ['PolicyVersion' => 2, 'RecoverableMode' => 2, 'TestWorkers' => 8,
        'CaseSeconds' => 20, 'SuiteSeconds' => 2400, 'AllocationCases' => 192,
        'AllocationPhases' => 2, 'MaxAllocationOrdinal' => 8192, 'MutationCases' => 2048,
        'ServiceRequests' => 80, 'ServiceFaultEvery' => 8];
    if ($limits !== $expected) { throw new RuntimeException('Unsupported benchmark configuration'); }
    return [$limits, ['schema' => 'php-oom-benchmark-config/1', 'sha256' => hash_file('sha256', $path), 'values' => $limits]];
}

function safety_source(): string
{
    $source = getenv('PHP_SOURCE');
    $resolved = $source === false ? false : realpath($source);
    if ($resolved === false || !is_file($resolved . '/Zend/zend_alloc.h')) {
        throw new RuntimeException('Set PHP_SOURCE to the prepared PHP source directory; see BUILD.md');
    }
    return $resolved;
}

function safety_build_root(): string
{
    return getenv('PHP_BUILD_ROOT') ?: dirname(__DIR__) . '/work/build';
}

function safety_php(string $profile): string
{
    if (!in_array($profile, ['upstream', 'strict', 'recoverable', 'sanitized-upstream', 'sanitized'], true)) {
        throw new RuntimeException('Unknown build profile');
    }
    $path = safety_build_root() . '/' . $profile . '/sapi/cli/php';
    if (!is_executable($path)) { throw new RuntimeException("Build profile '$profile' first; see BUILD.md"); }
    return $path;
}

function safety_controller(): string { return getenv('PHP_CONTROLLER') ?: PHP_BINARY; }
function safety_cc(): string { return getenv('CC') ?: 'cc'; }
function safety_shared_flags(): array
{
    return PHP_OS_FAMILY === 'Darwin' ? ['-bundle', '-undefined', 'dynamic_lookup'] : ['-shared'];
}

function safety_run(string $id, array $command, string $out, int $seconds = 20, string $input = '', string $cwd = __DIR__): array
{
    $limiter = getenv('GUARD_LIMIT_BINARY') ?: dirname(__DIR__) . '/work/limit';
    if (!is_executable($limiter)) { throw new RuntimeException('Build the supervisor first; see BUILD.md'); }
    $p = proc_open([$limiter, (string)$seconds, '33554432', ...$command],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($p)) { throw new RuntimeException("Could not start $id"); }
    if ($input !== '') { fwrite($pipes[0], $input); }
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $stdout = $stderr = ''; $exit = -1;
    do {
        $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
        $state = proc_get_status($p);
        if (!$state['running']) { $exit = $state['exitcode']; break; }
        usleep(5000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($p);
    if ($exit < 0) { $exit = $closed; }
    $metrics = [];
    if (preg_match('/\nGUARD_METRICS (\{[^\n]+\})\n/', $stderr, $m)) {
        $metrics = json_decode($m[1], true, 16, JSON_THROW_ON_ERROR);
        $stderr = str_replace($m[0], '', $stderr);
    }
    file_put_contents("$out/$id.log", $stdout . "\n--- STDERR ---\n" . $stderr . "\n--- METRICS ---\n" . json_encode($metrics) . "\n");
    return ['id' => $id, 'exit' => $exit, 'out' => $stdout, 'err' => $stderr,
        'termination' => $metrics['termination'] ?? 'missing-metrics', 'metrics' => $metrics,
        'command' => $command, 'log' => "$id.log"];
}

function safety_env(array $command, array $environment = []): array
{
    $environment += ['USE_ZEND_ALLOC' => '1', 'USE_TRACKED_ALLOC' => '0', 'ZEND_MM_MAX_ALLOCS' => '0',
        'ASAN_OPTIONS' => 'detect_leaks=0:abort_on_error=1:allocator_may_return_null=1',
        'UBSAN_OPTIONS' => 'halt_on_error=1:print_stacktrace=1'];
    $args = ['env'];
    foreach ($environment as $key => $value) { $args[] = "$key=$value"; }
    return [...$args, ...$command];
}

function safety_diagnostic(string $text): bool
{
    return (bool)preg_match('/ERROR: AddressSanitizer|SUMMARY: AddressSanitizer|UndefinedBehaviorSanitizer|runtime error:|AddressSanitizer:DEADLYSIGNAL/', $text);
}

function safety_result_status(array $run, bool $expected, int $knownFailures = 0): string
{
    if ($knownFailures > 0) { return 'fail'; }
    if ($run['termination'] !== 'completed' || in_array($run['exit'], [126, 127], true)) { return 'inconclusive'; }
    return $expected ? 'pass' : 'fail';
}
