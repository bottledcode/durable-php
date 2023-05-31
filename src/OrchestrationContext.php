<?php
/*
 * Copyright ©2023 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use DateTimeInterface;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class OrchestrationContext implements OrchestrationContextInterface
{
	private int $currentReplayIteration = 0;

	public function __construct(private OrchestrationInstance $id, private OrchestrationHistory $history)
	{
	}

	public function callActivity(string $name, array $args = [], ?RetryOptions $retryOptions = null): mixed
	{
		return \Fiber::suspend(
			['type' => 'callActivity', 'name' => $name, 'args' => $args, 'retryOptions' => $retryOptions]
		);
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
		return $this->history->input;
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
		return array_key_exists($this->currentReplayIteration, $this->history->historicalTaskResults);
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
