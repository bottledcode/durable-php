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

namespace Bottledcode\DurablePhp\Testing;

use Amp\DeferredFuture;
use Bottledcode\DurablePhp\DurableFuture;
use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\OrchestrationContextInterface;
use Bottledcode\DurablePhp\Proxy\OrchestratorProxy;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\RetryOptions;
use Bottledcode\DurablePhp\SerializedArray;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityLock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use Bottledcode\DurablePhp\State\Status;
use Bottledcode\DurablePhp\Testing\Exceptions\ContinuedAsNew;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Guid\Guid;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionUnionType;

use function Bottledcode\DurablePhp\OrchestrationInstance;

class DummyOrchestrationContext implements OrchestrationContextInterface
{
    /** @var array<ActivityMock> */
    public array $activities;

    /** @var array<EntityMock> */
    public array $entities;

    public Status $status;

    private array $locks;

    private array $events;

    private string $currentUserId = '';

    public function __construct(public mixed $orchestration, private array $input)
    {
        $this->status = new Status(
            new DateTimeImmutable(),
            '',
            SerializedArray::fromArray($input),
            StateId::fromInstance(OrchestrationInstance('test', 'test')),
            new DateTimeImmutable(),
            null,
            RuntimeStatus::Running,
        );
    }

    public function handleActivities(ActivityMock ...$activities): void
    {
        $this->activities = array_column($activities, null, 'name');
    }

    public function handleEntities(EntityMock ...$entities): void
    {
        $this->entities = array_column($entities, null, 'name');
    }

    public function handleEvent(string $name, mixed $value): void
    {
        $this->events[$name] = $value;
    }

    public function asUser(string $userId): void
    {
        $this->currentUserId = $userId;
    }

    public function callActivity(
        string $name,
        array $args = [],
        ?RetryOptions $retryOptions = null,
    ): DurableFuture {
        $future = new DeferredFuture();
        if ($this->activities[$name] ?? false) {
            $result = $this->activities[$name]->getResult($args);
            if ($this->activities[$name]->isError()) {
                $future->error($result[0]);
            } else {
                $future->complete($result);
            }

            return new DurableFuture($future);
        }

        throw new LogicException('Failed to find registered activity: ' . $name);
    }

    public function callActivityInline(Closure $activity): DurableFuture
    {
        return $activity();
    }

    public function getReplayAwareLogger(): LoggerInterface
    {
        return new DurableLogger();
    }

    public function entityOp(EntityId|string $id, Closure $operation): mixed
    {
        $func = new ReflectionFunction($operation);
        if ($func->getNumberOfParameters() !== 1) {
            throw new LogicException('Must only be a single parameter');
        }
        $arg = $func->getParameters()[0];
        $type = $arg->getType();
        if ($type === null || $type instanceof ReflectionIntersectionType || $type instanceof ReflectionUnionType) {
            throw new LogicException('Must be a single type');
        }

        $name = $type->getName();
        if (!interface_exists($name)) {
            throw new LogicException('Unable to load interface: ' . $name);
        }

        $proxy = new SpyProxy();
        $spy = $proxy->define($name);
        $operationName = $arguments = null;
        $signal = new $spy($operationName, $arguments);
        $returns = false;
        try {
            $operation($signal);
        } catch (Exception) {
            // there is a return
            $returns = true;
        }

        if ($operationName === null || $arguments === null) {
            throw new LogicException('Did not call an operation');
        }

        $entityId = $id instanceof EntityId ? $id : EntityId($name, $id);

        if ($returns) {
            return $this->waitOne($this->callEntity($entityId, $operationName, $arguments));
        }

        $this->signalEntity($entityId, $operationName, $arguments);

        return null;
    }

    public function waitOne(DurableFuture $task): mixed
    {
        return $task->getResult();
    }

    public function callEntity(
        EntityId $entityId,
        string $operation,
        array $args = [],
    ): DurableFuture {
        return ($this->entities[$entityId->name] ??
            throw new LogicException('Failed to find registered entity: ' . $entityId->name))->mock->{$operation}(
                ...$args,
            );
    }

    public function signalEntity(
        EntityId $entityId,
        string $operation,
        array $args = [],
    ): void {
        ($this->entities[$entityId->name] ??
            throw new LogicException('Failed to find registered entity: ' . $entityId->name))->mock->{$operation}(
                ...$args,
            );
    }

    public function isLockedOwned(EntityId $entityId): bool
    {
        return $this->isLocked($entityId);
    }

