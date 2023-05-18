<?php

namespace Bottledcode\DurablePhp\Events;

use Bottledcode\DurablePhp\State\OrchestrationInstance;
use Carbon\Carbon;

class StartExecution extends Event
{
    public function __construct(
        public OrchestrationInstance $instance,
        public OrchestrationInstance|null $parentInstance,
        public string $name,
        public string $version,
        public string $input,
        public array $tags,
        public string $correlation,
        public Carbon $scheduledAt,
        public int $generation,
        public string $eventId,
    ) {
        parent::__construct($eventId);
    }
}
