<?php

namespace {{.Name}}\Entities;

use Bottledcode\DurablePhp\State\Attributes\AllowCreateAll;

#[AllowCreateAll]
interface CountInterface
{
    public function countTo(int $number): void;
    public function receiveResult(int $result): void;
}
