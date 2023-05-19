<?php

namespace Bottledcode\DurablePhp;

class HelloSequence
{
    public function __invoke(OrchestrationContextInterface $context, string $name): void
    {
        $context->setCustomStatus("Hello {$name}!");
        $hello = $context->callActivity('strlen', [$name]);
        $wait = $context->createTimer(new \DateTimeImmutable('+5 seconds'));
        $winner = $context->waitAny($hello, $wait);

        if ($winner === $hello) {
            $context->setCustomStatus('hello!');
        } else {
            $context->setCustomStatus('timeout!');
        }
    }
}
