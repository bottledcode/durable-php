<?php

namespace Bottledcode\DurablePhp\History;

use Spatie\Cloneable\Cloneable;

abstract readonly class HistoryEvent
{
	use Cloneable;

	public function __construct(
		public int $eventId,
		public \DateTimeInterface $timestamp = new \DateTimeImmutable(),
		public bool $isPlayed = false,
		public string $eventType = ''
	) {
	}
}
