<?php

namespace Bottledcode\DurablePhp;

class HelloSequence
{
    public function __invoke(OrchestrationContextInterface $context, string $name): array
    {
        Logger::log("HelloSequence: %s", $name);
        return [];
    }
}
