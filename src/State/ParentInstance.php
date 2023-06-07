<?php

namespace Bottledcode\DurablePhp\State;

readonly class ParentInstance
{
    public function __construct(
        public string $name,
        public int $taskScheduledId,
        public int $version,
        public OrchestrationInstance|null $parentInstance = null,
    ) {
    }
}
