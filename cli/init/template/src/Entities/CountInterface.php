<?php

namespace {{.Name}}\Entities;

interface CountInterface
{
    public function countTo(int $number): void;
    public function receiveResult(int $result): void;
}
