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
use Bottledcode\DurablePhp\Events\RevokeRole;
use Bottledcode\DurablePhp\Events\RevokeUser;
use Bottledcode\DurablePhp\Events\ShareOwnership;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\Events\ShareWithRole;
use Bottledcode\DurablePhp\Events\ShareWithUser;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Ext\Worker;
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

class LocalEntityClient implements EntityClientInterface
{
    private ?Provenance $userContext = null;

    public function __construct(
        private SpyProxy $spyProxy,
        private Worker $worker,
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
        $interfaceName = $interfaceReflector->getParameters()[0]?->getType()?->getName();
        if ($interfaceName === null || interface_exists($interfaceName) === false) {
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
            RaiseEvent::forOperation($operationName, $input),
        );

        if ($scheduledTime) {
            $event = WithDelay::forEvent($scheduledTime, $event);
        }

        $this->worker->emitEvent(new EventDescription($event)->toArray());
    }

    #[Override]
    public function getEntitySnapshot(EntityId $entityId): ?EntityState
    {
        $state = $this->worker->queryState(StateId::fromEntityId($entityId));
        if (empty($state)) {
            return null;
        }

        return Serializer::deserialize($state, EntityState::class);
    }

    #[Override]
    public function withAuth(Provenance|string|null $token): void
    {
        $this->worker->setUser($token instanceof Provenance ? Serializer::serialize($token) : null);
    }

    #[Override]
    public function deleteEntity(EntityId $entityId): void
    {
        $this->worker->emitEvent(
            new EventDescription(
                WithEntity::forInstance(
                    StateId::fromEntityId($entityId),
                    RaiseEvent::forOperation('delete', []),
                ),
            )->toArray(),
        );
    }

    public function shareEntityOwnership(EntityId $id, string $with): void
    {
        $this->worker->emitEvent(
            new EventDescription(
                WithEntity::forInstance(
                    StateId::fromEntityId($id),
                    ShareOwnership::withUser($with),
                ),
            )->toArray(),
        );
    }

    public function grantEntityAccessToUser(EntityId $id, string $user, Operation $operation): void
    {
        $this->worker->emitEvent(
            new EventDescription(
                WithEntity::forInstance(
                    StateId::fromEntityId($id),
                    ShareWithUser::For($user, $operation),
                ),
            )->toArray(),
        );
    }

    public function grantEntityAccessToRole(EntityId $id, string $role, Operation $operation): void
    {
        $this->worker->emitEvent(
            new EventDescription(
                WithEntity::forInstance(
                    StateId::fromEntityId($id),
                    ShareWithRole::For($role, $operation),
                ),
            )->toArray(),
        );
    }

    public function revokeEntityAccessToUser(EntityId $id, string $user): void
    {
        $this->worker->emitEvent(
            new EventDescription(
                WithEntity::forInstance(
                    StateId::fromEntityId($id),
                    RevokeUser::completely($user),
                ),
            )->toArray(),
        );
    }

    public function revokeEntityAccessToRole(EntityId $id, string $role): void
    {
        $this->worker->emitEvent(
            new EventDescription(
                WithEntity::forInstance(
                    StateId::fromEntityId($id),
                    RevokeRole::completely($role),
                ),
            )->toArray(),
        );
    }
}
