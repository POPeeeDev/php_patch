<?php
declare(strict_types=1);
register_shutdown_function(static function(): void { echo "GUARD_SHUTDOWN_RAN\n"; });
echo "GUARD_STARTED\n";
$blocks = [];
while (true) { $blocks[] = str_repeat('x', 1048576); }
