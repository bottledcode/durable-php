<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\OrchestrationInstance;
use DateTimeInterface;
use Psr\Http\Message\RequestInterface;
use Ramsey\Uuid\UuidInterface;

interface OrchestrationContextInterface
{
	public function callActivity(string $name, array $args = [], RetryOptions|null $retryOptions = null): Task;

	public function callHttp(RequestInterface $request, RetryOptions|null $retryOptions = null): Task;

	public function callSubOrchestrator(
		string $name,
		array $args = [],
		string|null $instanceId = null,
		RetryOptions|null $retryOptions = null
	): Task;

	public function continueAsNew(array $args = []): void;

	public function createTimer(DateTimeInterface $fireAt): Task;

	public function getInput(): array;

	public function newGuid(): UuidInterface;

	public function setCustomStatus(string $customStatus): void;

	public function waitAll(Task ...$tasks): Task;

	public function waitAny(Task ...$tasks): Task;

	public function waitForExternalEvent(string $name): Task;

	public function getCurrentTime(): DateTimeInterface;

	public function getCustomStatus(): string;

	public function getCurrentId(): OrchestrationInstance;

	public function isReplaying(): bool;

	public function getParentId(): OrchestrationInstance;

	public function willContinueAsNew(): bool;
}
