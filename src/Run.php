<?php

namespace Bottledcode\DurablePhp;

use Amp\Parallel\Context\DefaultContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Bottledcode\DurablePhp\Contexts\LoggingContextFactory;

use function Amp\Parallel\Worker\workerPool;

require_once __DIR__ . '/../vendor/autoload.php';

class Run
{
    public function __construct(private Config $config, private \Redis|\RedisCluster $redis)
    {
    }

    public function __invoke(): void
    {
        $pool = $this->createPool($this->config);
        $this->createPartition();
    }

    private function createPool(Config $config): ContextWorkerPool
    {
        $factory = new ContextWorkerFactory(
            $config->bootstrapPath,
            new LoggingContextFactory(new DefaultContextFactory())
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
}

(static function ($argv) {
    $config = Config::fromArgs($argv);
    $runner = new Run($config);
})(
    $argv
);
