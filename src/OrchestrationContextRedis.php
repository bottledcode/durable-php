<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use DateTimeInterface;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Ramsey\Collection\DoubleEndedQueueInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class OrchestrationContextRedis implements OrchestrationContextInterface
{
	/**
	 * @var DoubleEndedQueueInterface<Task>
	 */
	private DoubleEndedQueueInterface $taskHistory;

	public function __construct(private OrchestrationInstance $id, private OrchestrationHistory $history)
	{
		$this->taskHistory = clone $this->history->historicalTaskResults;
	}

	public function callActivity(string $name, array $args = [], ?RetryOptions $retryOptions = null): Task
	{
		$task = $this->taskHistory->pollFirst();
		if ($task !== null) {
			return $task;
		}

		throw new LogicException('Not implemented');
	}

	public function callHttp(RequestInterface $request, ?RetryOptions $retryOptions = null): Task
	{
		throw new LogicException('Not implemented');
	}

	public function callSubOrchestrator(
		string $name,
		array $args = [],
		?string $instanceId = null,
		?RetryOptions $retryOptions = null
	): Task {
		throw new LogicException('Not implemented');
	}

	public function continueAsNew(array $args = []): void
	{
		throw new LogicException('Not implemented');
	}

	public function createTimer(DateTimeInterface $fireAt): Task
	{
		throw new LogicException('Not implemented');
	}

	public function getInput(): array
	{
		throw new LogicException('Not implemented');
	}

	public function newGuid(): UuidInterface
	{
		return Uuid::uuid7();
	}

	public function setCustomStatus(string $customStatus): void
	{
		$this->history->tags['customStatus'] = $customStatus;
	}

	public function waitAll(Task ...$tasks): Task
	{
		throw new LogicException('Not implemented');
	}

	public function waitAny(Task ...$tasks): Task
	{
		throw new LogicException('Not implemented');
	}

	public function waitForExternalEvent(string $name): Task
	{
		throw new LogicException('Not implemented');
	}

	public function getCurrentTime(): DateTimeInterface
	{
		return $this->history->now;
	}

	public function getCustomStatus(): string
	{
		return $this->history->tags['customStatus'] ?? throw new LogicException('No custom status set');
	}

	public function getCurrentId(): OrchestrationInstance
	{
		return $this->id;
	}

	public function isReplaying(): bool
	{
		return $this->taskHistory->count() > 0;
	}

	public function getParentId(): OrchestrationInstance
	{
		return $this->history->parentInstance;
	}

	public function willContinueAsNew(): bool
	{
		throw new LogicException('Not implemented');
	}
}
