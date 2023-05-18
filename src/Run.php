<?php

namespace Bottledcode\DurablePhp;

require_once __DIR__ . '/../vendor/autoload.php';

use function parallel\bootstrap;

bootstrap(__DIR__ . '/../vendor/autoload.php');
set_error_handler(Worker::errorHandler(...));
(new Supervisor(Config::fromArgs($argv)))->run(null);
