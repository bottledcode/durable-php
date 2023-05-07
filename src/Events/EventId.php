<?php

namespace Bottledcode\DurablePhp\Events;

use Ramsey\Uuid\UuidInterface;

readonly class EventId implements \Stringable
{
	/**
	 * @param EventCategory $category The category of the event
	 * @param UuidInterface|null $clientId The client id if originating from a client
	 * @param int|null $number The sequence number if originating from a client
	 * @param int|null $partitionId The partition id if originating from a partition
	 * @param int|null $subIndex The sub-index if originating from a partition
	 * @param string|null $workItemId The work item id if originating from a partition
	 * @param int|null $index The fragment number or index
	 */
	private function __construct(
		public EventCategory $category,
		public UuidInterface|null $clientId = null,
		public int|null $number = null,
		public int|null $partitionId = null,
		public int|null $subIndex = null,
		public string|null $workItemId = null,
		public int|null $index = null
	) {
	}

	public static function makeClientRequestEventId(UuidInterface $clientId, int $requestId): self
	{
		return new self(EventCategory::ClientRequest, $clientId, $requestId);
	}

	public static function makeClientResponseId(UuidInterface $clientId, int $requestId): self
	{
		return new self(EventCategory::ClientResponse, $clientId, $requestId);
	}

	public static function makeLoadMonitorEventId(UuidInterface $requestId): self
	{
		return new self(EventCategory::ToLoadMonitor, workItemId: $requestId->toString());
	}

	public static function makePartitionInternalEventId(string $workItemId): self {
		return new self(EventCategory::PartitionInternal, workItemId: $workItemId);
	}

	public static function makePartitionToPartitionEventId(string $workItemId, int $partitionId): self {
		return new self(EventCategory::PartitionToPartition, partitionId: $partitionId, workItemId: $workItemId);
	}

	public static function makeLoadMonitorToPartitionEventId(UuidInterface $requestId, int $destinationPartition): self {
		return new self(EventCategory::LoadMonitorToPartition, partitionId: $destinationPartition, workItemId: $requestId->toString());
	}

	public static function makeSubEventId(EventId $id, int $fragment): self {
		return new self($id->category, $id->clientId, $id->number, $id->partitionId, $fragment, $id->workItemId);
	}

	public function __toString(): string
	{
		return match($this->category) {
			EventCategory::ClientRequest =>
				"{$this->clientId}R{$this->number}{$this->indexSuffix()}",
			EventCategory::ClientResponse =>
				"{$this->clientId}R{$this->number}R{$this->indexSuffix()}",
			EventCategory::ToLoadMonitor,
			EventCategory::PartitionInternal =>
				"{$this->workItemId}{$this->indexSuffix()}",
			EventCategory::PartitionToPartition, EventCategory::LoadMonitorToPartition =>
				"{$this->workItemId}P{$this->partitionId}{$this->indexSuffix()}"
		};
	}

	public function indexSuffix(): string {
		return $this->index === null ? "" : "I{$this->index}";
	}
}
