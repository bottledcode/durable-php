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

use Bottledcode\DurablePhp\Abstractions\Sources\PartitionCalculator;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\StartExecution;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\State\OrchestrationStatus;
use Carbon\Carbon;
use Fiber;
use LogicException;
use Ramsey\Uuid\Uuid;
use Withinboredom\Time\ReadableConverterInterface;

final class OrchestrationClient implements OrchestrationClientInterface
{
	use PartitionCalculator;

	private readonly Source $source;

	public function __construct(private readonly Config $config)
	{
		$this->source = SourceFactory::fromConfig($config);
	}

	public function getStatus(OrchestrationInstance $instance): OrchestrationStatus
	{
		throw new LogicException('Not implemented');
	}

	public function getStatusAll(): array
	{
		throw new LogicException('Not implemented');
	}

	public function getStatusBy(
		?Carbon $createdFrom = null,
		?Carbon $createdTo = null,
		?OrchestrationStatus ...$status
	): array {
		throw new LogicException('Not implemented');
	}

	public function purge(OrchestrationInstance $instance): void
	{
		throw new LogicException('Not implemented');
	}

	public function raiseEvent(OrchestrationInstance $instance, string $eventName, array $eventData): void
	{
		throw new LogicException('Not implemented');
	}

	public function rewind(OrchestrationInstance $instance): void
	{
		throw new LogicException('Not implemented');
	}

	public function startNew(string $name, array $args = [], string|null $id = null): OrchestrationInstance
	{
		$instance = $this->getInstanceFor($name);
		if ($id) {
			$instance = new OrchestrationInstance($instance->instanceId, $id);
		}
		$event = new StartExecution(
			$instance, null, $name, '0', $args, [], Uuid::uuid7(), new \DateTimeImmutable(), 0, ''
		);
		$this->postEvent($event);
		return $instance;
	}

	private function getInstanceFor(string $name): OrchestrationInstance
	{
		return new OrchestrationInstance($name, Uuid::uuid7()->toString());
	}

	private function postEvent(Event $event): string
	{
		return $this->source->storeEvent($event, false);
	}

	public function terminate(OrchestrationInstance $instance, string $reason): void
	{
		throw new LogicException('Not implemented');
	}

	public function waitForCompletion(OrchestrationInstance $instance, ReadableConverterInterface $timeout = null): void
	{
		$fiber = new Fiber(function ($channel, $fiber) {
			$this->redis->subscribe([$channel], function () use ($fiber) {
				$fiber->resume();
			});
			Fiber::suspend('waiting');
		});
		$channel = Uuid::uuid7()->toString();

		$this->postEvent(new SubscribeToCompletion($instance, $timeout, $channel));
		$waiting = $fiber->start($channel);
		if ($waiting === null) {
			return;
		}
	}
}
