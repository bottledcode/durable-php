<?php

namespace Bottledcode\DurablePhp\Events;

use Throwable;

class TaskFailed extends Event
{
	public function __construct(
		public string $eventId,
		public string $taskScheduledId,
		public string|null $reason,
		public string|null $details = null,
		public Throwable|null $previous = null,
	) {
		parent::__construct($eventId);
	}
}
