<?php

namespace Bottledcode\DurablePhp\State;

use Redis;
use RedisCluster;

interface ApplyStateInterface
{
	public function __invoke(Redis|RedisCluster $redis): array;
}
