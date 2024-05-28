<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Crell\Serde\Attributes\SequenceField;

readonly class AstMethod
{
    /**
     * @param string $name
     * @param AstProperty[] $arguments
     * @param AstType $returnType
     * @param array<AstAttribute> $attributes
     */
    public function __construct(
        public string $name,
        #[SequenceField(arrayType: AstProperty::class)]
        public array $arguments,
        public AstType $returnType,
        #[SequenceField(arrayType: AstAttribute::class)]
        public array $attributes,
    ) {}
}
