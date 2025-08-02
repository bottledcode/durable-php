<?php

namespace Bottledcode\DurablePhp\State\Attributes;

use Attribute;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;

#[Attribute(Attribute::IS_REPEATABLE)]
class AllowAnyOperation implements AccessControl
{
    public function __construct(
        public ?string $fromType = null,
        public EntityId|OrchestrationInstance|null $fromId = null,
        public ?string $fromUser = null,
        public ?string $fromRole = null,
    ) {}
}
