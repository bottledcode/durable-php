<?php

namespace {{.Name}}\Orchestrations;

use Bottledcode\DurablePhp\OrchestrationContext;
use Bottledcode\DurablePhp\State\Attributes\EntryPoint;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateAll;

#[AllowCreateAll]
class Password
{
    public function __construct(private OrchestrationContext $context) {}

    #[EntryPoint]
    public function check(string $password): bool
    {
        $now = $this->context->getCurrentTime()->add($this->context->createInterval(minutes: 5));

        $timeout = $this->context->createTimer($now);
        $entry = $this->context->waitForExternalEvent('password');

        if($this->context->waitAny($entry, $timeout) === $entry && $entry->getResult() === ['password' => $password]) {
            $this->context->setCustomStatus('success!');
            return true;
        }

        return false;
    }
}
