<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;

abstract class Worker
{
    public static function errorHandler(...$props): void
    {
        echo 'Error: ' . json_encode($props) . PHP_EOL;
        die(1);
    }

    abstract public function run(Channel $commander);
}
