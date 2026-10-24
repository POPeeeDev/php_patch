<?php
declare(strict_types=1);
$mode = $argv[1] ?? 'array';
set_error_handler(static fn(): bool => true);
ob_start();
register_shutdown_function(static function (): void { echo "GUARD_SHUTDOWN\n"; });
echo "GUARD_ENTERED\n";
fwrite(STDERR, "GUARD_BODY_ENTERED\n");
if ((int)($argv[2] ?? 0) > 0) { guard_test_arm_allocations((int)$argv[2]); }
switch ($mode) {
    case 'array':
        $items = [];
        for ($i = 0; $i < 128; $i++) { $items['key-' . $i] = [(object)['i' => $i], str_repeat('a', 33 + $i)]; }
        $copy = $items; unset($items['key-10']); sort($copy); serialize($copy);
        break;
    case 'session':
        ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
        session_id('guardfaults'); session_start();
        $_SESSION['data'] = range(1, 100); $serialized = session_encode();
        session_decode($serialized); session_decode('malformed|a:3:{'); session_abort();
        break;
    case 'callbacks':
        $cycle = new stdClass(); $cycle->self = $cycle;
        $values = array_map(static fn($x) => (object)['v' => $x], range(1, 100));
        $g = (static function () use ($values) { foreach ($values as $v) { yield $v; } })();
        foreach ($g as $v) { json_encode($v); }
        $fiber = new Fiber(static function () { Fiber::suspend('yield'); return ['done']; });
        $fiber->start(); $fiber->resume(); unset($cycle, $values, $g, $fiber); gc_collect_cycles();
        break;
    default: throw new RuntimeException('Unknown workload');
}
echo "GUARD_COMPLETE\n";
