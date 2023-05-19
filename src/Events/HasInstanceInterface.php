<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\OrchestrationInstance;

interface HasInstanceInterface
{
    public function getInstance(): OrchestrationInstance;
}
