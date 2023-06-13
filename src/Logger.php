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
            "%s: " . $message . PHP_EOL,
            ...
            [basename($caller['file'], '.php'), ...$vars]
        );
        $logger->debug($output);
    }

    public static function always(string $message, ...$vars): void
    {
        $logger = self::init();
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $output = sprintf(
            "%s: " . $message . PHP_EOL,
            ...
            [basename($caller['file'], '.php'), ...$vars]
        );
        $logger->alert($output, ['e/s' => number_format(self::$counter / self::$elapsed, 2), 'est' => number_format((self::$counter / self::$elapsed) * 3, 2)]);
    }

    public static function error(string $message, ...$vars): void
    {
        $logger = self::init();
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $output = sprintf(
            "%s: " . $message . PHP_EOL,
            ...
            [basename($caller['file'], '.php'), ...$vars]
        );
        $logger->error($output);
    }

    private static Monologger|null $logger = null;

    private static function init(): Monologger
    {
        if (self::$logger) {
            return self::$logger;
        }

        $handler = new StreamHandler(getStdout());
        //$handler = new StreamHandler(STDOUT);
        $handler->setFormatter(new ConsoleFormatter(allowInlineLineBreaks: true));
        $handler->pushProcessor(new MemoryPeakUsageProcessor(true, true));
        $handler->pushProcessor(new ProcessIdProcessor());
        $handler->setLevel(Level::Error);

        $logger = new Monologger('main');
        $logger->pushHandler($handler);


        return self::$logger = $logger;
    }

    private static int $elapsed = 1;
    private static int $counter = 0;

    public static function event(string $message, ...$vars): void
    {
        static $time = 0;
        $time = $time === 0 ? microtime(true) : $time;

        self::$elapsed = max(microtime(true) - $time, 1);

        // calculate the time since the last event
        /*if(time() - $lastTime > 4) {
            $lastTime = time();
            $elapsed = microtime(true) - $time;
            $time = microtime(true);
            $counter = 0;
        }*/

        $logger = self::init();
        $logger->info(
            sprintf($message, ...$vars),
            ['total' => ++self::$counter, 'elapsed' => self::$elapsed, 'eps' => self::$counter / self::$elapsed]
        );
    }

    public static function reset()
    {
        self::$logger = null;
    }
}
