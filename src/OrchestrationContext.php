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
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\StateId;
use DateTimeInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class OrchestrationContext implements OrchestrationContextInterface
{
	private int $currentReplayIteration = 0;

	public function __construct(
		private OrchestrationInstance $id,
		private OrchestrationHistory $history,
		private EventDispatcherTask $taskController
	) {
	}

	public function callActivity(string $name, array $args = [], ?RetryOptions $retryOptions = null): Future
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
			return $deferred->getFuture();
		}

		// this event has already been sent, so we need to replay it
		$deferred = new DeferredFuture();

		$this->history->historicalTaskResults->trackIdentity($identity, $deferred);

		return $deferred->getFuture();
	}

	public function callSubOrchestrator(
		string $name,
		array $args = [],
		?string $instanceId = null,
		?RetryOptions $retryOptions = null
	): Future {
		throw new LogicException('Not implemented');
	}

	public function continueAsNew(array $args = []): void
	{
		throw new LogicException('Not implemented');
	}

	public function createTimer(DateTimeInterface $fireAt): Future
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
			return $deferred->getFuture();
		}

		// this event has already been sent, so we need to replay it
		$deferred = new DeferredFuture();
		$this->history->historicalTaskResults->trackIdentity($identity, $deferred);
		return $deferred->getFuture();
	}

	public function waitForExternalEvent(string $name): Future
	{
		$identity = sha1($name);
		$deferred = new DeferredFuture();
		$this->history->historicalTaskResults->trackIdentity($identity, $deferred);
		return $deferred->getFuture();
	}

	public function getInput(): array
	{
		return $this->history->input;
	}

	public function newGuid(): UuidInterface
	{
		return Uuid::uuid7();
	}

	public function setCustomStatus(string $customStatus): void
	{
		$this->history->tags['customStatus'] = $customStatus;
	}

	public function waitAll(Future ...$tasks): Future
	{
		$completed = $this->history->historicalTaskResults->awaitingFutures(...$tasks);
		if (count($completed) === count($tasks)) {
			return Future::complete(true);
		}

		// there is no task that is already complete, so we need to unwind the stack
		throw new Unwind();
	}

	public function waitOne(Future $task): mixed
	{
		$completed = $this->history->historicalTaskResults->awaitingFutures($task);
		if (count($completed) === 1) {
			return $completed[0]->await();
		}
		throw new Unwind();
	}

	public function waitAny(Future ...$tasks): Future
	{
		// track the awaited tasks
		$completed = $this->history->historicalTaskResults->awaitingFutures(...$tasks);
		foreach ($completed as $task) {
			if ($task->isComplete()) {
				return $task;
			}
		}

		// there is no task that is already complete, so we need to unwind the stack
		throw new Unwind();
	}

	public function getCurrentTime(): \DateTimeImmutable
	{
		return $this->history->now;
	}

	public function getCustomStatus(): string
	{
		return $this->history->tags['customStatus'] ?? throw new LogicException('No custom status set');
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
}
