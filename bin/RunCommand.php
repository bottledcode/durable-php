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
use Amp\Parallel\Worker\Execution;
use Amp\TimeoutCancellation;
use Bottledcode\DurablePhp\Abstractions\BeanstalkEventSource;
use Bottledcode\DurablePhp\Abstractions\EventHandlerInterface;
use Bottledcode\DurablePhp\Abstractions\EventQueueInterface;
use Bottledcode\DurablePhp\Abstractions\ProjectorInterface;
use Bottledcode\DurablePhp\Abstractions\QueueType;
use Bottledcode\DurablePhp\Abstractions\RethinkDbProjector;
use Bottledcode\DurablePhp\Abstractions\Semaphore;
use Bottledcode\DurablePhp\Config\ProviderTrait;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\DurableLogger;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\QueueTask;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\WorkerTask;
use Closure;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\JobId;
use Revolt\EventLoop;
use Throwable;

class RunCommand extends Command
{
    use ProviderTrait;

    private ProjectorInterface|null $projector = null;
    private Semaphore|null $semaphore = null;

    private string|null $namespace = null;

    private array $beanstalkConnectionParams = [];

    private ContextWorkerPool $workerPool;

    private int $workerTimeout = 60;
    private string $bootstrap;

    private array $providers;

    private EventHandlerInterface&EventQueueInterface $beanstalkClient;

    private DurableLogger $logger;

    private string $semaphoreProvider;

    private int $backpressure = 0;
    private int $maxPressure = 0;

    private Execution $q;

    public function __construct()
    {
        parent::__construct("run", "Run your application");
        $this->option("-b|--bootstrap", "A file to load before execution", default: 'bootstrap.php')
            ->option("-n|--namespace", "A short name for isolation", default: 'dphp')
            ->option("--nats", "host:port of a nats server to connect to", default: '127.0.0.1:4222')
            ->option("--max-workers", "maximum number of workers to run", default: "32")
            ->option("--execution-timeout", "maximum amount of time allowed to run code", default: '60')
            ->option("-m|--migrate", "migrate the db", default: true)
            ->option(
                "-p|--projector",
                "the projector to use",
                default: RethinkDbProjector::class
            )
            ->option('-l|--distributed-lock', 'The distributed lock implementation to use', default: RethinkDbProjector::class)
            ->option(
                "--monitor",
                "what queues to monitor for more fine-grained scaling",
                default: "activities,entities,orchestrations"
            )
            ->onExit($this->exit(...));

        pcntl_signal(SIGINT, $this->exit(...));
        $this->logger = new DurableLogger();
    }

    public function execute(
        string $bootstrap,
        string $namespace,
        string $beanstalk,
        string $projector,
        int $maxWorkers,
        int $executionTimeout,
        string $distributedLock,
        string $monitor,
        bool $migrate
    ): int {
        $this->maxPressure = $maxWorkers * 3;
        $this->namespace = $namespace;
        $this->workerTimeout = $executionTimeout;
        $this->bootstrap = $bootstrap;
        $this->logger->debug('Connecting to beanstalkd... ');
        $this->beanstalkConnectionParams = [$host, $port] = explode(':', $beanstalk);

        $this->configureBeanstalk($host, $port);
        assert($this->beanstalkClient !== null);

        $this->logger->debug("Connected to beanstalkd");

        $projectors = explode('->', $projector);

        $this->logger->debug("Configuring projectors and semaphore providers", ['projectors' => $projectors, 'semaphores' => $distributedLock]);

        $this->providers = $projectors;
        $this->semaphoreProvider = $distributedLock;

        $this->configureProviders($projectors, $distributedLock, $migrate);

        if (str_contains($monitor, 'activities')) {
            $this->logger->debug("Subscribing to activity feed...");
            $this->beanstalkClient->subscribe(QueueType::Activities);
        }

        if (str_contains($monitor, 'entities')) {
            $this->logger->debug("Subscribing to entities feed...");
            $this->beanstalkClient->subscribe(QueueType::Entities);
        }

        if (str_contains($monitor, 'orchestrations')) {
            $this->logger->debug("Subscribing to orchestration feed...");
            $this->beanstalkClient->subscribe(QueueType::Orchestrations);
        }

        $this->logger->info("starting worker pool with $maxWorkers workers...");

        $factory = new ContextWorkerFactory($bootstrap, new LoggingContextFactory(new DefaultContextFactory()));
        $this->workerPool = new ContextWorkerPool($maxWorkers + 1, $factory);
        $this->q = $this->workerPool->submit(new QueueTask($host, $port, $namespace, $monitor, $maxWorkers * 12));

        EventLoop::setErrorHandler($this->exit(...));

        $this->logger->alert("Ready");

        /** @var Job $bEvent */
        while($bEvent = $this->q->getChannel()->receive()) {
            $this->logger->info("Processing event", ['bEventId' => $bEvent->getId()]);
            $event = Serializer::deserialize(json_decode($bEvent->getData(), true), Event::class);
            $this->handleEvent($event, $bEvent);
        }

        EventLoop::run();

        return 0;
    }

    private function configureBeanstalk(string $host, int $port): void
    {
        $this->beanstalkClient = new BeanstalkEventSource($host, $port, $this->namespace);
    }

    private function handleEvent(Event $event, JobIdInterface $bEvent): void
    {
        $this->logger->info("Sending to worker", ['event' => $event, 'bEventId' => $bEvent->getId(),
            'idle' => $this->workerPool->getIdleWorkerCount(), 'running' => $this->workerPool->isRunning()]);
        $task = new WorkerTask($this->bootstrap, $event, $this->providers, $this->semaphoreProvider);
        $execution = $this->workerPool->submit($task, new TimeoutCancellation($this->workerTimeout));
        $execution->getFuture()->catch(function ($e) use ($bEvent) {
            $this->logger->error("Unable to process job", ['bEventId' => $bEvent->getId(), 'exception' => $e]);
            $this->q->getChannel()->send(['dead', $bEvent->getId()]);
        })->map($this->handleTaskResult(new JobId($bEvent->getId())));
    }

    private function handleTaskResult(JobIdInterface $bEvent): Closure
    {
        return function (array|null $result) use ($bEvent) {
            // mark event as successful
            $this->logger->info("Acknowledge", ['bEventId' => $bEvent->getId(), 'result' => $result]);
            $this->q->getChannel()->send(['ack', $bEvent->getId()]);
            //$this->beanstalkClient->ack($bEvent);

            $this->logger->info("Firing " . count($result ?? []) . " events");
            // dispatch events
            foreach ($result ?? [] as $event) {
                $this->beanstalkClient->fire($event);
            }
        };
    }

    private function exit(string|Throwable $reason = "exit")
    {
        if ($this->namespace) {
            $this->logger->error("releasing locks", ['reason' => $reason]);

            EventLoop::queue(function () {
                $this->semaphore->signalAll();
                $this->logger->critical("Successfully released locks");
                exit(1);
            });

            return true;
        }

        if ($reason instanceof Throwable) {
            throw $reason;
        }

        exit(1);
    }
}
