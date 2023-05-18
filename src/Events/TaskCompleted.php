<?php

namespace Bottledcode\DurablePhp\Events;

class TaskCompleted extends Event
{
    public function __construct(string $eventId, string $taskScheduledId, string|null $result = null)
    {
        parent::__construct($eventId);
    }
}
