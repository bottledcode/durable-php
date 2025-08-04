<?php

use function Bottledcode\DurablePhp\ext\emit_event;

echo emit_event(['userId' => 'bob', 'roles' => ['admin']], [], 'activity:bullshit');
try {
    echo emit_event([]);
} catch (Throwable $e) {
    echo "\nFailed: $e";
}
