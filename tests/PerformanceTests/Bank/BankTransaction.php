<?php

/*
 * Copyright ©2023 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Bottledcode\DurablePhp\Tests\PerformanceTests\Bank;

use Bottledcode\DurablePhp\OrchestrationContextInterface;
use Bottledcode\DurablePhp\State\EntityId;

class BankTransaction
{
    public function __invoke(OrchestrationContextInterface $context)
    {
        // get the seed for the bank id
        [$target] = $context->getInput();

        // generate the source account
        $sourceId = "src$target";
        $sourceEntity = new EntityId(Account::class, $sourceId);

        // generate the destination account
        $destinationId = "dst$target";
        $destinationEntity = new EntityId(Account::class, $destinationId);
        $feeId = "fee$target";
        $feeEntity = new EntityId(Account::class, $feeId);

        // the amount to transfer
        $transferAmount = 1000;
        $fee = 5;

        // generate proxies for the source and destination accounts
        $sourceProxy = $context->createEntityProxy(AccountInterface::class, $sourceEntity);
        $destinationProxy = $context->createEntityProxy(AccountInterface::class, $destinationEntity);
        $feeCollector = $context->createEntityProxy(AccountInterface::class, $feeEntity);

        // enter critical section
        $lock = $context->lockEntity($sourceEntity, $destinationEntity, $feeEntity);

        //$sourceBalance = $sourceProxy->get();
        $sourceProxy->add(-$transferAmount);
        $destinationProxy->add($transferAmount);
        $feeCollector->add($fee);
        $value = $sourceProxy->get();
        $feeBalance = $feeCollector->get();

        // exit critical section
        $lock->unlock();
        return compact('value', 'feeBalance');
    }
}
