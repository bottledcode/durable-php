<?php
/*
 * Copyright ©2024 Robert Landers
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

use Ahc\Cli\Output\Color;
use Ahc\Cli\Output\Writer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Formatter\FormatterInterface;
use Monolog\Level;
use Monolog\Logger as Monologger;
use Monolog\LogRecord;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use function Amp\ByteStream\getOutputBufferStream;

class DurableLogger implements LoggerInterface
{
    private ?Writer $writer = null;

    private LogLevel $level;

    public function __construct(private ?LoggerInterface $logger = null, private string|null $name = null, Level|null $level = null)
    {
        if ($logger === null) {
            $handler = new StreamHandler(getOutputBufferStream());
            //$handler = new StreamHandler(STDOUT);
            //$handler->setFormatter(new ConsoleFormatter(allowInlineLineBreaks: true));
            $handler->setFormatter(
                new class (new ConsoleFormatter(
                    format: $this->name ? "%channel%.%level_name%: %message% %context% %extra%\n" : "[%datetime%] %level_name%: %message% %context% %extra%\n",
                    allowInlineLineBreaks: true,
                    ignoreEmptyContextAndExtra: true
                ), $this->name) implements FormatterInterface {
                    private Color $colorize;

                    public function __construct(private FormatterInterface $innerFormat, private string|null $name = null)
                    {
                        $this->colorize = new Color();
                    }

                    #[\Override]
                    public function format(LogRecord $record): string
                    {
                        $color = match ($record->level) {
                            Level::Debug, Level::Notice => fn(string $text) => $this->colorize->comment($text),
                            Level::Info => fn(string $text) => $this->colorize->info($text),
                            Level::Warning, Level::Alert => fn(string $text) => $this->colorize->warn($text),
                            Level::Critical, Level::Error, Level::Emergency => fn(
                                string $text
                            ) => $this->colorize->error($text),
                        };

                        return $color($this->innerFormat->format($record->with(channel: $this->name))) . "\033[0m";
                    }

                    #[\Override]
                    public function formatBatch(array $records): string
                    {
                        return implode("", array_map($this->format(...), $records));
                    }
                }
            );
            $handler->pushProcessor(new MemoryPeakUsageProcessor(true, true));
            $handler->pushProcessor(new ProcessIdProcessor());
            $handler->setLevel(getenv('LOG_LEVEL') ?: ($level ?? Level::Warning));

            $logger = new Monologger('main');
            $logger->pushHandler($handler);

            $this->logger = $logger;
        }
    }

    #[\Override]
    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    #[\Override]
    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    #[\Override]
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->logger->emergency($message, $context);
    }

    #[\Override]
    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->logger->alert($message, $context);
    }

    #[\Override]
    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    #[\Override]
    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    #[\Override]
    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->logger->notice($message, $context);
    }

    #[\Override]
    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    #[\Override]
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
