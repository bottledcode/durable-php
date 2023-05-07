<?php

namespace Bottledcode\DurablePhp\OrchestrationService;

use Bottledcode\DurablePhp\Events\PartitionEvents\PartitionUpdateEvent;
use Bottledcode\DurablePhp\Infrastructure\Interfaces\PartitionErrorHandlerInterface;
use Bottledcode\DurablePhp\Infrastructure\TaskHubParameters;
use Withinboredom\Time\Milliseconds;
use Withinboredom\Time\ReadableConverterInterface;
use Withinboredom\Time\Seconds;

use function Withinboredom\Time\Seconds;

class Partition implements PartitionInterface {
	private Seconds $initialTime;

	public function __construct() {
		$this->initialTime = new Seconds(microtime(true));
	}

	public function getPartitionId(): int
	{
		// TODO: Implement getPartitionId() method.
	}

	public function createOrRestore(
		PartitionErrorHandlerInterface $partitionErrorHandler,
		TaskHubParameters $parameters
	): int {
		// TODO: Implement createOrRestore() method.
	}

	public function stop(bool $quickly): void
	{
		// TODO: Implement stop() method.
	}

	public function submitEvent(PartitionUpdateEvent ...$events): void
	{
		// TODO: Implement submitEvent() method.
	}

	public function getErrorHandler(): PartitionErrorHandlerInterface
	{
		// TODO: Implement getErrorHandler() method.
	}

	public function getCurrentTime(): Milliseconds
	{
		return new Milliseconds(seconds(microtime(true) - $this->initialTime->inSeconds())->inMilliseconds());
	}
}
