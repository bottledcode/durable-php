<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;
use parallel\Future;

use function parallel\run;

class Pipe
{
    public readonly array $sinks;
    private Future $thread;

    public function __construct(public readonly Channel $source, Channel ...$sinks)
    {
        $this->sinks = $sinks;
        $this->thread = run(static function (Channel $source, Channel ...$sinks) {
            $counter = 0;
            while (true) {
                $message = $source->recv();
                Logger::log("Pipe received message: %d", ++$counter);
                foreach ($sinks as $sink) {
                    $sink->send($message);
                }
            }
        }, [$source, ...$sinks]);
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
