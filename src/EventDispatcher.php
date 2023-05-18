<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Events\ActivityTransfer;
use parallel\Channel;
use Ramsey\Uuid\Uuid;

class EventDispatcher extends Worker
{
    public function __construct(Config $config, private Channel $workChannel)
    {
        parent::__construct($config);
    }

    public function run(Channel|null $commander): void
    {
        $redis = RedisReader::connect($this->config);
        while (true) {
            $event = $commander?->recv();
            $this->heartbeat($redis, 'dispatcher');
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

                        Logger::log('Sending activity to worker');
                        $this->workChannel->send(igbinary_serialize($activityInfo));
                    }
                    break;
            }

            $this->collectGarbage();
        }
    }
}
