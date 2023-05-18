<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\ActivityTransfer;
use parallel\Channel;
use parallel\Events;
use parallel\Future;
use Ramsey\Uuid\Uuid;

use function parallel\run;
use function Withinboredom\Time\Milliseconds;

class Supervisor extends Worker
{
    private Future $redisReader;

    public function maybeRestart(
        Future|null $runtime,
        string $worker,
        int $special,
        Channel $commander,
        Events $events,
        ...$args
    ): Future {
        if ($runtime === null || $runtime->done() || $runtime->cancelled()) {
            if ($runtime?->cancelled() || $runtime?->done()) {
                Logger::log("Worker {$worker} has died, restarting from result: {$runtime->value()}");
            } else {
                //Logger::log("Starting worker {$worker}");
            }

            $runtime = run(static function (string $workerClass, Channel|null $commander, Config $config, $args): void {
                //set_error_handler(Worker::errorHandler(...));
                try {
                    $worker = new $workerClass($config, ...$args);
                    //Logger::log("Worker {$workerClass} started");
                    $worker->run($commander);
                } catch (\Throwable $e) {
                    Logger::log(
                        "Worker %s died with exception: (%s) %s\n%s",
                        $workerClass,
                        get_class($e),
                        $e->getMessage(),
                        $e->getTraceAsString()
                    );
                    throw new \RuntimeException("Worker died", previous: $e);
                }
            }, [$worker, $commander, $this->config, $args]);
            $events->addFuture("{$worker}:{$special}", $runtime);
        }
        return $runtime;
    }

    public function run(Channel|null $commander): never
    {
        $events = new Events();
        $threads = [];
        $redisChannel = new Channel(Channel::Infinite);
        $events->addChannel($redisChannel);
        $redisReader = null;

        $dispatchChannel = new Channel(Channel::Infinite);
        //$events->addChannel($dispatchChannel);
        $dispatcher = null;

        $redisToDispatch = null;

        /**
         * @var Channel[] $taskChannels
         */
        $taskChannels = [];
        /**
         * @var Future[] $taskWorkers
         */
        $taskWorkers = [];

        for ($i = 0; $i < $this->config->totalWorkers; $i++) {
            $taskChannels[$i] = new Channel(Channel::Infinite);
            //$events->addChannel($taskChannels[$i]);
            $taskWorkers[$i] = null;
        }

        $working = [];
        $workQueue = new Channel(Channel::Infinite);
        $queueDepth = 0;
        $nextWorker = 0;

        $workChannel = new Channel(Channel::Infinite);
        $events->addChannel($workChannel);

        //$dispatchToTask = new Pipe('dispatchToTask', $dispatchChannel, ...$taskChannels);

        Logger::log('supervisor starting');
        $redis = RedisReader::connect($this->config);
        $redis->xAdd(
            'partition_0',
            '*',
            [
                igbinary_serialize(
                    new ActivityTransfer(
                        new \DateTimeImmutable(),
                        [
                            ActivityTransfer::activity(
                                new TaskMessage(
                                    new HistoryEvent(Uuid::uuid7(), false, EventType::ExecutionStarted),
                                    null,
                                    'test'
                                ),
                                null
                            )
                        ]
                    )
                )
            ]
        );

        $redisReader = $this->maybeRestart($redisReader ?? null, RedisReader::class, 0, $redisChannel, $events);
        $dispatcher = $this->maybeRestart(
            $dispatcher ?? null,
            EventDispatcher::class,
            0,
            $dispatchChannel,
            $events,
            workChannel: $workChannel
        );
        foreach ($taskChannels as $id => $channel) {
            $taskWorkers[$id] = $this->maybeRestart(
                $taskWorkers[$id] ?? null,
                TaskWorker::class,
                $id,
                $channel,
                $events,
                id: $id,
                dispatchChannel: $dispatchChannel
            );
        }

        while (true) {
            $events->setBlocking(true);
            Logger::log('polling');
            $result = $events->poll();

            if ($result?->object instanceof Channel) {
                $events->addChannel($result?->object);
            }

            switch ($result?->type) {
                case Events\Event\Type::Read:
                    if ($result?->object instanceof Channel && $result?->value instanceof Channel) {
                        Logger::log('received new redis reader');
                        $redisToDispatch?->cancel();
                        $redisToDispatch = new Pipe('redisToDispatch', $result?->value, $dispatchChannel);
                        break;
                    }
                    if ($result?->object === $workChannel) {
                        Logger::log('received message from dispatch channel');

                        if ($queueDepth === 0 && !in_array($nextWorker, $working, true)) {
                            Logger::log('assigned work to %d', $nextWorker);
                            $working[] = $nextWorker;
                            $taskChannels[$nextWorker]->send($result?->value);
                            $nextWorker = ($nextWorker + 1) % $this->config->totalWorkers;
                            break;
                        }

                        Logger::log('putting work on backlog: %d', $queueDepth);
                        $workQueue->send($result?->value);
                        $queueDepth++;
                        break;
                    }

                    if ($result?->object instanceof Future) {
                        // something died!
                        [$className, $id] = explode(':', $result?->source);
                        switch ($className) {
                            case RedisReader::class:
                                $redisReader = $this->maybeRestart(
                                    $result?->object,
                                    $className,
                                    $id,
                                    $redisChannel,
                                    $events
                                );
                                break 2;
                            case EventDispatcher::class:
                                $dispatcher = $this->maybeRestart(
                                    $result?->object,
                                    $className,
                                    $id,
                                    $dispatchChannel,
                                    $events,
                                    workChannel: $workChannel
                                );
                                break 2;
                            case TaskWorker::class:
                                $taskWorkers[$id] = $this->maybeRestart(
                                    $result?->object,
                                    $className,
                                    $id,
                                    $taskChannels[$id],
                                    $events,
                                    id: $id,
                                    dispatchChannel: $dispatchChannel
                                );
                                // check if there is work in the queue
                                $work = $workQueue->recv();
                                if(null !== $work) {
                                    $queueDepth--;
                                    Logger::log('pulling from backlog: %d items remaining', $queueDepth);
                                    $taskChannels[$id]->send($work);
                                } else {
                                    $working = array_filter($working, static fn($v) => $v !== $id);
                                    $nextWorker = (int) $id;
                                }
                                break 2;
                            default:
                                Logger::log('an unknown future was finished');
                                var_dump($result);
                                break 2;
                        }
                    }

                    Logger::log('received unknown message');
                    var_dump($result);
                    static $why = 0;
                    if ($why++ > 10) {
                        die();
                    }
                    break;
                case Events\Event\Type::Write:
                    Logger::log('received message write');
                    break;
                case Events\Event\Type::Close:
                    Logger::log('received message close');
                    break;
                case Events\Event\Type::Cancel:
                    Logger::log('received message cancel');
                    break;
                case Events\Event\Type::Kill:
                    Logger::log('received message kill');
                    break;
                case Events\Event\Type::Error:
                    Logger::log('received message error');
                    if ($result?->value instanceof \Throwable) {
                        Logger::log("%s\n%s", $result?->value->getMessage(), $result?->value->getTraceAsString());
                    }
                    break;
            }

            $this->collectGarbage();
        }
    }
}
