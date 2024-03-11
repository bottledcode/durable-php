<?php

namespace {{.Name}}\Entities;

use Bottledcode\DurablePhp\EntityContext;
use Bottledcode\DurablePhp\State\EntityState;
use {{.Name}}\Orchestrations\Counter;

class CountState extends EntityState implements CountInterface
{
    public int $count = 0;

    public function countTo(int $number): void
    {
        EntityContext::current()
            ->startNewOrchestration(
                Counter::class,
                ['count' => $number, 'replyTo' => EntityContext::current()->getId()],
                EntityContext::current()->getId()->id
            );
    }

    public function receiveResult(int $result): void
    {
        $this->count = $result;
    }
}
