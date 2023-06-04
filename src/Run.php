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

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventQueue;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\StateTargetInterface;

use function Amp\async;
use function Amp\Future\awaitFirst;
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
		$clock = MonotonicClock::current();

		$queue = new EventQueue();
		/**
		 * @var Execution[] $map
		 */
		$map = [];

		$pool = $this->createPool($this->config);
		foreach ($this->source->getPastEvents() as $event) {
			$key = $this->getEventKey($event);
			$map[$key] = null;
			$queue->enqueue($key, $event);
		}
		Logger::log('Replay completed');

		$cancellation = new DeferredCancellation();
		$queue->setCancellation($cancellation);

		$eventSource = async(function () use ($queue, &$cancellation) {
			foreach ($this->source->receiveEvents() as $event) {
				$queue->enqueue($this->getEventKey($event), $event);
				if ($queue->getSize() === 1 && !$cancellation->isCancelled()) {
					// if this is the first event, we need to wake up the main loop
					// so that it can process the event
					// this is because the main loop is waiting for an event to be
					// added to the queue
					$cancellation->cancel();
				}
			}

			throw new \LogicException('The event source should never end');
		});

		startOver:
		// if there is a queue, we need to process it first
		if ($queue->getSize() > 0) {
			// attempt to get the next event from the queue
			$event = $queue->getNext(array_keys(array_filter($map)));
			if ($event === null) {
				// there currently are not any events that we can get from the queue
				// so we need to wait for an event or a worker to finish
				goto waitForEvents;
			}
			// we have an event, so we need to dispatch it
			$map[$this->getEventKey($event)] = $pool->submit(new EventDispatcherTask($this->config, $event, $clock));

			// process the queue
			goto startOver;
		}

		waitForEvents:
		$futures = array_map(static fn(Execution $e) => $e->getFuture(), $map);
		try {
			try {
				$event = awaitFirst([$eventSource, ...$futures], $cancellation->getCancellation());
			} catch (CancelledException) {
				$cancellation = new DeferredCancellation();
				$queue->setCancellation($cancellation);
				goto startOver;
			}


			// handle the case where events were sent but there are still events
			// in the channel
			$execution = $map[$this->getEventKey($event)];
			/*$prefix = [];
			while (!$execution->getChannel()->isClosed()) {
				try {
					$prefix[] = $execution->getChannel()->receive(new TimeoutCancellation(1));
				} catch (TimeoutException) {
					Logger::log('Warning: waited for event to prefix!');
				}
			}
			$queue->prefix($this->getEventKey($event), ...$prefix);*/
			// now we can remove the execution from the map
			unset($map[$this->getEventKey($event)]);

			// process the queue
			goto startOver;
		} catch (\Throwable $e) {
			Logger::log(
				"An error occurred while waiting for an event to complete: %s\n%s",
				$e->getMessage(),
				$e->getTraceAsString()
			);
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

	private function getEventKey(Event $event): string
	{
		while ($event instanceof HasInnerEventInterface) {
			if ($event instanceof StateTargetInterface) {
				return $event->getTarget();
			}
			$event = $event->getInnerEvent();
		}

		return $event->eventId;
	}
}

(static function ($argv) {
	$config = Config::fromArgs($argv);
	$runner = new Run($config);
	$runner();
})(
	$argv
);
