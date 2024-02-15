<?php

namespace Bottledcode\DurablePhp\Nats;

use Basis\Nats\Configuration;

class EnvConfiguration extends Configuration
{
    public function __construct()
    {
        parent::__construct([
            'host' => getenv('NATS_HOST') ?: 'localhost',
            'port' => (int) (getenv('NATS_PORT') ?: '4222'),
            'jwt' => getenv('NATS_JWT') ?: null,
            'timeout' => 10,
            'user' => getenv('NATS_USER') ?: null,
            'nkey' => getenv('NATS_NKEY') ?: null,
        ]);
    }
}
