<?php

namespace Bottledcode\DurablePhp;

class TaskMessage
{
    public function __construct(public HistoryEvent $event, public OrchestrationInstance|null $instance, string $name)
    {
    }
}
