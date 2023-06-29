<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Status;
use DateTimeImmutable;
use Generator;

class DurableClient implements DurableClientInterface
{
    public function __construct(private EntityClientInterface $entityClient, private OrchestrationClientInterface $orchestrationClient)
    {
    }

    public function cleanEntityStorage(): void
    {
        $this->entityClient->cleanEntityStorage();
    }

    public function listEntities(): Generator
    {
        yield from $this->entityClient->listEntities();
    }

    public function signalEntity(
        EntityId $entityId,
        string $operationName,
        array $input = [],
        DateTimeImmutable $scheduledTime = null
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
}
