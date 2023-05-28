<?php
/*
 * Copyright ©2023 Robert Landers
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

namespace Bottledcode\DurablePhp;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Redis;
use RedisCluster;
use RedisClusterException;

use function Withinboredom\Time\Minutes;
use function Withinboredom\Time\Seconds;

class RedisReaderTask implements \Amp\Parallel\Worker\Task
{
	use GarbageCollecting;

	public function __construct(private Config $config)
	{
	}

	public function run(Channel $channel, Cancellation $cancellation): mixed
	{
		$redis = self::connect($this->config);

		$redis->xGroup('CREATE', 'partition_' . $this->config->currentPartition, 'consumer_group', '$', true);

		// read the stream up to now...
		$replay = $redis->xReadGroup(
			'consumer_group',
			'consumer',
			['partition_' . $this->config->currentPartition => '0-0'],
			500,
			null
		);
		if (!empty($replay)) {
			$replay = $replay['partition_' . $this->config->currentPartition];
			Logger::log('replaying ' . count($replay) . ' events');
			foreach ($replay as $eventId => ['event' => $event]) {
				if ($event === null) {
					continue;
				}
				/**
				 * @var Event $devent
				 */
				$devent = igbinary_unserialize($event);
				$devent->eventId = $eventId;
				$channel->send($devent);
				Logger::log('replaying %s event', get_class($devent));
			}
		}

		while (true) {
			// read the stream
			$replay = $redis->xReadGroup(
				'consumer_group',
				'consumer',
				['partition_' . $this->config->currentPartition => '>'],
				50,
				seconds(30)->inMilliseconds()
			);

			if (empty($replay)) {
				Logger::log('no events for awhile, doing housekeeping');
				$this->cleanHouse($redis);
				continue;
			}

			$replay = $replay['partition_' . $this->config->currentPartition];
			Logger::log('running ' . count($replay) . ' events');
			foreach ($replay as $eventId => ['event' => $event]) {
				if ($event === null) {
					continue;
				}
				/**
				 * @var Event $devent
				 */
				$devent = igbinary_unserialize($event);
				$devent->eventId = $eventId;
				$channel->send($devent);
				Logger::log('running %s event', get_class($devent));
			}

			if ($this->collectGarbage()) {
				$this->cleanHouse($redis);
			}
		}
	}

	public static function connect(Config $config): Redis|RedisCluster
	{
		try {
			Logger::log('connecting to redis cluster');
			$redis = new RedisCluster(
				null,
				['redis:6379', $config->redisHost . ':' . $config->redisPort],
				minutes(1)->inMilliseconds(),
				minutes(1)->inMilliseconds(),
				true
			);
		} catch (RedisClusterException) {
			Logger::log('connecting to redis single');
			$redis = new Redis();
			$redis->connect(
				$config->redisHost,
				$config->redisPort,
				timeout: minutes(1)->inSeconds(),
			);
		}
		return $redis;
	}

	private function cleanHouse(Redis|RedisCluster $redis)
	{
		$pending = $redis->xPending('partition_' . $this->config->currentPartition, 'consumer_group');
		if ($pending[0] === 0) {
			// trim the stream
			$redis->xTrim('partition_' . $this->config->currentPartition, 50);
		}
	}
}
