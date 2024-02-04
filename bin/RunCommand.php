<?php
/*
 * Copyright ©2024 Robert Landers
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

namespace Bottledcode;

use Ahc\Cli\Input\Command;
use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\TimeoutCancellation;
use Bottledcode\DurablePhp\Abstractions\ApcuProjector;
use Bottledcode\DurablePhp\Abstractions\ProjectorInterface;
use Bottledcode\DurablePhp\Abstractions\RethinkDbProjector;
use Bottledcode\DurablePhp\Abstractions\Semaphore;
use Bottledcode\DurablePhp\Abstractions\Sources\PartitionCalculator;
use Bottledcode\DurablePhp\Config\ProviderTrait;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInnerEventInterface;
use Bottledcode\DurablePhp\Events\StateTargetInterface;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\WorkerTask;
use Closure;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Exception\ConnectionException;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\TubeName;
use Revolt\EventLoop;
use Throwable;

require_once __DIR__ . '/../vendor/autoload.php';

class RunCommand extends Command
{
    use PartitionCalculator;
    use ProviderTrait;

    private Pheanstalk|null $beanstalkClient = null;
    private ProjectorInterface|null $projector = null;
    private Semaphore|null $semaphore = null;

    private string|null $namespace = null;
    private string|null $partition = null;

    private array $beanstalkConnectionParams = [];

    private ContextWorkerPool $workerPool;

    private int $workerTimeout = 60;
    private string $bootstrap;

    private array $providers;

    public function __construct()
    {
        parent::__construct("run", "Run your application");
        $this->option("--bootstrap", "A file to load before execution", default: 'bootstrap.php')
            ->option("--namespace", "A short name for isolation", default: 'dphp')
            ->option("--beanstalk", "host:port of a beanstalkd server to connect to", default: 'localhost:11300')
            ->option("--max-workers", "maximum number of workers to run", default: "32")
            ->option("--execution-timeout", "maximum amount of time allowed to run code", default: '60')
            ->option(
                "--projector", "the projector to use", default: ApcuProjector::class . "->" . RethinkDbProjector::class
            )
            ->option(
                "--monitor", "what queues to monitor for more fine-grained scaling",
                default: "activities,entities,orchestrations"
            )
            ->onExit($this->exit(...));

        pcntl_signal(SIGINT, $this->exit(...));
    }

    public function execute(
        string $bootstrap, string $namespace, string $beanstalk, string $projector, int $maxWorkers,
        int $executionTimeout, string $monitor
    ): int {
        $this->namespace = $namespace;
        $this->workerTimeout = $executionTimeout;
        $this->bootstrap = $bootstrap;
        $writer = $this->io()->writer();
        $writer->write('Connecting to beanstalkd...');
        $this->beanstalkConnectionParams = [$host, $port] = explode(':', $beanstalk);

        $this->configureBeanstalk($host, $port);
        assert($this->beanstalkClient !== null);

        $writer->green('connected', true);

        $projectors = explode('->', $projector);

        $writer->write("Configuring projectors and semaphore providers")->eol();

        $this->providers = $projectors;

        $this->configureProviders($projectors);

        if (str_contains($monitor, 'activities')) {
            $writer->yellow("Subscribing to activity feed...")->eol();
            $this->beanstalkClient->watch($this->getTubeFor("activities"));
        }

        if (str_contains($monitor, 'entities')) {
            $writer->yellow("Subscribing to entities feed...")->eol();
            $this->beanstalkClient->watch($this->getTubeFor('entities'));
        }

        if (str_contains($monitor, 'orchestrations')) {
            $writer->yellow("Subscribing to orchestration feed...")->eol();
            $this->beanstalkClient->watch($this->getTubeFor('orchestrations'));
        }

        $writer->yellow("starting worker pool with $maxWorkers workers...")->eol();

        $factory = new ContextWorkerFactory($bootstrap, new LoggingContextFactory(new DefaultContextFactory()));
        $this->workerPool = new ContextWorkerPool($maxWorkers, $factory);

        EventLoop::setErrorHandler($this->exit(...));

        EventLoop::repeat(0.001, function () use ($executionTimeout): void {
            try {
                $bEvent = $this->beanstalkClient->reserveWithTimeout(0);
            } catch (ConnectionException $exception) {
                $this->exit($exception->getMessage());
                return;
            }
            if ($bEvent) {
                $this->io()->blue("Processing event id {$bEvent->getId()}")->eol();

                $event = Serializer::deserialize(json_decode($bEvent->getData(), true), Event::class);

                $this->handleEvent($event, $bEvent);

                $this->io()->yellow("done")->eol();
            }
        });

        $writer->blue("Starting processing of events")->eol();

        EventLoop::run();

        return 0;
    }

    private function configureBeanstalk(string $host, int $port): void
    {
        $this->beanstalkClient = Pheanstalk::create($host, $port);
    }

    private function getTubeFor(string $type): TubeName
    {
        static $tubes = [];

        return $tubes[$type] ??= new TubeName("{$this->namespace}_{$type}");
    }

    private function exit(string|Throwable $reason = "exit")
    {
        if ($this->namespace && $this->partition >= 0) {
            $this->writer()->red("releasing locks due to $reason")->eol();

            EventLoop::queue(function () {
                $this->semaphore->signalAll();
                $this->writer()->green("Successfully released locks")->eol();
                exit(1);
            });

            return true;
        }

        if ($reason instanceof Throwable) {
            throw $reason;
        }

        exit(1);
    }

    private function handleEvent(Event $event, JobIdInterface $bEvent): void
    {
        $this->io()->blue("Sending $event to worker")->eol();
        $task = new WorkerTask($this->bootstrap, $event, $this->providers);
        $execution = $this->workerPool->submit($task, new TimeoutCancellation($this->workerTimeout));
        $execution->getFuture()->map($this->handleTaskResult($event, $bEvent));
    }

    private function handleTaskResult(Event $originalEvent, JobIdInterface $bEvent): Closure
    {
        return function (array $result) use ($originalEvent, $bEvent) {
            // mark event as successful
            $this->beanstalkClient->delete($bEvent);

            // dispatch events
            foreach ($result as $event) {
                $queue = $this->getQueueForEvent($event);
                $this->beanstalkClient->useTube($queue);
                $this->beanstalkClient->put($event);
            }
        };
    }

    private function getQueueForEvent(Event $event): TubeName
    {
        $tubes = [
            'entity' => new TubeName('entities'),
            'activity' => new TubeName('activities'),
            'orchestration' => new TubeName('orchestrations'),
        ];

        while ($event instanceof HasInnerEventInterface) {
            if ($event instanceof StateTargetInterface) {
                $state = $event->getTarget();
                return $tubes[explode(':', $state->id)[0]];
            }

            $event = $this->event->getInnerEvent();
        }

        return $tubes['activity'];
    }
}
