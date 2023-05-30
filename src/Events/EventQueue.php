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

namespace Bottledcode\DurablePhp\Events;

use SplQueue;

class EventQueue
{
	/**
	 * @var array<array-key, SplQueue<Event>>
	 */
	private array $queues = [];
	private SplQueue $keys;
	private array $usedKeys = [];
	private int $size = 0;

	public function __construct()
	{
		$this->keys = new SplQueue();
	}

	public function hasKey(string $key): bool
	{
		return isset($this->queues[$key]);
	}

	public function getSize(): int
	{
		return $this->size;
	}

	public function getNext(array $requeueKeys): Event|null
	{
		if ($this->keys->isEmpty()) {
			return null;
		}

		// get the next key
		$nextKey = $this->keys->dequeue();

		// see if it is an allowed key
		if (in_array($nextKey, $requeueKeys, true)) {
			// if not, requeue it
			$this->keys->enqueue($nextKey);
			// we could keep checking, but we want to avoid an infinite loop
			return null;
		}

		// get the next event
		$nextEvent = $this->queues[$nextKey]->dequeue();

		// if the queue is empty, remove it
		if ($this->queues[$nextKey]->isEmpty()) {
			unset($this->queues[$nextKey]);
			$this->removeKey($nextKey);
		}

		$this->size--;

		// return the event
		return $nextEvent;
	}

	public function enqueue(string $key, Event $event): void
	{
		$this->addKey($key);
		if (!isset($this->queues[$key])) {
			$this->queues[$key] = new SplQueue();
		}
		$this->queues[$key]->enqueue($event);
		$this->size++;
	}

	private function addKey(string $key): void
	{
		if (in_array($key, $this->usedKeys, true)) {
			return;
		}
		@$this->usedKeys[$key]++;
		$this->keys->enqueue($key);
	}

	private function removeKey(string $key): void
	{
		if (!in_array($key, $this->usedKeys, true)) {
			return;
		}
		--$this->usedKeys[$key];
		if ($this->usedKeys[$key] === 0) {
			assert(!isset($this->queues[$key]));
			unset($this->usedKeys[$key]);
		}
	}

	public function prefix(string $key, Event ...$event): void
	{
		$this->addKey($key);
		if (!isset($this->queues[$key])) {
			$this->queues[$key] = new SplQueue();
		}
		$event = array_reverse($event);
		foreach ($event as $e) {
			$this->queues[$key]->unshift($e);
		}
		$this->size += count($event);
	}
}
