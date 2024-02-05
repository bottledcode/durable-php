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

namespace Bottledcode\DurablePhp\Tests\Unit;

use Bottledcode\DurablePhp\OrchestrationContext;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;

test('multilock example', function () {
    $instance = getOrchestration('test', function (OrchestrationContext $context) {
        $lock = $context->lockEntity(new EntityId('test', 'test'));
        expect($lock->isLocked())->toBeTrue();
        $result = $context->callEntity(new EntityId('test', 'test'), 'test');
        $result = $context->waitOne($result);
        $lock->unlock();
        expect($lock->isLocked())->toBeFalse();
        return $result;
    }, [], $nextEvent);
    $entity = getEntityHistory(new class () extends EntityState {
        public function test()
        {
            return 'hello world';
        }
    });

    $result = processEvent($nextEvent, $instance->applyStartOrchestration(...));
    $instance->resetState();
    expect($result)->toHaveCount(1);
    $result = processEvent($result[0], $entity->applyRaiseEvent(...));
    $entity->resetState();
    expect($result)->toHaveCount(2);
    $result = processEvent($result[0], $instance->applyTaskCompleted(...));
    $instance->resetState();
    expect($result)->toHaveCount(1);
    $result = processEvent($result[0], $entity->applyRaiseEvent(...));
    $entity->resetState();
    expect($result)->toHaveCount(1);
    $result = processEvent($result[0], $instance->applyTaskCompleted(...));
    $instance->resetState();
    expect($result)->toHaveCount(1);
    $result = processEvent($result[0], $entity->applyRaiseEvent(...));
    expect($result)->toHaveCount(1);
});
