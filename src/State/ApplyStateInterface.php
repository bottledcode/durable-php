<?php

namespace Bottledcode\DurablePhp\State;

interface ApplyStateInterface {
    public function __invoke(\Redis|\RedisCluster $redis): array;
}
