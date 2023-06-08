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
        [$target] = $context->getInput();
        $sourceId = "src$target";
        $sourceEntity = new EntityId(Account::class, $sourceId);
        $destinationId = "dst$target";
        $destinationEntity = new EntityId(Account::class, $destinationId);
        $transferAmount = 1000;
        $sourceProxy = $context->createEntityProxy(AccountInterface::class, $sourceEntity);
        $destinationProxy = $context->createEntityProxy(AccountInterface::class, $destinationEntity);
        $forceSuccess = false;
        $lock = $context->lockEntity($sourceEntity, $destinationEntity);
        $sourceBalance = $sourceProxy->get();
        $sourceProxy->add(-$transferAmount);
        $destinationProxy->add($transferAmount);
        $value = $sourceProxy->get();
        $lock->unlock();
        return $value;
    }
}
