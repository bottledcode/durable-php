<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\OrchestrationInstance;

class AwaitResult extends Event implements HasInstanceInterface
{
    public function __construct(string $eventId, public OrchestrationInstance $instance, public string $origin)
    {
        parent::__construct($eventId);
    }

    public function getInstance(): OrchestrationInstance
    {
        return $this->instance;
    }
}
