<?php

namespace Bottledcode\DurablePhp;

use Carbon\CarbonPeriod;

class RetryOptions
{
    public function __construct(
        public CarbonPeriod $firstRetryInterval,
        public int $maxNumberAttempts,
        public CarbonPeriod $maxRetryInterval = new CarbonPeriod('PT1H'),
        public float $backoffCoefficient = 1.0,
        public CarbonPeriod $retryTimeout = new CarbonPeriod('PT1H'),

    ) {
        if ($this->firstRetryInterval->end < $this->firstRetryInterval->start) {
            throw new \InvalidArgumentException('First retry interval end must be greater than or equal to start.');
        }
    }
}
