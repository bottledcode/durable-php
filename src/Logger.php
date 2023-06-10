<?php

/*
 * Copyright ©2023 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monologger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;

use function Amp\ByteStream\getStdout;

class Logger
{
    public static function log(string $message, ...$vars): void
    {
        $logger = self::init();
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $output = sprintf(
            "%s: " . $message . PHP_EOL, ...
            [basename($caller['file'], '.php'), ...$vars]
        );
        $logger->debug($output);
    }

    public static function always(string $message, ...$vars): void {
        $logger = self::init();
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $output = sprintf(
            "%s: " . $message . PHP_EOL, ...
            [basename($caller['file'], '.php'), ...$vars]
        );
        $logger->alert($output);
    }

    public static function error(string $message, ...$vars): void {
        $logger = self::init();
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $output = sprintf(
            "%s: " . $message . PHP_EOL, ...
            [basename($caller['file'], '.php'), ...$vars]
        );
        $logger->error($output);
    }

    private static function init(): Monologger
    {
        static $logger = false;

        if ($logger) {
            return $logger;
        }

        $handler = new StreamHandler(getStdout());
        $handler->setFormatter(new ConsoleFormatter(allowInlineLineBreaks: true));
        $handler->pushProcessor(new MemoryPeakUsageProcessor(true, true));
        $handler->pushProcessor(new ProcessIdProcessor());
        $handler->setLevel(Level::Info);

        $logger = new Monologger('main');
        $logger->pushHandler($handler);


        return $logger;
    }

    public static function event(string $message, ...$vars): void
    {
        static $counter = 0;
        static $time = 0;
        static $lastTime = 0;
        $time = $time === 0 ? microtime(true) : $time;

        $elapsed = max(microtime(true) - $time, 1);

        // calculate the time since the last event
        /*if(time() - $lastTime > 4) {
            $lastTime = time();
            $elapsed = microtime(true) - $time;
            $time = microtime(true);
            $counter = 0;
        }*/

        $logger = self::init();
        $logger->info(sprintf($message, $vars), ['total' => ++$counter, 'elapsed' => $elapsed, 'eps' => $counter / $elapsed]);
    }
}
