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
use Amp\Parallel\Worker\TaskFailureError;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventQueue;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Throwable;

use function Amp\async;
use function Amp\delay;
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

        /**
         * @var int[] $lastSent
         */
        $lastSent = [];

        $pool = $this->createPool($this->config);
        foreach ($this->source->getPastEvents() as $event) {
            if ($event === null) {
                continue;
            }
            $key = $this->getEventKey($event);
            $map[$key] = null;
            $queue->enqueue($key, $event);
        }
        Logger::log('Replay completed');

        $cancellation = new DeferredCancellation();
        $queue->setCancellation($cancellation);

        $eventSource = async(function () use ($queue, &$cancellation, &$map, $pool) {
            $batch = $this->config->totalWorkers;
            foreach ($this->source->receiveEvents() as $event) {
                $queue->enqueue($this->getEventKey($event), $event);
                Logger::event('Received event: ' . $event);
                Logger::always(
                    'Queue size[%d] complete[%d] idle[%d/%d]', $queue->getSize(), count(
                        array_filter(array_map(static fn(Execution $e) => $e->getFuture()->isComplete(), $map ?? []))
                    ),
                    $pool->getIdleWorkerCount(),
                    $pool->getWorkerCount()
                );
                if ($queue->getSize() === 1 && !$cancellation->isCancelled()) {
                    // if this is the first event, we need to wake up the main loop
                    // so that it can process the event
                    // this is because the main loop is waiting for an event to be
                    // added to the queue
                    $cancellation->cancel();
                }
                if($batch-- <= 0) {
                    $batch = $this->config->totalWorkers;
                    delay(0);
                }
                if($cancellation->isCancelled()) {
                    delay(0);
                }
            }

            throw new \LogicException('The event source should never end');
        });

        $timeout = $this->config->workerTimeoutSeconds - $this->config->workerGracePeriodSeconds;

        $removeExecution = function (Execution $execution) use (&$map) {
            $first = count($map);
            $map = array_filter($map ?? [], static fn(Execution $e) => $e !== $execution);
            $second = count($map);
            Logger::always('Removed execution? %s', $first !== $second ? 'true' : 'false');
        };

        startOver:
        // if there is a queue, we need to process it first
        if ($queue->getSize() > 0) {
            // attempt to get the next event from the queue
            $event = $queue->getNext([]);
            if ($event === null) {
                Logger::always('No event in queue');
                // there currently are not any events that we can get from the queue
                // so we need to wait for an event or a worker to finish
                goto waitForEvents;
            }

            $execution = $map[$this->getEventKey($event)] ?? null;

            if ($execution === null) {
                // we have an event, so we need to dispatch it
                $map[$this->getEventKey($event)] = $pool->submit(
                    new EventDispatcherTask($this->config, $event, $clock)
                );
                Logger::always('New Execution');
            } else {
                Logger::always('Reusing execution');
                $execution->getChannel()->send($event);
            }
            $lastSent[$this->getEventKey($event)] = time();

            // process the queue
            goto startOver;
        }

        waitForEvents:
        $futures = array_map(static fn(Execution $e) => $e->getFuture()->finally(fn() => $removeExecution($e)), $map);
        try {
            try {
                $event = awaitFirst([$eventSource, ...$futures], $cancellation->getCancellation());
                unset($map[$this->getEventKey($event)]);
                Logger::always('Deleted execution? %s', isset($map[$this->getEventKey($event)]) ? 'true' : 'false');
                goto startOver;
            } catch (CancelledException) {
                $cancellation = new DeferredCancellation();
                $queue->setCancellation($cancellation);
                Logger::always('Cancelled');
                goto startOver;
            } catch (TaskFailureError $e) {
                // a worker failed ... we can retry the event, maybe.
                // but first, we need to remove the execution from the map
                foreach ($map as $key => $execution) {
                    if ($execution->getFuture()->isComplete()) {
                        unset($map[$key]);
                    }
                }
                Logger::error('A worker failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            } catch (Throwable $e) {
                // shit hit the fan in a worker.
                var_dump(get_class($e));
                throw $e;
            }


            // handle the case where events were sent but there are still events
            // in the channel
            //$execution = $map[$this->getEventKey($event)];
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

            // process the queue
            goto startOver;
        } catch (Throwable $e) {
            Logger::error(
                "An error occurred while waiting for an event to complete: %s\n%s", $e->getMessage(),
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
