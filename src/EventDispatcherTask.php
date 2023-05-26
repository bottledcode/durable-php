<?php

namespace Bottledcode\DurablePhp;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Amp\Sync\StaticKeyMutex;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInstanceInterface;
use Bottledcode\DurablePhp\State\OrchestrationHistory;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Bottledcode\DurablePhp\Transmutation\Router;
use Redis;
use RedisCluster;

class EventDispatcherTask implements \Amp\Parallel\Worker\Task
{
	use Router;

	private Redis|RedisCluster $redis;

	public function __construct(private Config $config, private Event $event)
	{
	}

	public function run(Channel $channel, Cancellation $cancellation): mixed
	{
		$this->redis = RedisReaderTask::connect($this->config);

		Logger::log("EventDispatcher received event: %s", get_class($this->event));

		$state = null;
		if ($this->event instanceof HasInstanceInterface) {
			$lock = new StaticKeyMutex()
            $state = $this->getState($this->event->getInstance());
            if ($state->lastAppliedEvent) {
				$currentEventId = explode('-', $this->event->eventId);
				$lastAppliedEventId = explode('-', $state->lastAppliedEvent);
				if ($currentEventId[0] < $lastAppliedEventId[0] || ($currentEventId[0] === $lastAppliedEventId[0] && $currentEventId[1] <= $lastAppliedEventId[1])) {
					$this->ack($this->event);
					return null;
				}
			}
        }

		$events = $this->transmutate($this->event, $state);

		if ($state !== null) {
			$state->lastAppliedEvent = $this->event->eventId;
			$this->updateState($state);
		}

		$this->fire(...$events);

		return null;
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

	private function ack(Event $event): void
	{
		$this->redis->xack($this->getPartitionKey(), 'consumer_group', [$event->eventId]);
	}

	private function getPartitionKey(): string
	{
		return $this->config->partitionKeyPrefix . $this->config->currentPartition;
	}

	public function updateState(OrchestrationHistory $state): void
	{
		$this->redis->set(
			"state:{$state->instance->instanceId}:{$state->instance->executionId}",
			igbinary_serialize($state)
		);
	}

	private function fire(Event ...$events): void
	{
		foreach ($events as $event) {
			$this->redis->xAdd($this->getPartitionKey(), '*', ['event' => igbinary_serialize($event)]);
		}
	}
}
