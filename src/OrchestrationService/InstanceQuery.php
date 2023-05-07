<?php

namespace Bottledcode\DurablePhp\OrchestrationService;

use Bottledcode\DurablePhp\Infrastructure\OrchestrationRuntimeState;
use Bottledcode\DurablePhp\OrchestrationStatus;
use Bottledcode\DurablePhp\Serialization\DataContract;
use Bottledcode\DurablePhp\Serialization\DataMember;
use DateTimeInterface;

#[DataContract]
class InstanceQuery
{
	/**
	 * @param array<OrchestrationStatus>|null $runtimeStatus The runtime status of the orchestrations to return
	 * @param DateTimeInterface|null $createdTimeFrom The start time of the query range
	 * @param DateTimeInterface|null $createdTimeTo The end time of the query range
	 * @param string|null $instanceIdPrefix The prefix of the instance IDs to return
	 * @param bool $fetchInput Whether to fetch the input of the orchestrations
	 * @param bool $prefetchHistory Whether to prefetch the history of the orchestrations
	 */
	public function __construct(
		#[DataMember] public array|null $runtimeStatus = null,
		#[DataMember] public DateTimeInterface|null $createdTimeFrom = null,
		#[DataMember] public DateTimeInterface|null $createdTimeTo = null,
		#[DataMember] public string|null $instanceIdPrefix = null,
		#[DataMember] public bool $fetchInput = false,
		#[DataMember] public bool $prefetchHistory = false,
	) {
	}

	public function isSet(): bool
	{
		return $this->hasRuntimeStatus() || !empty(
			trim(
				$this->instanceIdPrefix
			)
			) || $this->createdTimeTo !== null || $this->createdTimeFrom !== null;
	}

	public function hasRuntimeStatus(): bool
	{
		return empty($this->runtimeStatus);
	}

	public function matches(OrchestrationRuntimeState $state): bool
	{
		// todo: implement
		return false;
	}
}
