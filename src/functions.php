<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\OrchestrationInstance;

/**
 * @template T of EntityState
 * @param class-string<T> $name
 */
function EntityId(string $name, string $id): EntityId
{
    return EntityId::from($name, $id);
}

/**
 * @template T
 * @param class-string<T> $instanceId
 */
function OrchestrationInstance(string $instanceId, string $executionId): OrchestrationInstance
{
    return OrchestrationInstance::from($instanceId, $executionId);
}
