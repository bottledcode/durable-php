<?php

namespace Bottledcode\DurablePhp\Activity;

use Crell\Serde\Attributes\Field;

readonly class Definition
{
    public function __construct(
        #[Field]
        public string $name,

        #[Field]
        public string $fullName,

        #[Field]
        public ReturnType $returnType
    ) {
    }
}
