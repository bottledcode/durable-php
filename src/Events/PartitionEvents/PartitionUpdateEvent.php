<?php

namespace Bottledcode\DurablePhp\Events\PartitionEvents;

use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\IgnoreDataMember;

#[DataContract]
class PartitionUpdateEvent extends PartitionEvent
{
	#[IgnoreDataMember]
	public int $nextCommitLogPosition;
}
