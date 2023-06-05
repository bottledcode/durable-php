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
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\MonotonicClock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use DateTimeImmutable;
use Generator;
use ReflectionClass;

class EntityHistory extends AbstractHistory
{
	public DateTimeImmutable $now;

	public string $name;
	public array $tags = [];
	public DateTimeImmutable $createdTime;

	public HistoricalStateTracker $historicalTaskResults;

	public OrchestrationStatus $status = OrchestrationStatus::Pending;

	public array $history = [];

	private bool $debugHistory = false;

	private mixed $state = null;

	private string|null $lock;

	private array $lockQueue = [];

	public function __construct(public StateId $id)
	{
	}

	public function hasAppliedEvent(Event $event): bool
	{
		return $this->history[$event->eventId] ?? false;
	}

	public function resetState(): void
	{
		$this->historicalTaskResults?->resetState();
	}

	public function ackedEvent(Event $event): void
	{
		unset($this->history[$event->eventId]);
	}

	public function applyRaiseEvent(RaiseEvent $event, Event $original): Generator
	{
		$this->init();

		switch ($event->eventName) {
			case '__signal':
				$input = $event->eventData['input'];
				$operation = $event->eventData['operation'];
				try {
					$this->state->$operation(...$input);
				} catch (Unwind) {
					// nothing to do here except wait for an event
				}
				break;
			case '__lock':
				$this->lock = $event->eventData['lock'];
				break;
			case '__unlock':
				if ($this->lock === $event->eventData['lock']) {
					$this->lock = null;
				}
				break;
			default:
				$this->historicalTaskResults->receivedEvent($event);
				try {
					$this->state->$event->eventName(...$event->eventData);
				} catch (Unwind) {
					// nothing to do here except wait for an event
				}
		}

		yield from $this->finalize($event);
	}

	public function init(): void
	{
		if ($this->isRunning()) {
			return;
		}

		$this->now = MonotonicClock::current()->now();
		$this->name = $this->id->toEntityId()->name;
		$this->createdTime = $this->now;
		$this->historicalTaskResults = new HistoricalStateTracker();
		$this->status = OrchestrationStatus::Running;

		$this->state = (new ReflectionClass($this->name))->newInstanceWithoutConstructor();
	}

	private function finalize(Event $event): Generator
	{
		$this->history[$event->eventId] = $this->debugHistory ? $event : true;

		yield null;
	}
}
