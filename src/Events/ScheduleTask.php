<?php

namespace Bottledcode\DurablePhp\Events;

class ScheduleTask extends Event
{
	public function __construct(
		public string $eventId,
		public string $name,
		public string|null $version = null,
		public string|null $input = null,
	) {
		parent::__construct($eventId);
	}
}
