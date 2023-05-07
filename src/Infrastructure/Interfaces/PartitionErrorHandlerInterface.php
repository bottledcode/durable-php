<?php

namespace Bottledcode\DurablePhp\Infrastructure\Interfaces;

use Withinboredom\Time\Seconds;

interface PartitionErrorHandlerInterface
{
	public function onShutdown(callable $callback): void;

	public function isTerminated(): bool;

	public function isNormalTermination(): bool;

	public function waitForTermination(Seconds $timeout): void;

	public function handleError(
		string $where,
		string $message,
		\Throwable|null $exception,
		bool $terminatePartition,
		bool $reportAsWarning
	): void;

	public function terminate(): void;
}
