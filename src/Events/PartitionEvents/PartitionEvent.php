<?php

namespace Bottledcode\DurablePhp\Events\PartitionEvents;

use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\DataMember;
use Bottledcode\DurablePhp\Serialization\IgnoreDataMember;

#[DataContract]
class PartitionEvent extends Event
{
	#[DataMember]
	public int $partitionId;

	/**
	 * @var int For events coming from the input queue, the next input queue position after this event. For internal events, zero.
	 */
	#[DataMember]
	public int $nextInputQueuePosition;

	#[IgnoreDataMember]
	public \DateTimeInterface|null $receivedTimestamp = null;

	#[IgnoreDataMember]
	public \DateTimeInterface|null $issuedTimestamp = null;

	#[IgnoreDataMember]
	public bool $resetInputQueue = false;

	#[IgnoreDataMember]
	public bool $countsAsPartitionActivity = true;

	#[IgnoreDataMember]
	public string $tracedInstanceId = '';

	public function onSubmit($partition): void {}

	public function __clone(): void
	{
		$this->listeners->clear();
		$this->nextInputQueuePosition = 0;
		$this->issuedTimestamp = null;
	}
}
