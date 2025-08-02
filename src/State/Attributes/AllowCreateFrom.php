<?php

namespace Bottledcode\DurablePhp\State\Attributes;

use Attribute;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\OrchestrationInstance;
use LogicException;

#[Attribute(Attribute::IS_REPEATABLE)]
class AllowCreateFrom implements AccessControl
{
    /**
     * @template T of EntityState
     *
     * @param  class-string<T>  $type
     */
    public function __construct(public ?string $type = null, public EntityId|OrchestrationInstance|null $id = null)
    {
        if ($type === null && $id === null) {
            throw new LogicException('At least one of type or id must be provided to AllowCreateFrom');
        }
        if ($type !== null && $id !== null) {
            throw new LogicException('Only one of type or id can be provided to AllowCreateFrom');
        }
    }
}
