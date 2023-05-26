<?php

namespace Bottledcode\DurablePhp;

use Amp\Future;
use Amp\NullCancellation;
use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\ChannelException;
use Amp\Sync\LocalKeyedSemaphore;
use Amp\Sync\RateLimitingSemaphore;
use Amp\Sync\SemaphoreMutex;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;
use Bottledcode\DurablePhp\Events\Event;
use Bottledcode\DurablePhp\Events\HasInstanceInterface;
use Generator;
use Redis;
use RedisCluster;
use SplQueue;
use Withinboredom\Time\Seconds;

use function Amp\async;
use function Amp\Parallel\Worker\workerPool;

require_once __DIR__ . '/../vendor/autoload.php';

class Run
{
	public function __construct(private Config $config, private Redis|RedisCluster $redis)
	{
	}

	public function __invoke(): void
	{
		$queue = new SplQueue();
		$map = [];

		$pool = $this->createPool($this->config);
		$this->createPartition();
		foreach ($this->replay() as $event) {
			if ($event instanceof HasInstanceInterface) {
				if (isset($map[$event->getInstance()->instanceId])) {
					$queue->enqueue($event);
					continue;
				}
			}
			$w = $pool->submit(new EventDispatcherTask($this->config, $event));
			$w->getChannel()->send('purple');
			$result = $w->getChannel()->receive(new NullCancellation());
		}
		Logger::log('Replay completed');
		foreach ($this->consumeEvents() as $event) {
			if ($event === null) {
				// todo: housekeeping
				continue;
			}

			$pool->submit(new EventDispatcherTask($this->config, $event));
		}
	}

	private function createPool(Config $config): ContextWorkerPool
	{
		$factory = new ContextWorkerFactory(
			$config->bootstrapPath, new LoggingContextFactory(new DefaultContextFactory())
		);
		$pool = new ContextWorkerPool($config->totalWorkers, $factory);
		workerPool($pool);
		return $pool;
	}

	private function createPartition(): void
	{
		$this->redis->xGroup(
			'CREATE',
			$this->getPartitionKey(),
			'consumer_group',
			'$',
			true
		);
	}

	private function getPartitionKey(): string
	{
		return $this->config->partitionKeyPrefix . $this->config->currentPartition;
	}

	private function replay(): Generator
	{
		$lastEventId = '0-0';
		replay:
		$events = $this->readNext($lastEventId, 50, null);
		Logger::log('replaying ' . count($events) . ' events');
		if (empty($events)) {
			return;
		}
		foreach ($events as $eventId => ['event' => $event]) {
			if ($event === null) {
				continue;
			}
			/**
			 * @var Event $devent
			 */
			$devent = igbinary_unserialize($event);
			$lastEventId = $devent->eventId = $eventId;

			Logger::log('replaying %s event', get_class($devent));
			yield $devent;
		}
		goto replay;
	}

	private function readNext(string $from, int $count, Seconds|null $timeout): Future
	{
		return async(function () {
			[$this->getPartitionKey() => $events] = $this->redis->xReadGroup(
				'consumer_group',
				'consumer',
				[$this->getPartitionKey() => $from],
				$count,
				$timeout?->inMilliseconds()
			);
			return $events;
		});
	}

	private function consumeEvents(): Generator
	{
		while (true) {
			$events = $this->readNext('>', 50, new Seconds(30));
			if (empty($events)) {
				continue;
			}
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
			}
		}
	}

	private function doWork(WorkerPool $pool, Event $event): void
	{
		/**
		 * @var array<array-key, Execution> $map
		 */
		static $map = [];
		static $queue = new SplQueue();

		$mapKey = $event->eventId;
		if ($event instanceof HasInstanceInterface) {
			$mapKey = "{$event->getInstance()->instanceId}:{$event->getInstance()->executionId}";
		}

		if (isset($map[$mapKey])) {
			try {
				$channel = $map[$mapKey]->getChannel();
				$channel->send($event);
			} catch (ChannelException) {
				// now try reading back any events that were lost

				unset($map[$mapKey]);
			}
		}

		$map[$mapKey] = $pool->submit(new EventDispatcherTask($this->config, $event));
	}
}

(static function ($argv) {
	$config = Config::fromArgs($argv);
	$runner = new Run($config, RedisReaderTask::connect($config));
	$runner();
})(
	$argv
);
