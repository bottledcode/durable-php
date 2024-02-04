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
use Bottledcode\DurablePhp\Abstractions\BeanstalkEventSource;
use Bottledcode\DurablePhp\Abstractions\EventHandlerInterface;
use Bottledcode\DurablePhp\Abstractions\EventQueueInterface;
use Bottledcode\DurablePhp\Abstractions\ProjectorInterface;
use Bottledcode\DurablePhp\Abstractions\QueueType;
use Bottledcode\DurablePhp\Abstractions\RethinkDbProjector;
use Bottledcode\DurablePhp\Abstractions\Semaphore;
use Bottledcode\DurablePhp\Abstractions\Sources\PartitionCalculator;
use Bottledcode\DurablePhp\Config\ProviderTrait;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\State\Serializer;
use Bottledcode\DurablePhp\WorkerTask;
use Closure;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Exception\ConnectionException;
use Revolt\EventLoop;
use Throwable;

class RunCommand extends Command
{
    use ProviderTrait;

    private ProjectorInterface|null $projector = null;
    private Semaphore|null $semaphore = null;

    private string|null $namespace = null;
    private string|null $partition = null;

    private array $beanstalkConnectionParams = [];

    private ContextWorkerPool $workerPool;

    private int $workerTimeout = 60;
    private string $bootstrap;

    private array $providers;

    private EventHandlerInterface&EventQueueInterface $beanstalkClient;

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
        $this->io()->comment('Connecting to beanstalkd... ');
        $this->beanstalkConnectionParams = [$host, $port] = explode(':', $beanstalk);

        $this->configureBeanstalk($host, $port);
        assert($this->beanstalkClient !== null);

        $this->io()->ok("Connected to beanstalkd", true);

        $projectors = explode('->', $projector);

        $this->io()->comment("Configuring projectors and semaphore providers", true);

        $this->providers = $projectors;

        $this->configureProviders($projectors);

        if (str_contains($monitor, 'activities')) {
            $this->io()->comment("Subscribing to activity feed...")->eol();
            $this->beanstalkClient->subscribe(QueueType::Activities);
        }

        if (str_contains($monitor, 'entities')) {
            $this->io()->comment("Subscribing to entities feed...")->eol();
            $this->beanstalkClient->subscribe(QueueType::Entities);
        }

        if (str_contains($monitor, 'orchestrations')) {
            $this->io()->comment("Subscribing to orchestration feed...")->eol();
            $this->beanstalkClient->subscribe(QueueType::Orchestrations);
        }

        $this->io()->info("starting worker pool with $maxWorkers workers...")->eol();

        $factory = new ContextWorkerFactory($bootstrap, new LoggingContextFactory(new DefaultContextFactory()));
        $this->workerPool = new ContextWorkerPool($maxWorkers, $factory);

        EventLoop::setErrorHandler($this->exit(...));

        EventLoop::repeat(0.001, function () use ($executionTimeout): void {
            try {
                $bEvent = $this->beanstalkClient->getSingleEvent();
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

        $this->io()->comment("Starting processing of events")->eol();

        EventLoop::run();

        return 0;
    }

    private function configureBeanstalk(string $host, int $port): void
    {
        $this->beanstalkClient = new BeanstalkEventSource($host, $port, $this->namespace);
    }

    private function exit(string|Throwable $reason = "exit")
    {
        if ($this->namespace && $this->partition >= 0) {
            $this->io()->error("releasing locks due to $reason")->eol();

            EventLoop::queue(function () {
                $this->semaphore->signalAll();
                $this->io()->ok("Successfully released locks")->eol();
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
        $this->io()->info("Sending $event to worker")->eol();
        $task = new WorkerTask($this->bootstrap, $event, $this->providers);
        $execution = $this->workerPool->submit($task, new TimeoutCancellation($this->workerTimeout));
        $execution->getFuture()->map($this->handleTaskResult($event, $bEvent));
    }

    private function handleTaskResult(Event $originalEvent, JobIdInterface $bEvent): Closure
    {
        return function (array $result) use ($originalEvent, $bEvent) {
            // mark event as successful
            $this->io()->info("Acknowledge")->eol();
            $this->beanstalkClient->ack($bEvent);

            $this->io()->info("Firing " . count($result) . " events")->eol();
            // dispatch events
            foreach ($result as $event) {
                $this->beanstalkClient->fire($event);
            }
        };
    }
}
