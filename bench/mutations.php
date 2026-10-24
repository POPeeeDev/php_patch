<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
[$limits] = safety_policy();
$path = $argv[1];
set_error_handler(static fn(): bool => true);
ini_set('session.save_path', $path); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
session_id('guardmutations'); session_start();
$seed = 104729; mt_srand($seed);
$value = ['a' => range(0, 31), 'b' => ['k' => str_repeat('a', 127), 'q' => true, 'z' => null]];
$json = json_encode($value, JSON_THROW_ON_ERROR); $serialized = serialize($value);
$session = 'data|' . $serialized;
$families = ['json' => $json, 'serialization' => $serialized, 'session' => $session];
$counts = array_fill_keys(array_keys($families), 0);
for ($i = 0; $i < $limits['MutationCases']; $i++) {
    foreach ($families as $kind => $original) {
        $data = $original; $offset = mt_rand(0, strlen($data) - 1);
        switch ($i % 4) {
            case 0: $data = substr($data, 0, $offset); break;
            case 1: $data[$offset] = chr(mt_rand(0, 255)); break;
            case 2: $data = substr_replace($data, str_repeat(chr(mt_rand(0, 255)), 1 + ($i % 17)), $offset, 0); break;
            case 3: $data = substr_replace($data, '', $offset, 1 + ($i % 7)); break;
        }
        if ($kind === 'json') { json_decode($data, true, 64); }
        elseif ($kind === 'serialization') { unserialize($data, ['allowed_classes' => false, 'max_depth' => 64]); }
        else {
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
            session_decode($data);
        }
        $counts[$kind]++;
    }
    if (unserialize(serialize($value), ['allowed_classes' => false]) !== $value || json_decode($json, true, 64, JSON_THROW_ON_ERROR) !== $value) {
        throw new RuntimeException('Valid round-trip changed after malformed input');
    }
    $cycle = new stdClass(); $cycle->self = $cycle; unset($cycle);
    if ($i % 32 === 0) { gc_collect_cycles(); }
}
if (session_status() === PHP_SESSION_ACTIVE) { session_abort(); }
gc_collect_cycles();
echo json_encode(['seed' => $seed, 'mutations' => $counts, 'valid_roundtrips' => 2 * $limits['MutationCases'],
    'status' => 'completed', 'scope' => 'deterministic-local-input-mutations-not-exhaustive-fuzzing'], JSON_THROW_ON_ERROR), "\n";
