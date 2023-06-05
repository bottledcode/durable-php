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

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\ReplyToInterface;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\State\Ids\StateId;

class ActivityHistory extends AbstractHistory
{
	public string $activityId;

	public OrchestrationStatus $status = OrchestrationStatus::Pending;
	public mixed $result = null;

	public function __construct(StateId $id)
	{
		$this->activityId = $id->toActivityId();
	}

	public function hasAppliedEvent(Event $event): bool
	{
		return false;
	}

	public function applyScheduleTask(ScheduleTask $event, Event $original): \Generator
	{
		$task = $event->name;
		$replyTo = $this->getReplyTo($original);
		try {
			if (class_exists($task)) {
				$task = new $task();
			}
			$result = $task(...($event->input ?? []));
			$this->result = $result;
			$this->status = OrchestrationStatus::Completed;
			foreach ($replyTo as $id) {
				yield WithOrchestration::forInstance(
					$id,
					TaskCompleted::forId($original->eventId, $result)
				);
			}
		} catch (\Throwable $e) {
			$this->status = OrchestrationStatus::Failed;
			$this->result = $e->getMessage();
			foreach ($replyTo as $id) {
				yield WithOrchestration::forInstance(
					$id,
					TaskFailed::forTask(
						$original->eventId,
						$e->getMessage(),
						$e->getTraceAsString(),
						$e::class
					)
				);
			}
		}
	}

	/**
	 * @param Event $event
	 * @return array<StateId>
	 */
	private function getReplyTo(Event $event): array
	{
		$ids = [];
		while ($event instanceof HasInnerEventInterface) {
			if ($event instanceof ReplyToInterface) {
				$ids[] = $event->getReplyTo();
			}
			$event = $event->getInnerEvent();
		}
		return $ids;
	}

	public function resetState(): void
	{
	}

	public function ackedEvent(Event $event): void
	{
		// TODO: Implement ackedEvent() method.
	}
}
