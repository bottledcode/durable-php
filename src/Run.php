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

use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventQueue;
use Bottledcode\DurablePhp\Events\HasInstanceInterface;
use SplQueue;

use function Amp\Parallel\Worker\workerPool;

require_once __DIR__ . '/../vendor/autoload.php';

class Run
{
	private readonly Source $source;

	public function __construct(private Config $config)
	{
		$this->source = SourceFactory::fromConfig($config);
	}

	public function __invoke(): void
	{
		$queue = new SplQueue();
		$map = [];

		$pool = $this->createPool($this->config);
		foreach ($this->source->getPastEvents() as $event) {
			if ($event instanceof HasInstanceInterface) {
				if (isset($map[$event->getInstance()->instanceId])) {
					$queue->enqueue($event);
					continue;
				}
			}
			$pool->submit(new EventDispatcherTask($this->config, $event));
		}
		Logger::log('Replay completed');
		foreach ($this->source->receiveEvents() as $event) {
			if ($event === null) {
				$this->source->cleanHouse();
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
	$runner = new Run($config);
	$runner();
})(
	$argv
);
