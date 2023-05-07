<?php

namespace Bottledcode\DurablePhp\OrchestrationService;

use Bottledcode\DurablePhp\Events\PartitionEvents\PartitionEvent;
use Bottledcode\DurablePhp\Events\PartitionEvents\PartitionUpdateEvent;
use Bottledcode\DurablePhp\Infrastructure\Interfaces\PartitionErrorHandlerInterface;
use Bottledcode\DurablePhp\Infrastructure\TaskHubParameters;
use Withinboredom\Time\Milliseconds;

interface PartitionInterface {

	public function getPartitionId(): int;
	public function createOrRestore(PartitionErrorHandlerInterface $partitionErrorHandler, TaskHubParameters $parameters): int;

	public function stop(bool $quickly): void;

	public function submitEvent(PartitionUpdateEvent ...$events): void;

	public function getErrorHandler(): PartitionErrorHandlerInterface;

	public function getCurrentTime(): Milliseconds;
}
