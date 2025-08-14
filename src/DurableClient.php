<?php

/*
 * Copyright ©2024 Robert Landers
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

use Amp\Http\Client\HttpClientBuilder;
use Bottledcode\DurablePhp\Events\Shares\Operation;
use Bottledcode\DurablePhp\Ext\Worker;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\Search\EntityFilter;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Status;
use Closure;
use DateTimeImmutable;
use Generator;
use Override;

final readonly class DurableClient implements DurableClientInterface
{
    public function __construct(
        private EntityClientInterface $entityClient,
        private OrchestrationClientInterface $orchestrationClient,
    ) {}

    public static function local(?Provenance $userContext = null): self
    {
        $worker = new Worker();
        $entityClient = new LocalEntityClient(new SpyProxy(), $worker);
        $orchestrationClient = new LocalOrchestrationClient(new SpyProxy(), $worker);
        $entityClient->withAuth($userContext);
        $orchestrationClient->withAuth($userContext);

        return new self($entityClient, $orchestrationClient);
    }

    #[Override]
    public function withAuth(Provenance|string|null $token): void
    {
        $this->orchestrationClient->withAuth($token);
        $this->entityClient->withAuth($token);
    }

    public static function remote(string $apiHost = 'http://localhost:8080'): self
    {
        $builder = new HttpClientBuilder();
        $builder->retry(3);

        $httpClient = $builder->build();

        return new self(new RemoteEntityClient($apiHost, $httpClient), new RemoteOrchestrationClient($apiHost, $httpClient));
    }

    public function cleanEntityStorage(): void
    {
        $this->entityClient->cleanEntityStorage();
    }

    public function listEntities(EntityFilter $filter, int $page): Generator
    {
        yield from $this->entityClient->listEntities($filter, $page);
    }

    public function signalEntity(
        EntityId $entityId,
        string $operationName,
        array $input = [],
        ?DateTimeImmutable $scheduledTime = null,
    ): void {
        $this->entityClient->signalEntity($entityId, $operationName, $input, $scheduledTime);
    }

    public function getStatus(OrchestrationInstance $instance): Status
    {
        return $this->orchestrationClient->getStatus($instance);
    }

    public function listInstances(): Generator
    {
        yield from $this->orchestrationClient->listInstances();
    }

    public function purge(OrchestrationInstance $instance): void
    {
        $this->orchestrationClient->purge($instance);
    }

    public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
    {
        $this->orchestrationClient->raiseEvent($instance, $eventName, $eventData);
    }

    public function restart(OrchestrationInstance $instance): void
    {
        $this->orchestrationClient->restart($instance);
    }

    public function resume(OrchestrationInstance $instance, string $reason): void
    {
        $this->orchestrationClient->resume($instance, $reason);
    }

    public function startNew(string $name, array $args = [], ?string $id = null): OrchestrationInstance
    {
        return $this->orchestrationClient->startNew($name, $args, $id);
    }

    public function suspend(OrchestrationInstance $instance, string $reason): void
    {
        $this->orchestrationClient->suspend($instance, $reason);
    }

    public function terminate(OrchestrationInstance $instance, string $reason): void
    {
        $this->orchestrationClient->terminate($instance, $reason);
    }

    public function waitForCompletion(OrchestrationInstance $instance): void
    {
        $this->orchestrationClient->waitForCompletion($instance);
    }

    public function getEntitySnapshot(EntityId $entityId): ?EntityState
    {
        return $this->entityClient->getEntitySnapshot($entityId);
    }

    public function signal(EntityId|string $entityId, Closure $signal): void
    {
        $this->entityClient->signal($entityId, $signal);
    }

    public function deleteEntity(EntityId $entityId): void
    {
        $this->entityClient->deleteEntity($entityId);
    }

    public function shareEntityOwnership(EntityId $id, string $with): void
    {
        $this->entityClient->shareEntityOwnership($id, $with);
    }

    public function grantEntityAccessToUser(EntityId $id, string $user, Operation $operation): void
    {
        $this->entityClient->grantEntityAccessToUser($id, $user, $operation);
    }

    public function grantEntityAccessToRole(EntityId $id, string $role, Operation $operation): void
    {
        $this->entityClient->grantEntityAccessToRole($id, $role, $operation);
    }

    public function revokeEntityAccessToUser(EntityId $id, string $user): void
    {
        $this->entityClient->revokeEntityAccessToUser($id, $user);
    }

    public function revokeEntityAccessToRole(EntityId $id, string $role): void
    {
        $this->entityClient->revokeEntityAccessToRole($id, $role);
    }

    public function shareOrchestrationOwnership(OrchestrationInstance $id, string $with): void
    {
        $this->orchestrationClient->shareOrchestrationOwnership($id, $with);
    }

    public function grantOrchestrationAccessToUser(OrchestrationInstance $id, string $user, Operation $operation): void
    {
        $this->orchestrationClient->grantOrchestrationAccessToUser($id, $user, $operation);
    }

    public function grantOrchestrationAccessToRole(OrchestrationInstance $id, string $role, Operation $operation): void
    {
        $this->orchestrationClient->grantOrchestrationAccessToRole($id, $role, $operation);
    }

    public function revokeOrchestrationAccessToUser(OrchestrationInstance $id, string $user): void
    {
        $this->orchestrationClient->revokeOrchestrationAccessToUser($id, $user);
    }

    public function revokeOrchestrationAccessToRole(OrchestrationInstance $id, string $role): void
    {
        $this->orchestrationClient->revokeOrchestrationAccessToRole($id, $role);
    }
}
