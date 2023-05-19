<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\OrchestrationStatus;

class CompleteExecution extends Event implements HasInstanceInterface
{
    public function __construct(
        string $eventId,
        public OrchestrationInstance $instance,
        public string|null $result,
        public OrchestrationStatus $status,
        public \Throwable|null $previous = null
    ) {
        parent::__construct($eventId);
    }

    public function getInstance(): OrchestrationInstance
    {
        return $this->instance;
    }
}
