<?php

/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\EventDescription;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\Proxy\SpyException;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\Search\EntityFilter;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\Serializer;
use Closure;
use DateTimeImmutable;
use Exception;
use Generator;
use Override;
use ReflectionFunction;

use function Bottledcode\DurablePhp\Ext\emit_event;

class LocalEntityClient implements EntityClientInterface
{
    private ?Provenance $userContext = null;

    public function __construct(
        private SpyProxy $spyProxy = new SpyProxy(),
    ) {}

    #[Override]
    public function cleanEntityStorage(): void {}

    #[Override]
    public function listEntities(EntityFilter $filter, int $page): Generator
    {
        throw new Exception('listEntities not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    #[Override]
    public function signal(EntityId|string $entityId, Closure $signal): void
    {
        $interfaceReflector = new ReflectionFunction($signal);
        $interfaceName = $interfaceReflector->getParameters()[0]->getType()?->getName();
        if (interface_exists($interfaceName) === false) {
            throw new Exception("Interface {$interfaceName} does not exist");
        }
        $spy = $this->spyProxy->define($interfaceName);
        $operationName = null;
        $arguments = null;
        try {
            $class = new $spy($operationName, $arguments);
            $signal($class);
        } catch (SpyException) {
        }

        if ($operationName === null || $arguments === null) {
            return;
        }

        $this->signalEntity(
            is_string($entityId) ? EntityId($interfaceName, $entityId) : $entityId,
            $operationName,
            $arguments,
        );
    }

    #[Override]
    public function signalEntity(
        EntityId $entityId,
        string $operationName,
        array $input = [],
        ?DateTimeImmutable $scheduledTime = null,
    ): void {
        $event = WithEntity::forInstance(
            StateId::fromEntityId($entityId),
            RaiseEvent::forSignal($operationName, SerializedArray::fromArray($input), $scheduledTime),
        );

        $eventDescription = new EventDescription($event);
        $userArray = $this->userContext ? Serializer::serialize($this->userContext) : null;

        emit_event($userArray, $eventDescription->toArray(), StateId::fromEntityId($entityId)->id);
    }

    #[Override]
    public function getEntitySnapshot(EntityId $entityId): ?EntityState
    {
        throw new Exception('getEntitySnapshot not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    #[Override]
    public function withAuth(string $token): void
    {
        throw new Exception('withAuth not implemented for LocalEntityClient - set user context directly using setUserContext');
    }

    public function setUserContext(?Provenance $userContext): void
    {
        $this->userContext = $userContext;
    }

    #[Override]
    public function deleteEntity(EntityId $entityId): void
    {
        throw new Exception('deleteEntity not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    public function shareEntityOwnership(EntityId $id, string $with): void
    {
        throw new Exception('shareEntityOwnership not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    public function grantEntityAccessToUser(EntityId $id, string $user, Operation $operation): void
    {
        throw new Exception('grantEntityAccessToUser not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    public function grantEntityAccessToRole(EntityId $id, string $role, Operation $operation): void
    {
        throw new Exception('grantEntityAccessToRole not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    public function revokeEntityAccessToUser(EntityId $id, string $user): void
    {
        throw new Exception('revokeEntityAccessToUser not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }

    public function revokeEntityAccessToRole(EntityId $id, string $role): void
    {
        throw new Exception('revokeEntityAccessToRole not supported in LocalEntityClient - use RemoteEntityClient for this operation');
    }
}
