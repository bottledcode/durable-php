<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

class ScalarRenderer implements SchemaRendererInterface
{
    public function __construct(
        public string $graphQlType,
        public bool $nullable = true,
        public bool $alwaysNullable = false,
    ) {}

    public function renderType(TypeManager $typeManager): string
    {
        return "scalar {$this->graphQlType}";
    }

    public function renderInputType(TypeManager $typeManager): string
    {
        return '';
    }

    public function renderMutations(TypeManager $typeManager): array
    {
        return [];
    }

    public function renderQueries(TypeManager $typeManager): array
    {
        return [];
    }

    public function getGraphQlType(bool $forInput = false, bool $nullable = false): string
    {
        return $this->graphQlType . ($this->alwaysNullable || ($this->nullable && $nullable) ? '' : '!');
    }

    public function isHidden(): false
    {
        return false;
    }
}
