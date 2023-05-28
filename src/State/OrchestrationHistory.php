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

namespace Bottledcode\DurablePhp\State;

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\CompleteExecution;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\Logger;
use Bottledcode\DurablePhp\Task;
use Carbon\Carbon;
use Pleo\BloomFilter\BloomFilter;
use Ramsey\Collection\DoubleEndedQueue;
use Ramsey\Collection\DoubleEndedQueueInterface;

class OrchestrationHistory
{
	public Carbon $now;

	public string $name;

	public string $version;

	public array $tags;

	public OrchestrationInstance $instance;

	public OrchestrationInstance|null $parentInstance;

	public Carbon $createdTime;

	public string $input;

	public DoubleEndedQueueInterface $historicalTaskResults;

	public array $awaiters = [];

	public bool $isCompleted = false;

	public BloomFilter $appliedEvents;

	public function __construct()
	{
		$this->historicalTaskResults = new DoubleEndedQueue(Task::class);
		$this->appliedEvents = BloomFilter::init(10000, 0.001);
	}

	public function applyStartExecution(StartExecution $event): array
	{
		Logger::log("Applying StartExecution event to OrchestrationHistory");
		$this->now = $event->timestamp;
		$this->createdTime = $event->timestamp;
		$this->name = $event->name;
		$this->version = $event->version;
		$this->tags = $event->tags;
		$this->instance = $event->instance;
		$this->parentInstance = $event->parentInstance;
		$this->input = $event->input;

		return [
			new ApplyStateToProjection($event, $this, $event->eventId),
		];
	}

	public function applyCompleteExecution(CompleteExecution $event): array
	{
		Logger::log("Applying CompleteExecution event to OrchestrationHistory");
		foreach ($this->awaiters as $awaiter) {
			//todo
		}

		return [];
	}

	public function applyAwaitResult(AwaitResult $event): array
	{
		if ($this->isCompleted) {
			return [

			];
		}
	}
}
