<?php

namespace Bottledcode\DurablePhp\Abstractions\PartitionState;

enum TrackedObjectType: int
{
	case Activities = 0;
	case Dedupe = 1;
	case Outbox = 2;
	case Reassembly = 3;
	case Sessions = 4;
	case Timers = 5;
	case Prefetch = 6;
	case Queries = 7;
	case Stats = 8;

	case History = 100;
	case Instance = 101;

	public function isSingleton(): bool
	{
		return $this->value < self::History->value;
	}
}
