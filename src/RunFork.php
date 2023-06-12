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

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamChannel;
use Amp\ByteStream\WritableResourceStream;
use Amp\Future\UnhandledFutureError;
use Amp\Parallel\Ipc\IpcHub;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\Socket\ResourceSocket;
use Amp\Sync\Channel;
use Amp\Sync\LocalMutex;
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\EventQueue;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use LogicException;
use Revolt\EventLoop;
use Throwable;

use function Amp\delay;
use function Amp\Parallel\Ipc\connect;

require_once __DIR__ . '/../vendor/autoload.php';

class RunFork
{
    /**
     * @var array<int, true>
     */
    private array $jobs = [];

    /**
     * @var array<string, int>
     */
    private array $map = [];

    private array $signals = [];

    private IpcHub $hub;

    private EventQueue $queue;

    private Channel $channel;

    private bool $terminating = false;

    private Source $source;

    private int $parentPid;

    public function __construct(private Config $config)
    {
        $this->queue = new EventQueue();
    }

    public function __invoke()
    {
        $this->parentPid = getmypid();
        pcntl_signal(SIGCHLD, $this->childSignalHandler(...));
        EventLoop::onSignal(SIGCHLD, $this->evLoopSignalHandler(...));
        EventLoop::onSignal(SIGTERM, fn() => $this->terminating = true);
        EventLoop::setErrorHandler(function ($error) {
            Logger::error('Uncaught Error: %s', $error);
            if ($error instanceof Throwable) {
                throw $error;
            }
            throw new \RuntimeException($error);
        });
        EventLoop::repeat(0.25, function () {
            $this->childSignalHandler(SIGCHLD);
        });
        //[$thread, $this->channel] = $this->spawnSource();
        $this->source = SourceFactory::fromConfig($this->config, true);

        Logger::always('server started for partition %d', $this->config->currentPartition);

        // receive events from source
        while (true) {
            /**
             * @var Event $event
             */
            try {
                //$event = $this->channel->receive();
            } catch (\TypeError) {
                Logger::error('Receive failed');
            }

            foreach ($this->source->receiveEvents() as $event) {
                if (getmypid() !== $this->parentPid) {
                    Logger::always('Child process %d received event %s', getmypid(), $event);
                }
                if ($this->terminating) {
                    delay(10);
                    die();
                }
                $key = $this->getEventKey($event);

                $this->queue->enqueue($key, $event);

                Logger::log('Queue size: %d, Workers: %d', $this->queue->getSize(), count($this->jobs));

                $this->workFromQueue();

                delay(0);
            }
        }
    }

