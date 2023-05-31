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

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Events\StartOrchestration;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithActivity;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\MonotonicClock;
use Bottledcode\DurablePhp\OrchestrationContext;
use Ramsey\Uuid\Uuid;

class OrchestrationHistory implements StateInterface
{
	public \DateTimeImmutable $now;

	public string $name;

	public string $version;

	public array $tags;

	public OrchestrationInstance $instance;

	public OrchestrationInstance|null $parentInstance;

	public \DateTimeImmutable $createdTime;

	public array $input;

	public array $historicalTaskResults = [];

	public \DateTimeImmutable $lastProcessedEventTime;

	public OrchestrationStatus $status = OrchestrationStatus::Pending;

	public array $history = [];

	public function __construct(StateId $id)
	{
		$this->lastProcessedEventTime = new \DateTimeImmutable('2023-05-30');
		$this->instance = $id->toOrchestrationInstance();
	}

	/**
	 * This represents the beginning of the orchestration and is the first event
	 * that is applied to the history. The next phase is to actually run the
	 * orchestration now that we've set up the history.
	 *
	 * @param StartExecution $event
	 * @return array
	 */
	public function applyStartExecution(StartExecution $event): \Generator
	{
		Logger::log("Applying StartExecution event to OrchestrationHistory");
		$this->now = $event->timestamp;
		$this->createdTime = $event->timestamp;
		$this->name = $event->name;
		$this->version = $event->version;
		$this->tags = $event->tags;;
		$this->parentInstance = $event->parentInstance ?? null;
		$this->input = $event->input;

		yield StartOrchestration::forInstance($this->instance);

		yield from $this->finalize($event);
	}

	private function finalize(Event $event): \Generator
	{
		$this->lastProcessedEventTime = $event->timestamp;
		$this->history[$event->eventId] = $event;

		yield null;
	}

	public function applyStartOrchestration(StartOrchestration $event): \Generator
	{
		$this->now = MonotonicClock::current()->now();
		$this->status = OrchestrationStatus::Running;

		// go ahead and finalize this event to the history and update the status
		// we won't be updating any more state
		yield from $this->finalize($event);

		yield from $this->construct();
	}

	private function construct(): \Generator
	{
		$class = new \ReflectionClass($this->instance->instanceId);

		$class = $class->newInstanceWithoutConstructor();
		try {
			$fiber = new \Fiber(fn() => $class(new OrchestrationContext($this->instance, $this)));
			$result = $fiber->start();

			if ($result !== null) {
				switch ($result['type']) {
					case 'callActivity':
						$activityId = Uuid::uuid7();
						return yield AwaitResult::forEvent(
							StateId::fromState($this),
							WithActivity::forEvent(
								$activityId,
								ScheduleTask::fromOrchestrationContext($result)
							)
						);
				}
			}

			yield TaskCompleted::forId(
				"orchestration:{$this->instance->instanceId}:{$this->instance->executionId}",
				$result
			);
		} catch (\Throwable $e) {
			yield TaskFailed::forTask(
				"orchestration:{$this->instance->instanceId}:{$this->instance->executionId}",
				$e->getMessage(),
				previous: $e::class
			);
		}
	}

	public function hasAppliedEvent(Event $event): bool
	{
		return array_key_exists($event->eventId, $this->history);
	}
}
