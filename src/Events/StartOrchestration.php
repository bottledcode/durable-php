<?php

namespace Bottledcode\DurablePhp\Events;

class StartOrchestration extends Event
{
    public function __construct(public string $eventId)
    {
        parent::__construct($eventId);
    }
}
