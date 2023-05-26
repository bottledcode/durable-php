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
		$redis = RedisReader::connect($this->config);
		Logger::log('Task worker %d waiting for work', $this->id);

		$work = $commander?->recv();
		Logger::log('[%d] Received work', $this->id);
		$work = igbinary_unserialize($work);

		$events = $work($redis);

		foreach ($events as $event) {
			$this->dispatchChannel->send(igbinary_serialize($event));
		}

		$redis->xAck('partition_0', 'consumer_group', [$work->eventId]);
		Logger::log('[%d] acked event %s', $this->id, $work->eventId);
	}
}
