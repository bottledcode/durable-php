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
use Amp\NullCancellation;
use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventQueue;
use Bottledcode\DurablePhp\Events\HasInstanceInterface;
use Generator;
use Redis;
use RedisCluster;
use SplQueue;
use Withinboredom\Time\Seconds;

use function Amp\async;
use function Amp\Parallel\Worker\workerPool;

require_once __DIR__ . '/../vendor/autoload.php';

class Run
{
	public function __construct(private Config $config, private Redis|RedisCluster $redis)
	{
	}

	public function __invoke(): void
	{
		$queue = new SplQueue();
		$map = [];

		$pool = $this->createPool($this->config);
		$this->createPartition();
		foreach ($this->replay() as $event) {
			if ($event instanceof HasInstanceInterface) {
				if (isset($map[$event->getInstance()->instanceId])) {
					$queue->enqueue($event);
					continue;
				}
			}
			$w = $pool->submit(new EventDispatcherTask($this->config, $event));
			$w->getChannel()->send('purple');
			$result = $w->getChannel()->receive(new NullCancellation());
		}
		Logger::log('Replay completed');
		foreach ($this->consumeEvents() as $event) {
			if ($event === null) {
				// todo: housekeeping
				continue;
			}

			$pool->submit(new EventDispatcherTask($this->config, $event));
		}
	}

	private function createPool(Config $config): ContextWorkerPool
	{
		$factory = new ContextWorkerFactory(
			$config->bootstrapPath, new LoggingContextFactory(new DefaultContextFactory())
		);
		$pool = new ContextWorkerPool($config->totalWorkers, $factory);
		workerPool($pool);
		return $pool;
	}

	private function createPartition(): void
	{
		$this->redis->xGroup(
			'CREATE',
			$this->getPartitionKey(),
			'consumer_group',
			'$',
			true
		);
	}

	private function getPartitionKey(): string
	{
		return $this->config->partitionKeyPrefix . $this->config->currentPartition;
	}

	private function replay(): Generator
	{
		$lastEventId = '0-0';
		replay:
		$events = $this->readNext($lastEventId, 50, null);
		Logger::log('replaying ' . count($events) . ' events');
		if (empty($events)) {
			return;
		}
		foreach ($events as $eventId => ['event' => $event]) {
			if ($event === null) {
				continue;
			}
			/**
			 * @var Event $devent
			 */
			$devent = igbinary_unserialize($event);
			$lastEventId = $devent->eventId = $eventId;

			Logger::log('replaying %s event', get_class($devent));
			yield $devent;
		}
		goto replay;
	}

	private function readNext(string $from, int $count, Seconds|null $timeout): Future
	{
		return async(function () {
			[$this->getPartitionKey() => $events] = $this->redis->xReadGroup(
				'consumer_group',
				'consumer',
				[$this->getPartitionKey() => $from],
				$count,
				$timeout?->inMilliseconds()
			);
			return $events;
		});
	}

	private function consumeEvents(): Generator
	{
		while (true) {
			$events = $this->readNext('>', 50, new Seconds(30));
			if (empty($events)) {
				continue;
			}
			foreach ($events as $eventId => ['event' => $event]) {
				if ($event === null) {
					continue;
				}
				/**
				 * @var Event $devent
				 */
				$devent = igbinary_unserialize($event);
				$devent->eventId = $eventId;
				yield $devent;
			}
		}
	}

	private function doWork(WorkerPool $pool, Event $event): void
	{
		static $queue = new EventQueue();
		/**
		 * @var Execution[] $map
		 */
		static $map = [];

		// mark instances as complete
		foreach ($map as $key => $execution) {
			if ($execution->getFuture()->isComplete()) {
				unset($map[$key]);
			}
		}

		$key = $event->eventId;
		if ($event instanceof HasInstanceInterface) {
			$key = $event->getInstance()->instanceId . ':' . $event->getInstance()->executionId;
		}

		// try to drain the queue
		while ($queuedEvent = $queue->getNext(array_keys($map))) {
			$map[$key] = $pool->submit(new EventDispatcherTask($this->config, $queuedEvent));
		}

		// check the map for this key
		if (array_key_exists($key, $map) || $queue->hasKey($key)) {
			// we are currently processing this instance, so we cannot process this now
			$queue->enqueue($key, $event);
			return;
		}

		// we are not processing this instance, so we can process this now
		$map[$key] = $pool->submit(new EventDispatcherTask($this->config, $event));
	}
}

(static function ($argv) {
	$config = Config::fromArgs($argv);
	$runner = new Run($config, RedisReaderTask::connect($config));
	$runner();
})(
	$argv
);
