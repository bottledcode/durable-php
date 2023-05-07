<?php

namespace Bottledcode\DurablePhp\Infrastructure;

use Bottledcode\DurablePhp\OrchestrationStatus;

class OrchestrationRuntimeState {
    private OrchestrationStatus $status;


    public function __construct(array $events) {

    }
}
