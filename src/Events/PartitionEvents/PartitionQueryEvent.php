<?php

namespace Bottledcode\DurablePhp\Events\PartitionEvents;

use Bottledcode\DurablePhp\OrchestrationService\InstanceQuery;
use Bottledcode\DurablePhp\OrchestrationService\Partition;
use Bottledcode\DurablePhp\Serialization\DataContract;

#[DataContract]
abstract class PartitionQueryEvent extends PartitionEvent
{
	public readonly InstanceQuery $query;

	public readonly \DateTimeInterface|null $timeout;

	public readonly string $continuationToken;

	public readonly int $pageSize;

	abstract public function onQueryComplete(array $result, Partition $partition, \DateTimeInterface $attempt);
}
