<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\ActivityTransfer;
use parallel\Channel;
use Ramsey\Uuid\Uuid;

class EventDispatcher extends Worker
{
    private \RedisCluster|\Redis $redis;

    public function __construct()
    {
        $this->redis = RedisReader::connect();
    }

    public function ackEvent(string $eventId): void
    {
        $this->redis->xAck('partition_0', 'consumer_group', [$eventId]);
    }

    public function run(Channel|null $commander): void
    {
        while (true) {
            $event = $commander->recv();
            $event = igbinary_unserialize($event);
            if ($event === null) {
                break;
            }

            Logger::log("EventDispatcher received event: %s", get_class($event));

            switch (true) {
                case $event instanceof ActivityTransfer:
                    foreach ($event->activities as [$message, $origin]) {
                        $activityInfo = new ActivityInfo(
                            Uuid::uuid7(),
                            $message,
                            $origin,
                            $event->timestamp,
                            0,
                            $event->eventId
                        );

                        // todo: maybe send to remotes

                        if (!$event->isReplaying) {
                            Logger::log('Sending activity to worker');
                            $commander->send(igbinary_serialize($activityInfo));
                        }
                    }
                    $this->ackEvent($event->eventId);
                    Logger::log('Activity acked');
                    break;
            }
        }
    }
}
