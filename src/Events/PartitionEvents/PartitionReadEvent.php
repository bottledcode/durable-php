<?php

namespace Bottledcode\DurablePhp\Events\PartitionEvents;

use Bottledcode\DurablePhp\OrchestrationService\Partition;
use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\IgnoreDataMember;

#[DataContract]
abstract class PartitionReadEvent extends PartitionEvent
{
	#[IgnoreDataMember]
	public object $readTarget;

	#[IgnoreDataMember]
	public object|null $prefetch = null;

	abstract public function onReadComplete(object $target, Partition $partition): void;
}
