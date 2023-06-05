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

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\EventDispatcherTask;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\ExecutionTerminated;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\OrchestrationContext;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Crell\Serde\Attributes\Field;

class OrchestrationHistory extends AbstractHistory
{
	public \DateTimeImmutable $now;

	public string $name;

	public string $version;

	public array $tags;

	public OrchestrationInstance $instance;

	public OrchestrationInstance|null $parentInstance;

	public \DateTimeImmutable $createdTime;

	public array $input = [];

	public HistoricalStateTracker $historicalTaskResults;

	public \DateTimeImmutable $lastProcessedEventTime;

	public OrchestrationStatus $status = OrchestrationStatus::Pending;

	public array $history = [];

	private bool $debugHistory = false;

	#[Field(exclude: true)]
	private mixed $constructed = null;

	public function __construct(StateId $id)
	{
		$this->lastProcessedEventTime = new \DateTimeImmutable('2023-05-30');
		$this->instance = $id->toOrchestrationInstance();
		$this->historicalTaskResults = new HistoricalStateTracker();
	}

	/**
	 * This represents the beginning of the orchestration and is the first event
	 * that is applied to the history. The next phase is to actually run the
	 * orchestration now that we've set up the history.
	 *
	 * @param StartExecution $event
	 * @return array
	 */
	public function applyStartExecution(StartExecution $event, Event $original): \Generator
	{
		if ($this->isFinished()) {
			return;
		}

		Logger::log("Applying StartExecution event to OrchestrationHistory");
		$this->now = $event->timestamp;
		$this->createdTime = $event->timestamp;
		$this->name = $event->name;
		$this->version = $event->version;
		$this->tags = $event->tags;;
		$this->parentInstance = $event->parentInstance ?? null;
		$this->input = $event->input;
		$this->history = [];
		$this->historicalTaskResults = new HistoricalStateTracker();

		yield StartOrchestration::forInstance($this->instance);

		yield from $this->finalize($event);
	}

	private function isFinished(): bool
	{
		return match ($this->status) {
			OrchestrationStatus::Terminated, OrchestrationStatus::Canceled, OrchestrationStatus::Failed, OrchestrationStatus::Completed => true,
			default => false,
		};
	}

	private function finalize(Event $event): \Generator
	{
		$this->lastProcessedEventTime = $event->timestamp;
		$this->addEventToHistory($event);

		yield null;
	}

	private function addEventToHistory(Event $event): void
	{
		if ($this->debugHistory) {
			$this->history[$event->eventId] = $event;
		} else {
			$this->history[$event->eventId] = true;
		}
	}

	public function applyStartOrchestration(StartOrchestration $event, Event $original): \Generator
	{
		if ($this->isFinished()) {
			return;
		}

		$this->status = OrchestrationStatus::Running;

		// go ahead and finalize this event to the history and update the status
		// we won't be updating any more state
		yield from $this->finalize($event);
		yield from $this->construct();
	}

	private function construct(): \Generator
	{
		$class = new \ReflectionClass($this->instance->instanceId);

		$this->constructed ??= $class->newInstanceWithoutConstructor();
		try {
			$taskScheduler = null;
			yield static function (EventDispatcherTask $task) use (&$taskScheduler) {
				$taskScheduler = $task;
			};
			$context = new OrchestrationContext($this->instance, $this, $taskScheduler);
			try {
				$result = ($this->constructed)($context);
			} catch (Unwind) {
				// we don't need to do anything here, we just need to catch it
				// so that we don't throw an exception
				return;
			}

			$this->status = OrchestrationStatus::Completed;
			$this->tags['result'] = $result;
			$completion = TaskCompleted::forId(StateId::fromInstance($this->instance), $result);
		} catch (\Throwable $e) {
			$this->status = OrchestrationStatus::Failed;
			$this->tags['error'] = $e->getMessage();
			$this->tags['stacktrace'] = $e->getTraceAsString();
			$this->tags['exception'] = $e::class;
			$completion = TaskFailed::forTask(
				StateId::fromInstance($this->instance),
				$e->getMessage(),
				$e->getTraceAsString(),
				$e::class
			);
		}

		$completion = WithOrchestration::forInstance(StateId::fromInstance($this->instance), $completion);

		if ($this->parentInstance ?? false) {
			$completion = AwaitResult::forEvent(StateId::fromInstance($this->parentInstance), $completion);
		} else {
			$completion = null;
		}

		yield $completion;
	}

	public function applyTaskCompleted(TaskCompleted $event, Event $original): \Generator
	{
		if ($this->isFinished()) {
			return;
		}

		yield from $this->finalize($event);

		$this->historicalTaskResults->receivedEvent($event);

		yield from $this->construct();
	}

	public function applyTaskFailed(TaskFailed $event, Event $original): \Generator
	{
		if ($this->isFinished()) {
			return;
		}

		yield from $this->finalize($event);

		$this->historicalTaskResults->receivedEvent($event);

		yield from $this->construct();
	}

	public function applyRaiseEvent(RaiseEvent $event, Event $original): \Generator
	{
		yield from $this->finalize($event);

		$this->historicalTaskResults->receivedEvent($event);

		if ($this->isRunning()) {
			yield from $this->construct();
		}
	}

	private function isRunning(): bool
	{
		return match ($this->status) {
			OrchestrationStatus::Running => true,
			default => false,
		};
	}

	public function applyExecutionTerminated(ExecutionTerminated $event, Event $original): \Generator
	{
		if ($this->isFinished()) {
			return;
		}

		$this->status = OrchestrationStatus::Terminated;

		yield from $this->finalize($event);
	}

	public function hasAppliedEvent(Event $event): bool
	{
		return array_key_exists($event->eventId, $this->history);
	}

	public function resetState(): void
	{
		$this->historicalTaskResults->resetState();
	}

	public function ackedEvent(Event $event): void
	{
		unset($this->history[$event->eventId]);
	}
}
