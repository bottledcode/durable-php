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
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\Status;
use DateTimeImmutable;
use Generator;

final readonly class DurableClient implements DurableClientInterface
{
    public function __construct(
        private EntityClientInterface $entityClient,
        private OrchestrationClientInterface $orchestrationClient
    ) {}

    public static function get(string $apiHost = 'http://localhost:8080'): self
    {
        $httpClient = HttpClientBuilder::buildDefault();

        return new self(new RemoteEntityClient($apiHost, $httpClient), new RemoteOrchestrationClient($apiHost, $httpClient));
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
        ?DateTimeImmutable $scheduledTime = null
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

    public function signal(EntityId|string $entityId, \Closure $signal): void
    {
        $this->entityClient->signal($entityId, $signal);
    }
}
