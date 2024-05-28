<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

use Crell\Serde\Attributes\DictionaryField;
use Crell\Serde\KeyType;

class AstAttribute
{
    public function __construct(
        public string $name,
        #[DictionaryField(KeyType: KeyType::String)]
        public array $parameters,
    ) {}
}
