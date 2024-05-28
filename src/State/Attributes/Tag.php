<?php

namespace Bottledcode\DurablePhp\State\Attributes;

use LogicException;

abstract class Tag
{
    public function __construct(public string|null $name = null, public bool $hidden = false)
    {
        if ($name === null) {
            return;
        }
        $this->name = trim($name);

        if (empty($this->name)) {
            throw new LogicException('Orchestration name must not be empty');
        }
    }
}
