<?php

namespace Bottledcode\DurablePhp\History;

readonly class ExecutionCompletedEvent extends HistoryEvent
{
	public function __construct(int $eventId)
	{
		parent::__construct($eventId);
	}
}
