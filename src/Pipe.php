<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;
use parallel\Future;

use function parallel\run;

class Pipe
{
    public readonly array $sinks;
    private Future $thread;

    public function __construct(string $name, public readonly Channel $source, Channel ...$sinks)
    {
        $this->sinks = $sinks;
        $this->thread = run(static function (string $name, Channel $source, Channel ...$sinks) {
            $counter = 0;
            while (true) {
                $message = $source->recv();
                Logger::log("%s received message: %d", $name, ++$counter);
                foreach ($sinks as $sink) {
                    $sink->send($message);
                }
            }
        }, [$name, $source, ...$sinks]);
    }

    public function __destruct()
    {
        $this->cancel();
    }

    public function cancel(): void
    {
        if ($this->isRunning()) {
            $this->thread->cancel();
        }
    }

    public function isRunning(): bool
    {
        return !($this->thread->cancelled() || $this->thread->done());
    }
}