    public function childSignalHandler($signo, $pid = null, $status = null)
    {
        if (!$pid) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        // get all children
        while ($pid > 0) {
            if ($pid && isset($this->jobs[$pid])) {
                $code = pcntl_wexitstatus($status);
                if ($code !== 0) {
                    Logger::error('Child ' . $pid . ' exited with error code ' . $code);
                    $this->queue->prefix($this->getEventKey($this->jobs[$pid]), $this->jobs[$pid]);
                }

                Logger::log('c: ' . $this->jobs[$pid]->eventId);

                Logger::log('Child %d finished [%d/%d]', $pid, count($this->jobs), $this->config->totalWorkers);

                unset($this->jobs[$pid]);
                $key = array_search($pid, $this->map, true);
                if ($key !== false) {
                    unset($this->map[$key]);
                }
                $this->workFromQueue();
                return true;
            } elseif ($pid) {
                // job finished before we could even add it to the list of jobs.
                Logger::log('Child ' . $pid . ' finished before we knew it was started');
                $this->signals[$pid] = $status;
            }

            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        if ($this->terminating && count($this->jobs)) {
            die();
        }

        return true;
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

    private function workFromQueue(): void
    {
        // critical section
        $mutex = new LocalMutex();
        $lock = $mutex->acquire();

        try {
            while (count($this->jobs) < $this->config->totalWorkers) {
                if ($this->terminating) {
                    return;
                }

                $event = $this->queue->getNext(array_keys($this->map));
                if ($event === null) {
                    Logger::log('No more events');
                    return;
                }

                Logger::log('d: ' . $event->eventId);

            // check if we have a process we can send the event to
                $key = $this->getEventKey($event);
                if (isset($this->map[$key])) {
                    throw new LogicException('not possible in forking mode');
                }

            // spawn worker
                $pid = $this->spawnWorker($event);
                $this->map[$key] = $pid;
            }
        } finally {
            $lock->release();
            Logger::always(
                'Queue size[%d] workers[%d/%d] active[%d]',
                $this->queue->getSize(),
                count($this->jobs),
                $this->config->totalWorkers,
                count($this->map)
            );
        }
    }

    private function spawnWorker(Event $event): int|null
    {
        $result = pcntl_fork();
        if ($result === -1) {
            throw new \RuntimeException('Could not fork');
        }
        if ($result === 0) {
            // we need to prevent the current channel from being read by this process
            /*foreach (EventLoop::getIdentifiers() as $identifier) {
                try {
                    EventLoop::cancel($identifier);
                } catch (EventLoop\InvalidCallbackError) {
                    // ignore
                }
            }*/
            pcntl_unshare(0x00000400);
            EventLoop::setErrorHandler(function ($error) {
                if ($error instanceof UnhandledFutureError) {
                    Logger::log('Unhandled future error: %s', $error->getMessage());
                    return;
                }
                Logger::error('Uncaught Error: %s', $error);
                //posix_kill(getmypid(), SIGKILL);
                exit(1);
            });
            Logger::reset();
            Logger::log('Starting work');

            Logger::log('w: ' . $event->eventId);
            $worker = new EventDispatcherTask($this->config, $event, MonotonicClock::current());
            $worker->runOnce();
            delay($this->config->workerGracePeriodSeconds);
            // and then we are done
            // we cannot simply call die here, because destructors will be called
            // in fact, we have to kill ourselves...
            //posix_kill(getmypid(), SIGKILL);
            exit(0);
        }

        Logger::log('Spawned worker %d', $result);

        $this->jobs[$result] = $event;

        if (isset($this->signals[$result])) {
            // crashed!
            $this->childSignalHandler(SIGCHLD, $result, $this->signals[$result]);
            unset($this->signals[$result]);

            return null;
        }

        return $result;
    }

    public function __destruct()
    {
        echo 'Destructing RunFork: ' . getmypid() . PHP_EOL;
    }

    public function evLoopSignalHandler($id, $signo)
    {
        $this->childSignalHandler($signo);
    }

    private function spawnSource(): array
    {
        $channelSpawners = $this->createChannel();
        $result = pcntl_fork();
        if ($result === -1) {
            throw new \RuntimeException('Could not fork');
        }
        if ($result === 0) {
            $channel = $channelSpawners[1]();
            $source = SourceFactory::fromConfig($this->config);
            foreach ($source->receiveEvents() as $event) {
                Logger::log('Sending event: %s', $event);
                $channel->send($event);
            }
            throw new LogicException('Should not be reached');
        }
        return [$result, $channelSpawners[0]()];
    }

    private function createChannel(): array
    {
        $this->hub = new LocalIpcHub();
        $channelKey = $this->hub->generateKey();
        $uri = $this->hub->getUri();
        return [
            function () use ($channelKey) {
                //$timeout = new TimeoutCancellation(2, 'Timeout waiting for child to start');
                $socket = $this->hub->accept($channelKey);
                return new StreamChannel($socket, $socket);
            }, function () use ($channelKey, $uri) {
                $socket = connect($uri, $channelKey);
                return new StreamChannel($socket, $socket);
            }
        ];
    }

    private function forceCloseChannel(Channel $channel): void
    {
        $reflector = new \ReflectionClass($channel);
        $read = $reflector->getProperty('read');
        $write = $reflector->getProperty('write');
        $this->forceCloseResourceSocket($read->getValue($channel));
        $this->forceCloseResourceSocket($write->getValue($channel));
    }

    private function forceCloseResourceSocket(ResourceSocket $socket): void
    {
        $reflector = new \ReflectionClass($socket);
        $reader = $reflector->getProperty('reader');
        $writer = $reflector->getProperty('writer');
        $this->forceCloseReadableResourceStream($reader->getValue($socket));
        $this->forceCloseReadableResourceStream($writer->getValue($socket));
    }

    private function forceCloseReadableResourceStream(ReadableResourceStream|WritableResourceStream $stream): void
    {
        $reflector = new \ReflectionClass($stream);
        $resource = $reflector->getProperty('resource');
        $resource->setValue($stream, null);
    }
}

(static function ($argv) {
    $config = Config::fromArgs($argv);
    $runner = new RunFork($config);
    $runner();
})(
    $argv
);
