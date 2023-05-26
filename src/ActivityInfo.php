<?php

namespace Bottledcode\DurablePhp;

use DateTimeInterface;
use Ramsey\Uuid\UuidInterface;

class ActivityInfo
{
	public function __construct(
		public UuidInterface $activityId,
		public TaskMessage $taskMessage,
		public UuidInterface|null $originWorkItemId,
		public DateTimeInterface $issueTime,
		public int $dequeueCount,
		public string $eventId
	) {
	}
}
