<?php
declare(strict_types=1);
$id = $_GET['id'] ?? 'startup';
$kind = $_GET['kind'] ?? 'normal';
$audit = __DIR__ . '/requests.jsonl';
$event = static function (string $event) use ($id, $kind, $audit): void {
    file_put_contents($audit, json_encode(['id' => $id, 'kind' => $kind, 'pid' => getmypid(), 'event' => $event]) . "\n", FILE_APPEND | LOCK_EX);
};
$event('begin');
register_shutdown_function(static function () use ($event): void { $event('shutdown'); });
header('Content-Type: application/json');
if ($kind === 'soft') {
    $GLOBALS['guard_blocks'] = [];
    for ($i = 0; $i < 128; $i++) { $GLOBALS['guard_blocks'][] = str_repeat('x', 256 * 1024); }
    throw new RuntimeException('Soft OOM was not triggered');
}
if ($kind === 'hard') {
    guard_test_hard_oom();
    throw new RuntimeException('Hard OOM was not triggered');
}
if ($kind === 'fatal') { guard_intentional_undefined_function(); }
set_error_handler(static fn(): bool => true);
switch ($kind) {
    case 'gc':
        for ($i = 0; $i < 64; $i++) { $v = new stdClass(); $v->self = $v; unset($v); }
        gc_collect_cycles(); break;
    case 'session':
        session_id('guardservice'); session_start(); $_SESSION['counter'] = ($_SESSION['counter'] ?? 0) + 1;
        session_write_close(); break;
    case 'serialization':
        unserialize(serialize(['x' => range(1, 128)]), ['allowed_classes' => false]);
        unserialize('a:5:{malformed', ['allowed_classes' => false]); break;
    case 'fiber':
        $fiber = new Fiber(static function () { Fiber::suspend(1); return 2; });
        $fiber->start(); $fiber->resume(); break;
    case 'json':
        json_decode('{"malformed":'); json_encode([str_repeat('x', 4096), "\xff"]); break;
    case 'array':
        $a = range(1, 1024); $b = $a; unset($a[20]); sort($b); break;
}
echo json_encode(['ok' => true, 'pid' => getmypid(), 'id' => $id, 'kind' => $kind]);
