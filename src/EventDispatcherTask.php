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
use Bottledcode\DurablePhp\State\ApplyStateToProjection;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\Transmutation\Router;

class EventDispatcherTask implements \Amp\Parallel\Worker\Task
{
	use Router;

	private Source $source;

	public function __construct(private Config $config, private Event $event)
	{
	}

	public function run(Channel $channel, Cancellation $cancellation): mixed
	{
		$this->source = SourceFactory::fromConfig($this->config);

		Logger::log("EventDispatcher received event: %s", get_class($this->event));

		$state = null;
		if ($this->event instanceof HasInstanceInterface) {
			$state = $this->getState($this->event->getInstance());
			if ($state->appliedEvents->exists($this->event->eventId)) {
				// it is a very low probability that we have NOT processed this event before...
				// but it IS possible
				Logger::log(
					"EventDispatcher received event: %s, but it has already been processed",
					get_class($this->event)
				);
				// todo: copy event to a dead-letter queue
				$this->source->ack($this->event);
			}
		}

		$events = $this->transmutate($this->event, $state);

		if ($state !== null) {
			$state->appliedEvents->add($this->event->eventId);
			$this->updateState($state);
		}

		foreach ($events as $event) {
			if ($event instanceof ApplyStateToProjection) {
				$events = $event($this->source);
			}
		}

		$this->fire(...$events);

		return null;
	}

	public function getState(OrchestrationInstance $instance): OrchestrationHistory
	{
		$rawState = $this->source->get("state:{$instance->instanceId}:{$instance->executionId}");
		if (empty($rawState)) {
			$state = new OrchestrationHistory();
			$state->instance = $instance;
		} else {
			$state = igbinary_unserialize($rawState);
		}

		return $state;
	}

	public function updateState(OrchestrationHistory $state): void
	{
		$this->source->put(
			"state:{$state->instance->instanceId}:{$state->instance->executionId}",
			igbinary_serialize($state)
		);
	}

	private function fire(Event ...$events): void
	{
		foreach ($events as $event) {
			$this->source->storeEvent($event);
		}
	}
}
