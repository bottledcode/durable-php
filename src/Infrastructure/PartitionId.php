<?php

namespace Bottledcode\DurablePhp\Infrastructure;

readonly class PartitionId implements \Stringable
{
    private function __construct(public EventId $eventId, public int $partitionNumber)
    {
    }

    public static function fromString(string $partitionId): self
    {
        $id = explode('!', $partitionId, 2);
        $id = [EventId::fromString($id[0]), $id[1] ?? '-1'];
        return self::fromArray($id);
    }

    public static function fromArray(array $partitionId): self
    {
        return new self($partitionId[0], $partitionId[1]);
    }

    public function __toString(): string
    {
        return "$this->eventId!$this->partitionNumber";
    }
}
