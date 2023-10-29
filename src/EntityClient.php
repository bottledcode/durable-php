<?php

/*
 * Copyright ©2023 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Abstractions\Sources\PartitionCalculator;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Ids\StateId;

class EntityClient implements EntityClientInterface
{
    use PartitionCalculator;


    public function __construct(private Config $config, private Source $source, private SpyProxy $spyProxy)
    {
    }

    public function cleanEntityStorage(): void
    {
        throw new \Exception('Not implemented');
    }

    public function listEntities(): \Generator
    {
        throw new \Exception('Not implemented');
    }

    public function getEntitySnapshot(EntityId $entityId): EntityState|null
    {
        return $this->source->get(StateId::fromEntityId($entityId), EntityHistory::class)?->getState();
    }

    public function signal(EntityId|string $entityId, \Closure $signal): void
    {
        $interfaceReflector = new \ReflectionFunction($signal);
        $interfaceName = $interfaceReflector->getParameters()[0]->getType()?->getName();
        if(interface_exists($interfaceName) === false) {
            throw new \Exception("Interface $interfaceName does not exist");
        }
        $spy = $this->spyProxy->define($interfaceName);
        $class = new $spy($operationName, $arguments);
        $signal($class);
        $this->signalEntity(
            is_string($entityId) ? new EntityId($interfaceName, $entityId) : $entityId, $operationName, $arguments
        );
    }

    public function signalEntity(
        EntityId $entityId,
        string $operationName,
        array $input = [],
        \DateTimeImmutable $scheduledTime = null
    ): void {
        $event = WithEntity::forInstance(
            StateId::fromEntityId($entityId),
            RaiseEvent::forOperation($operationName, $input)
        );
        if ($scheduledTime !== null) {
            WithDelay::forEvent($scheduledTime, $event);
        }

        $this->source->storeEvent($event, false);
    }
}
