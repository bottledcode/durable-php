<?php

namespace Bottledcode\DurablePhp;

use Amp\Parallel\Worker\Environment;
use Redis;
use RedisCluster;

abstract class Worker implements \Amp\Parallel\Worker\Task
{
	private $timesCollectedGarbage = 0;

	public function __construct(protected Config $config)
	{
	}

	public static function errorHandler(...$props): void
	{
		echo 'Error: ' . json_encode($props) . PHP_EOL;
		die(1);
	}

	abstract public function run(Environment $environment): void;

	protected function heartbeat(Redis|RedisCluster $redis, string $name): void
	{
		$redis->set(sprintf('partition_%d:%s:heartbeat', $this->config->currentPartition, $name), time());
	}

	protected function collectGarbage(): bool
	{
		if ($this->timesCollectedGarbage++ % 100 === 0) {
			gc_collect_cycles();
			return true;
		}

		return false;
	}
}
