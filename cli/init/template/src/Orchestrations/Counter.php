<?php

namespace {{.Name}}\Orchestrations;

use Bottledcode\DurablePhp\OrchestrationContextInterface;
use {{.Name}}\Activities\AddOne;
use {{.Name}}\Entities\CountInterface;
use Bottledcode\DurablePhp\State\Attributes\AllowCreateAll;

#[AllowCreateAll]
class Counter
{
    public function __invoke(OrchestrationContextInterface $context): void
    {
        $input = $context->getInput();

        if($input['replyTo'] ?? null) {
            // only we will be allowed to communicate with the entity
            $lock = $context->lockEntity($input['replyTo']);
        }

        for ($i = 0, $sum = 0; $i < $input['count']; $i++) {
            $resultFuture = $context->callActivity(AddOne::class, [$sum]);
            $sum = $context->waitOne($resultFuture);
        }

        if($input['replyTo'] ?? null) {
            $context->entityOp($input['replyTo'], fn(CountInterface $entity) => $entity->receiveResult($sum));
        }
        $context->setCustomStatus($sum);
        if ($lock ?? null) {
            $lock->unlock();
        }
    }
}
