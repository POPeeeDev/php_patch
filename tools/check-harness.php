<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bench/common.php';
$cases = [
    ['completed', 0, true, 0, 'pass'],
    ['completed', 134, false, 0, 'fail'],
    ['timeout', 124, false, 0, 'inconclusive'],
    ['completed', 126, false, 0, 'inconclusive'],
    ['completed', 127, false, 0, 'inconclusive'],
    ['some-timeouts-or-launch-failures', 0, false, 0, 'inconclusive'],
    ['some-timeouts-or-launch-failures', 0, false, 1, 'fail'],
    ['completed', 0, false, 1, 'fail'],
];
foreach ($cases as [$termination, $exit, $expected, $knownFailures, $want]) {
    if (safety_result_status(compact('termination', 'exit'), $expected, $knownFailures) !== $want) {
        throw new RuntimeException('Result classification mismatch');
    }
}
echo 'Passed ' . count($cases) . " result-classification cases\n";
