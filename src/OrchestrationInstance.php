<?php

namespace Bottledcode\DurablePhp;

use Ramsey\Uuid\UuidInterface;

class OrchestrationInstance
{
    public function __construct(public UuidInterface $instanceId, public UuidInterface $executionId,) {}
}
