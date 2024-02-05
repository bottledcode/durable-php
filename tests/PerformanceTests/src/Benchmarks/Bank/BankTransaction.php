<?php

namespace Bottledcode\DurablePhp\Tests\PerformanceTests\src\Benchmarks\Bank;

use Bottledcode\DurablePhp\Attributes\Orchestration;
use Bottledcode\DurablePhp\EntityId;

#[Orchestration]
function BankTransaction($context): bool
{
    $pair = $context->getInput();

    $sourceId = sprintf('src%d-!-%d', $pair, ($pair + 1) % 32);
    $sourceEntity = new EntityId(AccountInterface::class, $sourceId);

    $destinationId = sprintf('dst%d-!%d', $pair, ($pair + 2) % 32);
    $destinationEntity = new EntityId(AccountInterface::class, $destinationId);

    $transferAmount = 1000;
    $sourceProxy = $context->createProxy(AccountInterface::class, $sourceEntity);
    $destinationProxy = $context->createProxy(AccountInterface::class, $destinationEntity);

    $context->lock($sourceEntity, $destinationEntity);

    $sourceBalance = $context->await($sourceProxy->get());
    $context->await($sourceProxy->add(-$transferAmount));
    $context->await($destinationProxy->add($transferAmount));

    return true;
}
