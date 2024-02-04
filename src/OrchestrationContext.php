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

use Amp\DeferredFuture;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithActivity;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\Proxy\OrchestratorProxy;
use Bottledcode\DurablePhp\Proxy\SpyProxy;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityLock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\RuntimeStatus;
use LogicException;
use Ramsey\Uuid\Guid\Guid;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class OrchestrationContext implements OrchestrationContextInterface
{
    private int $guidCounter = 0;

    private int $randomKey = 0;

    public function __construct(
        private readonly OrchestrationInstance $id,
        private readonly OrchestrationHistory $history,
        private readonly WorkerTask $taskController,
        private readonly OrchestratorProxy $proxyGenerator,
        private readonly SpyProxy $spyProxy,
    ) {
        $this->history->historicalTaskResults->setCurrentTime(MonotonicClock::current()->now());
    }

    public function callActivity(string $name, array $args = [], ?RetryOptions $retryOptions = null): DurableFuture
    {
        $identity = $this->newGuid();
        return $this->createFuture(
            fn() => $this->taskController->fire(
                AwaitResult::forEvent(
                    StateId::fromInstance($this->id),
                    WithActivity::forEvent($identity, ScheduleTask::forName($name, $args))
                )
            ),
            function (Event $event, string $eventIdentity) use ($identity): array {
                if (($event instanceof TaskCompleted || $event instanceof TaskFailed) &&
                    $eventIdentity === $identity->toString()) {
                    return [$event, true];
                }
                return [null, false];
            },
            $identity->toString()
        );
    }

    public function newGuid(): UuidInterface
    {
        $namespace = Guid::fromString('00e0be66-7498-45d1-90ca-be447398ea22');
        $hash = sprintf('%s-%s-%d-%d', $this->id->instanceId, $this->id->executionId, $this->history->version, $this->guidCounter++);
        return Uuid::uuid5($namespace, $hash);
    }

    private function createFuture(
        \Closure $onSent,
        \Closure $onReceived,
        string $identity = null
    ): DurableFuture {
        $identity ??= $this->history->historicalTaskResults->getIdentity();
        if (!$this->history->historicalTaskResults->hasSentIdentity($identity)) {
            [$eventId] = $onSent();
            $deferred = new DeferredFuture();
            $this->history->historicalTaskResults->sentEvent($identity, $eventId);
            $future = new DurableFuture($deferred);
            $this->history->historicalTaskResults->trackFuture($onReceived, $future);
            return $future;
        }

        $deferred = new DeferredFuture();
        $future = new DurableFuture($deferred);
        $this->history->historicalTaskResults->trackFuture($onReceived, $future);
        return $future;
    }

    public function callSubOrchestrator(
        string $name,
        array $args = [],
        ?string $instanceId = null,
        ?RetryOptions $retryOptions = null
    ): DurableFuture {
        throw new LogicException('Not implemented');
    }

    public function continueAsNew(array $args = []): never
    {
        // alright, we just want to totally reset internal state and pass the new args...
        // first, release any locks we might have
        foreach ($this->history->releaseAllLocks() as $event) {
            $this->taskController->fire($event);
        }

        $this->history->restartAsNew($args);
        $this->taskController->fire(WithOrchestration::forInstance(StateId::fromInstance($this->id), StartOrchestration::forInstance($this->id)));
        throw new Unwind();
    }

    public function createTimer(\DateTimeImmutable $fireAt): DurableFuture
    {
        $identity = sha1($fireAt->format('c'));
        return $this->createFuture(
            fn() => $this->taskController->fire(
                WithOrchestration::forInstance(
                    StateId::fromInstance($this->id),
                    WithDelay::forEvent($fireAt, RaiseEvent::forTimer($identity))
                )
            ),
            function (Event $event) use ($identity): array {
                if ($event instanceof RaiseEvent && $event->eventName === $identity) {
                    return [$event, true];
                }
                return [null, false];
            }
        );
    }

    public function waitForExternalEvent(string $name): DurableFuture
    {
        $future = new DurableFuture(new DeferredFuture());
        $this->history->historicalTaskResults->trackFuture(function (Event $event) use ($name): array {
            $found = false;
            $result = null;
            if ($event instanceof RaiseEvent && $event->eventName === $name) {
                $found = true;
                $result = $event;
            }

            return [$result, $found];
        }, $future);
        return $future;
    }

    public function getInput(): array
    {
        return $this->history->status->input;
    }

    public function setCustomStatus(string $customStatus): void
    {
        $this->history->status = $this->history->status->with(customStatus: $customStatus);
    }

    public function waitAny(DurableFuture ...$tasks): DurableFuture
    {
        // track the awaited tasks
        $completed = $this->history->historicalTaskResults->awaitingFutures(...$tasks);
        foreach ($completed as $task) {
            if ($task->future->isComplete()) {
                return $task;
            }
        }

        // there is no task that is already complete, so we need to unwind the stack
        throw new Unwind();
    }

    public function getCustomStatus(): string
    {
        return $this->history->status->customStatus;
    }

    public function getCurrentId(): OrchestrationInstance
    {
        return $this->id;
    }

    public function getParentId(): OrchestrationInstance|null
    {
        return $this->history->parentInstance ?? null;
    }

    public function willContinueAsNew(): bool
    {
        return $this->history->status->runtimeStatus === RuntimeStatus::ContinuedAsNew;
    }

    public function createInterval(
        int $years = null,
        int $months = null,
        int $weeks = null,
        int $days = null,
        int $hours = null,
        int $minutes = null,
        int $seconds = null,
        int $microseconds = null
    ): \DateInterval {
        if (
            empty(
            array_filter(
                compact('years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'microseconds')
            )
            )
        ) {
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

        $interval = new \DateInterval($spec);
        $interval->f = ($microseconds ?? 0) / 1000000;
        return $interval;
    }

    public function getCurrentTime(): \DateTimeImmutable
    {
        return $this->history->historicalTaskResults->getCurrentTime();
    }

    public function isLocked(EntityId $entityId): bool
    {
        if ($this->isLockedOwned($entityId)) {
            return true;
        }

        // we have to query the entity to see if it is locked.
        $state = $this->taskController->getState(StateId::fromEntityId($entityId));
        if ($state instanceof EntityHistory) {
            return $state->lock === StateId::fromInstance($this->id)->id;
        }

        throw new LogicException('Entity not found');
    }

    public function isLockedOwned(EntityId $entityId): bool
    {
        $id = StateId::fromEntityId($entityId);
        return in_array($id->id, array_map(static fn($x) => $x->id, $this->history->locks ?? []), true);
    }

    public function lockEntity(EntityId ...$entityId): EntityLock
    {
        if (!empty($this->history->locks ?? []) && !$this->isReplaying()) {
            throw new LogicException('Cannot lock an entity while holding locks');
        }

        // create a deterministic order of locks
        $entityId = array_map(static fn(EntityId $x) => StateId::fromEntityId($x), $entityId);
        sort($entityId);

        $owner = StateId::fromInstance($this->id);
        $event = AwaitResult::forEvent(
            $owner,
            WithEntity::forInstance(current($entityId), RaiseEvent::forLockNotification($owner->id))
        );
        $identity = $this->newGuid()->toString();
        $future =
            $this->createFuture(
                fn() => $this->taskController->fire(WithLock::onEntity($owner, $event, ...$entityId)),
                function (Event $event, string $eventIdentity) use ($identity) {
                    return [$event, $identity === $eventIdentity];
                },
                $identity
            );
        $this->waitOne($future);

        $this->history->locks = $entityId;

        return new EntityLock(function () use ($owner) {
            foreach ($this->history->locks as $lock) {
                $this->taskController->fire(
                    WithLock::onEntity(
                        $owner,
                        WithEntity::forInstance($lock, RaiseEvent::forUnlock($owner->id, null, null))
                    )
                );
            }
            $this->history->locks = [];
        });
    }

    public function isReplaying(): bool
    {
        return $this->history->historicalTaskResults->isReading();
    }

    public function waitOne(DurableFuture $task): mixed
    {
        $completed = $this->history->historicalTaskResults->awaitingFutures($task);
        if (count($completed) >= 1) {
            return current($completed)->getResult();
        }
        throw new Unwind();
    }

    public function waitAll(DurableFuture ...$tasks): DurableFuture
    {
        $completed = $this->history->historicalTaskResults->awaitingFutures(...$tasks);
        if (count($completed) === count($tasks)) {
            /**
             * @var DurableFuture $complete
             */
            foreach ($completed as $complete) {
                // rethrow any exceptions
                $complete->getResult();
            }
            return current($tasks);
        }

        // there is no task that is already complete, so we need to unwind the stack
        throw new Unwind();
    }

    /**
     * @template T
     * @param class-string<T> $className
     * @return T
     */
    public function createEntityProxy(string $className, EntityId|null $entityId = null): object
    {
        if ($entityId === null) {
            $entityId = new EntityId($className, $this->newGuid());
        }

        $class = new \ReflectionClass($className);
        if (!$class->isInterface()) {
            throw new LogicException('Only interfaces can be proxied');
        }

        $name = $this->proxyGenerator->define($className);
        return new $name($this, $entityId);
    }

    public function signalEntity(EntityId $entityId, string $operation, array $args = []): void
    {
        if ($this->isReplaying()) {
            return;
        }
        $id = StateId::fromInstance($this->id);

        $event = WithEntity::forInstance(StateId::fromEntityId($entityId), RaiseEvent::forOperation($operation, $args));
        if ($this->isLockedOwned($entityId)) {
            $event = WithLock::onEntity($id, $event);
        }

        $this->taskController->fire($event);
    }

    public function callEntity(EntityId $entityId, string $operation, array $args = []): DurableFuture
    {
        $id = StateId::fromInstance($this->id);

        $event = AwaitResult::forEvent(
            $id,
            WithEntity::forInstance(StateId::fromEntityId($entityId), RaiseEvent::forOperation($operation, $args))
        );
        if ($this->isLockedOwned($entityId)) {
            $event = WithLock::onEntity($id, $event);
        }

        $identity = $this->newGuid()->toString();

        return $this->createFuture(
            fn() => $this->taskController->fire($event),
            fn(Event $event, string $eventIdentity) => [$event, $identity === $eventIdentity],
            $identity
        );
    }

    public function getRandomInt(int $min, int $max): int
    {
        ++$this->randomKey;
        return $this->history->randoms[$this->randomKey] ??= random_int($min, $max);
    }

    public function getRandomBytes(int $length): string
    {
        ++$this->randomKey;
        return $this->history->randoms[$this->randomKey] ??= random_bytes($length);
    }

    public function getReplayAwareLogger(): Logger
    {
        return new class($this) extends Logger {
            private static OrchestrationContextInterface $context;

            public function __construct(OrchestrationContextInterface $context) {
                self::$context = $context;
            }

            public static function always(string $message,...$vars) : void{
                if(self::$context->isReplaying()) return;
                parent::always($message,$vars);
            }

            public static function log(string $message,...$vars) : void{
                if(self::$context->isReplaying()) return;
                parent::log($message,$vars); // TODO: Change the autogenerated stub
            }

            public static function error(string $message,...$vars) : void{
                if(self::$context->isReplaying()) return;
                parent::error($message,$vars); // TODO: Change the autogenerated stub
            }

            public static function event(string $message,...$vars) : void{
                if(self::$context->isReplaying()) return;
                parent::event($message,$vars); // TODO: Change the autogenerated stub
            }

            public static function reset(){
                if(self::$context->isReplaying()) return;
                parent::reset(); // TODO: Change the autogenerated stub
            }
        };
    }

    public function entityOp(string|EntityId $id, \Closure $operation): mixed
    {
        $func = new \ReflectionFunction($operation);
        if($func->getNumberOfParameters() !== 1) {
            throw new LogicException('Must only be a single parameter');
        }
        $arg  = $func->getParameters()[0];
        $type = $arg->getType();
        if($type === null || $type instanceof \ReflectionIntersectionType || $type instanceof \ReflectionUnionType) {
            throw new LogicException('Must be a single type');
        }

        $name = $type->getName();
        if(!interface_exists($name)) {
            throw new LogicException('Unable to load interface: ' . $name);
        }

        $spy = $this->spyProxy->define($name);
        $operationName = $arguments = null;
        $signal = new $spy($operationName, $arguments);
        $returns = false;
        try {
            $operation($signal);
        } catch(\Exception) {
            // there is a return
            $returns = true;
        }

        if($operationName === null || $arguments === null) {
            throw new LogicException('Did not call an operation');
        }

        $entityId = $id instanceof EntityId ? $id : new EntityId($name, $id);

        if($returns) {
            return $this->waitOne($this->callEntity($entityId, $operationName, $arguments));
        }

        $this->signalEntity($entityId, $operationName, $arguments);

        return null;
    }
}
