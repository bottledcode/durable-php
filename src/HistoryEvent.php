<?php

namespace Bottledcode\DurablePhp;

use Ramsey\Uuid\UuidInterface;

class HistoryEvent
{
    public function __construct(
        public UuidInterface $eventId,
        public bool $isPlayed,
        public EventType $eventType,
        public \DateTimeInterface $timestamp = new \DateTimeImmutable()
    ) {
    }
}
