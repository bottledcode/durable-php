<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\OrchestrationInstance;

class EventQueue
{
	private array $queue = [];
	private array $order = [];
	private bool $dirty = false;

	public function insert(Event $event): void
	{
		$this->queue[$this->getKey($event)][] = $event;
		$this->dirty = true;
	}

	private function getKey(Event $event): string
	{
		if ($event instanceof HasInstanceInterface) {
			return "{$event->getInstance()->instanceId}:{$event->getInstance()->executionId}";
		}

		return $event->eventId;
	}

	public function setHeat(OrchestrationInstance ...$instance): void
	{
	}

	public function getNext(): Event|null
	{
	}
}
