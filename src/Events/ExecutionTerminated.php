<?php

namespace Bottledcode\DurablePhp\Events;

class ExecutionTerminated extends Event
{
    public function __construct(
        public string $eventId,
        public string $input,
    ) {
        parent::__construct($eventId);
    }
}
