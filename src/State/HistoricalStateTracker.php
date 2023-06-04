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

use Amp\DeferredFuture;
use Amp\Future;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Crell\Serde\Attributes\Field;
use Crell\Serde\SerdeCommon;

class HistoricalStateTracker
{
	public function __construct(
		#[Field(exclude: true)]
		private \WeakMap|null $eventSlots = null,
		#[Field(exclude: true)]
		private int|null $readKey = null,
		private array $waiters = [],
		private array $results = [],
	) {
	}

	/**
	 * Begin tracking an event and it's future.
	 *
	 * @param string $identity
	 * @param string $eventId
	 * @param DeferredFuture $future
	 * @return void
	 */
	public function sentEvent(string $identity, string $eventId, DeferredFuture $future): void
	{
		$this->getSlots()[$future->getFuture()] = [
			'eventId' => $eventId,
			'identity' => $identity,
			'handler' => $future
		];
	}

	private function getSlots(): \WeakMap
	{
		return $this->eventSlots ??= new \WeakMap();
	}

	public function hasSentIdentity(string $identity): bool
	{
		return isset($this->results[$identity]);
	}

	public function trackIdentity(string $identity, DeferredFuture $future): void
	{
		$this->getSlots()[$future->getFuture()] = ['identity' => $identity, 'handler' => $future];
	}

	/**
	 * Assign a result to an awaiting slot.
	 *
	 * @param TaskCompleted|TaskFailed $event
	 * @return void
	 */
	public function receivedEvent(TaskCompleted|TaskFailed|RaiseEvent $event): void
	{
		$id = $event->scheduledId ?? $event->eventId;
		// get the identity of the event and delete it from the results
		$identities = array_column($this->results[$id] ?? [], 'identity');
		// if we have no identity, we are not waiting for this event
		if (empty($identities)) {
			// this happens when an event is raised but the executing code has not yet awaited it...
			// this should only be when an event is raised.
			if ($event instanceof RaiseEvent) {
				$identity = sha1($event->eventName);
				$this->results[$identity][] = ['result' => $event];
			}
			return;
		}
		unset($this->results[$id]);

		// get the waiter for this identity
		foreach ($identities as $identity) {
			$waiterKeys = array_column($this->results[$identity], 'key');
			foreach ($waiterKeys as $key) {
				// add the result to the waiter
				$this->waiters[$key][] = ['result' => $event, 'identity' => $identity];
			}
		}
	}

	/**
	 * Match waiting futures to event slots.
	 *
	 * @param Future ...$futures
	 * @return void
	 * @throws \Exception
	 */
	public function awaitingFutures(Future ...$futures): array
	{
		// determine if we're writing or reading
		$writeKey = count($this->waiters);
		// if the write key === read key, we are writing
		// if the write key > read key, we are reading
		// if the write key < read key, we are in an error condition
		if ($writeKey === $this->getReadKey()) {
			// we are writing
			$this->writeFutures($futures);
			$writeKey += 1;
		}

		// we can go ahead and try reading as well
		if ($writeKey > $this->getReadKey()) {
			// we are reading
			return $this->readFutures($futures);
		}

		// we are in an error condition
		throw new \Exception('Invalid state');
	}

	private function getReadKey(): int
	{
		return $this->readKey ??= 0;
	}

	/**
	 * @param array<Future> $futures
	 * @return void
	 */
	private function writeFutures(array $futures): void
	{
		$currentKey = count($this->waiters);
		foreach ($futures as $future) {
			$this->waiters[$currentKey][] = array_intersect_key(
				$this->getSlots()[$future] ?? [],
				array_flip(['eventId', 'identity'])
			);
			$meta = $this->getSlots()[$future];
			$this->results[$meta['identity']][] = ['key' => $currentKey];
			$this->results[$meta['eventId']][] = ['identity' => $meta['identity']];
		}
	}

	/**
	 * @param array<Future> $futures
	 * @return array
	 */
	private function readFutures(array $futures): array
	{
		$currentKey = $this->getReadKey();

		$this->readKey = $currentKey + 1;

		$identToFutures = [];

		$completedInOrder = [];


		// go through the futures
		foreach ($futures as $future) {
			$meta = $this->getSlots()[$future];
			// look up the identity and verify it matches the current read slot
			$identity = $meta['identity'];
			$results = $this->results[$identity] ?? null;
			$allowedKeys = array_column($results ?? [], 'key');
			if (!empty($allowedKeys) && !in_array($currentKey, $allowedKeys, true)) {
				throw new \LogicException('Detected order change in historical state.');
			}
			// check if we received results before now
			$previousResults = array_column($results ?? [], 'result');
			foreach ($previousResults as $result) {
				if (is_array($result)) {
					$serde = new SerdeCommon();
					$result = $serde->deserialize($result, 'array', Event::class);
				}
				$this->waiters[$currentKey][] = ['result' => $result, 'identity' => $identity];
			}
			$identToFutures[$identity][] = $meta['handler'];
		}

		// find the matching event slots
		$waiter = $this->waiters[$currentKey];
		// get just the results, in reverse order
		$results = array_reverse(array_column($waiter, 'result', 'identity'));

		foreach ($results as $identity => $result) {
			/**
			 * @var DeferredFuture $handler
			 */
			foreach ($identToFutures[$identity] ?? [] as $handler) {
				switch (true) {
					case $result instanceof TaskCompleted:
						$handler?->complete($result->result);
						$completedInOrder[] = $handler?->getFuture();
						break;
					case $result instanceof TaskFailed:
						$handler?->error(
							$result->previous ? new ($result->previous)(
								$result->reason . $result->details
							) : new \RuntimeException($result->reason . $result->details)
						);
						$completedInOrder[] = $handler?->getFuture();
						break;
					case $result instanceof RaiseEvent:
						$handler?->complete($result->eventData);
						$completedInOrder[] = $handler?->getFuture();
						break;
					default:
						throw new \LogicException('Invalid result type');
				}
			}
		}

		return $completedInOrder;
	}

	public function isReading(): bool
	{
		return count($this->waiters) > $this->getReadKey();
	}
}
