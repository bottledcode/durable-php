<?php

namespace Bottledcode\DurablePhp;

use Carbon\Carbon;

class Logger
{
    public static function log(string $message, ...$vars): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        printf("%s %s: " . $message . PHP_EOL, ...[(Carbon::now()->format('Y-m-d\TH:i:s.vp')), basename($caller['file'], '.php'), ...$vars]);
    }
}
