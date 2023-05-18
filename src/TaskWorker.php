<?php

namespace Bottledcode\DurablePhp;

use parallel\Channel;

class TaskWorker extends Worker
{
    public function run(Channel|null $commander): void
    {
    }
}
