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

namespace Bottledcode\DurablePhp\Abstractions\Sources;

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Logger;
use Generator;
use Redis;
use RedisCluster;
use RedisClusterException;
use Withinboredom\Time\Seconds;

use function Withinboredom\Time\Minutes;
use function Withinboredom\Time\Seconds;

class RedisSource implements Source
{
	use PartitionCalculator;

	public function __construct(
		private Redis|RedisCluster $redis,
		private Config $config,
	) {
	}

	public static function connect(Config $config): static
	{
		try {
			Logger::log('connecting to redis cluster');
			$redis = new RedisCluster(
				null,
				[$config->storageConfig->host . ':' . $config->storageConfig->port],
				minutes(1)->inMilliseconds(),
				minutes(1)->inMilliseconds(),
				true
			);
		} catch (RedisClusterException) {
			Logger::log('connecting to redis single');
			$redis = new Redis();
			$redis->connect(
				$config->storageConfig->host,
				$config->storageConfig->port,
				timeout: minutes(1)->inSeconds(),
			);
		}
		return new self($redis, $config);
	}

	public function getPastEvents(): Generator
	{
		$replay = $this->redis->xReadGroup(
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
				yield $devent;
				Logger::log('replaying %s event', get_class($devent));
			}
		}
	}

	public function receiveEvents(): Generator
	{
		$events = $this->redis->xReadGroup(
			'consumer_group',
			'consumer',
			['partition_' . $this->config->currentPartition => '>'],
			50,
			seconds(30)->inMilliseconds()
		);

		if (empty($events)) {
			Logger::log('no events for awhile, doing housekeeping');
			return;
		}

		$events = $events['partition_' . $this->config->currentPartition];
		Logger::log('running ' . count($events) . ' events');

		foreach ($events as $eventId => ['event' => $event]) {
			if ($event === null) {
				continue;
			}
			/**
			 * @var Event $devent
			 */
			$devent = igbinary_unserialize($event);
			$devent->eventId = $eventId;
			yield $devent;
			Logger::log('running %s event', get_class($devent));
		}
	}

	public function cleanHouse(): void
	{
		$pending = $this->redis->xPending('partition_' . $this->config->currentPartition, 'consumer_group');
		if ($pending[0] === 0) {
			// trim the stream
			$this->redis->xTrim('partition_' . $this->config->currentPartition, 50);
		}
	}

	public function storeEvent(Event $event): void
	{
		$partition = $this->calculateDestinationPartitionFor($event);
		$this->redis->xAdd('partition_' . $partition, '*', ['event' => igbinary_serialize($event)]);
	}

	public function put(string $key, string $data, ?Seconds $ttl = null, ?string $etag = null): void
	{
		$this->redis->set($key, $data, $ttl?->inSeconds());
	}

	public function get(string $key, ?string &$etag = null): string|null
	{
		return $this->redis->get($key);
	}

	public function ack(Event $event): void
	{
		$this->redis->xAck('partition_' . $this->config->currentPartition, 'consumer_group', [$event->eventId]);
	}
}
