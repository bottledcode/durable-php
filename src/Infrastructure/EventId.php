<?php

namespace Bottledcode\DurablePhp\Infrastructure;

readonly class EventId implements \Stringable
{
    private function __construct(public int $timestamp, public int $sequenceNumber)
    {
    }

    public static function fromString(string $partitionId): self
    {
        $id = explode('-', $partitionId, 2);
        return self::fromArray($id);
    }

    public static function fromArray(array $partitionId): self
    {
        return new self((int)$partitionId[0], (int)$partitionId[1]);
    }

    public function __toString(): string
    {
        return sprintf('%s-%s', $this->timestamp, $this->sequenceNumber);
    }

    public function next(): self
    {
        return new self($this->timestamp, $this->sequenceNumber + 1);
    }
}
