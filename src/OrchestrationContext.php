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

use Amp\DeferredFuture;
use Amp\Future;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\WithActivity;
use Bottledcode\DurablePhp\Events\WithDelay;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityLock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use LogicException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class OrchestrationContext implements OrchestrationContextInterface
{
	public function __construct(
		private OrchestrationInstance $id,
		private OrchestrationHistory $history,
		private EventDispatcherTask $taskController
	) {
		$this->history->historicalTaskResults->setCurrentTime(MonotonicClock::current()->now());
	}

	public function callActivity(string $name, array $args = [], ?RetryOptions $retryOptions = null): DurableFuture
	{
		if (!$this->history->historicalTaskResults->hasSentIdentity($identity = sha1($name . print_r($args, true)))) {
			// this event has yet to be sent.
			[$eventId] = $this->taskController->fire(
				AwaitResult::forEvent(
					StateId::fromInstance($this->id),
					WithActivity::forEvent(Uuid::uuid7(), new ScheduleTask('', $name, 0, $args))
				)
			);
			$deferred = new DeferredFuture();
			$this->history->historicalTaskResults->sentEvent($identity, $eventId, $deferred);
			return new DurableFuture($deferred->getFuture());
		}

		// this event has already been sent, so we need to replay it
		$deferred = new DeferredFuture();

		$this->history->historicalTaskResults->trackIdentity($identity, $deferred);

		return new DurableFuture($deferred->getFuture());
	}

	public function callSubOrchestrator(
		string $name,
		array $args = [],
		?string $instanceId = null,
		?RetryOptions $retryOptions = null
	): DurableFuture {
		throw new LogicException('Not implemented');
	}

	public function continueAsNew(array $args = []): void
	{
		throw new LogicException('Not implemented');
	}

	public function createTimer(\DateTimeImmutable $fireAt): DurableFuture
	{
		if (!$this->history->historicalTaskResults->hasSentIdentity($identity = sha1($fireAt->format('c')))) {
			// this event has yet to be sent.
			[$eventId] = $this->taskController->fire(
				new WithOrchestration(
					'', StateId::fromInstance($this->id), new WithDelay('', $fireAt, new RaiseEvent('', $identity, []))
				)
			);
			$deferred = new DeferredFuture();
			$this->history->historicalTaskResults->sentEvent($identity, $eventId, $deferred);
			return new DurableFuture($deferred->getFuture());
		}

		// this event has already been sent, so we need to replay it
		$deferred = new DeferredFuture();
		$this->history->historicalTaskResults->trackIdentity($identity, $deferred);
		return new DurableFuture($deferred->getFuture());
	}

	public function waitForExternalEvent(string $name): DurableFuture
	{
		$identity = sha1($name);
		$deferred = new DeferredFuture();
		$this->history->historicalTaskResults->trackIdentity($identity, $deferred);
		return new DurableFuture($deferred->getFuture());
	}

	public function getInput(): array
	{
		return $this->history->status->input;
	}

	public function newGuid(): UuidInterface
	{
		return Uuid::uuid7();
	}

	public function setCustomStatus(string $customStatus): void
	{
		$this->history->status = $this->history->status->with(customStatus: $customStatus);
	}

	public function waitAny(DurableFuture ...$tasks): DurableFuture
	{
		// track the awaited tasks
		$completed = $this->history->historicalTaskResults->awaitingFutures(
			...
			array_map(static fn(DurableFuture $f) => $f->future, $tasks)
		);
		foreach ($completed as $task) {
			if ($task->isComplete()) {
				return new DurableFuture($task);
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

	public function isReplaying(): bool
	{
		return $this->history->historicalTaskResults->isReading();
	}

	public function getParentId(): OrchestrationInstance|null
	{
		return $this->history->parentInstance ?? null;
	}

	public function willContinueAsNew(): bool
	{
		throw new LogicException('Not implemented');
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
		if (empty(
		array_filter(
			compact('years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds', 'microseconds')
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
		return $this->history->locks[$id->id] ?? false;
	}

	public function lockEntity(EntityId ...$entityId): EntityLock
	{
		$instanceId = StateId::fromInstance($this->id);
		$futures = [];
		foreach ($entityId as $id) {
			$id = StateId::fromEntityId($id);
			$event = AwaitResult::forEvent(
				$instanceId,
				WithEntity::forInstance($id, RaiseEvent::forLock($instanceId->id))
			);
			[$eventId] = $this->taskController->fire($event);
			$future = new DeferredFuture();
			$this->history->historicalTaskResults->sentEvent($eventId, $eventId, $future);
			$futures[] = new DurableFuture($future->getFuture());
			$this->history->locks[$id->id] = time();
		}
		$this->waitAll(...$futures);
		return new EntityLock(function () use ($instanceId, $entityId) {
			$futures = [];
			foreach ($entityId as $id) {
				$id = StateId::fromEntityId($id);
				$event = AwaitResult::forEvent(
					$instanceId,
					WithEntity::forInstance($id, RaiseEvent::forUnlock($instanceId->id))
				);
				[$eventId] = $this->taskController->fire($event);
				$future = new DeferredFuture();
				$this->history->historicalTaskResults->sentEvent($id->id . 'unlock', $eventId, $future);
				$futures[] = new DurableFuture($future->getFuture());
			}
			$this->waitAll(...$futures);
			foreach ($entityId as $id) {
				$id = StateId::fromEntityId($id);
				unset($this->history->locks[$id->id]);
			}
		});
	}

	public function waitAll(DurableFuture ...$tasks): DurableFuture
	{
		$completed = $this->history->historicalTaskResults->awaitingFutures(
			...
			array_map(static fn(DurableFuture $f) => $f->future, $tasks)
		);
		if (count($completed) === count($tasks)) {
			return new DurableFuture(Future::complete(true));
		}

		// there is no task that is already complete, so we need to unwind the stack
		throw new Unwind();
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @return T
	 */
	public function createEntityProxy(string $className, EntityId $id): object
	{
		$class = new \ReflectionClass($className);
		if (!$class->isInterface()) {
			throw new LogicException('Only interfaces can be proxied');
		}
		$methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
		$proxies = [];
		foreach ($methods as $method) {
			$hasReturn = $method->hasReturnType();
			$isVoid = $hasReturn && $method->getReturnType()?->getName() === 'void';
			$proxies[$method->getName()] = compact('hasReturn', 'isVoid');
		}

		return new class($this, $proxies, $id) {
			public function __construct(
				private OrchestrationContext $context,
				private array $proxies,
				private EntityId $id
			) {
			}

			public function __call(string $name, array $arguments)
			{
				$proxy = $this->proxies[$name] ?? throw new LogicException('Method not found');
				if ($proxy['isVoid'] || !$proxy['hasReturn']) {
					$this->context->signalEntity($this->id, $name, $arguments);
					return null;
				}

				return $this->context->waitOne($this->context->callEntity($this->id, $name, $arguments));
			}

			public function __debugInfo(): ?array
			{
				return ['id' => $this->id];
			}
		};
	}

	public function signalEntity(EntityId $entityId, string $operation, array $args = []): void
	{
		$entityId = StateId::fromEntityId($entityId);
		$event = WithEntity::forInstance($entityId, RaiseEvent::forOperation($operation, $args));
		$this->taskController->fire($event);
	}

	public function waitOne(DurableFuture $task): mixed
	{
		$completed = $this->history->historicalTaskResults->awaitingFutures($task->future);
		if (count($completed) === 1) {
			return $completed[0]->await();
		}
		throw new Unwind();
	}

	public function callEntity(EntityId $entityId, string $operation, array $args = []): DurableFuture
	{
		$entityId = StateId::fromEntityId($entityId);
		$instanceId = StateId::fromInstance($this->id);
		$event = AwaitResult::forEvent(
			$instanceId,
			WithEntity::forInstance($entityId, RaiseEvent::forOperation($operation, $args))
		);
		$future = new DeferredFuture();
		[$eventId] = $this->taskController->fire($event);
		$this->history->historicalTaskResults->sentEvent(
			sha1($operation . json_encode($args) . $entityId),
			$eventId,
			$future
		);
		return new DurableFuture($future->getFuture());
	}
}
