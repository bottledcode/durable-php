<?php

namespace Bottledcode\DurablePhp\History;

readonly class ContinueAsNewEvent extends ExecutionCompletedEvent
{
	public function __construct(int $eventId, public string $input = '')
	{
		parent::__construct($eventId);
	}
}
