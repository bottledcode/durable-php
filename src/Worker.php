<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;

abstract class Worker
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

    abstract public function run(Channel $commander);

    protected function heartbeat(\Redis|\RedisCluster $redis, string $name): void
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
