<?php

namespace Bottledcode\DurablePhp\Gateway\Graph;

class AstType
{
    public function __construct(public array $types, public bool $nullable) {}

    public static function fromArray(array $types): self
    {
        $isNullable = in_array('null', $types, true);
        return new self(array_filter($types, static fn($s) => $s !== 'null'), $isNullable);
    }

    public function getUnionOrType(): Union|string
    {
        if (count($this->types) > 1) {
            return new Union($this->types);
        }

        return $this->types[0];
    }

    public function getUnionOrTypeForInput(): string
    {
        if (count($this->types) > 1) {
            // there simply isn't a way to define an input type that is a union, so we must hope there is a scalar.
            return (new Union($this->types))->name . 'Input';
        }

        return $this->types[0] . 'Input';
    }
}
