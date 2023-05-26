<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\OrchestrationStatus;
use Carbon\Carbon;

interface OrchestrationClientInterface
{
	public function getStatus(OrchestrationInstance $instance): OrchestrationStatus;

	/**
	 * @return array<OrchestrationStatus>
	 */
	public function getStatusAll(): array;

	public function getStatusBy(
		Carbon|null $createdFrom = null,
		Carbon|null $createdTo = null,
		OrchestrationStatus|null ...$status
	): array;

	public function purge(OrchestrationInstance $instance): void;

	public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void;

	public function rewind(OrchestrationInstance $instance): void;

	public function startNew(string $name, array $args = []): OrchestrationInstance;

	public function terminate(OrchestrationInstance $instance, string $reason): void;

	public function waitForCompletion(OrchestrationInstance $instance): void;
}
