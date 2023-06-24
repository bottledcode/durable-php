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

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\State\EntityHistory;
use Bottledcode\DurablePhp\State\EntityId;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Ids\StateId;
use Bottledcode\DurablePhp\State\OrchestrationInstance;

function getEntityHistory(EntityState|null $withState = null): EntityHistory
{
    static $id = 0;
    $withState ??= new class extends EntityState {
    };
    $entityId = new EntityId('test', $id++);
    $history = new EntityHistory(StateId::fromEntityId($entityId), getConfig());
    $reflector = new \ReflectionClass($history);
    $reflector->getProperty('state')->setValue($history, $withState);
    return $history;
}

it('knows if it has applied an event', function () {
    $history = getEntityHistory();
    processEvent($event = new RaiseEvent('test', 'test', []), $history->applyRaiseEvent(...));
    $this->assertTrue($history->hasAppliedEvent($event));
});

test('acking an event removes it from history', function () {
    $history = getEntityHistory();
    processEvent($event = new RaiseEvent('test', 'test', []), $history->applyRaiseEvent(...));
    $history->ackedEvent($event);
    $this->assertFalse($history->hasAppliedEvent($event));
});

it('processes signals', function () {
    $called = 0;
    $outerCall = function () use (&$called) {
        $called++;
    };
    $history = getEntityHistory(
        new class($outerCall) extends EntityState {
            public function __construct(public $outerCall)
            {
            }

            public function signal()
            {
                ($this->outerCall)();
            }
        }
    );

    processEvent(
        new RaiseEvent('id', '__signal', ['operation' => 'signal', 'input' => []]), $history->applyRaiseEvent(...)
    );

    $this->assertSame(1, $called);
});

it('only processes locked events', function () {
    $called = 0;
    $outerCall = function () use (&$called) {
        $called++;
    };
    $history = getEntityHistory(
        new class($outerCall) extends EntityState {
            public function __construct(public $outerCall)
            {
            }

            public function signal()
            {
                ($this->outerCall)();
            }
        }
    );

    $owner = StateId::fromInstance(new OrchestrationInstance('owner', 'owner'));
    $other = StateId::fromInstance(new OrchestrationInstance('other', 'other'));

    $lockResult = processEvent(
        AwaitResult::forEvent(
            $owner, WithLock::onEntity($owner, RaiseEvent::forLockNotification($owner), $history->id)
        ), $history->applyRaiseEvent(...)
    );
    $this->assertCount(2, $lockResult);

    $result = processEvent(
        WithLock::onEntity($owner, AwaitResult::forEvent($owner, RaiseEvent::forOperation('signal', [])), $history->id),
        $history->applyRaiseEvent(...)
    );

    $otherResult = processEvent(
        $waiting = AwaitResult::forEvent($other, RaiseEvent::forOperation('signal', [])), $history->applyRaiseEvent(...)
    );

    $this->assertSame(1, $called);

    $unlockResult = processEvent(
        WithLock::onEntity(
            $owner, AwaitResult::forEvent($owner, RaiseEvent::forUnlock($owner->id, null, null)), $history->id
        ), $history->applyRaiseEvent(...)
    );

    $this->assertContains($waiting, $unlockResult);
    $this->assertSame(1, $called);
});

it('properly locks in a chain', function () {
    $called = 0;
    $outerCall = function () use (&$called) {
        $called++;
    };
    $history = getEntityHistory(
        new class($outerCall) extends EntityState {
            public function __construct(public $outerCall)
            {
            }

            public function signal()
            {
                ($this->outerCall)();
            }
        }
    );

    $owner = StateId::fromInstance(new OrchestrationInstance('owner', 'owner'));
    $other = StateId::fromInstance(new OrchestrationInstance('other', 'other'));

    $otherEntity = getEntityHistory();

    $firstLock = WithEntity::forInstance(
        $history->id,
        AwaitResult::forEvent(
            $owner,
            WithLock::onEntity(
                $owner,
                RaiseEvent::forLockNotification($owner),
                $otherEntity->id,
                $history->id,
            )
        )
    );

    $actualEvent = WithEntity::forInstance(
        $history->id,
        AwaitResult::forEvent(
            $owner,
            WithLock::onEntity(
                $owner,
                RaiseEvent::forOperation('signal', []),
                $otherEntity->id,
                $history->id,
            )
        )
    );

    // send the first lock notification in the chain
    $firstResult = processEvent($firstLock, $otherEntity->applyRaiseEvent(...));
    $this->assertCount(3, $firstResult);
    $this->assertSame($history->id->id, $firstResult[0]->innerEvent->target->id);

    // send a signal to be run once the lock is complete
    $locked = processEvent($actualEvent, $history->applyRaiseEvent(...));
    $this->assertCount(1, $locked);

    // complete the lock sequence
    $secondResult = processEvent($firstResult[0], $history->applyRaiseEvent(...));
    $this->assertCount(3, $secondResult);
    $this->assertInstanceOf(WithEntity::class, $secondResult[0]);

    // process the actual event earlier
    $finalResult = processEvent($secondResult[0], $history->applyRaiseEvent(...));
    $this->assertSame(1, $called);

    // process the final lock notification
    $finalResult = processEvent($secondResult[1], $otherEntity->applyRaiseEvent(...));
});
