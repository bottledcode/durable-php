<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\ActivityTransfer;
use parallel\Channel;
use parallel\Events;
use parallel\Future;

use Ramsey\Uuid\Uuid;

use function parallel\run;

class Supervisor extends Worker
{
    private Future $redisReader;

    public function __construct()
    {
    }

    public function maybeRestart(Future|null $runtime, string $worker, Channel $commander): Future
    {
        if ($runtime === null || $runtime->done() || $runtime->cancelled()) {
            if ($runtime?->cancelled() || $runtime?->done()) {
                Logger::log("Worker {$worker} has died, restarting from result: {$runtime->value()}");
            } else {
                Logger::log("Starting worker {$worker}");
            }

            $runtime = run(static function (string $workerClass, Channel|null $commander) {
                //set_error_handler(Worker::errorHandler(...));
                $worker = new $workerClass();
                $worker->run($commander);
                Logger::log("Started worker {$worker}");
            }, [$worker, $commander]);
        }
        return $runtime;
    }

    public function run(Channel|null $commander): never
    {
        $redisChannel = new Channel(Channel::Infinite);
        $redisReader = null;

        $dispatchChannel = new Channel(Channel::Infinite);
        $dispatcher = null;

        $redisToDispatch = null;

        $taskChannels = [];
        $taskWorkers = [];

        Logger::log('supervisor starting');
        /*$redis = RedisReader::connect();
        $redis->xAdd(
            'partition_0',
            '*',
            [
                igbinary_serialize(
                    new ActivityTransfer(
                        new \DateTimeImmutable(),
                        [ActivityTransfer::activity(
                            new TaskMessage(
                                new HistoryEvent(Uuid::uuid7(), false, EventType::ExecutionStarted),
                                null,
                                'test'
                            ),
                            null
                        )]
                    )
                )
            ]
        );*/

        while (true) {
            $redisCommander = $this->maybeRestart($redisCommander ?? null, RedisReader::class, $redisChannel);
            $dispatcher = $this->maybeRestart($dispatcher, EventDispatcher::class, $dispatchChannel);

            /*$message = $redisChannel->recv();
            if ($message instanceof Channel) {
                // we received a new task channel
                $redisReader = $message;
                $redisToDispatch?->cancel();
                Logger::log('received new redis reader');
                $redisToDispatch = new Pipe($redisReader, $dispatchChannel);
            }*/

            $events = new Events();
            //$events->addChannel($dispatchChannel);
            $events->addChannel($redisChannel);
            $events->addFuture(RedisReader::class, $redisCommander);
            $events->addFuture(EventDispatcher::class, $dispatcher);
            $events->setBlocking(true);
            Logger::log('polling');
            $result = $events->poll();
            //var_dump($result);
            switch ($result?->type) {
                case Events\Event\Type::Read:
                    if ($result?->object instanceof Channel && $result?->value instanceof Channel) {
                        Logger::log('received new redis reader');
                        $redisReader = $result?->value;
                        $redisToDispatch?->cancel();
                        $redisToDispatch = new Pipe($redisReader, $dispatchChannel);
                        break;
                    }
                    if ($result->object instanceof Channel) {
                        //var_dump($result);
                        Logger::log('received message from dispatch channel');
                        // spawn an activity worker
                        /*if ($message instanceof ActivityInfo) {
                            $taskChannel = new Channel(Channel::Infinite);
                            $taskChannels[$message->activityId->toString()] = new Pipe($dispatchChannel, $taskChannel);
                            $taskWorkers[$message->activityId->toString()] = $this->maybeRestart(
                                $taskWorkers[$message->activityId->toString()] ?? null,
                                TaskWorker::class,
                                $taskChannel
                            );
                            $taskChannel->send($message);
                        }*/
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
                    if($result->value instanceof \Throwable) {
                        Logger::log("%s\n%s", $result->value->getMessage(), $result->value->getTraceAsString());
                    }
                    break;
            }
        }
    }
}
