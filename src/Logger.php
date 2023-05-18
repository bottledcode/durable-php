<?php

namespace Bottledcode\DurablePhp;

class Logger
{
    public static function log(string $message, ...$vars): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        printf("%s %s: " . $message . PHP_EOL, ...[date(DATE_RFC3339), basename($caller['file'], '.php'), ...$vars]);
    }
}
