<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

interface SchemaRendererInterface
{
    public function renderType(TypeManager $typeManager): string;

    public function renderInputType(TypeManager $typeManager): string;

    public function renderMutations(TypeManager $typeManager): array;

    public function renderQueries(TypeManager $typeManager): array;

    public function getGraphQlType(bool $forInput = false, bool $nullable = false): string;

    public function isHidden(): bool;
}
