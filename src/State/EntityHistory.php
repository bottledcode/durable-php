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

use Bottledcode\DurablePhp\EntityContext;
use Bottledcode\DurablePhp\EntityContextInterface;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithOrchestration;
use Bottledcode\DurablePhp\Exceptions\Unwind;
use Bottledcode\DurablePhp\MonotonicClock;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Generator;
use ReflectionClass;

class EntityHistory extends AbstractHistory
{
	public EntityId $entityId;

	public string $name;
	public array $history = [];
	public string|null $lock;
	private bool $debugHistory = false;
	private EntityState|null $state = null;
	private array $lockQueue = [];

	public function __construct(public StateId $id)
	{
		$this->entityId = $id->toEntityId();
	}

	public function hasAppliedEvent(Event $event): bool
	{
		return $this->history[$event->eventId] ?? false;
	}

	public function resetState(): void
	{
	}

	public function delete(): void
	{
		$this->state = null;
	}

	public function ackedEvent(Event $event): void
	{
		unset($this->history[$event->eventId]);
	}

	public function setState(mixed $state): void
	{
		$this->state = $state;
	}


	public function applyRaiseEvent(RaiseEvent $event, Event $original): Generator
	{
		if ($this->queueIfLocked($original)) {
			return;
		}

		$this->init();

		switch ($event->eventName) {
			case '__signal':
				$input = $event->eventData['input'];
				$operation = $event->eventData['operation'];
				yield from $this->execute($original, $operation, $input);
				break;
			case '__lock':
				$this->lock = $event->eventData['name'];
				foreach ($this->getReplyTo($original) as $replyTo) {
					yield WithOrchestration::forInstance($replyTo, TaskCompleted::forId($original->eventId, null));
				}
				break;
			case '__unlock':
				if ($this->lock === $event->eventData['name']) {
					$this->lock = null;
				}
				foreach ($this->getReplyTo($original) as $replyTo) {
					yield WithOrchestration::forInstance($replyTo, TaskCompleted::forId($original->eventId, null));
				}
				foreach ($this->lockQueue as $nextEvent) {
					yield $nextEvent;
				}
				$this->lockQueue = [];
				break;
			default:
				break;
		}

		yield from $this->finalize($event);
	}

	private function queueIfLocked(Event $original): bool
	{
		if ($this->isLocked($original)) {
			$this->lockQueue[] = $original;
			return true;
		}
		return false;
	}

	private function isLocked(Event $original): bool
	{
		if (($this->lock ?? null) === null) {
			return false;
		}
		while ($original instanceof HasInnerEventInterface) {
			if (($original instanceof AwaitResult) && $original->origin->id === $this->lock) {
				return false;
			}
			$original = $original->getInnerEvent();
		}
		return true;
	}

	public function init(): void
	{
		if ($this->isRunning()) {
			return;
		}

		$this->name = $this->id->toEntityId()->name;
		$now = MonotonicClock::current()->now();
		$this->status = new Status($now, '', [], $this->id, $now, null, RuntimeStatus::Running);

		if (class_exists($this->name)) {
			$reflection = new ReflectionClass($this->name);
			$this->state = $reflection->newInstanceWithoutConstructor();
		}
	}

	private function execute(Event $original, string $operation, array $input): Generator
	{
		$replyTo = $this->getReplyTo($original);

		$taskDispatcher = null;
		yield static function ($task) use (&$taskDispatcher) {
			$taskDispatcher = $task;
		};

		$context = new EntityContext(
			$this->id->toEntityId(),
			$operation,
			$input,
			$this->state,
			$this,
			$taskDispatcher,
			$replyTo,
			$original->eventId
		);

		if (is_object($this->state)) {
			$reflector = new ReflectionClass($this->state);
			$properties = $reflector->getProperties();
			foreach ($properties as $property) {
				$type = $property->getType();
				if ($type instanceof \ReflectionNamedType && $type->getName() === EntityContextInterface::class) {
					$property->setValue($this->state, $context);
				}
			}
			try {
				$result = $this->state->$operation(...$input);
			} catch (Unwind) {
				return;
			}
		} elseif (is_callable($this->name)) {
			try {
				$result = ($this->name)($context);
			} catch (Unwind) {
				return;
			}
		}

		if ($replyTo) {
			foreach ($replyTo as $reply) {
				yield WithOrchestration::forInstance($reply, TaskCompleted::forId($original->eventId, $result ?? null));
			}
		}
	}

	private function finalize(Event $event): Generator
	{
		$this->history[$event->eventId] = $this->debugHistory ? $event : true;
		$this->status = $this->status->with(lastUpdated: MonotonicClock::current()->now());

		yield null;
	}

	public function applyTaskCompleted(TaskCompleted $event, Event $original): \Generator
	{
		if ($this->queueIfLocked($original)) {
			return;
		}
		$this->init();

		yield from $this->finalize($event);
	}

	public function applyTaskFailed(TaskFailed $event, Event $original): \Generator
	{
		if ($this->queueIfLocked($original)) {
			return;
		}
		$this->init();

		yield from $this->finalize($event);
	}

	public function applyAwaitResult(AwaitResult $event, Event $original): \Generator
	{
		if ($this->queueIfLocked($original)) {
			return;
		}
		yield from $this->finalize($event);
	}
}