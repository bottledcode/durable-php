<?php

namespace Bottledcode\DurablePhp\Abstractions\PartitionState;

use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\DataMember;
use Bottledcode\DurablePhp\Serialization\IgnoreDataMember;

#[DataContract]
abstract class TrackedObject
{
	#[DataMember]
	public int $version = 0;

	#[IgnoreDataMember]
	public TrackedObjectKey $key;
}
