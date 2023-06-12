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
use Amp\Sync\ChannelException;
use Amp\Sync\LocalMutex;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventQueue;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Revolt\EventLoop;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Parallel\Worker\workerPool;

require_once __DIR__ . '/../vendor/autoload.php';

class Run
{
    private readonly Source $source;

    private EventQueue $queue;

    /**
     * @var array<Execution> $map
     */
    private array $map = [];

    private ContextWorkerPool $pool;

    public function __construct(private Config $config)
    {
        $this->source = SourceFactory::fromConfig($config);
    }

    private function processQueue(): void
    {
        $mutex = new LocalMutex();
        $lock = $mutex->acquire();

        try {
            while ($this->queue->getSize() > 0) {
                if ($this->pool->getWorkerCount() - $this->pool->getIdleWorkerCount() === $this->pool->getLimit()) {
                    Logger::log('Worker pool is full, waiting for a worker to become available');
                    $lock->release();
                    return;
                }

                $next = $this->queue->getNext(array_keys($this->map));
                if ($next === null) {
                    break;
                }
                $execution = $this->map[$this->getEventKey($next)] ?? null;
                if ($execution === null) {
                    $key = $this->getEventKey($next);
                    $execution = $this->map[$key] = $this->pool->submit(new EventDispatcherTask($this->config, $next, MonotonicClock::current()));
                    $execution->getFuture()->map(function (Event $event) use ($key) {
                        Logger::event('Event processed: %s', $event);
                        while (true) {
                            try {
                                $prepend = $this->map[$key]->getChannel()->receive();
                                $this->queue->prefix($key, $prepend);
                            } catch (ChannelException) {
                                break;
                            }
                        }
                    })->catch(function (Throwable $e) {
                        Logger::error('Error in event dispatcher: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    })->finally(function () use ($key) {
                        unset($this->map[$key]);
                        if (isset($this->map[$key])) {
                            Logger::error('Key still exists');
                        }
                        $this->processQueue();
                    });
                } else {
                    $execution->getChannel()->send($next);
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function __invoke(): void
    {
        EventLoop::onSignal(SIGTERM, static fn() => die());
        EventLoop::repeat(1, function () {
            Logger::always(
                'Queue size[%d] complete[%d] idle[%d/%d/%d]',
                $this->queue->getSize(),
                count(
                    array_filter(array_map(static fn(Execution $e) => $e->getFuture()->isComplete(), $this->map ?? []))
                ),
                $this->pool->getIdleWorkerCount(),
                $this->pool->getWorkerCount(),
                $this->pool->getLimit()
            );
        });

        MonotonicClock::current();

        $this->queue = new EventQueue();

        $this->pool = $this->createPool($this->config);
        foreach ($this->source->getPastEvents() as $event) {
            if ($event === null) {
                continue;
            }
            $key = $this->getEventKey($event);
            $this->map[$key] = null;
            $this->queue->enqueue($key, $event);
        }
        Logger::log('Replay completed');

        async(function () {
            foreach ($this->source->receiveEvents() as $event) {
                $this->queue->enqueue($this->getEventKey($event), $event);
                $this->processQueue();
                Logger::log('Waiting for next event');
                delay(0);
            }

            throw new \LogicException('The event source should never end');
        })->await();
    }

    private function createPool(Config $config): ContextWorkerPool
    {
        $factory = new ContextWorkerFactory(
            $config->bootstrapPath,
            new LoggingContextFactory(new DefaultContextFactory())
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
}(static function ($argv) {
    $config = Config::fromArgs($argv);
    $runner = new Run($config);
    $runner();
})(
    $argv
);
