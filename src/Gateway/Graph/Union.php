<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

class Union
{
    public string $name;

    public function __construct(public array $types)
    {
        sort($this->types);

        $name = array_map(fn($x) => ucfirst($x), $this->types);
        $this->name = implode('Or', $name);
    }
}
