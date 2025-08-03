<?php

/*
 * Copyright ©2024 Robert Landers
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

// namespace Bottledcode\DurablePhp\Tests\Unit;

use Bottledcode\DurablePhp\Contexts\AuthContext\SecurityException;
use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\RaiseEvent;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\WithEntity;
use Bottledcode\DurablePhp\Events\WithLock;
use Bottledcode\DurablePhp\State\EntityState;
use Bottledcode\DurablePhp\State\Ids\StateId;

use function Bottledcode\DurablePhp\OrchestrationInstance;

it('knows if it has applied an event', function (): void {
    $history = getEntityHistory();
    processEvent($event = new RaiseEvent('test', 'test', []), $history->applyRaiseEvent(...));
    expect($history->hasAppliedEvent($event))->toBeTrue();
});

test('acking an event removes it from history', function (): void {
    $history = getEntityHistory();
    processEvent($event = new RaiseEvent('test', 'test', []), $history->applyRaiseEvent(...));
    $history->ackedEvent($event);
    expect($history->hasAppliedEvent($event))->toBeFalse();
});

it('processes signals', function (): void {
    $called = 0;
    $outerCall = function () use (&$called): void {
        $called++;
    };
    $history = getEntityHistory(
        new class ($outerCall) extends EntityState {
            public function __construct(public $outerCall) {}

            public function signal(): void
            {
                ($this->outerCall)();
            }
        },
    );
    $history->from = StateId::fromInstance(OrchestrationInstance('test', 'test'));

    processEvent(
        new RaiseEvent('id', '__signal', ['operation' => 'signal', 'input' => []]),
        $history->applyRaiseEvent(...),
    );
    expect($called)->toBe(1);
});

it('only processes locked events', function (): void {
    $called = 0;
    $outerCall = function () use (&$called): void {
        $called++;
    };
    $history = getEntityHistory(
        new class ($outerCall) extends EntityState {
            public function __construct(public $outerCall) {}

            public function signal(): void
            {
                ($this->outerCall)();
            }
        },
    );
    $history->from = StateId::fromInstance(OrchestrationInstance('test', 'test'));

    $owner = StateId::fromInstance(OrchestrationInstance('owner', 'owner'));
    $other = StateId::fromInstance(OrchestrationInstance('other', 'other'));

    $lockResult = processEvent(
        AwaitResult::forEvent(
            $owner,
            WithLock::onEntity($owner, RaiseEvent::forLockNotification($owner), $history->id),
        ),
        $history->applyRaiseEvent(...),
    );
    expect($lockResult)->toHaveCount(2);

    $result = processEvent(
        WithLock::onEntity($owner, AwaitResult::forEvent($owner, RaiseEvent::forOperation('signal', [])), $history->id),
        $history->applyRaiseEvent(...),
    );

    $otherResult = processEvent(
        $waiting = AwaitResult::forEvent($other, RaiseEvent::forOperation('signal', [])),
        $history->applyRaiseEvent(...),
    );
    expect($called)->toBe(1);

    $unlockResult = processEvent(
        WithLock::onEntity(
            $owner,
            AwaitResult::forEvent($owner, RaiseEvent::forUnlock($owner->id, null, null)),
            $history->id,
        ),
        $history->applyRaiseEvent(...),
    );

    expect($unlockResult)
        ->toContain($waiting)->and($called)->toBe(1);
});

it('properly locks in a chain', function (): void {
    $called = 0;
    $outerCall = function () use (&$called): void {
        $called++;
    };
    $history = getEntityHistory(
        new class ($outerCall) extends EntityState {
            public function __construct(public $outerCall) {}

            public function signal(): void
            {
                ($this->outerCall)();
            }
        },
    );
    $history->from = StateId::fromInstance(OrchestrationInstance('test', 'test'));

    $owner = StateId::fromInstance(OrchestrationInstance('owner', 'owner'));
    $other = StateId::fromInstance(OrchestrationInstance('other', 'other'));

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
            ),
        ),
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
            ),
        ),
    );

    // send the first lock notification in the chain
    $firstResult = processEvent($firstLock, $otherEntity->applyRaiseEvent(...));
    expect($firstResult)
        ->toHaveCount(3)->and($firstResult[0]->innerEvent->target->id)->toBe($history->id->id);

    // send a signal to be run once the lock is complete
    $locked = processEvent($actualEvent, $history->applyRaiseEvent(...));
    expect($locked)->toHaveCount(1);

    // complete the lock sequence
    $secondResult = processEvent($firstResult[0], $history->applyRaiseEvent(...));
    expect($secondResult)
        ->toHaveCount(3)->and($secondResult[0])->toBeInstanceOf(WithEntity::class);

    // process the actual event earlier
    $finalResult = processEvent($secondResult[0], $history->applyRaiseEvent(...));
    expect($called)->toBe(1);

    // process the final lock notification
    $finalResult = processEvent($secondResult[1], $otherEntity->applyRaiseEvent(...));
    expect($finalResult)->toBeEmpty();
});

it('can get a property value using get signal', function (): void {
    // Create an entity state with a property
    $history = getEntityHistory(
        new class extends EntityState {
            public string $testProperty = 'test value';
        },
    );
    $history->from = StateId::fromInstance(OrchestrationInstance('test', 'test'));

    // Create a signal to get the property value
    $event = RaiseEvent::forOperation('$testProperty::get', []);
    $event = AwaitResult::forEvent(StateId::fromInstance(OrchestrationInstance('test', 'test')), $event);

    // Process the event
    $result = processEvent($event, $history->applyRaiseEvent(...));

    // Verify the result contains a TaskCompleted event with the property value
    expect($result)->toHaveCount(1);
    expect($result[0]->getInnerEvent()->getInnerEvent())->toBeInstanceOf(TaskCompleted::class);
    expect($result[0]->getInnerEvent()->getInnerEvent()->result)->toBe(['value' => 'test value']);
});

it('can set a property value using set signal', function (): void {
    // Create an entity state with a property
    $history = getEntityHistory(
        new class extends EntityState {
            public string $testProperty = 'initial value';
        },
    );
    $history->from = StateId::fromInstance(OrchestrationInstance('test', 'test'));

    // Create a signal to set the property value
    $event = RaiseEvent::forOperation('$testProperty::set', ['new value']);
    $event = AwaitResult::forEvent(StateId::fromInstance(OrchestrationInstance('test', 'test')), $event);

    // Process the event
    $result = processEvent($event, $history->applyRaiseEvent(...));

    // Verify the property was updated
    expect($history->getState()->testProperty)->toBe('new value');

    // Verify the result contains a TaskCompleted event
    expect($result)->toHaveCount(1);
    expect($result[0]->getInnerEvent()->getInnerEvent())->toBeInstanceOf(TaskCompleted::class);
});

it('handles access control for property signals', function (): void {
    // Create a mock class with an AccessControl attribute on a property
    $from = StateId::fromInstance(OrchestrationInstance('test', 'test'));
    $mockClass = new class extends EntityState {
        #[Bottledcode\DurablePhp\State\Attributes\DenyAnyOperation(fromType: 'test')]
        public string $restrictedProperty = 'restricted value';

        public string $publicProperty = 'public value';
    };

    $history = getEntityHistory($mockClass);
    $history->from = $from;

    // Try to access the restricted property
    $restrictedEvent = RaiseEvent::forOperation('$restrictedProperty::get', []);

    // This should throw a SecurityException, which is caught in the execute method
    expect(fn() => processEvent($restrictedEvent, $history->applyRaiseEvent(...)))->toThrow(SecurityException::class);
});
