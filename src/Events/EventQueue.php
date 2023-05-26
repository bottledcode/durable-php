<?php

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

	private function addKey(string $key): void
	{
		if (in_array($key, $this->usedKeys, true)) {
			return;
		}
		++$this->usedKeys[$key];
		$this->keys->enqueue($key);
	}

	public function enqueue(string $key, Event $event): void
	{
		$this->addKey($key);
		$this->queues[$key] = new SplQueue();
		$this->queues[$key]->enqueue($event);
		$this->size++;
	}
}
