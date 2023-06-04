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

use Amp\Future;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use DateTimeInterface;
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
	public function callActivity(string $name, array $args = [], RetryOptions|null $retryOptions = null): Future;

	public function callSubOrchestrator(
		string $name,
		array $args = [],
		string|null $instanceId = null,
		RetryOptions|null $retryOptions = null
	): Future;

	public function continueAsNew(array $args = []): void;

	public function createTimer(DateTimeInterface $fireAt): Future;

	public function getInput(): array;

	public function newGuid(): UuidInterface;

	public function setCustomStatus(string $customStatus): void;

	public function waitForExternalEvent(string $name): Future;

	public function getCurrentTime(): \DateTimeImmutable;

	public function getCustomStatus(): string;

	public function getCurrentId(): OrchestrationInstance;

	public function isReplaying(): bool;

	public function getParentId(): OrchestrationInstance|null;

	public function willContinueAsNew(): bool;

	public function createInterval(
		int $years = null,
		int $months = null,
		int $weeks = null,
		int $days = null,
		int $hours = null,
		int $minutes = null,
		int $seconds = null,
		int $microseconds = null
	): \DateInterval;

	public function waitAny(Future ...$tasks): Future;

	public function waitAll(Future ...$tasks): Future;

	public function waitOne(Future $task): mixed;
}
