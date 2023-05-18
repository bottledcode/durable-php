<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;

class TaskWorker extends Worker
{
    public function __construct(Config $config, private int $id, private Channel $dispatchChannel)
    {
        parent::__construct($config);
    }

    public function run(Channel|null $commander): void
    {
        Logger::log('Task worker %d waiting for work', $this->id);

        $work = $commander?->recv();;
        Logger::log('[%d] Received work', $this->id);
        /**
         * @var ActivityInfo $work
         */
        $work = igbinary_unserialize($work);

        Logger::log('[%d] doing pretend work', $this->id);
        sleep(10);
        Logger::log('[%d] done with pretend work', $this->id);

        $redis = RedisReader::connect($this->config);
        $redis->xAck('partition_0', 'consumer_group', [$work->eventId]);
        Logger::log('[%d] acked event %s', $this->id, $work->eventId);
    }
}
