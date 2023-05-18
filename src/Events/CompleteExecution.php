<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\OrchestrationStatus;

class CompleteExecution extends Event
{
    public function __construct(
        string $eventId,
        OrchestrationInstance $instance,
        string|null $result,
        OrchestrationStatus $status,
        \Throwable|null $previous = null
    ) {
        parent::__construct($eventId);
    }
}
