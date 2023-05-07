<?php

namespace Bottledcode\DurablePhp;

use Bottledcode\DurablePhp\Infrastructure\TaskHubParameters;
use parallel\Channel;
use Redis;
use RedisCluster;

use function Withinboredom\Time\Minutes;
use function Withinboredom\Time\Seconds;

class PartitionReader
{
	private Redis|RedisCluster $redis;

	public function __construct(private string $host = 'redis', private int $port = 6379)
	{
	}

	public function connect(): void
	{
		try {
			$redis = new RedisCluster(null, [$this->host . ':' . $this->port], 1, 5, true);
			$redis->setOption(RedisCluster::OPT_SLAVE_FAILOVER, \RedisCluster::FAILOVER_DISTRIBUTE);
		} catch (\RedisClusterException $e) {
			echo "failed to connect to redis cluster, falling back to single node\n";
			$redis = new Redis();
			if (!$redis->connect($this->host, $this->port)) {
				throw new \LogicException('cannot connect to redis');
			}
			$redis->set('foo', 'bar');
			$bar = $redis->get('foo');
			if ($bar !== 'bar') {
				throw new \LogicException('cannot connect to redis');
			}
		} finally {
			$this->redis = $redis;
		}
	}

	public function readPartition(Channel $channel, TaskHubParameters $parameters, int $partition): void
	{
		$partitionKey = $parameters->taskHubName . ':' . $partition;
		$lastId = "0-0";
		$checkBacklog = true;
		echo "Starting partition reader for partition $partitionKey\n";
		$this->redis->xGroup('CREATE', $partitionKey, 'worker', '$', true);
		while (true) {
			$myId = $checkBacklog ? $lastId : '>';
			$items = $this->redis->xReadGroup(
				'worker',
				'worker',
				[$partitionKey => $myId],
				50,
				$checkBacklog ? 0 : seconds(5)->inMilliseconds()
			);
			if (empty($items) && $checkBacklog) {
				echo "No items found to replay\n";
			} elseif (empty($items)) {
				echo "No items, idle\n";
				continue;
			}

			foreach ($items[$partitionKey] as $key => $item) {
				echo "Received item $key\n";
				$channel->send($item);
			}

			$checkBacklog = false;

			// todo: this is not safe, we should check if the item was processed successfully
			$this->redis->xAck($partitionKey, 'worker', array_keys($items[$partitionKey]));
		}
	}
}
