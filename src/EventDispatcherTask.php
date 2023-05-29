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
use Bottledcode\DurablePhp\Abstractions\Sources\Source;
use Bottledcode\DurablePhp\Abstractions\Sources\SourceFactory;
use Bottledcode\DurablePhp\Config\Config;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInstanceInterface;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\Transmutation\Router;

class EventDispatcherTask implements \Amp\Parallel\Worker\Task
{
	use Router;

	private Source $source;

	public function __construct(private Config $config, private Event $event, private MonotonicClock $clock)
	{
	}

	public function run(Channel $channel, Cancellation $cancellation): mixed
	{
		$this->source = SourceFactory::fromConfig($this->config);

		Logger::log("EventDispatcher received event: %s", get_class($this->event));

		// todo: skip event if already processed...

		if ($this->event instanceof HasInstanceInterface) {
			$instance = $this->event->getInstance();
			$state = $this->getState($instance);
		} else {
			throw new \LogicException('not implemented');
		}

		foreach ($this->transmutate($this->event, $state) as $eventOrCallable) {
			if ($eventOrCallable instanceof Event) {
				$this->fire($eventOrCallable);
			} elseif ($eventOrCallable instanceof \Closure) {
				$eventOrCallable($this, $this->source, $this->clock);
			}
		}

		if ($this->event instanceof HasInstanceInterface) {
			$this->updateState($state);
		}

		$this->source->ack($this->event);

		return null;
	}

	public function getState(OrchestrationInstance $instance): OrchestrationHistory
	{
		$rawState = $this->source->get(
			"state:{$instance->instanceId}:{$instance->executionId}",
			OrchestrationHistory::class
		);
		if (empty($rawState)) {
			$state = new OrchestrationHistory();
			$state->instance = $instance;
		}

		return $rawState ?? $state;
	}

	private function fire(Event ...$events): void
	{
		foreach ($events as $event) {
			$this->source->storeEvent($event, false);
		}
	}

	public function updateState(OrchestrationHistory $state): void
	{
		$this->source->put(
			"state:{$state->instance->instanceId}:{$state->instance->executionId}",
			$state
		);
	}
}
