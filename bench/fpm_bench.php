<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
$source = safety_source();
require_once $source . '/sapi/fpm/tests/fcgi.inc';
[$script, $profile, $root, $maxRequests, $extension] = $argv;
[$limits] = safety_policy();
$fpm = dirname(safety_php($profile), 2) . '/fpm/php-fpm';
if (file_exists($root)) { throw new RuntimeException('FPM measurements require a fresh directory'); }
mkdir($root, 0700, true); mkdir($root . '/sessions', 0700);
copy(__DIR__ . '/request.php', $root . '/request.php');
$socketDir = sys_get_temp_dir() . '/guard-fpm-' . bin2hex(random_bytes(6));
mkdir($socketDir, 0700); $socket = $socketDir . '/fpm.sock';
$config = "$root/fpm.conf";
file_put_contents($config, "[global]\nerror_log = $root/fpm.log\npid = $root/fpm.pid\ndaemonize = no\n" .
    "[guard]\nlisten = $socket\nlisten.mode = 0600\npm = static\npm.max_children = 1\n" .
    "pm.max_requests = $maxRequests\ncatch_workers_output = yes\nclear_env = no\n" .
    "security.limit_extensions = .php\n");
$p = proc_open([$fpm, '-n', '-F', '-O', '-y', $config, '-d', 'display_errors=0', '-d', 'log_errors=1',
    '-d', 'extension=' . $extension,
    '-d', 'error_log=' . $root . '/php-errors.log', '-d', 'memory_limit=16M',
    '-d', 'memory_hard_limit_percent=200', '-d', 'session.save_path=' . $root . '/sessions'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$root/master.log", 'a'], 2 => ['file', "$root/master.log", 'a']], $pipes);
if (!is_resource($p)) { throw new RuntimeException('Cannot start local FPM'); }

function fpm_request(string $socket, string $root, string $id, string $kind): array
{
    $start = hrtime(true);
    $client = new FPM\FastCGI\Client('unix://' . $socket, -1, new FPM\FastCGI\StreamTransport());
    $client->setConnectTimeout(1000); $client->setReadWriteTimeout(2000);
    $query = http_build_query(['id' => $id, 'kind' => $kind]);
    try {
        $reply = @$client->request_data(['GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_SOFTWARE' => 'GUARD-local-benchmark',
            'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => "$root/request.php", 'SCRIPT_NAME' => '/request.php',
            'QUERY_STRING' => $query, 'REQUEST_URI' => '/request.php?' . $query, 'DOCUMENT_ROOT' => $root,
            'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1', 'REMOTE_PORT' => '10000', 'CONTENT_LENGTH' => '0'], '', 65536);
        $raw = $reply['out_response'] ?? '';
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $status = preg_match('/^Status:\s*(\d+)/mi', $parts[0] ?? '', $m) ? (int)$m[1] : ($raw !== '' ? 200 : 0);
        $body = json_decode($parts[1] ?? '', true);
        return ['id' => $id, 'kind' => $kind, 'status' => $status,
            'ok' => is_array($body) && ($body['ok'] ?? false) === true && ($body['id'] ?? null) === $id,
            'pid' => $body['pid'] ?? null, 'ms' => (hrtime(true) - $start) / 1e6, 'error' => null];
    } catch (Throwable $e) {
        return ['id' => $id, 'kind' => $kind, 'status' => 0, 'ok' => false,
            'pid' => null, 'ms' => (hrtime(true) - $start) / 1e6, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}
function percentiles(array $values): array
{
    if ($values === []) { return ['p50' => null, 'p95' => null, 'max' => null]; }
    sort($values, SORT_NUMERIC); $n = count($values);
    return ['p50' => $values[(int)ceil(0.5 * $n) - 1], 'p95' => $values[(int)ceil(0.95 * $n) - 1], 'max' => max($values)];
}
$result = ['profile' => $profile, 'max_requests' => (int)$maxRequests, 'workers' => 1,
    'transport' => 'private-unix-socket-fastcgi', 'scenarios' => []];
try {
    $deadline = hrtime(true) + 5000000000;
    do {
        usleep(20000);
        $ready = is_file($socket) || file_exists($socket);
        $state = proc_get_status($p);
    } while (!$ready && $state['running'] && hrtime(true) < $deadline);
    if (!$ready || !$state['running']) { throw new RuntimeException('Local FPM did not become ready; inspect master.log'); }
    for ($i = 0; $i < 5; $i++) {
        if (!fpm_request($socket, $root, "warmup-$i", 'normal')['ok']) { throw new RuntimeException('FPM warmup failed'); }
    }
    $normalKinds = ['normal', 'gc', 'session', 'serialization', 'fiber', 'json', 'array'];
    foreach (['healthy', 'soft', 'fatal', 'hard'] as $scenario) {
        $requests = []; $start = hrtime(true);
        for ($i = 1; $i <= $limits['ServiceRequests']; $i++) {
            $kind = $scenario !== 'healthy' && $i % $limits['ServiceFaultEvery'] === 0 ? $scenario : $normalKinds[$i % count($normalKinds)];
            $requests[] = fpm_request($socket, $root, "$scenario-$i", $kind);
        }
        // The final probe explicitly checks recovery after a fault at the end of a batch.
        $requests[] = fpm_request($socket, $root, "$scenario-recovery", 'normal');
        $elapsed = (hrtime(true) - $start) / 1e6;
        $events = [];
        foreach (file("$root/requests.jsonl", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $event = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            $events[$event['id']][$event['event']] = $event;
        }
        $normalFailures = $faults = $faultUnexpectedSuccess = $shutdowns = $workerChanges = 0;
        $normalTimes = $recoveryTimes = $pids = []; $previous = null; $lastFault = false;
        foreach ($requests as &$r) {
            $r['pid'] ??= $events[$r['id']]['begin']['pid'] ?? null;
            $r['shutdown'] = isset($events[$r['id']]['shutdown']);
            $fault = in_array($r['kind'], ['soft', 'hard', 'fatal'], true);
            if ($fault) { $faults++; $faultUnexpectedSuccess += (int)$r['ok']; }
            else { $normalFailures += (int)!$r['ok']; $normalTimes[] = $r['ms']; }
            if ($lastFault && !$fault) { $recoveryTimes[] = $r['ms']; }
            if ($r['shutdown']) { $shutdowns++; }
            if ($r['pid'] !== null) {
                $pids[$r['pid']] = true;
                if ($previous !== null && $previous !== $r['pid']) { $workerChanges++; }
                $previous = $r['pid'];
            }
            $lastFault = $fault;
        }
        unset($r);
        $result['scenarios'][$scenario] = ['requests' => count($requests), 'fault_requests' => $faults,
            'normal_failures' => $normalFailures, 'unexpected_fault_successes' => $faultUnexpectedSuccess,
            'shutdown_callbacks' => $shutdowns, 'unique_workers' => count($pids), 'worker_changes' => $workerChanges,
            'elapsed_ms' => $elapsed, 'normal_latency_ms' => percentiles($normalTimes),
            'next_normal_after_fault_ms' => percentiles($recoveryTimes), 'samples' => $requests];
        if (!proc_get_status($p)['running']) { throw new RuntimeException('FPM master exited during benchmark'); }
    }
} finally {
    proc_terminate($p, 15);
    $deadline = hrtime(true) + 2000000000;
    do { usleep(10000); $state = proc_get_status($p); } while ($state['running'] && hrtime(true) < $deadline);
    if ($state['running']) { proc_terminate($p, 9); }
    proc_close($p);
    if (file_exists($socket)) { unlink($socket); }
    if (is_dir($socketDir)) { rmdir($socketDir); }
}
file_put_contents("$root/result.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