    public function isLocked(EntityId $entityId): bool
    {
        return $this->locks[$entityId->name . $entityId->id] ?? false;
    }

    public function lockEntity(EntityId ...$entityId): EntityLock
    {
        foreach ($entityId as $id) {
            $this->locks[$id->name . $id->id] = true;
        }

        return new EntityLock(function () use ($entityId): void {
            foreach ($entityId as $id) {
                unset($this->locks[$id->name . $id->id]);
            }
        }, true);
    }

    public function callSubOrchestrator(
        string $name,
        array $args = [],
        ?string $instanceId = null,
        ?RetryOptions $retryOptions = null,
    ): DurableFuture {
        throw new LogicException('Not implemented');
    }

    public function continueAsNew(array $args = []): never
    {
        throw new ContinuedAsNew();
    }

    public function createTimer(DateTimeImmutable $fireAt): DurableFuture
    {
        $future = new DeferredFuture();
        $future->complete();

        return new DurableFuture($future);
    }

    public function getInput(): array
    {
        return $this->input;
    }

    public function setCustomStatus(string $customStatus): void
    {
        $this->status = $this->status->with(customStatus: $customStatus);
    }

    public function waitForExternalEvent(string $name): DurableFuture
    {
        $future = new DeferredFuture();
        $value = $this->events[$name] ?? throw new LogicException('Event not found: ' . $name);
        $future->complete($value);

        return new DurableFuture($future);
    }

    public function getCurrentTime(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getCustomStatus(): ?string
    {
        return $this->status->customStatus;
    }

    public function getCurrentId(): OrchestrationInstance
    {
        return $this->status->id->toOrchestrationInstance();
    }

    public function isReplaying(): bool
    {
        return false;
    }

    public function getParentId(): ?OrchestrationInstance
    {
        return null;
    }

    public function willContinueAsNew(): bool
    {
        return false;
    }

    public function createInterval(
        ?int $years = null,
        ?int $months = null,
        ?int $weeks = null,
        ?int $days = null,
        ?int $hours = null,
        ?int $minutes = null,
        ?int $seconds = null,
        ?int $microseconds = null,
    ): DateInterval {
        if (empty(
            array_filter(
                compact('years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'microseconds'),
            )
        )) {
            throw new LogicException('At least one interval part must be specified');
        }

        $spec = 'P';
        $spec .= $years ? $years . 'Y' : '';
        $spec .= $months ? $months . 'M' : '';

        $specDays = 0;
        $specDays += $weeks ? $weeks * 7 : 0;
        $specDays += $days ?? 0;

        $spec .= $specDays ? $specDays . 'D' : '';
        if ($hours || $minutes || $seconds) {
            $spec .= 'T';
            $spec .= $hours ? $hours . 'H' : '';
            $spec .= $minutes ? $minutes . 'M' : '';
            $spec .= $seconds ? $seconds . 'S' : '';
        }

        if ($spec === 'P') {
            $spec .= '0Y';
        }

        $interval = new DateInterval($spec);
        $interval->f = ($microseconds ?? 0) / 1000000;

        return $interval;
    }

    public function waitAny(DurableFuture ...$tasks): DurableFuture
    {
        foreach ($tasks as $task) {
            if ($task->future->isComplete()) {
                return $task;
            }
        }

        throw new LogicException('No future completed');
    }

    public function waitAll(DurableFuture ...$tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            if (!$task->future->isComplete()) {
                throw new LogicException('Not all futures are completed');
            }
            $results[] = $task->getResult();
        }

        return $results;
    }

    public function createEntityProxy(
        string $className,
        ?EntityId $entityId = null,
    ): object {
        if ($entityId === null) {
            $entityId = EntityId($className, $this->newGuid());
        }

        $class = new ReflectionClass($className);
        if (!$class->isInterface()) {
            throw new LogicException('Only interfaces can be proxied');
        }

        $proxy = new OrchestratorProxy();
        $name = $proxy->define($className);

        return new $name($this, $entityId);
    }

    public function newGuid(): UuidInterface
    {
        $namespace = Guid::fromString('00e0be66-7498-45d1-90ca-be447398ea22');
        $hash = random_bytes(32);

        return Uuid::uuid5($namespace, $hash);
    }

    public function getRandomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function getRandomBytes(int $length): string
    {
        return random_bytes($length);
    }

    public function getCurrentUserId(): string
    {
        return $this->currentUserId;
    }
}
