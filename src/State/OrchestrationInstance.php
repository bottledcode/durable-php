<?php

namespace Bottledcode\DurablePhp\State;

readonly class OrchestrationInstance
{
    public function __construct(public string $instanceId, public string $executionId)
    {
    }
}
