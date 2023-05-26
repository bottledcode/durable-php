<?php

namespace Bottledcode\DurablePhp\Events;

use Carbon\Carbon;

abstract class Event
{
	public bool $isPlayed;

	public Carbon $timestamp;

	public function __construct(public string $eventId)
	{
		$this->isPlayed = false;
		$this->timestamp = Carbon::now();
	}

	public function eventType(): string
	{
		return static::class;
	}
}
