<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;

use function Withinboredom\Time\Seconds;

class RedisReader extends Worker
{
    public function __construct(public int $currentPartition = 0, public int $totalPartitions = 1)
    {
    }

    public function run(Channel $commander)
    {
        $redis = self::connect();

        $sender = new Channel(50);
        $commander->send($sender);

        // create the stream if it doesn't exist
        $redis->xGroup('CREATE', 'partition_' . $this->currentPartition, 'consumer_group', '$', true);

        // read the stream up to now...
        $replay = $redis->xReadGroup(
            'consumer_group',
            'consumer',
            ['partition_' . $this->currentPartition => '0-0'],
            500,
            null
        );
        if (!empty($replay)) {
            $replay = $replay['partition_' . $this->currentPartition];
            Logger::log('replaying ' . count($replay) . ' events');
            foreach ($replay as $eventId => [$event]) {
                if($event === null) {
                    continue;
                }
                $devent = igbinary_unserialize($event);
                $devent->isReplaying = true;
                $devent->eventId = $eventId;
                $sender->send(igbinary_serialize($devent));
                Logger::log('replaying %s event', get_class($devent));
            }
        }

        while (true) {
            // read the stream
            $replay = $redis->xReadGroup(
                'consumer_group',
                'consumer',
                ['partition_' . $this->currentPartition => '>'],
                50,
                seconds(30)->inMilliseconds()
            );

            if (!empty($replay)) {
                // todo: replay the events
            }
        }
    }

    public static function connect(): \Redis|\RedisCluster
    {
        try {
            Logger::log('connecting to redis cluster');
            $redis = new \RedisCluster(
                null,
                ['redis:6379'],
                seconds(10)->inMilliseconds(),
                seconds(10)->inMilliseconds(),
                true
            );
        } catch (\RedisClusterException) {
            Logger::log('connecting to redis single');
            $redis = new \Redis();
            $redis->connect('redis');
        }
        return $redis;
    }
}
