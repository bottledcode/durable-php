<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

class UnionRenderer extends ScalarRenderer
{
    public function __construct(string $graphQlType, public array $unions)
    {
        parent::__construct($graphQlType);
    }

    public function renderType(TypeManager $typeManager): string
    {
        $unions =
            array_map(static fn(string $type) => $typeManager->lookupType($type)?->getGraphQlType(), $this->unions);
        return "union {$this->graphQlType} = " . implode(' | ', $unions);
    }
}
