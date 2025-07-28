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

//namespace Bottledcode\DurablePhp\Tests\Unit;

use Bottledcode\DurablePhp\Events\AwaitResult;
use Bottledcode\DurablePhp\Events\ScheduleTask;
use Bottledcode\DurablePhp\Events\TaskCompleted;
use Bottledcode\DurablePhp\Events\TaskFailed;
use Bottledcode\DurablePhp\Events\WithActivity;
use Bottledcode\DurablePhp\Glue\Provenance;
use Bottledcode\DurablePhp\State\ActivityHistory;
use Bottledcode\DurablePhp\State\Ids\StateId;
use DI\Container;
use Ramsey\Uuid\Uuid;

use function Bottledcode\DurablePhp\EntityId;

function activity(bool $fail): void
{
    if ($fail) {
        throw new Exception('test');
    }
}

test('exampleaa', function (): void {
    expect(true)->toBeTrue();
});

it('real: fails on an exception', function (): void {
    $history = new ActivityHistory(StateId::fromActivityId(Uuid::uuid7()), null, new Provenance('', []));
    $event = AwaitResult::forEvent(
        StateId::fromEntityId(EntityId('test', 'test')),
        WithActivity::forEvent(Uuid::uuid7(), ScheduleTask::forName(__NAMESPACE__ . '\activity', [true])),
    );
    $result1 = processEvent($event, $history->applyScheduleTask(...));
    expect($result1)->toHaveCount(1)->and($result1[0]->getInnerEvent())->toBeInstanceOf(TaskFailed::class);

    $result2 = processEvent($event, $history->applyScheduleTask(...));
    expect($result2)
        ->toHaveCount(1)->and($result2[0]->getInnerEvent())->toBeInstanceOf(TaskFailed::class)->and(current($result1))
        ->toEqual(current($result2));
});

it('succeeds on no exception', function (): void {
    $history = new ActivityHistory(StateId::fromActivityId(Uuid::uuid7()), null, new Provenance('', []));
    $container = new Container([__NAMESPACE__ . '\activity' => activity(...)]);
    $history->setContainer($container);
    $event = AwaitResult::forEvent(
        StateId::fromEntityId(EntityId('test', 'test')),
        WithActivity::forEvent(Uuid::uuid7(), ScheduleTask::forName(__NAMESPACE__ . '\activity', [false])),
    );
    $result1 = processEvent($event, $history->applyScheduleTask(...));
    expect($result1)->toHaveCount(1)->and($result1[0]->getInnerEvent()->getInnerEvent())->toBeInstanceOf(
        TaskCompleted::class,
    );

    $result2 = processEvent($event, $history->applyScheduleTask(...));
    expect($result2)
        ->toHaveCount(1)->and($result2[0]->getInnerEvent())->toBeInstanceOf(TaskCompleted::class)->and(
            current($result1),
        )->toEqual(current($result2));
});
