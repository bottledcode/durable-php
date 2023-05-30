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

use Bottledcode\DurablePhp\State\OrchestrationInstance;
use DateTimeInterface;
use Psr\Http\Message\RequestInterface;
use Ramsey\Uuid\UuidInterface;

interface OrchestrationContextInterface
{
	/**
	 * @template T
	 * @param string $name
	 * @param array $args
	 * @param RetryOptions|null $retryOptions
	 * @return T
	 */
	public function callActivity(string $name, array $args = [], RetryOptions|null $retryOptions = null): mixed;

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
