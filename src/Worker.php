<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;

require_once __DIR__ . '/../vendor/autoload.php';

abstract class Worker
{
    public static function errorHandler(): void
    {
    }

    abstract public function run(Channel $commander);
}

set_error_handler(Worker::errorHandler(...));
