<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Crell\Serde\Attributes\SequenceField;

readonly class AstProperty
{
    public function __construct(
        public AstType $type,
        public string $name,
        #[SequenceField(arrayType: AstAttribute::class)]
        public array $attributes,
    ) {}
}
