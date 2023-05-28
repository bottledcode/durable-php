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

use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInstanceInterface;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\Transmutation\Router;
use parallel\Channel;
use Redis;
use RedisCluster;

class EventDispatcher extends Worker
{
	use Router;

	private Redis|RedisCluster $redis;

	public function __construct(Config $config, private Channel $workChannel)
	{
		parent::__construct($config);
	}

	public function setState(OrchestrationHistory $state): void
	{
		$this->redis->set(
			"state:{$state->instance->instanceId}:{$state->instance->executionId}",
			igbinary_serialize($state)
		);
	}

	public function run(Channel|null $commander): void
	{
		$this->redis = RedisReader::connect($this->config);
		while (true) {
			$event = $commander?->recv();
			$this->heartbeat($this->redis, 'dispatcher');
			/**
			 * @var Event $event
			 */
			$event = igbinary_unserialize($event);
			if ($event === null) {
				break;
			}

			Logger::log("EventDispatcher received event: %s", get_class($event));
			$state = null;
			if ($event instanceof HasInstanceInterface) {
				$state = $this->getState($event->getInstance());
			}

			$events = $this->transmutate($event, $state);
			array_walk($events, fn($event) => $this->workChannel->send(igbinary_serialize($event)));

			$this->collectGarbage();
		}
	}

	public function getState(OrchestrationInstance $instance): OrchestrationHistory
	{
		$rawState = $this->redis->get("state:{$instance->instanceId}:{$instance->executionId}");
		if (empty($rawState)) {
			$state = new OrchestrationHistory();
			$state->instance = $instance;
		} else {
			$state = igbinary_unserialize($rawState);
		}

		return $state;
	}
}
