<?php

namespace Bottledcode\DurablePhp\Infrastructure;

use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\DataMember;
use Ramsey\Uuid\UuidInterface;

#[DataContract]
class TaskHubParameters
{
	public function __construct(
		#[DataMember] public string $taskHubName,
		#[DataMember] public UuidInterface $taskHubGuid,
		#[DataMember] public \DateTimeImmutable $creationTimestamp,
		#[DataMember] public string $storageFormat,
		#[DataMember] public int $partitionCount,
	) {
	}
}
